<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

/**
 * Everything about one trace, for the trace panel: its spans from the browser to the databases, the browser session
 * and page it came from, and the log records written while it ran.
 */
class TraceStats extends RumStats {

	// Seconds read around the moment the trace was seen: its other spans can start earlier or end later.
	public const WINDOW = 15 * SEC_PER_MIN;

	public const SPAN_LIMIT = 300;
	public const LOG_LIMIT = 200;
	public const LOG_BODY_MAX_LENGTH = 600;

	// How far back the page of a browser request is looked for (the page load that came before it in the session).
	private const VIEW_LOOKBACK = SEC_PER_HOUR;

	/**
	 * @param int $clock  A moment within the trace (e.g. the start of one of its spans).
	 *
	 * @return array  ['spans' => [...] (ApmStats::getTraceSpans(), start relative to the first span), 'start' => int
	 *                (ns since the epoch), 'duration' => float (ns), 'span_count' => int, 'truncated' => bool]
	 */
	public function getTrace(string $traceid, int $clock): array {
		$spans = $this->getTraceSpans([$traceid], $clock - self::WINDOW, $clock + self::WINDOW,
			self::SPAN_LIMIT + 1
		)[$traceid] ?? [];

		$truncated = count($spans) > self::SPAN_LIMIT;
		$spans = array_slice($spans, 0, self::SPAN_LIMIT);

		if (!$spans) {
			return ['spans' => [], 'start' => 0, 'duration' => 0.0, 'span_count' => 0, 'truncated' => false];
		}

		$start = min(array_column($spans, 'start'));
		$end = $start;

		foreach ($spans as &$span) {
			$end = max($end, $span['start'] + $span['duration']);
			$span['start'] -= $start;
		}
		unset($span);

		return ['spans' => $spans, 'start' => $start, 'duration' => (float) ($end - $start),
			'span_count' => count($spans), 'truncated' => $truncated
		];
	}

	/**
	 * Where a trace came from: the browser session, user and page that started it. Null if no browser span belongs to
	 * the trace (a call from another system, a job, or a browser that is not instrumented).
	 *
	 * @return array|null  ['application', 'service', 'session', 'user', 'page', 'view', 'url', 'agent', 'clock',
	 *                     'session_from', 'session_to']
	 */
	public function getOrigin(string $traceid, int $clock): ?array {
		[$access, $access_params] = self::accessCondition(ApmAccess::TRACES);
		$access = $access !== '' ? ' AND '.$access : '';

		$sql = 'SELECT any(ResourceAttributes[\'service.namespace\']) AS application, any(ServiceName) AS service,'.
				' anyIf(SpanAttributes[\'session.id\'],SpanAttributes[\'session.id\']!=\'\') AS session,'.
				' anyIf(SpanAttributes[\'enduser.id\'],SpanAttributes[\'enduser.id\']!=\'\') AS user,'.
				' anyIf(SpanAttributes[\'view.id\'],SpanAttributes[\'view.id\']!=\'\') AS view,'.
				' anyIf(SpanAttributes[\'view.path\'],SpanAttributes[\'view.path\']!=\'\') AS view_path,'.
				' anyIf(SpanAttributes[\'url.full\'],SpanName=\''.RumStats::VIEW_SPAN.'\') AS load_url,'.
				' '.self::pageOf('anyIf(SpanAttributes[\'url.full\'],SpanName=\''.RumStats::VIEW_SPAN.'\')').' AS load_page,'.
				' anyIf(SpanAttributes[\'url.full\'],SpanAttributes[\'url.full\']!=\'\') AS url,'.
				' any(if(ResourceAttributes[\'user_agent.original\']!=\'\',ResourceAttributes[\'user_agent.original\'],'.
					'SpanAttributes[\'user_agent.original\'])) AS agent,'.
				' toUnixTimestamp(min(Timestamp)) AS clock, count() AS spans'.
			' FROM otel_traces'.
			' WHERE '.self::PERIOD.$access.' AND TraceId={traceid:String} AND '.self::BROWSER;

		$params = [
			'time_from' => ['Int32', $clock - self::WINDOW],
			'time_till' => ['Int32', $clock + self::WINDOW],
			'traceid' => ['String', $traceid]
		] + $access_params;

		$row = self::rows($this->db, $sql, $params)[0] ?? null;

		if ($row === null || (int) $row['spans'] == 0) {
			return null;
		}

		$origin = [
			'application' => (string) $row['application'],
			'service' => (string) $row['service'],
			'session' => (string) $row['session'],
			'user' => (string) $row['user'],
			'view' => (string) $row['view'],
			'page' => (string) $row['view_path'],
			'url' => (string) $row['url'],
			'agent' => (string) $row['agent'],
			'clock' => (int) $row['clock'],
			'session_from' => (int) $row['clock'],
			'session_to' => (int) $row['clock']
		];

		// The trace of a page load names its own page.
		if ($origin['page'] === '' && (string) $row['load_url'] !== '') {
			$origin['page'] = (string) $row['load_page'];
		}

		if ($origin['session'] === '') {
			return $origin;
		}

		// The span of the session, to open its timeline, and the page that was on screen: the last one loaded before
		// the trace.
		$sql = 'SELECT toUnixTimestamp(min(Timestamp)) AS first, toUnixTimestamp(max(Timestamp)) AS last,'.
				' argMaxIf(SpanAttributes[\'url.full\'],Timestamp,SpanName=\''.RumStats::VIEW_SPAN.'\''.
					' AND Timestamp<=toDateTime64({clock:Int32},9)+1) AS load_url,'.
				' '.self::pageOf('argMaxIf(SpanAttributes[\'url.full\'],Timestamp,SpanName=\''.RumStats::VIEW_SPAN.'\''.
					' AND Timestamp<=toDateTime64({clock:Int32},9)+1)').' AS load_page'.
			' FROM otel_traces'.
			' WHERE '.self::PERIOD.$access.' AND '.self::BROWSER.' AND SpanAttributes[\'session.id\']={session:String}';

		$row = self::rows($this->db, $sql, [
			'time_from' => ['Int32', $origin['clock'] - self::VIEW_LOOKBACK],
			'time_till' => ['Int32', $origin['clock'] + self::VIEW_LOOKBACK],
			'session' => ['String', $origin['session']],
			'clock' => ['Int32', $origin['clock']]
		] + $access_params)[0] ?? null;

		if ($row !== null && (int) $row['first'] > 0) {
			$origin['session_from'] = (int) $row['first'];
			$origin['session_to'] = (int) $row['last'];

			if ($origin['page'] === '' && (string) $row['load_url'] !== '') {
				$origin['page'] = (string) $row['load_page'];
			}
		}

		return $origin;
	}

	/**
	 * Application (service.namespace) of the first backend span of a trace, '' if it has none.
	 */
	public function getApplicationOf(string $traceid, int $clock): string {
		[$access, $access_params] = self::accessCondition(ApmAccess::TRACES);

		$sql = 'SELECT argMin(ResourceAttributes[\'service.namespace\'],Timestamp) AS application'.
			' FROM otel_traces'.
			' WHERE '.self::PERIOD.($access !== '' ? ' AND '.$access : '').' AND TraceId={traceid:String}'.
				' AND NOT ('.self::BROWSER.') AND ResourceAttributes[\'service.namespace\']!=\'\'';

		return (string) (self::rows($this->db, $sql, [
			'time_from' => ['Int32', $clock - self::WINDOW],
			'time_till' => ['Int32', $clock + self::WINDOW],
			'traceid' => ['String', $traceid]
		] + $access_params)[0]['application'] ?? '');
	}

	/**
	 * Log records of a trace, oldest first.
	 *
	 * @return array  ['logs' => [['clock', 'ms', 'service', 'severity', 'number', 'body', 'spanid'], ...],
	 *                'truncated' => bool]
	 */
	public function getLogs(string $traceid, int $clock): array {
		[$access, $access_params] = self::accessCondition(ApmAccess::LOGS);

		$sql = 'SELECT toUnixTimestamp(Timestamp) AS clock, toUnixTimestamp64Milli(Timestamp)%1000 AS ms,'.
				' ServiceName AS service, SeverityText AS severity, SeverityNumber AS number,'.
				' substringUTF8(Body,1,'.self::LOG_BODY_MAX_LENGTH.') AS body, SpanId AS spanid'.
			' FROM otel_logs'.
			' WHERE '.self::PERIOD.($access !== '' ? ' AND '.$access : '').' AND TraceId={traceid:String}'.
			' ORDER BY Timestamp'.
			' LIMIT '.(self::LOG_LIMIT + 1);

		$rows = self::rows($this->db, $sql, [
			'time_from' => ['Int32', $clock - self::WINDOW],
			'time_till' => ['Int32', $clock + self::WINDOW],
			'traceid' => ['String', $traceid]
		] + $access_params);

		$logs = [];

		foreach (array_slice($rows, 0, self::LOG_LIMIT) as $row) {
			$logs[] = [
				'clock' => (int) $row['clock'],
				'ms' => (int) $row['ms'],
				'service' => (string) $row['service'],
				'severity' => (string) $row['severity'],
				'number' => (int) $row['number'],
				'body' => (string) $row['body'],
				'spanid' => (string) $row['spanid']
			];
		}

		return ['logs' => $logs, 'truncated' => count($rows) > self::LOG_LIMIT];
	}

	/**
	 * Services of a trace in the order they joined it, with what each one did.
	 *
	 * @return array  [['service', 'spans', 'errors', 'duration' (longest span, ns), 'browser'], ...]
	 */
	public static function services(array $spans): array {
		$services = [];

		foreach ($spans as $span) {
			$services[$span['service']] ??= ['service' => $span['service'], 'spans' => 0, 'errors' => 0,
				'duration' => 0.0, 'browser' => false
			];
			$service = &$services[$span['service']];
			$service['spans']++;
			$service['errors'] += (int) $span['error'];
			$service['duration'] = max($service['duration'], $span['duration']);
			$service['browser'] = $service['browser'] || ($span['browser'] ?? false);
			unset($service);
		}

		return array_values($services);
	}

	/**
	 * Where the time of a request made by a browser went: the browser waited "total", of which the backend worked
	 * "backend" (its first server span); the rest is network and queueing. Null if the trace has no browser span or
	 * no server span below it.
	 *
	 * @return array|null  ['total', 'backend', 'network'], nanoseconds.
	 */
	public static function split(array $spans): ?array {
		$browser = null;
		$server = null;

		foreach ($spans as $span) {
			if (($span['browser'] ?? false) && $span['kind'] === 'Client' && $browser === null) {
				$browser = $span;
			}
			elseif (!($span['browser'] ?? false) && $span['kind'] === 'Server' && $server === null) {
				$server = $span;
			}
		}

		if ($browser === null || $server === null || $browser['duration'] <= 0) {
			return null;
		}

		$backend = min($server['duration'], $browser['duration']);

		return ['total' => $browser['duration'], 'backend' => $backend, 'network' => $browser['duration'] - $backend];
	}
}

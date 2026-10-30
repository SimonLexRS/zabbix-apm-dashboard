<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

/**
 * Real user monitoring statistics: the spans of the OpenTelemetry web SDK (browser), limited like every other query to
 * the application and to what the user may see.
 */
class RumStats extends ApmStats {

	public const SESSION_LIMIT = 100;
	public const TIMELINE_LIMIT = 2000;

	// Spans sent from a browser by the OpenTelemetry web SDK.
	private const BROWSER = 'ResourceAttributes[\'telemetry.sdk.language\']=\'webjs\'';

	private const SESSION = 'SpanAttributes[\'session.id\']';
	private const USER = 'SpanAttributes[\'enduser.id\']';
	private const URL = 'SpanAttributes[\'url.full\']';
	private const IS_VIEW = 'SpanName=\'documentLoad\'';
	// One action per user event: the instrumentation adds a span per event listener, nested under the first one.
	private const IS_ACTION = 'SpanAttributes[\'event_type\']!=\'\' AND ParentSpanId=\'\'';
	private const IS_REQUEST = 'SpanKind=\'Client\'';
	private const USER_AGENT = 'if(ResourceAttributes[\'user_agent.original\']!=\'\','.
		'ResourceAttributes[\'user_agent.original\'],SpanAttributes[\'user_agent.original\'])';

	/*
	 * Page of a URL: its path with the variable segments replaced by ":id", so that /product/42 and /product/57 are one
	 * page. A segment is variable if it is all digits; or at least 6 letters, digits, "_" or "-" with one digit
	 * (2ZYFJ3GM2N); or a UUID.
	 */
	private const PAGE_OF = 'if(empty(trim(BOTH \'/\' FROM path(%1$s))),\'/\',concat(\'/\',arrayStringConcat(arrayMap('.
		's -> if(match(s,\'^[0-9]+$\') OR (length(s)>=6 AND match(s,\'^[A-Za-z0-9_-]+$\') AND match(s,\'[0-9]\'))'.
		' OR match(s,\'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$\'),\':id\',s),'.
		'splitByChar(\'/\',trim(BOTH \'/\' FROM path(%1$s)))),\'/\')))';

	// Kind of a resource, from the extension at the end of the path of its URL.
	private const RESOURCE_TYPE = 'multiIf(match(lower(path(%1$s)),\'[.]m?js$\'),\'script\','.
		'match(lower(path(%1$s)),\'[.]css$\'),\'stylesheet\','.
		'match(lower(path(%1$s)),\'[.](png|jpe?g|gif|webp|avif|svg|ico)$\'),\'image\','.
		'match(lower(path(%1$s)),\'[.](woff2?|ttf|otf|eot)$\'),\'font\',\'other\')';

	// Milliseconds from the start of the span to one of its events, NULL without the event.
	private const EVENT_MS = 'if(has(Events.Name,\'%1$s\'),(toUnixTimestamp64Nano(Events.Timestamp[indexOf(Events.Name,'.
		'\'%1$s\')])-toUnixTimestamp64Nano(Timestamp))/1e6,NULL)';

	/**
	 * Applications (service.namespace) with browser spans in the last $seconds, by name.
	 *
	 * @return string[]
	 */
	public function getBrowserApplications(int $seconds = 7 * SEC_PER_DAY): array {
		[$access, $params] = self::accessCondition(ApmAccess::TRACES);

		return array_map(static fn(array $row): string => (string) $row['app'], self::rows($this->db,
			'SELECT DISTINCT ResourceAttributes[\'service.namespace\'] AS app FROM otel_traces'.
			' WHERE Timestamp>=now()-{seconds:UInt32} AND '.self::BROWSER.($access !== '' ? ' AND '.$access : '').
			' ORDER BY app=\'\', app',
			['seconds' => ['UInt32', $seconds]] + $params
		));
	}

	/**
	 * Sessions, users, page loads, actions, requests, failed requests, load and first contentful paint p75.
	 */
	public function getKpis(int $time_from, int $time_till): array {
		$row = $this->fetch(
			'SELECT uniqExactIf('.self::SESSION.','.self::SESSION.'!=\'\') AS sessions,'.
				' uniqExactIf('.self::USER.','.self::USER.'!=\'\') AS users,'.
				' countIf('.self::IS_VIEW.') AS views,'.
				' countIf('.self::IS_ACTION.') AS actions,'.
				' countIf('.self::IS_REQUEST.') AS requests,'.
				' countIf('.self::IS_REQUEST.' AND '.self::failed().') AS failed,'.
				' countIf('.self::IS_REQUEST.' AND '.self::clientError().') AS client_errors,'.
				' quantileIf(0.75)(Duration,'.self::IS_VIEW.') AS load_p75,'.
				' quantileIf(0.75)('.self::eventMs('firstContentfulPaint').','.self::IS_VIEW.') AS fcp_p75'.
			$this->browserWhere(),
			$time_from, $time_till
		)[0] ?? [];

		return [
			'sessions' => (int) ($row['sessions'] ?? 0),
			'users' => (int) ($row['users'] ?? 0),
			'views' => (int) ($row['views'] ?? 0),
			'actions' => (int) ($row['actions'] ?? 0),
			'requests' => (int) ($row['requests'] ?? 0),
			'failed' => (int) ($row['failed'] ?? 0),
			'client_errors' => (int) ($row['client_errors'] ?? 0),
			'load_p75' => (int) ($row['views'] ?? 0) > 0 ? (float) $row['load_p75'] : null,
			'fcp_p75' => is_numeric($row['fcp_p75'] ?? null) ? (float) $row['fcp_p75'] : null
		];
	}

	/**
	 * Sessions, page loads and load p75 per minute, for the last $minutes complete minutes of the period.
	 *
	 * @return array  ['minutes' => int[], 'sessions' => int[], 'views' => int[], 'load_p75' => float[] (ns)]
	 */
	public function getActivity(int $time_from, int $time_till, int $minutes = self::SERIES_MINUTES): array {
		$window = self::getSeriesWindow($time_from, $time_till, $minutes);

		if ($window === null) {
			return ['minutes' => [], 'sessions' => [], 'views' => [], 'load_p75' => []];
		}

		[$series_from, $series_till, $buckets] = $window;
		$empty = array_fill_keys($buckets, 0);
		$result = ['minutes' => $buckets, 'sessions' => $empty, 'views' => $empty, 'load_p75' => $empty];

		$rows = $this->fetch(
			'SELECT toUnixTimestamp(toStartOfMinute(Timestamp)) AS minute,'.
				' uniqExactIf('.self::SESSION.','.self::SESSION.'!=\'\') AS sessions,'.
				' countIf('.self::IS_VIEW.') AS views,'.
				' quantileIf(0.75)(Duration,'.self::IS_VIEW.') AS load_p75'.
			$this->browserWhere().
			' GROUP BY minute',
			$series_from, $series_till
		);

		foreach ($rows as $row) {
			$minute = (int) $row['minute'];

			if (array_key_exists($minute, $empty)) {
				$result['sessions'][$minute] = (int) $row['sessions'];
				$result['views'][$minute] = (int) $row['views'];
				$result['load_p75'][$minute] = (int) $row['views'] > 0 ? (float) $row['load_p75'] : 0;
			}
		}

		return array_map('array_values', $result);
	}

	/**
	 * Pages: page loads, sessions, load p75 and p95 (ns), first contentful paint p75 (ms), most viewed first.
	 */
	public function getPages(int $time_from, int $time_till, int $limit = 100): array {
		return array_map(static fn(array $row): array => [
			'page' => $row['page'],
			'views' => (int) $row['views'],
			'sessions' => (int) $row['sessions'],
			'p75' => (float) $row['q'][0],
			'p95' => (float) $row['q'][1],
			'fcp_p75' => is_numeric($row['fcp_p75']) ? (float) $row['fcp_p75'] : null
		], $this->fetch(
			'SELECT '.self::pageOf(self::URL).' AS page, count() AS views,'.
				' uniqExactIf('.self::SESSION.','.self::SESSION.'!=\'\') AS sessions,'.
				' quantiles(0.75,0.95)(Duration) AS q,'.
				' quantile(0.75)('.self::eventMs('firstContentfulPaint').') AS fcp_p75'.
			$this->browserWhere().' AND '.self::IS_VIEW.
			' GROUP BY page'.
			' ORDER BY views DESC, page'.
			' LIMIT '.$limit,
			$time_from, $time_till
		));
	}

	/**
	 * The median and the slowest load of a page, with the events of the load and of its document request, as
	 * milliseconds from the start of the load.
	 *
	 * @return array  ['median' => ?load, 'slowest' => ?load], load ['traceid', 'clock', 'duration' (ns), 'events']
	 */
	public function getPageLoads(int $time_from, int $time_till, string $page): array {
		$loads = $this->fetch(
			'SELECT TraceId AS traceid, toUnixTimestamp64Nano(Timestamp) AS start, Duration AS duration'.
			$this->browserWhere().' AND '.self::IS_VIEW.' AND '.self::pageOf(self::URL).'={page:String}'.
			' ORDER BY Duration'.
			' LIMIT 5000',
			$time_from, $time_till, '', ['page' => ['String', $page]]
		);

		if (!$loads) {
			return ['median' => null, 'slowest' => null];
		}

		$picked = ['median' => $loads[intdiv(count($loads) - 1, 2)], 'slowest' => end($loads)];

		// The load (documentLoad) and its document request (documentFetch) are in the same trace.
		$rows = $this->fetch(
			'SELECT TraceId AS traceid, toUnixTimestamp64Nano(Timestamp) AS start, Events.Name AS names,'.
				' arrayMap(t -> toUnixTimestamp64Nano(t), Events.Timestamp) AS times'.
			$this->browserWhere().' AND SpanName IN (\'documentLoad\',\'documentFetch\')'.
				' AND TraceId IN {traceids:Array(String)}',
			$time_from - SEC_PER_MIN, $time_till + SEC_PER_MIN, '',
			['traceids' => ['String', array_values(array_unique(array_column($picked, 'traceid')))]]
		);

		$result = [];

		foreach ($picked as $key => $load) {
			$events = [];

			foreach ($rows as $row) {
				if ($row['traceid'] !== $load['traceid']) {
					continue;
				}

				foreach ($row['names'] as $i => $name) {
					$events[$name] = ((int) $row['times'][$i] - (int) $load['start']) / 1e6;
				}
			}

			$result[$key] = [
				'traceid' => $load['traceid'],
				'clock' => intdiv((int) $load['start'], 1000000000),
				'duration' => (float) $load['duration'],
				'events' => $events
			];
		}

		return $result;
	}

	/**
	 * Resources loaded by the pages (images, scripts, styles, fonts), by kind and host: count, p75 (ns) and the
	 * average of each phase (ms).
	 */
	public function getResources(int $time_from, int $time_till, int $limit = 50): array {
		return array_map(static fn(array $row): array => [
			'type' => $row['type'],
			'host' => $row['host'],
			'count' => (int) $row['count'],
			'p75' => (float) $row['p75'],
			'dns' => (float) $row['dns'],
			'connection' => (float) $row['connection'],
			'waiting' => (float) $row['waiting'],
			'download' => (float) $row['download']
		], $this->fetch(
			'SELECT '.sprintf(self::RESOURCE_TYPE, self::URL).' AS type, domain('.self::URL.') AS host,'.
				' count() AS count, quantile(0.75)(Duration) AS p75,'.
				' ifNull(avg('.self::eventMs('domainLookupEnd').'-'.self::eventMs('domainLookupStart').'),0) AS dns,'.
				' ifNull(avg('.self::eventMs('connectEnd').'-'.self::eventMs('connectStart').'),0) AS connection,'.
				' ifNull(avg('.self::eventMs('responseStart').'-'.self::eventMs('requestStart').'),0) AS waiting,'.
				' ifNull(avg('.self::eventMs('responseEnd').'-'.self::eventMs('responseStart').'),0) AS download'.
			$this->browserWhere().' AND SpanName=\'resourceFetch\''.
			' GROUP BY type, host'.
			' ORDER BY count DESC'.
			' LIMIT '.$limit,
			$time_from, $time_till
		));
	}

	/**
	 * Requests made by the browser (fetch, XMLHttpRequest), by method, host, path and status class: count, failed,
	 * p75 (ns) and the latest trace, to open it.
	 */
	public function getRequests(int $time_from, int $time_till, int $limit = 100): array {
		$method = 'if(SpanAttributes[\'http.request.method\']!=\'\',SpanAttributes[\'http.request.method\'],'.
			'SpanAttributes[\'http.method\'])';

		return array_map(static fn(array $row): array => [
			'method' => $row['method'],
			'host' => $row['host'],
			'path' => $row['path'],
			'status' => $row['status'],
			'count' => (int) $row['count'],
			'failed' => (int) $row['failed'],
			'p75' => (float) $row['p75'],
			'traceid' => $row['traceid'],
			'clock' => (int) $row['clock']
		], $this->fetch(
			'SELECT '.$method.' AS method, domain('.self::URL.') AS host, '.self::pageOf(self::URL).' AS path,'.
				' if('.self::HTTP_STATUS.'=\'\',\'—\',concat(left('.self::HTTP_STATUS.',1),\'xx\')) AS status,'.
				' count() AS count, countIf('.self::failed().') AS failed, quantile(0.75)(Duration) AS p75,'.
				' argMax(TraceId,Timestamp) AS traceid, toUnixTimestamp(max(Timestamp)) AS clock'.
			$this->browserWhere().' AND '.self::IS_REQUEST.
			' GROUP BY method, host, path, status'.
			' ORDER BY failed DESC, count DESC'.
			' LIMIT '.$limit,
			$time_from, $time_till
		));
	}

	/**
	 * Sessions per browser and per operating system, parsed from the user agents.
	 *
	 * @return array  ['browsers' => [name => sessions], 'systems' => [name => sessions], 'devices' => [kind => sessions]]
	 */
	public function getUserAgents(int $time_from, int $time_till): array {
		$result = ['browsers' => [], 'systems' => [], 'devices' => []];

		$rows = $this->fetch(
			'SELECT '.self::USER_AGENT.' AS ua, uniqExactIf('.self::SESSION.','.self::SESSION.'!=\'\') AS sessions'.
			$this->browserWhere().' AND '.self::IS_VIEW.
			' GROUP BY ua'.
			' ORDER BY sessions DESC'.
			' LIMIT 1000',
			$time_from, $time_till
		);

		foreach ($rows as $row) {
			$agent = Rum::userAgent((string) $row['ua']);

			foreach (['browsers' => 'browser', 'systems' => 'os', 'devices' => 'device'] as $key => $field) {
				$result[$key][$agent[$field]] = ($result[$key][$agent[$field]] ?? 0) + (int) $row['sessions'];
			}
		}

		foreach ($result as &$counts) {
			arsort($counts);
		}
		unset($counts);

		return $result;
	}

	/**
	 * The most recent sessions of the period.
	 *
	 * @param array $filter  ['page' => string, 'user' => string, 'failed' => bool]
	 *
	 * @return array  ['session', 'user', 'start' (ns), 'end' (ns), 'views', 'actions', 'requests', 'failed', 'ua',
	 *                 'pages' => string[]]
	 */
	public function getSessions(int $time_from, int $time_till, array $filter): array {
		$having = [];
		$params = [];

		if ($filter['page'] !== '') {
			$having[] = 'has(pages,{page:String})';
			$params['page'] = ['String', $filter['page']];
		}

		if ($filter['user'] !== '') {
			$having[] = 'user={user:String}';
			$params['user'] = ['String', $filter['user']];
		}

		if ($filter['failed']) {
			$having[] = 'failed>0';
		}

		return array_map(static fn(array $row): array => [
			'session' => $row['session'],
			'user' => $row['user'],
			'start' => (int) $row['start'],
			'end' => (int) $row['end'],
			'views' => (int) $row['views'],
			'actions' => (int) $row['actions'],
			'requests' => (int) $row['requests'],
			'failed' => (int) $row['failed'],
			'ua' => $row['ua'],
			'pages' => $row['pages']
		], $this->fetch(
			'SELECT '.self::SESSION.' AS session, anyIf('.self::USER.','.self::USER.'!=\'\') AS user,'.
				' min(toUnixTimestamp64Nano(Timestamp)) AS start,'.
				' max(toUnixTimestamp64Nano(Timestamp)+Duration) AS end,'.
				' countIf('.self::IS_VIEW.') AS views, countIf('.self::IS_ACTION.') AS actions,'.
				' countIf('.self::IS_REQUEST.') AS requests,'.
				' countIf('.self::IS_REQUEST.' AND '.self::failed().') AS failed,'.
				' anyIf('.self::USER_AGENT.','.self::USER_AGENT.'!=\'\') AS ua,'.
				' groupUniqArrayIf(20)('.self::pageOf(self::URL).','.self::IS_VIEW.') AS pages'.
			$this->browserWhere().' AND '.self::SESSION.'!=\'\''.
			' GROUP BY session'.
			($having ? ' HAVING '.implode(' AND ', $having) : '').
			' ORDER BY start DESC'.
			' LIMIT '.self::SESSION_LIMIT.
			self::LOW_MEMORY,
			$time_from, $time_till, '', $params
		));
	}

	/**
	 * Page loads, actions and requests of one session, with the backend part of each request's trace.
	 *
	 * @return array  ['spans' => rows for Rum::timeline(), 'backend' => [traceid => ['services' => string[],
	 *                 'error_service' => ?string, 'duration' => float (ns)]], 'truncated' => bool]
	 */
	public function getSessionTimeline(string $session, int $time_from, int $time_till): array {
		$rows = $this->fetch(
			'SELECT multiIf('.self::IS_VIEW.',\'view\','.self::IS_ACTION.',\'action\',\'request\') AS type,'.
				' TraceId AS trace, toUnixTimestamp64Nano(Timestamp) AS start, Duration AS duration,'.
				' toUnixTimestamp(Timestamp) AS clock, ServiceName AS service, '.self::URL.' AS url,'.
				' '.self::pageOf(self::URL).' AS page,'.
				' SpanAttributes[\'event_type\'] AS event, SpanAttributes[\'target_element\'] AS element,'.
				' SpanAttributes[\'target_xpath\'] AS xpath,'.
				' if(SpanAttributes[\'http.request.method\']!=\'\',SpanAttributes[\'http.request.method\'],'.
					'SpanAttributes[\'http.method\']) AS method,'.
				' '.self::HTTP_STATUS.' AS status, '.self::failed().' AS failed, '.self::clientError().' AS client_error,'.
				' '.self::eventMs('firstContentfulPaint').' AS fcp'.
			$this->browserWhere().' AND '.self::SESSION.'={session:String}'.
				' AND ('.self::IS_VIEW.' OR '.self::IS_ACTION.' OR '.self::IS_REQUEST.')'.
			' ORDER BY Timestamp'.
			' LIMIT '.(self::TIMELINE_LIMIT + 1).
			self::LOW_MEMORY,
			$time_from, $time_till, '', ['session' => ['String', $session]]
		);

		$truncated = count($rows) > self::TIMELINE_LIMIT;
		$rows = array_slice($rows, 0, self::TIMELINE_LIMIT);
		$traceids = array_values(array_unique(array_column(
			array_filter($rows, static fn(array $row): bool => $row['type'] === 'request'), 'trace'
		)));
		$backend = [];

		if ($traceids) {
			// The other services of each request's trace, in the order they started; the first one that failed.
			foreach ($this->fetch(
				'SELECT TraceId AS trace,'.
					' arrayDistinct(arrayMap(x -> x.2, arraySort(groupArray((Timestamp,ServiceName))))) AS services,'.
					' argMinIf(ServiceName,Timestamp,StatusCode=\'Error\') AS error_service,'.
					' countIf(StatusCode=\'Error\') AS errors,'.
					' max(Duration) AS duration'.
				' FROM otel_traces'.
				' WHERE '.$this->period().' AND NOT ('.self::BROWSER.')'.
					' AND TraceId IN {traceids:Array(String)}'.
				' GROUP BY trace'.
				self::LOW_MEMORY,
				$time_from - SEC_PER_MIN, $time_till + SEC_PER_MIN, '', ['traceids' => ['String', $traceids]]
			) as $row) {
				$backend[$row['trace']] = [
					'services' => $row['services'],
					'error_service' => (int) $row['errors'] > 0 ? $row['error_service'] : null,
					'duration' => (float) $row['duration']
				];
			}
		}

		$spans = array_map(static fn(array $row): array => [
			'type' => $row['type'],
			'trace' => $row['trace'],
			'start' => (int) $row['start'],
			'duration' => (float) $row['duration'],
			'clock' => (int) $row['clock'],
			'service' => $row['service'],
			'url' => $row['url'],
			'page' => $row['page'],
			'event' => $row['event'],
			'element' => $row['element'],
			'xpath' => $row['xpath'],
			'method' => $row['method'],
			'status' => $row['status'],
			'failed' => (bool) $row['failed'],
			'client_error' => (bool) $row['client_error'],
			'fcp' => is_numeric($row['fcp']) ? (float) $row['fcp'] : null
		], $rows);

		return ['spans' => $spans, 'backend' => $backend, 'truncated' => $truncated];
	}

	/**
	 * Checks of the browser instrumentation of the application over the last $seconds: browser spans, spans with a
	 * session, and requests whose trace reached a backend service.
	 */
	public function getInstrumentationChecks(int $seconds): array {
		$time_till = time();
		$time_from = $time_till - $seconds;

		$row = $this->fetch(
			'SELECT count() AS spans, countIf('.self::SESSION.'!=\'\') AS with_session,'.
				' countIf('.self::IS_VIEW.') AS views, countIf('.self::IS_ACTION.') AS actions,'.
				' countIf('.self::IS_REQUEST.') AS requests,'.
				' uniqExactIf(TraceId,'.self::IS_REQUEST.') AS request_traces,'.
				' groupUniqArray(10)(ServiceName) AS services'.
			$this->browserWhere(),
			$time_from, $time_till
		)[0] ?? ['spans' => 0, 'with_session' => 0, 'views' => 0, 'actions' => 0, 'requests' => 0,
			'request_traces' => 0, 'services' => []];

		$linked = 0;

		if ((int) $row['requests'] > 0) {
			$linked = (int) ($this->fetch(
				'SELECT uniqExact(TraceId) AS linked'.
				' FROM otel_traces'.
				' WHERE '.$this->period().' AND NOT ('.self::BROWSER.') AND SpanKind=\'Server\''.
					' AND TraceId IN (SELECT TraceId'.$this->browserWhere().' AND '.self::IS_REQUEST.')'.
				self::LOW_MEMORY,
				$time_from, $time_till
			)[0]['linked'] ?? 0);
		}

		return [
			'spans' => (int) $row['spans'],
			'with_session' => (int) $row['with_session'],
			'views' => (int) $row['views'],
			'actions' => (int) $row['actions'],
			'requests' => (int) $row['requests'],
			'request_traces' => (int) $row['request_traces'],
			'linked' => $linked,
			'services' => $row['services']
		];
	}

	private function browserWhere(): string {
		return ' FROM otel_traces WHERE '.$this->period().' AND '.self::BROWSER;
	}

	private static function pageOf(string $url): string {
		return sprintf(self::PAGE_OF, $url);
	}

	private static function eventMs(string $event): string {
		return sprintf(self::EVENT_MS, $event);
	}

	// A request failed: the server failed (HTTP 5xx), or there was no response (span status Error without a status).
	// A 4xx is the server answering (e.g. 401 before signing in): counted apart, see clientError().
	private static function failed(): string {
		return '(toUInt16OrZero('.self::HTTP_STATUS.')>=500'.
			' OR (StatusCode=\'Error\' AND toUInt16OrZero('.self::HTTP_STATUS.')=0))';
	}

	private static function clientError(): string {
		return '(toUInt16OrZero('.self::HTTP_STATUS.') BETWEEN 400 AND 499)';
	}
}

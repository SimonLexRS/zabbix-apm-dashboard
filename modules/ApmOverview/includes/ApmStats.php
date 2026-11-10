<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

use ApmDb,
	ApmDbClickHouse,
	CClickHouseQuery;

/**
 * Aggregated APM statistics read directly from the ClickHouse telemetry data source.
 */
class ApmStats {

	public const SORT_REQUESTS = 'requests';
	public const SORT_ERROR_RATE = 'error_rate';
	public const SORT_P95 = 'p95';

	/**
	 * Number of minutes covered by the per-minute series (sparklines).
	 */
	public const SERIES_MINUTES = 60;

	public const ERROR_MESSAGE_MAX_LENGTH = 500;

	/**
	 * Longest window of the queries that join spans (service map, time breakdown): the last hours of the period.
	 */
	public const JOIN_WINDOW = 6 * SEC_PER_HOUR;

	public const HISTOGRAM_BUCKETS = 14;

	// Connections (source, target, system, host) read for the service map.
	public const CALL_LIMIT = 300;

	/**
	 * Service filter starting with "=": exactly that service name (links from the map and tables).
	 */
	public const EXACT_PREFIX = '=';

	private const SIGNAL_TABLES = [
		'traces' => ['otel_traces' => 'Timestamp'],
		'logs' => ['otel_logs' => 'Timestamp'],
		'metrics' => [
			'otel_metrics_gauge' => 'TimeUnix',
			'otel_metrics_sum' => 'TimeUnix',
			'otel_metrics_histogram' => 'TimeUnix',
			'otel_metrics_exponential_histogram' => 'TimeUnix'
		]
	];

	private const SIGNAL_TYPES = ['traces' => ApmAccess::TRACES, 'logs' => ApmAccess::LOGS, 'metrics' => ApmAccess::METRICS];

	// Database (or queue) system of a client span: new semantic conventions first, then the old ones.
	private const DB_SYSTEM = 'multiIf(SpanAttributes[\'db.system.name\']!=\'\',SpanAttributes[\'db.system.name\'],'.
		'SpanAttributes[\'db.system\']!=\'\',SpanAttributes[\'db.system\'],SpanAttributes[\'messaging.system\'])';

	// Host of a URL. Not domain(): it returns nothing for a name without dots, as the service names inside Docker or
	// Kubernetes are (http://payments:8080/).
	protected const URL_HOST_OPEN = 'extract(';
	protected const URL_HOST_CLOSE = ',\'^[a-zA-Z][a-zA-Z0-9+.-]*://(?:[^/@?#]*@)?([^/:?#]+)\')';

	// Remote host of a client span: what the instrumentation names first, then the Envoy cluster, then the address.
	private const PEER_HOST = 'multiIf(SpanAttributes[\'server.address\']!=\'\',SpanAttributes[\'server.address\'],'.
		'SpanAttributes[\'net.peer.name\']!=\'\',SpanAttributes[\'net.peer.name\'],'.
		'SpanAttributes[\'url.full\']!=\'\','.self::URL_HOST_OPEN.'SpanAttributes[\'url.full\']'.self::URL_HOST_CLOSE.','.
		'SpanAttributes[\'http.url\']!=\'\','.self::URL_HOST_OPEN.'SpanAttributes[\'http.url\']'.self::URL_HOST_CLOSE.','.
		'SpanAttributes[\'http.host\']!=\'\',SpanAttributes[\'http.host\'],'.
		'SpanAttributes[\'upstream_cluster.name\']!=\'\',SpanAttributes[\'upstream_cluster.name\'],'.
		'SpanAttributes[\'upstream_cluster\']!=\'\',SpanAttributes[\'upstream_cluster\'],'.
		'SpanAttributes[\'peer.service\']!=\'\',SpanAttributes[\'peer.service\'],'.
		'SpanAttributes[\'rpc.service\']!=\'\',SpanAttributes[\'rpc.service\'],'.
		'SpanAttributes[\'messaging.destination.name\']!=\'\',SpanAttributes[\'messaging.destination.name\'],'.
		'SpanAttributes[\'network.peer.address\']!=\'\',SpanAttributes[\'network.peer.address\'],'.
		'SpanAttributes[\'net.sock.peer.addr\'])';

	/**
	 * A request received by a service: the first span of a trace, or a span that answers a call or consumes a message.
	 * The figures of a service mean the same whether users call it directly or other services do.
	 */
	public const ENTRY = '(ParentSpanId=\'\' OR SpanKind IN (\'Server\',\'Consumer\'))';

	// Span sent by a browser (OpenTelemetry web SDK).
	public const BROWSER = 'ResourceAttributes[\'telemetry.sdk.language\']=\'webjs\'';

	// A request that came from outside the traced system: it starts a trace in a browser or in a server span.
	// Consumers, timers and other background work that start their own trace are not user traffic.
	private const USER_ENTRY = '(ParentSpanId=\'\' AND (SpanKind=\'Server\' OR '.self::BROWSER.'))';

	/**
	 * Spans this long are connections kept open (streams, long polls), not requests: they are counted, but left out
	 * of the latency figures, where they would hide everything else.
	 */
	public const LONG_SPAN_DEFAULT = 5 * SEC_PER_MIN;

	/**
	 * "Now" for health and for the state of the errors: the last minutes of the period.
	 */
	public const RECENT_DEFAULT = 15 * SEC_PER_MIN;

	// HTTP status: new semantic conventions (Go, Next.js) first, then the old attribute (Python demo).
	protected const HTTP_STATUS = 'if(SpanAttributes[\'http.response.status_code\']!=\'\','.
		'SpanAttributes[\'http.response.status_code\'],SpanAttributes[\'http.status_code\'])';

	// Settings of the heavier queries (joins, span reads): fewer threads use less memory on a small ClickHouse.
	protected const LOW_MEMORY = ' SETTINGS max_threads=2';

	// Period condition, with the {time_from} and {time_till} parameters.
	protected const PERIOD = 'Timestamp>=toDateTime64({time_from:Int32},9) AND Timestamp<toDateTime64({time_till:Int32},9)';

	private const APPLICATION_CONDITION = 'ResourceAttributes[\'service.namespace\']={app:String}';

	protected ApmDbClickHouse $db;

	/**
	 * Application (service.namespace) the statistics are limited to; null = all applications.
	 */
	private ?string $application = null;

	private int $long_span = self::LONG_SPAN_DEFAULT;

	private int $recent = self::RECENT_DEFAULT;

	/**
	 * Names of the results of the last calls that were cut by their row limit (e.g. 'services', 'calls', 'errors').
	 */
	public array $truncated = [];

	/**
	 * @throws \DBException  If APM data source is not configured or not reachable.
	 */
	public function __construct() {
		$this->db = ApmDbClickHouse::getInstance(ApmDb::getInstance()->getConfig());
	}

	public function setApplication(?string $application): self {
		$this->application = $application;

		return $this;
	}

	/**
	 * @param int $seconds  Spans of this duration or longer are left out of the latency figures.
	 */
	public function setLongSpan(int $seconds): self {
		$this->long_span = max(1, $seconds);

		return $this;
	}

	/**
	 * @param int $seconds  Length of the recent window (health now, state of the errors).
	 */
	public function setRecent(int $seconds): self {
		$this->recent = max(SEC_PER_MIN, $seconds);

		return $this;
	}

	public function getRecent(): int {
		return $this->recent;
	}

	/**
	 * Start of the recent window of a period.
	 */
	public function recentFrom(int $time_from, int $time_till): int {
		return max($time_from, $time_till - $this->recent);
	}

	/**
	 * Applications (service.namespace values) with spans in the last $seconds, by name; '' (spans without a namespace)
	 * last, so it is never the default tab.
	 *
	 * @return string[]
	 */
	public function getApplications(int $seconds = 7 * SEC_PER_DAY): array {
		[$access, $params] = self::accessCondition(ApmAccess::TRACES);
		$sql = 'SELECT DISTINCT ResourceAttributes[\'service.namespace\'] AS app FROM otel_traces'.
			' WHERE Timestamp>=now()-{seconds:UInt32}'.($access !== '' ? ' AND '.$access : '').
			' ORDER BY app=\'\', app';

		return array_map(static fn(array $row): string => (string) $row['app'],
			self::rows($this->db, $sql, ['seconds' => ['UInt32', $seconds]] + $params)
		);
	}

	/**
	 * The stored application if it still has data, otherwise the first one; null if there are no applications. Null
	 * as stored means never chosen, unlike '' (the spans without a namespace).
	 */
	public static function resolveApplication(array $applications, ?string $stored): ?string {
		if (in_array($stored, $applications, true)) {
			return $stored;
		}

		return $applications ? reset($applications) : null;
	}

	public function getServerVersion(): string {
		return (string) (self::rows($this->db, 'SELECT version() AS version')[0]['version'] ?? '');
	}

	/**
	 * Names of the services with spans in the last $seconds (the filter's suggestions).
	 *
	 * @return string[]
	 */
	public function getServiceNames(int $seconds = SEC_PER_DAY, int $limit = 200): array {
		$sql = 'SELECT DISTINCT ServiceName AS service FROM otel_traces'.
			' WHERE Timestamp>=now()-{seconds:UInt32}'.$this->applicationCondition().
			' ORDER BY service'.
			' LIMIT '.$limit;

		return array_map(static fn(array $row): string => (string) $row['service'],
			self::rows($this->db, $sql, ['seconds' => ['UInt32', $seconds]] + $this->applicationParams())
		);
	}

	/**
	 * Rows received and last data timestamp per signal type (traces, logs, metrics) within the period.
	 *
	 * @param string[] $signals  The signals to read.
	 *
	 * @return array  [signal => ['rows' => int, 'last' => int|null, 'error' => string|null]]
	 */
	public function getSignalStats(int $time_from, int $time_till, array $signals): array {
		$result = [];

		foreach (array_intersect_key(self::SIGNAL_TABLES, array_flip($signals)) as $signal => $tables) {
			$result[$signal] = ['rows' => 0, 'last' => null, 'error' => null];

			foreach ($tables as $table => $time_column) {
				$query = (new CClickHouseQuery())
					->select('count()', 'rows')
					->select('toUnixTimestamp(max('.$time_column.'))', 'last')
					->from($table)
					->where($time_column.'>=toDateTime64({time_from:Int32},9)', ['time_from' => ['Int32', $time_from]])
					->where($time_column.'<toDateTime64({time_till:Int32},9)', ['time_till' => ['Int32', $time_till]]);

				$this->whereApplication($query, self::SIGNAL_TYPES[$signal]);

				try {
					$row = self::rows($this->db, $query->getSql(), $query->getParams())[0] ?? ['rows' => 0, 'last' => null];
				}
				catch (\DBException $e) {
					$result[$signal]['error'] = $e->getMessage();
					continue;
				}

				$rows = (int) $row['rows'];

				if ($rows > 0) {
					$result[$signal]['rows'] += $rows;
					$result[$signal]['last'] = max((int) $row['last'], $result[$signal]['last'] ?? 0);
				}
			}
		}

		return $result;
	}

	/**
	 * RED statistics (rate, errors, duration) per service, from the requests each service receives (ENTRY).
	 *
	 * @param string $service  Service name pattern, "*" is a wildcard. Empty string matches all services.
	 *
	 * @return array  List of ['service', 'requests', 'errors', 'error_rate', 'rpm', 'p50', 'p95', 'p99', 'browser',
	 *                'user' => ['requests', 'errors'], 'recent' => ['requests', 'errors', 'error_rate', 'p95']],
	 *                durations in nanoseconds. "user": the requests that came from outside (see USER_ENTRY); "recent":
	 *                the last minutes of the period (setRecent()).
	 */
	public function getServiceStats(int $time_from, int $time_till, string $service = '',
			string $sort = self::SORT_REQUESTS, int $limit = 100): array {
		$recent = 'Timestamp>=toDateTime64({recent_from:Int32},9)';
		$latency = $this->latency();

		$sql = 'SELECT ServiceName AS service, count() AS requests, countIf(StatusCode=\'Error\') AS errors,'.
				' quantilesIf(0.5,0.95,0.99)(Duration,'.$latency.') AS q,'.
				' countIf('.self::USER_ENTRY.') AS user_requests,'.
				' countIf('.self::USER_ENTRY.' AND StatusCode=\'Error\') AS user_errors,'.
				' countIf('.$recent.') AS recent_requests,'.
				' countIf('.$recent.' AND StatusCode=\'Error\') AS recent_errors,'.
				' quantileIf(0.95)(Duration,'.$recent.' AND '.$latency.') AS recent_p95,'.
				' max('.self::BROWSER.') AS browser'.
			' FROM otel_traces'.
			' WHERE '.$this->period().' AND '.self::ENTRY.self::serviceCondition($service).
			' GROUP BY service'.
			' ORDER BY '.self::getSortExpression($sort).' DESC, service'.
			' LIMIT '.($limit + 1);

		$minutes = max(1, ($time_till - $time_from) / SEC_PER_MIN);
		$rows = $this->fetch($sql, $time_from, $time_till, $service);
		$this->truncated['services'] = count($rows) > $limit;
		$services = [];

		foreach (array_slice($rows, 0, $limit) as $row) {
			$recent_requests = (int) $row['recent_requests'];
			$recent_errors = (int) $row['recent_errors'];

			$services[] = ['service' => $row['service']] + self::makeRedRow($row, $minutes) + [
				'browser' => (bool) $row['browser'],
				'user' => ['requests' => (int) $row['user_requests'], 'errors' => (int) $row['user_errors']],
				'recent' => [
					'requests' => $recent_requests,
					'errors' => $recent_errors,
					'error_rate' => $recent_requests > 0 ? $recent_errors / $recent_requests * 100 : 0,
					'p95' => self::finite($row['recent_p95'])
				]
			];
		}

		return $services;
	}

	/**
	 * Per-minute RED series per service, from the requests each service receives. To keep the query light, only the last
	 * $minutes complete minutes of the period are used. Minutes without data are filled with zeros.
	 *
	 * @param string $service  Service name pattern, "*" is a wildcard. Empty string matches all services.
	 *
	 * @return array  [service => ['requests' => int[], 'errors' => int[], 'error_rate' => float[], 'p95' => float[]]],
	 *                one value per minute, oldest first, durations in nanoseconds.
	 */
	public function getServiceSeries(int $time_from, int $time_till, string $service = '',
			int $minutes = self::SERIES_MINUTES): array {
		$window = self::getSeriesWindow($time_from, $time_till, $minutes);

		if ($window === null) {
			return [];
		}

		[$series_from, $series_till, $buckets] = $window;

		$query = (new CClickHouseQuery())
			->select('ServiceName', 'service')
			->select('toUnixTimestamp(toStartOfMinute(Timestamp))', 'bucket')
			->select('count()', 'requests')
			->select('countIf(StatusCode=\'Error\')', 'errors')
			->select('quantileIf(0.95)(Duration,'.$this->latency().')', 'p95')
			->from('otel_traces')
			->where('Timestamp>=toDateTime64({time_from:Int32},9)', ['time_from' => ['Int32', $series_from]])
			->where('Timestamp<toDateTime64({time_till:Int32},9)', ['time_till' => ['Int32', $series_till]])
			->where(self::ENTRY)
			->group('ServiceName')
			->group('bucket');

		$this->whereApplication($query);

		if ($service !== '') {
			$query->where('ServiceName LIKE {service:String}', ['service' => ['String', self::toLikePattern($service)]]);
		}

		$empty = array_fill_keys($buckets, 0);
		$series = [];

		foreach (self::rows($this->db, $query->getSql(), $query->getParams()) as $row) {
			$bucket = (int) $row['bucket'];

			if (!array_key_exists($bucket, $empty)) {
				continue;
			}

			$name = $row['service'];
			$series[$name] ??= ['requests' => $empty, 'errors' => $empty, 'error_rate' => $empty, 'p95' => $empty];

			$requests = (int) $row['requests'];
			$errors = (int) $row['errors'];

			$series[$name]['requests'][$bucket] = $requests;
			$series[$name]['errors'][$bucket] = $errors;
			$series[$name]['error_rate'][$bucket] = $requests > 0 ? $errors / $requests * 100 : 0;
			$series[$name]['p95'][$bucket] = self::finite($row['p95']);
		}

		foreach ($series as &$values) {
			$values = array_map('array_values', $values);
		}
		unset($values);

		return $series;
	}

	/**
	 * Business metrics (KPIs) of the period: totals per metric and per value of its attributes, and the per-minute
	 * values of the last $minutes complete minutes. See BusinessMetrics.
	 *
	 * @param string $service  Service name pattern, "*" is a wildcard. Empty string matches all services.
	 *
	 * @return array  BusinessMetrics::groupTotals(), each metric with 'series' (float[], oldest first, or null when the
	 *                period is too short).
	 */
	public function getBusinessMetrics(int $time_from, int $time_till, string $service = '',
			int $minutes = self::SERIES_MINUTES): array {
		$access = $this->applicationCondition(ApmAccess::METRICS);
		$service_condition = $service !== '' ? ' AND ServiceName LIKE {service:String}' : '';
		$params = BusinessMetrics::params();

		$metrics = BusinessMetrics::groupTotals(
			$this->fetch(BusinessMetrics::totalsSql($access, $service_condition), $time_from, $time_till, $service,
				$params
			)
		);

		$window = self::getSeriesWindow($time_from, $time_till, $minutes);
		$series = [];

		if ($metrics && $window !== null) {
			[$series_from, $series_till, $buckets] = $window;
			$series = BusinessMetrics::groupSeries(
				$this->fetch(BusinessMetrics::seriesSql($access, $service_condition), $buckets[0], $series_till,
					$service, $params
				),
				$buckets
			);
		}

		foreach ($metrics as $key => &$metric) {
			$metric['series'] = $window !== null ? ($series[$key] ?? array_fill(0, count($window[2]), 0.0)) : null;
		}
		unset($metric);

		return $metrics;
	}

	/**
	 * RED statistics per endpoint: server spans (SpanKind = 'Server') grouped by service and span name.
	 *
	 * @param string $service        Service name pattern, "*" is a wildcard. Empty string matches all services.
	 * @param string $exact_service  Exact service name. Empty string matches all services.
	 *
	 * @return array  List of ['service', 'endpoint', 'codes', 'requests', 'errors', 'error_rate', 'rpm', 'p50', 'p95',
	 *                'p99'], durations in nanoseconds.
	 */
	public function getEndpointStats(int $time_from, int $time_till, string $service = '',
			string $sort = self::SORT_REQUESTS, string $exact_service = '', int $limit = 100): array {
		$query = (new CClickHouseQuery())
			->select('ServiceName', 'service')
			->select('SpanName', 'endpoint')
			->select('count()', 'requests')
			->select('countIf(StatusCode=\'Error\')', 'errors')
			->select('quantilesIf(0.5,0.95,0.99)(Duration,'.$this->latency().')', 'q')
			->select('arraySort(groupUniqArray(4)('.self::HTTP_STATUS.'))', 'codes')
			->from('otel_traces')
			->where('Timestamp>=toDateTime64({time_from:Int32},9)', ['time_from' => ['Int32', $time_from]])
			->where('Timestamp<toDateTime64({time_till:Int32},9)', ['time_till' => ['Int32', $time_till]])
			->where('SpanKind=\'Server\'')
			->group('ServiceName')
			->group('SpanName');

		$this->whereApplication($query);

		if ($service !== '') {
			$query->where('ServiceName LIKE {service:String}', ['service' => ['String', self::toLikePattern($service)]]);
		}

		if ($exact_service !== '') {
			$query->where('ServiceName={exact_service:String}', ['exact_service' => ['String', $exact_service]]);
		}

		$query
			->order(self::getSortExpression($sort), ZBX_SORT_DOWN)
			->order('ServiceName')
			->order('SpanName')
			->limit($limit);

		$minutes = max(1, ($time_till - $time_from) / SEC_PER_MIN);
		$endpoints = [];

		foreach (self::rows($this->db, $query->getSql(), $query->getParams()) as $row) {
			$endpoints[] = [
				'service' => $row['service'],
				'endpoint' => $row['endpoint'],
				'codes' => self::nonEmpty($row['codes'])
			] + self::makeRedRow($row, $minutes);
		}

		return $endpoints;
	}

	/**
	 * Failures grouped into issues: per service, operation, kind of exception and message, with the changing parts of
	 * the message (numbers, ids) replaced by "?". A failed trace counts once, at the deepest span that failed, which is
	 * where the error started; the spans above it only passed it on.
	 *
	 * Each group has a state, worked out from when it happened (errorState()), reading one more period before the
	 * selected one: nothing is stored, a group is resolved when it stops happening.
	 *
	 * @param string $service  Service name pattern, "*" is a wildcard. Empty string matches all services.
	 * @param bool   $browser  True: only errors of the browser; false: all.
	 *
	 * @return array  List of ['service', 'endpoint', 'type', 'message', 'count', 'first', 'last', 'state', 'recent',
	 *                'codes', 'traceid', 'sessions', 'page', 'browser', 'series' => int[]], active groups first, then
	 *                the most recent. "first" is within the two periods read.
	 */
	public function getErrorGroups(int $time_from, int $time_till, string $service = '', int $limit = 50,
			bool $browser = false): array {
		$length = max(1, $time_till - $time_from);
		$read_from = $time_from - $length;
		$exception = 'Events.Attributes[indexOf(Events.Name,\'exception\')]';
		$message = 'multiIf(StatusMessage!=\'\',StatusMessage,'.$exception.'[\'exception.message\']!=\'\','.
			$exception.'[\'exception.message\'],SpanAttributes[\'exception.message\'])';

		$sql = 'SELECT service, endpoint, type, message, count() AS count,'.
				' toUnixTimestamp(min(ts)) AS first, toUnixTimestamp(max(ts)) AS last,'.
				' countIf(ts>=toDateTime64({period_start:Int32},9)) AS period_count,'.
				' countIf(ts>=toDateTime64({recent_from:Int32},9)) AS recent,'.
				' toUnixTimestamp(minIf(ts,ts>=toDateTime64({recent_from:Int32},9))) AS recent_first,'.
				' toUnixTimestamp(maxIf(ts,ts<toDateTime64({recent_from:Int32},9))) AS before_last,'.
				' arraySort(groupUniqArrayIf(5)(code,code!=\'\')) AS codes, argMax(traceid,ts) AS traceid,'.
				' uniqExactIf(b.session,b.session!=\'\') AS sessions, anyIf(b.page,b.page!=\'\') AS page,'.
				' max(is_browser) AS browser,'.
				' sumMapIf([toUInt16(intDiv((toUnixTimestamp(ts)-{period_start:Int32})*'.self::ERROR_SERIES.','.$length.'))],'.
					'[toUInt64(1)],ts>=toDateTime64({period_start:Int32},9)) AS series'.
			' FROM ('.
				'SELECT ServiceName AS service, SpanName AS endpoint, Timestamp AS ts, TraceId AS traceid,'.
					' '.self::HTTP_STATUS.' AS code, '.self::BROWSER.' AS is_browser,'.
					' if('.$exception.'[\'exception.type\']!=\'\','.$exception.'[\'exception.type\'],'.
						'SpanAttributes[\'exception.type\']) AS type,'.
					' substringUTF8(replaceRegexpAll('.$message.',{volatile:String},\'?\'),1,'.
						self::ERROR_MESSAGE_MAX_LENGTH.') AS message'.
				' FROM otel_traces'.
				' WHERE '.$this->period().' AND StatusCode=\'Error\''.self::serviceCondition($service).
					($browser ? ' AND '.self::BROWSER : '').
					' AND (TraceId,SpanId) NOT IN ('.
						'SELECT TraceId, ParentSpanId FROM otel_traces'.
						' WHERE '.$this->period().' AND StatusCode=\'Error\' AND ParentSpanId!=\'\''.
					')'.
			') AS e'.
			' LEFT JOIN ('.
				'SELECT TraceId, any(SpanAttributes[\'session.id\']) AS session,'.
					' any(path(SpanAttributes[\'url.full\'])) AS page'.
				' FROM otel_traces'.
				' WHERE '.$this->period().' AND '.self::BROWSER.' AND SpanAttributes[\'session.id\']!=\'\''.
				' GROUP BY TraceId'.
			') AS b ON b.TraceId=e.traceid'.
			' GROUP BY service, endpoint, type, message'.
			' HAVING period_count>0'.
			' ORDER BY recent>0 DESC, last DESC, count DESC'.
			' LIMIT '.($limit + 1).
			self::LOW_MEMORY;

		$rows = $this->fetch($sql, $read_from, $time_till, $service, [
			'period_start' => ['Int32', $time_from],
			'recent_from' => ['Int32', $this->recentFrom($time_from, $time_till)],
			'volatile' => ['String', self::VOLATILE_TEXT]
		]);
		$this->truncated['errors'] = count($rows) > $limit;
		$groups = [];

		foreach (array_slice($rows, 0, $limit) as $row) {
			$series = array_fill(0, self::ERROR_SERIES, 0);

			foreach (array_map(null, $row['series'][0] ?? [], $row['series'][1] ?? []) as [$bucket, $count]) {
				$series[min(self::ERROR_SERIES - 1, (int) $bucket)] += (int) $count;
			}

			$recent = (int) $row['recent'];

			$groups[] = [
				'service' => (string) $row['service'],
				'endpoint' => (string) $row['endpoint'],
				'type' => (string) $row['type'],
				'message' => (string) $row['message'],
				'count' => (int) $row['period_count'],
				'first' => (int) $row['first'],
				'last' => (int) $row['last'],
				'recent' => $recent,
				'state' => self::errorState($recent, (int) $row['recent_first'], (int) $row['before_last'], $this->recent),
				'codes' => self::nonEmpty($row['codes']),
				'traceid' => (string) $row['traceid'],
				'sessions' => (int) $row['sessions'],
				'page' => (string) $row['page'],
				'browser' => (bool) $row['browser'],
				'series' => $series
			];
		}

		return $groups;
	}

	public const STATE_NEW = 'new';
	public const STATE_ACTIVE = 'active';
	public const STATE_REGRESSED = 'regressed';
	public const STATE_RESOLVED = 'resolved';

	// Buckets of the trend of an error group over the period.
	public const ERROR_SERIES = 30;

	// The parts of an error message that change from one occurrence to the next: UUIDs, hexadecimal ids, numbers.
	public const VOLATILE_TEXT = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'.
		'|0x[0-9a-fA-F]+|\\b[0-9a-fA-F]{16,}\\b|\\d+';

	/**
	 * State of an error group from when it happened.
	 *
	 * @param int $recent        Occurrences in the recent window.
	 * @param int $recent_first  First occurrence in the recent window (0 = none).
	 * @param int $before_last   Last occurrence before the recent window (0 = none in what was read).
	 * @param int $window        Length of the recent window, seconds.
	 *
	 * @return string  resolved: it stopped happening; new: it was never seen before the recent window; regressed: it
	 *                 had stopped for longer than the window and came back; active: it keeps happening.
	 */
	public static function errorState(int $recent, int $recent_first, int $before_last, int $window): string {
		if ($recent <= 0) {
			return self::STATE_RESOLVED;
		}

		if ($before_last <= 0) {
			return self::STATE_NEW;
		}

		return $recent_first - $before_last > $window ? self::STATE_REGRESSED : self::STATE_ACTIVE;
	}

	/**
	 * An error message with its changing parts replaced by "?", as the queries group it.
	 */
	public static function normalizeMessage(string $message): string {
		return mb_substr((string) preg_replace('/'.self::VOLATILE_TEXT.'/', '?', $message), 0,
			self::ERROR_MESSAGE_MAX_LENGTH
		);
	}

	/**
	 * Calls between services and to their dependencies (databases, queues, external hosts), from client spans. A
	 * client span whose child is a server span of another service is a call to that service; otherwise it is a call to
	 * the database system or remote host it names. Measured on the caller's side.
	 *
	 * @return array  List of ['source', 'target', 'db_system', 'host', 'calls', 'errors', 'p95', 'recent' => ['calls',
	 *                'errors', 'p95']], target is the called service ('' for dependencies), p95 in nanoseconds.
	 */
	public function getServiceCalls(int $time_from, int $time_till, string $service = ''): array {
		$sql = 'SELECT p.ServiceName AS source, c.ServiceName AS target, p.db_system AS db_system, p.host AS host,'.
				' count() AS calls, countIf(p.StatusCode=\'Error\') AS errors,'.
				' quantileIf(0.95)(p.Duration,p.Duration<'.$this->longSpanNs().') AS p95,'.
				' countIf(p.recent) AS recent_calls, countIf(p.recent AND p.StatusCode=\'Error\') AS recent_errors,'.
				' quantileIf(0.95)(p.Duration,p.recent AND p.Duration<'.$this->longSpanNs().') AS recent_p95'.
			' FROM ('.
				'SELECT TraceId, SpanId, ServiceName, StatusCode, Duration,'.
					' Timestamp>=toDateTime64({recent_from:Int32},9) AS recent, '.self::DB_SYSTEM.' AS db_system,'.
					' '.self::PEER_HOST.' AS host'.
				' FROM otel_traces'.
				' WHERE '.$this->period().' AND SpanKind IN (\'Client\',\'Producer\')'.self::serviceCondition($service).
			') AS p'.
			' LEFT JOIN ('.
				'SELECT TraceId, ParentSpanId, ServiceName'.
				' FROM otel_traces'.
				' WHERE '.$this->period().' AND SpanKind IN (\'Server\',\'Consumer\') AND ParentSpanId!=\'\''.
			') AS c ON c.TraceId=p.TraceId AND c.ParentSpanId=p.SpanId'.
			' GROUP BY source, target, db_system, host'.
			' ORDER BY calls DESC'.
			' LIMIT '.(self::CALL_LIMIT + 1).
			self::LOW_MEMORY;

		// The recent window belongs to the whole period, not to the part of it that the join reads.
		$recent_from = $this->recentFrom($time_from, $time_till);
		[$time_from, $time_till] = self::joinWindow($time_from, $time_till);
		$rows = $this->fetch($sql, $time_from, $time_till, $service, ['recent_from' => ['Int32', $recent_from]]);
		$this->truncated['calls'] = count($rows) > self::CALL_LIMIT;
		$calls = [];

		foreach (array_slice($rows, 0, self::CALL_LIMIT) as $row) {
			$calls[] = [
				'source' => (string) $row['source'],
				'target' => (string) $row['target'],
				'db_system' => (string) $row['db_system'],
				'host' => (string) $row['host'],
				'calls' => (int) $row['calls'],
				'errors' => (int) $row['errors'],
				'p95' => self::finite($row['p95']),
				'recent' => [
					'calls' => (int) $row['recent_calls'],
					'errors' => (int) $row['recent_errors'],
					'p95' => self::finite($row['recent_p95'])
				]
			];
		}

		return $calls;
	}

	/**
	 * Where the time of each kind of request goes: per received request, the average total duration split into the
	 * service's own work, its database calls and its calls to other services or hosts (client spans of the same
	 * service in the same trace). Endpoints that consume most time (requests × average) first.
	 *
	 * @return array  List of ['service', 'endpoint', 'requests', 'total', 'db', 'calls', 'own', 'p95'], nanoseconds.
	 */
	public function getTimeBreakdown(int $time_from, int $time_till, string $service = '', int $limit = 15): array {
		// A service can receive several requests within one trace: its client time is shared between them (n).
		$sql = 'SELECT r.ServiceName AS service, r.SpanName AS endpoint, count() AS requests, avg(r.Duration) AS total,'.
				' avg(k.db/r.n) AS db, avg(k.calls/r.n) AS calls, quantile(0.95)(r.Duration) AS p95'.
			' FROM ('.
				'SELECT TraceId, ServiceName, SpanName, Duration,'.
					' count() OVER (PARTITION BY TraceId, ServiceName) AS n'.
				' FROM otel_traces'.
				' WHERE '.$this->period().' AND '.self::ENTRY.' AND '.$this->latency().self::serviceCondition($service).
			') AS r'.
			' LEFT JOIN ('.
				'SELECT TraceId, ServiceName, sumIf(Duration,db_system!=\'\') AS db,'.
					' sumIf(Duration,db_system=\'\') AS calls'.
				' FROM ('.
					'SELECT TraceId, ServiceName, Duration, '.self::DB_SYSTEM.' AS db_system'.
					' FROM otel_traces'.
					' WHERE '.$this->period().' AND SpanKind IN (\'Client\',\'Producer\')'.self::serviceCondition($service).
				')'.
				' GROUP BY TraceId, ServiceName'.
			') AS k ON k.TraceId=r.TraceId AND k.ServiceName=r.ServiceName'.
			' GROUP BY service, endpoint'.
			' ORDER BY requests*total DESC'.
			' LIMIT '.$limit.
			self::LOW_MEMORY;

		[$time_from, $time_till] = self::joinWindow($time_from, $time_till);
		$rows = [];

		foreach ($this->fetch($sql, $time_from, $time_till, $service) as $row) {
			$total = (float) $row['total'];
			$db = (float) $row['db'];
			$calls = (float) $row['calls'];

			// Parallel calls can add up to more than the request itself: scale them down to fit.
			if ($db + $calls > $total && $db + $calls > 0) {
				$scale = $total / ($db + $calls);
				$db *= $scale;
				$calls *= $scale;
			}

			$rows[] = [
				'service' => (string) $row['service'],
				'endpoint' => (string) $row['endpoint'],
				'requests' => (int) $row['requests'],
				'total' => $total,
				'db' => $db,
				'calls' => $calls,
				'own' => max(0, $total - $db - $calls),
				'p95' => (float) $row['p95']
			];
		}

		return $rows;
	}

	/**
	 * Slowest requests of the period with their spans, for the waterfall: whole traces (root spans), or, when a service
	 * is filtered, the requests that service received with what happened below them.
	 *
	 * @return array  List of ['traceid', 'service', 'endpoint', 'clock', 'duration', 'error', 'span_count', 'spans'],
	 *                each span ['id', 'parent', 'service', 'name', 'kind', 'db_system', 'start', 'duration', 'error',
	 *                'browser'], start relative to the request start, nanoseconds.
	 */
	public function getSlowTraces(int $time_from, int $time_till, string $service = '', int $limit = 8,
			int $span_limit = 40): array {
		$sql = 'SELECT TraceId AS traceid, SpanId AS spanid, ServiceName AS service, SpanName AS endpoint,'.
				' toUnixTimestamp(Timestamp) AS clock, toUnixTimestamp64Nano(Timestamp) AS start,'.
				' Duration AS duration, StatusCode=\'Error\' AS error'.
			' FROM otel_traces'.
			' WHERE '.$this->period().' AND '.($service !== '' ? self::ENTRY : 'ParentSpanId=\'\'').
				' AND '.$this->latency().self::serviceCondition($service).
			' ORDER BY Duration DESC'.
			' LIMIT 1 BY TraceId'.
			' LIMIT '.$limit.
			self::LOW_MEMORY;

		$traces = [];

		foreach ($this->fetch($sql, $time_from, $time_till, $service) as $row) {
			$traces[$row['traceid']] = [
				'traceid' => (string) $row['traceid'],
				'spanid' => (string) $row['spanid'],
				'service' => (string) $row['service'],
				'endpoint' => (string) $row['endpoint'],
				'clock' => (int) $row['clock'],
				'start' => (int) $row['start'],
				'duration' => (float) $row['duration'],
				'error' => (bool) $row['error'],
				'span_count' => 0,
				'spans' => []
			];
		}

		if (!$traces) {
			return [];
		}

		foreach ($this->getTraceSpans(array_keys($traces), $time_from, $time_till, self::TRACE_SPAN_READ) as $id => $spans) {
			$trace = &$traces[$id];
			$spans = self::subtree($spans, $trace['spanid']);
			$trace['span_count'] = count($spans);

			foreach (array_slice($spans, 0, $span_limit) as $span) {
				$span['start'] = max(0, $span['start'] - $trace['start']);
				$trace['spans'][] = $span;
			}

			unset($trace);
		}

		return array_values($traces);
	}

	// Spans read per trace for the waterfalls and the trace panel.
	public const TRACE_SPAN_READ = 400;

	/**
	 * Spans of some traces, oldest first. Spans of every application of the trace, except those the user may not see,
	 * as in the native trace view. Child spans can end a little after the period: one more minute on each side.
	 *
	 * @return array  [traceid => [['id', 'parent', 'service', 'name', 'kind', 'db_system', 'start', 'duration',
	 *                'error', 'browser'], ...]], start in nanoseconds since the epoch.
	 */
	public function getTraceSpans(array $traceids, int $time_from, int $time_till, int $span_limit): array {
		[$access, $access_params] = self::accessCondition(ApmAccess::TRACES);
		$sql = 'SELECT TraceId AS traceid, SpanId AS id, ParentSpanId AS parent, ServiceName AS service,'.
				' SpanName AS name, SpanKind AS kind, '.self::DB_SYSTEM.' AS db_system,'.
				' toUnixTimestamp64Nano(Timestamp) AS start, Duration AS duration, StatusCode=\'Error\' AS error,'.
				' '.self::BROWSER.' AS browser'.
			' FROM otel_traces'.
			' WHERE '.self::PERIOD.($access !== '' ? ' AND '.$access : '').' AND TraceId IN {traceids:Array(String)}'.
			' ORDER BY TraceId, Timestamp'.
			' LIMIT '.$span_limit.' BY TraceId'.
			self::LOW_MEMORY;

		$params = [
			'time_from' => ['Int32', $time_from - SEC_PER_MIN],
			'time_till' => ['Int32', $time_till + SEC_PER_MIN],
			'traceids' => ['String', array_values($traceids)]
		] + $access_params;

		$traces = [];

		foreach (self::rows($this->db, $sql, $params) as $row) {
			$traces[$row['traceid']][] = [
				'id' => (string) $row['id'],
				'parent' => (string) $row['parent'],
				'service' => (string) $row['service'],
				'name' => (string) $row['name'],
				'kind' => (string) $row['kind'],
				'db_system' => (string) $row['db_system'],
				'start' => (int) $row['start'],
				'duration' => (float) $row['duration'],
				'error' => (bool) $row['error'],
				'browser' => (bool) $row['browser']
			];
		}

		return $traces;
	}

	/**
	 * A span and everything below it, in the given order. All the spans if the span is not among them.
	 */
	public static function subtree(array $spans, string $root): array {
		$ids = array_column($spans, 'id');

		if (!in_array($root, $ids, true)) {
			return $spans;
		}

		$keep = [$root => true];

		// Spans are ordered by start, so a parent comes before its children, except for clock skew: repeat until stable.
		do {
			$added = false;

			foreach ($spans as $span) {
				if (!array_key_exists($span['id'], $keep) && array_key_exists($span['parent'], $keep)) {
					$keep[$span['id']] = true;
					$added = true;
				}
			}
		}
		while ($added);

		return array_values(array_filter($spans, static fn(array $span): bool => array_key_exists($span['id'], $keep)));
	}

	/**
	 * Database statements by total time: client spans with a database system, grouped by the query summary or the
	 * normalized query text (literals replaced by "?"), else the span name.
	 *
	 * @return array  List of ['service', 'db_system', 'statement', 'calls', 'total', 'p95', 'errors'], nanoseconds.
	 */
	public function getDbStatements(int $time_from, int $time_till, string $service = '', int $limit = 15): array {
		$sql = 'SELECT ServiceName AS service, '.self::DB_SYSTEM.' AS db_system,'.
				' multiIf(SpanAttributes[\'db.query.summary\']!=\'\',SpanAttributes[\'db.query.summary\'],'.
					'SpanAttributes[\'db.query.text\']!=\'\','.
						'substringUTF8(normalizeQuery(SpanAttributes[\'db.query.text\']),1,300),'.
					'SpanAttributes[\'db.statement\']!=\'\','.
						'substringUTF8(normalizeQuery(SpanAttributes[\'db.statement\']),1,300),'.
					'SpanName) AS statement,'.
				' count() AS calls, sum(Duration) AS total, quantile(0.95)(Duration) AS p95,'.
				' countIf(StatusCode=\'Error\') AS errors'.
			' FROM otel_traces'.
			' WHERE '.$this->period().' AND SpanKind=\'Client\' AND db_system!=\'\''.self::serviceCondition($service).
			' GROUP BY service, db_system, statement'.
			' ORDER BY total DESC'.
			' LIMIT '.$limit.
			self::LOW_MEMORY;

		$rows = [];

		foreach ($this->fetch($sql, $time_from, $time_till, $service) as $row) {
			$rows[] = [
				'service' => (string) $row['service'],
				'db_system' => (string) $row['db_system'],
				'statement' => (string) $row['statement'],
				'calls' => (int) $row['calls'],
				'total' => (float) $row['total'],
				'p95' => (float) $row['p95'],
				'errors' => (int) $row['errors']
			];
		}

		return $rows;
	}

	/**
	 * Endpoints (server spans) whose p95 grew the most against the previous period of the same length. Only endpoints
	 * with at least $min_requests in both periods.
	 *
	 * @return array  List of ['service', 'endpoint', 'requests', 'previous_requests', 'p95', 'previous_p95'], ns.
	 */
	public function getRegressions(int $time_from, int $time_till, string $service = '', int $limit = 15,
			int $min_requests = 5): array {
		$current = 'Timestamp>=toDateTime64({period_start:Int32},9)';

		$sql = 'SELECT ServiceName AS service, SpanName AS endpoint,'.
				' countIf('.$current.') AS requests, countIf(NOT '.$current.') AS previous_requests,'.
				' quantileIf(0.95)(Duration,'.$current.' AND '.$this->latency().') AS p95,'.
				' quantileIf(0.95)(Duration,NOT '.$current.' AND '.$this->latency().') AS previous_p95'.
			' FROM otel_traces'.
			' WHERE '.$this->period().' AND SpanKind=\'Server\''.self::serviceCondition($service).
			' GROUP BY service, endpoint'.
			' HAVING requests>='.$min_requests.' AND previous_requests>='.$min_requests.' AND previous_p95>0'.
			' ORDER BY p95/previous_p95 DESC'.
			' LIMIT '.$limit;

		$rows = [];

		$params = ['period_start' => ['Int32', $time_from]];

		foreach ($this->fetch($sql, 2 * $time_from - $time_till, $time_till, $service, $params) as $row) {
			$rows[] = [
				'service' => (string) $row['service'],
				'endpoint' => (string) $row['endpoint'],
				'requests' => (int) $row['requests'],
				'previous_requests' => (int) $row['previous_requests'],
				'p95' => self::finite($row['p95']),
				'previous_p95' => self::finite($row['previous_p95'])
			];
		}

		return $rows;
	}

	/**
	 * Apdex per service (received requests) and the endpoints (server spans) with the lowest score. Satisfied: duration up to
	 * T, tolerating: up to 4T, frustrated: slower or failed.
	 *
	 * @param int $threshold  T in nanoseconds.
	 *
	 * @return array  ['services' => [...], 'endpoints' => [...]], rows ['service', 'endpoint', 'requests',
	 *                'satisfied', 'tolerating', 'apdex'] (endpoint is '' for services).
	 */
	public function getApdex(int $time_from, int $time_till, string $service, int $threshold, int $limit = 10): array {
		$counts = 'count() AS requests,'.
			' countIf(StatusCode!=\'Error\' AND Duration<={t:UInt64}) AS satisfied,'.
			' countIf(StatusCode!=\'Error\' AND Duration>{t:UInt64} AND Duration<=4*{t:UInt64}) AS tolerating';

		$queries = [
			'services' => 'SELECT ServiceName AS service, \'\' AS endpoint, '.$counts.
				' FROM otel_traces'.
				' WHERE '.$this->period().' AND '.self::ENTRY.' AND '.$this->latency().self::serviceCondition($service).
				' GROUP BY service'.
				' ORDER BY requests DESC'.
				' LIMIT 50',
			'endpoints' => 'SELECT ServiceName AS service, SpanName AS endpoint, '.$counts.
				' FROM otel_traces'.
				' WHERE '.$this->period().' AND SpanKind=\'Server\' AND '.$this->latency().self::serviceCondition($service).
				' GROUP BY service, endpoint'.
				' HAVING requests>=5'.
				' ORDER BY (satisfied+tolerating/2)/requests, requests DESC'.
				' LIMIT '.$limit
		];

		$result = [];

		foreach ($queries as $key => $sql) {
			$result[$key] = [];

			foreach ($this->fetch($sql, $time_from, $time_till, $service, ['t' => ['UInt64', $threshold]]) as $row) {
				$requests = (int) $row['requests'];
				$satisfied = (int) $row['satisfied'];
				$tolerating = (int) $row['tolerating'];

				$result[$key][] = [
					'service' => (string) $row['service'],
					'endpoint' => (string) $row['endpoint'],
					'requests' => $requests,
					'satisfied' => $satisfied,
					'tolerating' => $tolerating,
					'apdex' => $requests > 0 ? ($satisfied + $tolerating / 2) / $requests : 0
				];
			}
		}

		return $result;
	}

	/**
	 * Latency distribution of the requests received by the busiest services, in power-of-two millisecond buckets: bucket 0 is
	 * up to 1 ms, bucket k is (2^(k-1), 2^k] ms, the last bucket is everything slower.
	 *
	 * @return array  [service => int[HISTOGRAM_BUCKETS]], busiest service first.
	 */
	public function getLatencyHistogram(int $time_from, int $time_till, string $service = '', int $limit = 4): array {
		$sql = 'SELECT ServiceName AS service,'.
				' toUInt8(least('.(self::HISTOGRAM_BUCKETS - 1).',greatest(0,ceil(log2(greatest(Duration,1)/1000000)))))'.
					' AS bucket,'.
				' count() AS requests'.
			' FROM otel_traces'.
			' WHERE '.$this->period().' AND '.self::ENTRY.' AND '.$this->latency().self::serviceCondition($service).
			' GROUP BY service, bucket';

		$histograms = [];

		foreach ($this->fetch($sql, $time_from, $time_till, $service) as $row) {
			$histograms[$row['service']] ??= array_fill(0, self::HISTOGRAM_BUCKETS, 0);
			$histograms[$row['service']][(int) $row['bucket']] = (int) $row['requests'];
		}

		uasort($histograms, static fn(array $a, array $b): int => array_sum($b) <=> array_sum($a));

		return array_slice($histograms, 0, $limit, true);
	}

	/**
	 * Core Web Vitals measured in real browsers (log records with a rum.metric attribute, sent by the application's
	 * RUM endpoint). 75th percentile, as Google evaluates them: overall, per page, per device and per hour.
	 *
	 * @return array  ['overall' => [metric => row], 'pages' => [page => [metric => row]],
	 *                 'devices' => [device => [metric => row]], 'hourly' => [metric => float[]]],
	 *                row ['samples', 'p75', 'poor'], values in ms (CLS unitless).
	 */
	public function getWebVitals(int $time_from, int $time_till, string $service = ''): array {
		// CLS and INP are reported again each time the page is hidden: one value per page load (rum.id), the last one,
		// which is also the largest.
		$value = 'toFloat64OrZero(LogAttributes[\'rum.value\'])';
		$from_where = ' FROM ('.
				'SELECT LogAttributes[\'rum.metric\'] AS metric, any(LogAttributes[\'rum.page\']) AS page,'.
					' any(LogAttributes[\'rum.device\']) AS device, max('.$value.') AS value,'.
					' argMax(LogAttributes[\'rum.rating\'],'.$value.') AS rating, min(Timestamp) AS ts'.
				' FROM otel_logs'.
				' WHERE '.$this->period(ApmAccess::LOGS).' AND LogAttributes[\'rum.metric\']!=\'\''.
					self::serviceCondition($service).
				' GROUP BY metric, if(LogAttributes[\'rum.id\']!=\'\',LogAttributes[\'rum.id\'],'.
					'toString(cityHash64(Timestamp,LogAttributes[\'rum.value\'])))'.
			')';

		$result = ['overall' => [], 'pages' => [], 'devices' => [], 'hourly' => []];

		foreach (['overall' => '\'\'', 'pages' => 'page', 'devices' => 'device'] as $key => $group) {
			$sql = 'SELECT '.$group.' AS grp, metric, count() AS samples, quantile(0.75)(value) AS p75,'.
					' countIf(rating=\'poor\') AS poor'.
				$from_where.
				' GROUP BY grp, metric'.
				' ORDER BY samples DESC'.
				' LIMIT 500';

			foreach ($this->fetch($sql, $time_from, $time_till, $service) as $row) {
				$values = ['samples' => (int) $row['samples'], 'p75' => (float) $row['p75'], 'poor' => (int) $row['poor']];

				if ($key === 'overall') {
					$result['overall'][$row['metric']] = $values;
				}
				else {
					$result[$key][$row['grp']][$row['metric']] = $values;
				}
			}
		}

		$sql = 'SELECT metric, toUnixTimestamp(toStartOfHour(ts)) AS hour, quantile(0.75)(value) AS p75'.
			$from_where.
			' GROUP BY metric, hour'.
			' ORDER BY hour';

		foreach ($this->fetch($sql, $time_from, $time_till, $service) as $row) {
			$result['hourly'][$row['metric']][] = (float) $row['p75'];
		}

		return $result;
	}

	/**
	 * Convert a pattern with "*" wildcards into a LIKE pattern. Without wildcards a substring match is used; with the
	 * EXACT_PREFIX, an exact match.
	 */
	public static function toLikePattern(string $pattern): string {
		if (str_starts_with($pattern, self::EXACT_PREFIX)) {
			return addcslashes(substr($pattern, strlen(self::EXACT_PREFIX)), '\\%_');
		}

		$pattern = addcslashes($pattern, '\\%_');

		return str_contains($pattern, '*') ? str_replace('*', '%', $pattern) : '%'.$pattern.'%';
	}

	/**
	 * Format nanoseconds as a human readable duration.
	 */
	public static function formatDuration(float $ns): string {
		return convertUnits(['value' => $ns / 1000000000, 'units' => 's']);
	}

	/**
	 * The last JOIN_WINDOW seconds of the period.
	 *
	 * @return array  [time_from, time_till]
	 */
	public static function joinWindow(int $time_from, int $time_till): array {
		return [max($time_from, $time_till - self::JOIN_WINDOW), $time_till];
	}

	/**
	 * Run a query with the {time_from}, {time_till} and, when filtered, {service} parameters.
	 */
	protected function fetch(string $sql, int $time_from, int $time_till, string $service = '',
			array $params = []): array {
		$params += [
			'time_from' => ['Int32', $time_from],
			'time_till' => ['Int32', $time_till],
			'recent_from' => ['Int32', $this->recentFrom($time_from, $time_till)]
		];

		if ($service !== '') {
			$params['service'] = ['String', self::toLikePattern($service)];
		}

		return self::rows($this->db, $sql, $params + $this->applicationParams());
	}

	/**
	 * All the rows of a query, read inside a window that hides one notice of the Zabbix frontend itself: its ClickHouse
	 * client (ApmDbClickHouse) calls curl_close(), deprecated since PHP 8.5 and without effect since PHP 8.0. Only that
	 * E_DEPRECATED is dropped; any other error goes to the Zabbix handler as usual. The rows are read completely here
	 * because the client closes the connection in the finally block of its generator.
	 */
	protected static function rows(ApmDbClickHouse $db, string $sql, array $params = []): array {
		$previous = set_error_handler(
			static function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use (&$previous): bool {
				if ($errno === E_DEPRECATED && str_ends_with($errfile, 'ApmDbClickHouse.php')) {
					return true;
				}

				return $previous !== null ? (bool) $previous($errno, $errstr, $errfile, $errline) : false;
			}
		);

		try {
			return iterator_to_array($db->fetch($sql, $params), false);
		}
		finally {
			restore_error_handler();
		}
	}

	/**
	 * Period condition of the hand-written queries, limited to what the user may see and to the application when one
	 * is set.
	 */
	protected function period(int $signal = ApmAccess::TRACES): string {
		return self::PERIOD.$this->applicationCondition($signal);
	}

	protected function applicationCondition(int $signal = ApmAccess::TRACES): string {
		$access = self::accessCondition($signal)[0];

		return ($access !== '' ? ' AND '.$access : '')
			.($this->application !== null ? ' AND '.self::APPLICATION_CONDITION : '');
	}

	/**
	 * Parameters of applicationCondition() for every signal, so that one set serves any query.
	 */
	protected function applicationParams(): array {
		$params = $this->application !== null ? ['app' => ['String', $this->application]] : [];

		foreach ([ApmAccess::TRACES, ApmAccess::METRICS, ApmAccess::LOGS] as $signal) {
			$params += self::accessCondition($signal)[1];
		}

		return $params;
	}

	protected function whereApplication(CClickHouseQuery $query, int $signal = ApmAccess::TRACES): void {
		[$access, $params] = self::accessCondition($signal);

		if ($access !== '') {
			$query->where($access, $params);
		}

		if ($this->application !== null) {
			$query->where(self::APPLICATION_CONDITION, ['app' => ['String', $this->application]]);
		}
	}

	protected static function accessCondition(int $signal): array {
		return ApmAccess::condition(ApmAccess::filters($signal), 'access'.$signal);
	}

	private static function serviceCondition(string $service): string {
		return $service !== '' ? ' AND ServiceName LIKE {service:String}' : '';
	}

	/**
	 * Condition of the spans that count for latency (see LONG_SPAN_DEFAULT).
	 */
	protected function latency(): string {
		return 'Duration<'.$this->longSpanNs();
	}

	protected function longSpanNs(): string {
		return $this->long_span.'000000000';
	}

	/**
	 * A quantile over no rows is NaN, which ClickHouse writes as null.
	 */
	protected static function finite($value): float {
		return is_numeric($value) && is_finite((float) $value) ? (float) $value : 0.0;
	}

	private static function nonEmpty(array $values): array {
		return array_values(array_filter(array_map('strval', $values), 'strlen'));
	}

	/**
	 * Time window of the per-minute series: the last $minutes complete minutes of the period.
	 *
	 * @return array|null  [time_from, time_till, minute timestamps] or null if the period is too short.
	 */
	protected static function getSeriesWindow(int $time_from, int $time_till, int $minutes): ?array {
		$series_till = intdiv($time_till, SEC_PER_MIN) * SEC_PER_MIN;
		$series_from = max($time_from, $series_till - $minutes * SEC_PER_MIN);
		$first_bucket = intdiv($series_from, SEC_PER_MIN) * SEC_PER_MIN;

		if ($first_bucket > $series_till - SEC_PER_MIN) {
			return null;
		}

		return [$series_from, $series_till, range($first_bucket, $series_till - SEC_PER_MIN, SEC_PER_MIN)];
	}

	private static function getSortExpression(string $sort): string {
		return match ($sort) {
			self::SORT_ERROR_RATE => 'errors/requests',
			self::SORT_P95 => 'ifNotFinite(q[2],0)',
			default => 'requests'
		};
	}

	/**
	 * @param array $row  Row with "requests", "errors" and "q" (quantiles 0.5, 0.95, 0.99 of Duration).
	 */
	private static function makeRedRow(array $row, float $minutes): array {
		$requests = (int) $row['requests'];
		$errors = (int) $row['errors'];

		return [
			'requests' => $requests,
			'errors' => $errors,
			'error_rate' => $requests > 0 ? $errors / $requests * 100 : 0,
			'rpm' => $requests / $minutes,
			'p50' => self::finite($row['q'][0] ?? null),
			'p95' => self::finite($row['q'][1] ?? null),
			'p99' => self::finite($row['q'][2] ?? null)
		];
	}
}

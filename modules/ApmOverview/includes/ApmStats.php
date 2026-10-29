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

	// Remote host of a client span.
	private const PEER_HOST = 'multiIf(SpanAttributes[\'server.address\']!=\'\',SpanAttributes[\'server.address\'],'.
		'SpanAttributes[\'net.peer.name\']!=\'\',SpanAttributes[\'net.peer.name\'],'.
		'SpanAttributes[\'url.full\']!=\'\',domain(SpanAttributes[\'url.full\']),domain(SpanAttributes[\'http.url\']))';

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
	 * RED statistics (rate, errors, duration) per service, calculated from root spans, the same way as the native
	 * trace list defines a trace.
	 *
	 * @param string $service  Service name pattern, "*" is a wildcard. Empty string matches all services.
	 *
	 * @return array  List of ['service', 'requests', 'errors', 'error_rate', 'rpm', 'p50', 'p95', 'p99'],
	 *                durations in nanoseconds.
	 */
	public function getServiceStats(int $time_from, int $time_till, string $service = '',
			string $sort = self::SORT_REQUESTS, int $limit = 100): array {
		$query = (new CClickHouseQuery())
			->select('ServiceName', 'service')
			->select('count()', 'requests')
			->select('countIf(StatusCode=\'Error\')', 'errors')
			->select('quantiles(0.5,0.95,0.99)(Duration)', 'q')
			->from('otel_traces')
			->where('Timestamp>=toDateTime64({time_from:Int32},9)', ['time_from' => ['Int32', $time_from]])
			->where('Timestamp<toDateTime64({time_till:Int32},9)', ['time_till' => ['Int32', $time_till]])
			->where('ParentSpanId=\'\'')
			->group('ServiceName');

		$this->whereApplication($query);

		if ($service !== '') {
			$query->where('ServiceName LIKE {service:String}', ['service' => ['String', self::toLikePattern($service)]]);
		}

		$query
			->order(self::getSortExpression($sort), ZBX_SORT_DOWN)
			->order('ServiceName')
			->limit($limit);

		$minutes = max(1, ($time_till - $time_from) / SEC_PER_MIN);
		$services = [];

		foreach (self::rows($this->db, $query->getSql(), $query->getParams()) as $row) {
			$services[] = ['service' => $row['service']] + self::makeRedRow($row, $minutes);
		}

		return $services;
	}

	/**
	 * Per-minute RED series per service, calculated from root spans. To keep the query light, only the last
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
			->select('quantile(0.95)(Duration)', 'p95')
			->from('otel_traces')
			->where('Timestamp>=toDateTime64({time_from:Int32},9)', ['time_from' => ['Int32', $series_from]])
			->where('Timestamp<toDateTime64({time_till:Int32},9)', ['time_till' => ['Int32', $series_till]])
			->where('ParentSpanId=\'\'')
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
			$series[$name]['p95'][$bucket] = (float) $row['p95'];
		}

		foreach ($series as &$values) {
			$values = array_map('array_values', $values);
		}
		unset($values);

		return $series;
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
			->select('quantiles(0.5,0.95,0.99)(Duration)', 'q')
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
	 * Failed requests (root or server spans with StatusCode = 'Error') grouped by service, endpoint and message, the
	 * most recent group first.
	 *
	 * @param string $service  Service name pattern, "*" is a wildcard. Empty string matches all services.
	 *
	 * @return array  List of ['service', 'endpoint', 'message', 'count', 'first', 'last', 'codes', 'traceid'], the
	 *                trace ID is the most recent one of the group.
	 */
	public function getErrorGroups(int $time_from, int $time_till, string $service = '', int $limit = 50): array {
		$sql = 'SELECT ServiceName AS service, SpanName AS endpoint,'.
				' substringUTF8(StatusMessage,1,'.self::ERROR_MESSAGE_MAX_LENGTH.') AS message, count() AS count,'.
				' toUnixTimestamp(min(Timestamp)) AS first, toUnixTimestamp(max(Timestamp)) AS last,'.
				' arraySort(groupUniqArray(5)('.self::HTTP_STATUS.')) AS codes, argMax(TraceId,Timestamp) AS traceid'.
			' FROM otel_traces'.
			' WHERE '.$this->period().' AND StatusCode=\'Error\' AND (ParentSpanId=\'\' OR SpanKind=\'Server\')'.
				self::serviceCondition($service).
			' GROUP BY service, endpoint, message'.
			' ORDER BY last DESC, count DESC'.
			' LIMIT '.$limit;

		$groups = [];

		foreach ($this->fetch($sql, $time_from, $time_till, $service) as $row) {
			$groups[] = [
				'service' => (string) $row['service'],
				'endpoint' => (string) $row['endpoint'],
				'message' => (string) $row['message'],
				'count' => (int) $row['count'],
				'first' => (int) $row['first'],
				'last' => (int) $row['last'],
				'codes' => self::nonEmpty($row['codes']),
				'traceid' => (string) $row['traceid']
			];
		}

		return $groups;
	}

	/**
	 * Calls between services and to their dependencies (databases, queues, external hosts), from client spans. A
	 * client span whose child is a server span of another service is a call to that service; otherwise it is a call to
	 * the database system or remote host it names. Measured on the caller's side.
	 *
	 * @return array  List of ['source', 'target', 'db_system', 'host', 'calls', 'errors', 'p95'], target is the called
	 *                service ('' for dependencies), p95 in nanoseconds.
	 */
	public function getServiceCalls(int $time_from, int $time_till, string $service = ''): array {
		$sql = 'SELECT p.ServiceName AS source, c.ServiceName AS target, p.db_system AS db_system, p.host AS host,'.
				' count() AS calls, countIf(p.StatusCode=\'Error\') AS errors, quantile(0.95)(p.Duration) AS p95'.
			' FROM ('.
				'SELECT TraceId, SpanId, ServiceName, StatusCode, Duration, '.self::DB_SYSTEM.' AS db_system,'.
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
			' LIMIT 200'.
			self::LOW_MEMORY;

		[$time_from, $time_till] = self::joinWindow($time_from, $time_till);
		$calls = [];

		foreach ($this->fetch($sql, $time_from, $time_till, $service) as $row) {
			$calls[] = [
				'source' => (string) $row['source'],
				'target' => (string) $row['target'],
				'db_system' => (string) $row['db_system'],
				'host' => (string) $row['host'],
				'calls' => (int) $row['calls'],
				'errors' => (int) $row['errors'],
				'p95' => (float) $row['p95']
			];
		}

		return $calls;
	}

	/**
	 * Where the time of each kind of request goes: per root span name, the average total duration split into the
	 * service's own work, its database calls and its calls to other services or hosts (client spans of the same
	 * service in the same trace). Endpoints that consume most time (requests × average) first.
	 *
	 * @return array  List of ['service', 'endpoint', 'requests', 'total', 'db', 'calls', 'own', 'p95'], nanoseconds.
	 */
	public function getTimeBreakdown(int $time_from, int $time_till, string $service = '', int $limit = 15): array {
		$sql = 'SELECT r.ServiceName AS service, r.SpanName AS endpoint, count() AS requests, avg(r.Duration) AS total,'.
				' avg(k.db) AS db, avg(k.calls) AS calls, quantile(0.95)(r.Duration) AS p95'.
			' FROM ('.
				'SELECT TraceId, ServiceName, SpanName, Duration'.
				' FROM otel_traces'.
				' WHERE '.$this->period().' AND ParentSpanId=\'\''.self::serviceCondition($service).
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
	 * Slowest traces of the period (root spans by duration) with their first spans, for the waterfall.
	 *
	 * @return array  List of ['traceid', 'service', 'endpoint', 'clock', 'duration', 'error', 'span_count', 'spans'],
	 *                each span ['id', 'parent', 'service', 'name', 'kind', 'db_system', 'start', 'duration', 'error'],
	 *                start relative to the trace start, nanoseconds.
	 */
	public function getSlowTraces(int $time_from, int $time_till, string $service = '', int $limit = 8,
			int $span_limit = 40): array {
		$sql = 'SELECT TraceId AS traceid, ServiceName AS service, SpanName AS endpoint,'.
				' toUnixTimestamp(Timestamp) AS clock, toUnixTimestamp64Nano(Timestamp) AS start,'.
				' Duration AS duration, StatusCode=\'Error\' AS error'.
			' FROM otel_traces'.
			' WHERE '.$this->period().' AND ParentSpanId=\'\''.self::serviceCondition($service).
			' ORDER BY Duration DESC'.
			' LIMIT '.$limit.
			self::LOW_MEMORY;

		$traces = [];

		foreach ($this->fetch($sql, $time_from, $time_till, $service) as $row) {
			$traces[$row['traceid']] = [
				'traceid' => (string) $row['traceid'],
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

		// Child spans can end a little after the period: one more minute on each side. Spans of every application of
		// the trace, except those the user may not see, as in the native trace view.
		[$access, $access_params] = self::accessCondition(ApmAccess::TRACES);
		$sql = 'SELECT TraceId AS traceid, SpanId AS id, ParentSpanId AS parent, ServiceName AS service,'.
				' SpanName AS name, SpanKind AS kind, '.self::DB_SYSTEM.' AS db_system,'.
				' toUnixTimestamp64Nano(Timestamp) AS start, Duration AS duration, StatusCode=\'Error\' AS error,'.
				' count() OVER (PARTITION BY TraceId) AS span_count'.
			' FROM otel_traces'.
			' WHERE '.self::PERIOD.($access !== '' ? ' AND '.$access : '').' AND TraceId IN {traceids:Array(String)}'.
			' ORDER BY TraceId, Timestamp'.
			' LIMIT '.$span_limit.' BY TraceId'.
			self::LOW_MEMORY;

		$params = [
			'time_from' => ['Int32', $time_from - SEC_PER_MIN],
			'time_till' => ['Int32', $time_till + SEC_PER_MIN],
			'traceids' => ['String', array_keys($traces)]
		] + $access_params;

		foreach (self::rows($this->db, $sql, $params) as $row) {
			$trace = &$traces[$row['traceid']];
			$trace['span_count'] = (int) $row['span_count'];

			$trace['spans'][] = [
				'id' => (string) $row['id'],
				'parent' => (string) $row['parent'],
				'service' => (string) $row['service'],
				'name' => (string) $row['name'],
				'kind' => (string) $row['kind'],
				'db_system' => (string) $row['db_system'],
				'start' => max(0, (int) $row['start'] - $trace['start']),
				'duration' => (float) $row['duration'],
				'error' => (bool) $row['error']
			];

			unset($trace);
		}

		return array_values($traces);
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
				' quantileIf(0.95)(Duration,'.$current.') AS p95,'.
				' quantileIf(0.95)(Duration,NOT '.$current.') AS previous_p95'.
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
				'p95' => (float) $row['p95'],
				'previous_p95' => (float) $row['previous_p95']
			];
		}

		return $rows;
	}

	/**
	 * Apdex per service (root spans) and the endpoints (server spans) with the lowest score. Satisfied: duration up to
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
				' WHERE '.$this->period().' AND ParentSpanId=\'\''.self::serviceCondition($service).
				' GROUP BY service'.
				' ORDER BY requests DESC'.
				' LIMIT 50',
			'endpoints' => 'SELECT ServiceName AS service, SpanName AS endpoint, '.$counts.
				' FROM otel_traces'.
				' WHERE '.$this->period().' AND SpanKind=\'Server\''.self::serviceCondition($service).
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
	 * Latency distribution of the root spans of the busiest services, in power-of-two millisecond buckets: bucket 0 is
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
			' WHERE '.$this->period().' AND ParentSpanId=\'\''.self::serviceCondition($service).
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
		$params += ['time_from' => ['Int32', $time_from], 'time_till' => ['Int32', $time_till]];

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
			self::SORT_P95 => 'q[2]',
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
			'p50' => (float) $row['q'][0],
			'p95' => (float) $row['q'][1],
			'p99' => (float) $row['q'][2]
		];
	}
}

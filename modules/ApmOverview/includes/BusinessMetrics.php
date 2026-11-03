<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

/**
 * Business metrics (KPIs) of an application: the counters, up-down counters, gauges and histograms it sends over
 * OpenTelemetry, such as orders by store or reservations by hotel. Queries of the "Business KPIs" tab.
 *
 * The template "APM business metrics by ClickHouse HTTP" (templates/src/apm_business_metrics.sql) measures the same
 * way, minute by minute; tests/run.php checks that both use the same default filters.
 */
class BusinessMetrics {

	/**
	 * Metrics that are not business metrics: runtime, HTTP, database and SDK metrics of the instrumentation. Same as
	 * the {$APM.KPI.METRIC.NOT_MATCHES} default of the template.
	 */
	public const METRIC_NOT_MATCHES = '^(otel[._]|otelcol|process\.|runtime\.|jvm\.|dotnet\.|kestrel\.|aspnetcore\.|'.
		'http\.|rpc\.|db\.|dns\.|system\.|go\.|cpython\.|nodejs\.|v8js\.|signalr\.|kafka\.|feature_flag\.|messaging\.|'.
		'container\.|k8s\.)';

	/**
	 * Attribute keys that are not broken down: identifiers and other keys with too many values. Same as the
	 * {$APM.KPI.DIMENSION.NOT_MATCHES} default of the template.
	 */
	public const DIMENSION_NOT_MATCHES = '(^|[._])(id|uuid|trace|span|session|user|email|url|ip|address)$';

	public const KIND_COUNTER = 'counter';
	public const KIND_UPDOWN = 'updown';
	public const KIND_GAUGE = 'gauge';
	public const KIND_HISTOGRAM = 'histogram';

	/**
	 * Values shown per attribute (the largest); the others are still in the total of the metric.
	 */
	public const VALUES_LIMIT = 10;

	public const ROWS_LIMIT = 2000;

	/**
	 * Points before the period that a cumulative series is measured from (its value when the period starts).
	 */
	public const LOOKBACK = 15 * SEC_PER_MIN;

	/**
	 * Seconds after which an instance that stopped reporting no longer adds its last value to a gauge or an up-down
	 * counter (e.g. the rooms occupied before a restart). Same as the template.
	 */
	public const STALE = 300;

	// One row per point of every metric of the three tables, with the kind of metric. {access} is replaced by the
	// application and permission conditions.
	private const POINTS = <<<'SQL'
		SELECT ServiceName, MetricName, MetricUnit, MetricDescription, Attributes, ResourceAttributes,
			TimeUnix, StartTimeUnix, Value AS v, 0 AS s, AggregationTemporality AS temporality,
			if(IsMonotonic, 'counter', 'updown') AS kind
		FROM otel_metrics_sum
		WHERE TimeUnix>=tw AND TimeUnix<t1{access}
		UNION ALL
		SELECT ServiceName, MetricName, MetricUnit, MetricDescription, Attributes, ResourceAttributes,
			TimeUnix, StartTimeUnix, Value, 0, 0, 'gauge'
		FROM otel_metrics_gauge
		WHERE TimeUnix>=tw AND TimeUnix<t1{access}
		UNION ALL
		SELECT ServiceName, MetricName, MetricUnit, MetricDescription, Attributes, ResourceAttributes,
			TimeUnix, StartTimeUnix, toFloat64(Count), Sum, AggregationTemporality, 'histogram'
		FROM otel_metrics_histogram
		WHERE TimeUnix>=tw AND TimeUnix<t1{access}
		SQL;

	// One row per series (metric, attribute set, instance): its points in time order and, for cumulative series, the
	// increase at each point (a lower value is a reset and counts the new value; the first point counts only when the
	// series started inside the window). {points} is POINTS, {service} the service condition.
	private const SERIES = <<<'SQL'
		SELECT service, metric, kind, unit, description, attrs, ts, vs,
			arrayMap((v, pv, st, i) -> if(i=1, if(st>=tw, v, 0), if(v>=pv, v-pv, v)),
				vs, arrayPopBack(arrayPushFront(vs, 0)), starts, arrayEnumerate(vs)) AS dv0,
			arrayMap((v, pv, st, i) -> if(i=1, if(st>=tw, v, 0), if(v>=pv, v-pv, v)),
				ss, arrayPopBack(arrayPushFront(ss, 0)), starts, arrayEnumerate(ss)) AS ds0,
			if(delta, vs, dv0) AS dv,
			if(delta, ss, ds0) AS ds
		FROM (
			SELECT clean(ServiceName) AS service, clean(MetricName) AS metric, kind, Attributes AS attrs,
				if(match(any(MetricUnit), '^([{].*[}]|1)$'), '', any(MetricUnit)) AS unit,
				any(MetricDescription) AS description, max(temporality=1) AS delta,
				arraySort(p -> p.1, groupArray((TimeUnix, v, s, StartTimeUnix))) AS pts,
				arrayMap(p -> p.1, pts) AS ts, arrayMap(p -> p.2, pts) AS vs, arrayMap(p -> p.3, pts) AS ss,
				arrayMap(p -> p.4, pts) AS starts
			FROM ({points})
			WHERE match(MetricName, {metric_matches:String})
				AND NOT match(MetricName, {metric_not_matches:String}){service}
			GROUP BY ServiceName, MetricName, kind, attrs, ResourceAttributes['service.instance.id']
		)
		SQL;

	/**
	 * Totals of the period [time_from, time_till) per metric (dim_key = '') and per value of each attribute: increase
	 * of counters and histograms (value = count, sum = sum of the measurements), last value of up-down counters and
	 * gauges (instances seen in the last STALE seconds). The largest VALUES_LIMIT values per attribute.
	 */
	public static function totalsSql(string $access, string $service): string {
		return 'WITH toDateTime({time_from:Int32}) AS t0, toDateTime({time_till:Int32}) AS t1,'.
			' t0-{lookback:UInt32} AS tw, '.self::CLEAN.
			' SELECT service, metric, kind, any(unit) AS unit, any(description) AS description,'.
				' dim.1 AS dim_key, dim.2 AS dim_value, sum(value) AS value, sum(total) AS sum,'.
				' toUnixTimestamp(max(last)) AS last'.
			' FROM ('.
				'SELECT service, metric, kind, unit, description, attrs,'.
					// Current values: only instances that reported in the last STALE seconds of the period.
					' if(kind IN (\'gauge\',\'updown\'), if(ts[-1]>=t1-'.self::STALE.', vs[-1], 0),'.
						' arraySum(arrayMap((d, t) -> if(t>=t0, d, 0), dv, ts))) AS value,'.
					' if(kind=\'histogram\', arraySum(arrayMap((d, t) -> if(t>=t0, d, 0), ds, ts)), 0) AS total,'.
					' ts[-1] AS last'.
				' FROM ('.self::series($access, $service).')'.
				' WHERE ts[-1]>=t0'.
			')'.
			' ARRAY JOIN arrayConcat([(\'\', \'\')], arrayMap(k -> (clean(k), clean(attrs[k])),'.
				' arrayFilter(k -> NOT match(k, {dim_not_matches:String}), mapKeys(attrs)))) AS dim'.
			' GROUP BY service, metric, kind, dim'.
			' ORDER BY dim_key!=\'\', service, metric, dim_key, value DESC'.
			' LIMIT {values_limit:UInt32} BY service, metric, kind, dim_key'.
			' LIMIT {limit:UInt32}';
	}

	/**
	 * Per-minute values of each metric in [time_from, time_till) (whole minutes): increase of counters and histogram
	 * counts, last value of up-down counters and gauges, all instances and attribute sets added up.
	 */
	public static function seriesSql(string $access, string $service): string {
		return 'WITH toDateTime({time_from:Int32}) AS t0, toDateTime({time_till:Int32}) AS t1,'.
			' t0-{lookback:UInt32} AS tw, '.self::CLEAN.
			' SELECT service, metric, kind, bucket, sum(v) AS value'.
			' FROM ('.
				'SELECT service, metric, kind, attrs, toUnixTimestamp(toStartOfMinute(p.1)) AS bucket,'.
					' if(kind IN (\'gauge\',\'updown\'), argMax(p.2, p.1), sum(p.3)) AS v'.
				' FROM ('.self::series($access, $service).')'.
				' ARRAY JOIN arrayZip(ts, vs, dv) AS p'.
				' WHERE p.1>=t0'.
				' GROUP BY service, metric, kind, attrs, bucket'.
			')'.
			' GROUP BY service, metric, kind, bucket';
	}

	/**
	 * Parameters of totalsSql() and seriesSql(), besides the period and the access conditions.
	 */
	public static function params(): array {
		return [
			'lookback' => ['UInt32', self::LOOKBACK],
			'metric_matches' => ['String', '.*'],
			'metric_not_matches' => ['String', self::METRIC_NOT_MATCHES],
			'dim_not_matches' => ['String', self::DIMENSION_NOT_MATCHES],
			'values_limit' => ['UInt32', self::VALUES_LIMIT],
			'limit' => ['UInt32', self::ROWS_LIMIT]
		];
	}

	/**
	 * Group the rows of totalsSql() by metric: [key => ['service', 'metric', 'kind', 'unit', 'description', 'value',
	 * 'sum', 'last', 'dims' => [attribute key => [['value' => string, 'total' => float], ...]]]], in query order.
	 */
	public static function groupTotals(array $rows): array {
		$metrics = [];

		foreach ($rows as $row) {
			$key = self::key($row['service'], $row['metric'], $row['kind']);

			if ($row['dim_key'] === '') {
				$metrics[$key] = [
					'service' => $row['service'],
					'metric' => $row['metric'],
					'kind' => $row['kind'],
					'unit' => $row['unit'],
					'description' => trim((string) $row['description']),
					'value' => (float) $row['value'],
					'sum' => (float) $row['sum'],
					'last' => (int) $row['last'],
					'dims' => []
				];
			}
			elseif (array_key_exists($key, $metrics)) {
				$metrics[$key]['dims'][$row['dim_key']][] = [
					'value' => $row['dim_value'],
					'total' => (float) $row['value']
				];
			}
		}

		return $metrics;
	}

	/**
	 * Per-minute values of seriesSql() for the given minute timestamps: [key => float[]], minutes without a point are
	 * 0 for counters and histograms and keep the previous value for up-down counters and gauges.
	 */
	public static function groupSeries(array $rows, array $buckets): array {
		$points = [];
		$kinds = [];

		foreach ($rows as $row) {
			$key = self::key($row['service'], $row['metric'], $row['kind']);
			$points[$key][(int) $row['bucket']] = (float) $row['value'];
			$kinds[$key] = $row['kind'];
		}

		$series = [];

		foreach ($points as $key => $values) {
			$current = in_array($kinds[$key], [self::KIND_GAUGE, self::KIND_UPDOWN], true);
			$previous = 0.0;
			$series[$key] = [];

			foreach ($buckets as $bucket) {
				$previous = $values[$bucket] ?? ($current ? $previous : 0.0);
				$series[$key][] = $previous;
			}
		}

		return $series;
	}

	public static function key(string $service, string $metric, string $kind): string {
		return $service."\x1F".$metric."\x1F".$kind;
	}

	/**
	 * Whether the metric is measured as a total over a period (counters, histograms) or as a current value.
	 */
	public static function isTotal(string $kind): bool {
		return $kind === self::KIND_COUNTER || $kind === self::KIND_HISTOGRAM;
	}

	/**
	 * A number with the unit of the metric, e.g. "1 234 order", "35.12 USD", "0.25 s".
	 */
	public static function format(float $value, string $unit): string {
		$abs = abs($value);

		if ($abs >= 1000000) {
			$text = number_format($value / 1000000, 2, '.', ' ').' M';
		}
		elseif ($abs >= 100 || $value == floor($value)) {
			$text = number_format($value, 0, '.', ' ');
		}
		elseif ($abs < 1) {
			// Three significant digits: 0.00134 s, not 0.00 s.
			$text = rtrim(rtrim(number_format($value, min(6, 2 - (int) floor(log10($abs))), '.', ''), '0'), '.');
		}
		else {
			$text = number_format($value, 2, '.', ' ');
		}

		return $unit !== '' ? $text.' '.$unit : $text;
	}

	private static function series(string $access, string $service): string {
		return strtr(self::SERIES, [
			'{points}' => strtr(self::POINTS, ['{access}' => $access]),
			'{service}' => $service
		]);
	}

	// Quotes and backslashes are removed from names, as in the template, whose item keys cannot hold them.
	private const CLEAN = '(x -> replaceRegexpAll(x, \'[\\\'"\\\\\\\\]\', \'\')) AS clean';
}

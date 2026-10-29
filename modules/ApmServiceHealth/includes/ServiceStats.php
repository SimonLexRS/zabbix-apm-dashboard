<?php declare(strict_types = 0);

namespace Modules\ApmServiceHealth\Includes;

use ApmDb,
	ApmDbClickHouse,
	CClickHouseQuery;

/**
 * RED statistics per service read from the ClickHouse telemetry data source.
 *
 * Kept in the widget (instead of shared with the APM Overview module) so the widget works on its own.
 */
class ServiceStats {

	/**
	 * Number of minutes covered by the trend (sparkline) column.
	 */
	public const SERIES_MINUTES = 30;

	/**
	 * Statistics are calculated from root spans, the same way as the native trace list defines a trace.
	 *
	 * @param string $service  Service name pattern, "*" is a wildcard. Empty string matches all services.
	 *
	 * @throws \DBException  If APM data source is not configured or not reachable.
	 *
	 * @return array  List of ['service', 'requests', 'errors', 'error_rate', 'rpm', 'p50', 'p95', 'p99'],
	 *                durations in nanoseconds.
	 */
	public static function get(int $time_from, int $time_till, string $service, int $sort, int $limit): array {
		$db = ApmDbClickHouse::getInstance(ApmDb::getInstance()->getConfig());

		$query = (new CClickHouseQuery())
			->select('ServiceName', 'service')
			->select('count()', 'requests')
			->select('countIf(StatusCode=\'Error\')', 'errors')
			->select('quantiles(0.5,0.95,0.99)(Duration)', 'q')
			->from('otel_traces')
			->where('Timestamp>=toDateTime64({time_from:Int32},9)', ['time_from' => $time_from])
			->where('Timestamp<toDateTime64({time_till:Int32},9)', ['time_till' => $time_till])
			->where('ParentSpanId=\'\'')
			->group('ServiceName');

		if ($service !== '') {
			$pattern = addcslashes($service, '\\%_');
			$pattern = str_contains($pattern, '*') ? str_replace('*', '%', $pattern) : '%'.$pattern.'%';

			$query->where('ServiceName LIKE {service:String}', ['service' => $pattern]);
		}

		$query
			->order(match ($sort) {
				WidgetForm::SORT_ERROR_RATE => 'errors/requests',
				WidgetForm::SORT_P95 => 'q[2]',
				default => 'requests'
			}, ZBX_SORT_DOWN)
			->order('ServiceName')
			->limit($limit);

		$minutes = max(1, ($time_till - $time_from) / SEC_PER_MIN);
		$services = [];

		foreach ($db->fetch($query->getSql(), $query->getParams()) as $row) {
			$requests = (int) $row['requests'];
			$errors = (int) $row['errors'];

			$services[] = [
				'service' => $row['service'],
				'requests' => $requests,
				'errors' => $errors,
				'error_rate' => $requests > 0 ? $errors / $requests * 100 : 0,
				'rpm' => $requests / $minutes,
				'p50' => (float) $row['q'][0],
				'p95' => (float) $row['q'][1],
				'p99' => (float) $row['q'][2]
			];
		}

		return $services;
	}

	/**
	 * Requests per minute of the given services (root spans), for the last $minutes complete minutes of the period.
	 * Minutes without data are filled with zeros.
	 *
	 * @param array $services  Exact service names.
	 *
	 * @throws \DBException  If APM data source is not configured or not reachable.
	 *
	 * @return array  [service => int[]], one value per minute, oldest first.
	 */
	public static function getRequestSeries(int $time_from, int $time_till, array $services,
			int $minutes = self::SERIES_MINUTES): array {
		$series_till = intdiv($time_till, SEC_PER_MIN) * SEC_PER_MIN;
		$series_from = max($time_from, $series_till - $minutes * SEC_PER_MIN);
		$first_bucket = intdiv($series_from, SEC_PER_MIN) * SEC_PER_MIN;

		if (!$services || $first_bucket > $series_till - SEC_PER_MIN) {
			return [];
		}

		$db = ApmDbClickHouse::getInstance(ApmDb::getInstance()->getConfig());

		$query = (new CClickHouseQuery())
			->select('ServiceName', 'service')
			->select('toUnixTimestamp(toStartOfMinute(Timestamp))', 'bucket')
			->select('count()', 'requests')
			->from('otel_traces')
			->where('Timestamp>=toDateTime64({time_from:Int32},9)', ['time_from' => $series_from])
			->where('Timestamp<toDateTime64({time_till:Int32},9)', ['time_till' => $series_till])
			->where('ParentSpanId=\'\'')
			->where('has({services:Array(String)},ServiceName)', ['services' => array_values($services)])
			->group('ServiceName')
			->group('bucket');

		$empty = array_fill_keys(range($first_bucket, $series_till - SEC_PER_MIN, SEC_PER_MIN), 0);
		$series = [];

		foreach ($db->fetch($query->getSql(), $query->getParams()) as $row) {
			$bucket = (int) $row['bucket'];

			if (array_key_exists($bucket, $empty)) {
				$series[$row['service']] ??= $empty;
				$series[$row['service']][$bucket] = (int) $row['requests'];
			}
		}

		return array_map('array_values', $series);
	}
}

<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

use DBException,
	Modules\ApmOverview\Actions\Overview;

/**
 * Collects what the page of one service (or of one of its endpoints) and the service panel of the map show.
 */
class ServiceLoader {

	public const TAB_OVERVIEW = 'overview';
	public const TAB_ERRORS = 'errors';
	public const TAB_ANALYSIS = 'analysis';

	public const TABS = [self::TAB_OVERVIEW, self::TAB_ERRORS, self::TAB_ANALYSIS];

	/**
	 * @param array $filter  Overview::getFilter(), with 'application' resolved.
	 * @param array $compare ['a' => string, 'b' => string] versions to compare, '' for the latest two.
	 */
	public static function load(array $filter, string $service, string $endpoint, int $time_from, int $time_till,
			string $tab, array $compare = ['a' => '', 'b' => '']): array {
		$data = ['source' => OverviewLoader::getSourceInfo(), 'error' => null, 'service' => $service,
			'endpoint' => $endpoint, 'application' => $filter['application'], 'time_from' => $time_from,
			'time_till' => $time_till, 'recent_min' => $filter['recent_min'], 'resolved' => $filter['resolved']
		];

		if ($data['source']['origin'] === null) {
			return $data;
		}

		$exact = ApmStats::EXACT_PREFIX.$service;

		try {
			$stats = self::stats($filter);

			switch ($tab) {
				case self::TAB_OVERVIEW:
					$data['info'] = $stats->getServiceInfo($time_from, $time_till, $service);
					$data['stats'] = self::figures($stats, $time_from, $time_till, $service, $endpoint);
					OverviewLoader::section($data, 'timeseries', [], fn() =>
						$stats->getTimeseries($time_from, $time_till, $service, $endpoint)
					);
					OverviewLoader::section($data, 'versions', [], fn() =>
						$stats->getVersions($time_from, $time_till, $service)[$service] ?? []
					);

					if ($endpoint === '') {
						OverviewLoader::section($data, 'calls', ['callers' => [], 'callees' => []], fn() =>
							self::neighbours($stats, $time_from, $time_till, $service)
						);
						OverviewLoader::section($data, 'endpoints', [], fn() =>
							$stats->getEndpointStats($time_from, $time_till, '', ApmStats::SORT_REQUESTS, $service, 30)
						);
						OverviewLoader::section($data, 'breakdown', [], fn() =>
							$stats->getTimeBreakdown($time_from, $time_till, $exact, 10)
						);
					}
					else {
						OverviewLoader::section($data, 'histograms', [], fn() =>
							$stats->getLatencyHistogram($time_from, $time_till, $exact, 1, $endpoint)
						);
						OverviewLoader::section($data, 'span_summary', [], fn() =>
							$stats->getSpanSummary($time_from, $time_till, $service, $endpoint)
						);
					}

					$data['problems'] = self::problems($filter['application'], $service, $data['info']['hosts']);
					break;

				case self::TAB_ERRORS:
					$errors = $stats->getErrorGroups($time_from, $time_till, $exact, OverviewLoader::ERROR_LIMIT);
					$data['errors'] = $endpoint === ''
						? $errors
						: array_values(array_filter($errors, static fn(array $error): bool => $error['endpoint'] === $endpoint));
					$data['truncated'] = array_keys(array_filter($stats->truncated));

					if ($endpoint === '') {
						OverviewLoader::section($data, 'versions', [], fn() =>
							$stats->getVersions($time_from, $time_till, $service)[$service] ?? []
						);
						$data['compare'] = self::pickVersions($data['versions'], $compare);

						if ($data['compare'] !== null) {
							OverviewLoader::section($data, 'comparison', ['endpoints' => [], 'errors' => []], fn() =>
								$stats->compareVersions($time_from, $time_till, $service, $data['compare']['a'],
									$data['compare']['b']
								)
							);
						}
					}
					break;

				case self::TAB_ANALYSIS:
					foreach ([ApmStats::TAGS_SLOW, ApmStats::TAGS_FAILED] as $mode) {
						OverviewLoader::section($data, 'tags_'.$mode, ['outliers' => 0, 'rest' => 0, 'threshold' => 0.0,
							'tags' => []], fn() => $stats->getTagAnalysis($time_from, $time_till, $service, $endpoint, $mode)
						);
					}

					OverviewLoader::section($data, 'slow_traces', [], fn() =>
						$stats->getSlowTraces($time_from, $time_till, $exact, 8, 40, $endpoint)
					);
					break;
			}
		}
		catch (DBException $e) {
			$data['error'] = $e->getMessage();
		}

		return $data;
	}

	/**
	 * What the service panel of the map shows: one light query set, no time series over days.
	 */
	public static function panel(array $filter, string $service, int $time_from, int $time_till): array {
		$data = ['source' => OverviewLoader::getSourceInfo(), 'error' => null, 'service' => $service, 'endpoint' => '',
			'application' => $filter['application'], 'time_from' => $time_from, 'time_till' => $time_till,
			'recent_min' => $filter['recent_min']
		];

		if ($data['source']['origin'] === null) {
			return $data;
		}

		try {
			$stats = self::stats($filter);
			$data['info'] = $stats->getServiceInfo($time_from, $time_till, $service);
			$data['stats'] = self::figures($stats, $time_from, $time_till, $service, '');
			$data['series'] = $stats->getServiceSeries($time_from, $time_till, ApmStats::EXACT_PREFIX.$service)[$service]
				?? null;
			OverviewLoader::section($data, 'calls', ['callers' => [], 'callees' => []], fn() =>
				self::neighbours($stats, $time_from, $time_till, $service)
			);
			OverviewLoader::section($data, 'versions', [], fn() =>
				$stats->getVersions($time_from, $time_till, $service)[$service] ?? []
			);
			$data['problems'] = self::problems($filter['application'], $service, $data['info']['hosts']);
		}
		catch (DBException $e) {
			$data['error'] = $e->getMessage();
		}

		return $data;
	}

	private static function stats(array $filter): ApmStats {
		return (new ApmStats())
			->setApplication($filter['application'])
			->setRecent(($filter['recent_min'] ?? Overview::RECENT_DEFAULT) * SEC_PER_MIN)
			->setLongSpan($filter['long_s'] ?? Overview::LONG_DEFAULT);
	}

	/**
	 * RED figures of the service, or of one of its endpoints; null when it had no requests.
	 */
	private static function figures(ApmStats $stats, int $time_from, int $time_till, string $service,
			string $endpoint): ?array {
		if ($endpoint === '') {
			return $stats->getServiceStats($time_from, $time_till, ApmStats::EXACT_PREFIX.$service)[0] ?? null;
		}

		foreach ($stats->getEndpointStats($time_from, $time_till, '', ApmStats::SORT_REQUESTS, $service, 500) as $row) {
			if ($row['endpoint'] === $endpoint) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Who calls the service and what it calls, from the connections of the map.
	 *
	 * @return array  ['callers' => [...], 'callees' => [...]], each ['name', 'kind', 'calls', 'rpm', 'error_rate',
	 *                'p95'], busiest first.
	 */
	private static function neighbours(ApmStats $stats, int $time_from, int $time_till, string $service): array {
		[$call_from, $call_till] = ApmStats::joinWindow($time_from, $time_till);
		$graph = ServiceMap::build($stats->getServiceStats($time_from, $time_till),
			$stats->getServiceCalls($time_from, $time_till), max(1, ($call_till - $call_from) / SEC_PER_MIN)
		);

		return self::pickNeighbours($graph, $service);
	}

	public static function pickNeighbours(array $graph, string $service): array {
		$id = 'svc:'.$service;
		$result = ['callers' => [], 'callees' => []];

		foreach ($graph['edges'] as $edge) {
			if ($edge['from'] !== $id && $edge['to'] !== $id) {
				continue;
			}

			$other = $graph['nodes'][$edge['from'] === $id ? $edge['to'] : $edge['from']];

			$result[$edge['from'] === $id ? 'callees' : 'callers'][] = [
				'name' => $other['name'],
				'kind' => $other['kind'],
				'calls' => $edge['calls'],
				'rpm' => $edge['rpm'],
				'error_rate' => $edge['calls'] > 0 ? $edge['errors'] / $edge['calls'] * 100 : 0.0,
				'p95' => $edge['p95']
			];
		}

		foreach ($result as &$list) {
			usort($list, static fn(array $a, array $b): int => [$b['calls'], $a['name']] <=> [$a['calls'], $b['name']]);
		}
		unset($list);

		return $result;
	}

	/**
	 * Open Zabbix problems around a service: its own (tag service = name), those of the Zabbix host of the application
	 * and those of the hosts it runs on.
	 *
	 * @return array  ['service' => summary, 'application' => ?['host' => array, 'problems' => summary],
	 *                'hosts' => [['host' => array, 'problems' => summary], ...]]
	 */
	public static function problems(?string $application, string $service, array $host_names): array {
		$result = [
			'service' => ZabbixProblems::forServices([$service])[$service] ?? ZabbixProblems::summarize([]),
			'application' => null,
			'hosts' => []
		];

		$app_host = $application !== null && $application !== '' ? OverviewLoader::findHost($application) : null;

		if ($app_host !== null) {
			$result['application'] = ['host' => $app_host, 'problems' => ZabbixProblems::forHosts([$app_host['hostid']])];
		}

		foreach (ZabbixProblems::hostsNamed($host_names) as $host) {
			$result['hosts'][] = ['host' => $host, 'problems' => ZabbixProblems::forHosts([$host['hostid']])];
		}

		return $result;
	}

	/**
	 * The two versions to compare: the ones asked for when both exist, otherwise the latest against the one before.
	 *
	 * @return array|null  ['a' => newer, 'b' => older, 'all' => string[]], null with fewer than two versions.
	 */
	public static function pickVersions(array $versions, array $asked): ?array {
		$names = array_column($versions, 'version');

		if (count($names) < 2) {
			return null;
		}

		$a = in_array($asked['a'], $names, true) ? $asked['a'] : $names[0];
		$b = in_array($asked['b'], $names, true) && $asked['b'] !== $a
			? $asked['b']
			: (array_values(array_diff($names, [$a]))[0]);

		return ['a' => $a, 'b' => $b, 'all' => $names];
	}
}

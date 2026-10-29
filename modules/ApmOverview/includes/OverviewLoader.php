<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

use API,
	CRoleHelper,
	CSettingsHelper,
	CWebUser,
	DBException;

/**
 * Collects what one tab of the APM overview page shows; only the active tab is queried. Shared by the page action and
 * the real-time data action.
 */
class OverviewLoader {

	public const ENDPOINT_LIMIT = 100;
	public const ERROR_LIMIT = 50;

	/**
	 * @param array  $filter  ['application' => ?string, 'service' => string, 'sort' => string,
	 *                        'endpoint_service' => string, 'apdex_ms' => int]
	 * @param string $tab     One of OverviewHtml::DATA_TABS.
	 */
	public static function load(array $filter, int $time_from, int $time_till, string $tab): array {
		$data = [
			'source' => self::getSourceInfo(),
			'can_edit_source' => CWebUser::checkAccess(CRoleHelper::UI_ADMINISTRATION_DATA_SOURCE),
			'error' => null
		];

		if ($data['source']['origin'] === null && $tab !== OverviewHtml::TAB_STATUS) {
			return $data;
		}

		$service = $filter['service'];

		try {
			$stats = (new ApmStats())->setApplication($filter['application']);

			switch ($tab) {
				case OverviewHtml::TAB_STATUS:
					$data['version'] = $stats->getServerVersion();
					$data['signals'] = $stats->getSignalStats($time_from, $time_till);
					break;

				case OverviewHtml::TAB_MAP:
					[$call_from, $call_till] = ApmStats::joinWindow($time_from, $time_till);

					// The whole application, always: the page filter only highlights (OverviewHtml::map()).
					$data['services'] = $stats->getServiceStats($time_from, $time_till);
					$data['calls'] = $stats->getServiceCalls($time_from, $time_till);
					$data['call_minutes'] = max(1, ($call_till - $call_from) / SEC_PER_MIN);
					break;

				case OverviewHtml::TAB_SERVICES:
					$data['services'] = $stats->getServiceStats($time_from, $time_till, $service, $filter['sort']);
					$data['series'] = $stats->getServiceSeries($time_from, $time_till, $service);
					break;

				case OverviewHtml::TAB_ENDPOINTS:
					$data['endpoints'] = $stats->getEndpointStats($time_from, $time_till, $service, $filter['sort'],
						$filter['endpoint_service'], self::ENDPOINT_LIMIT
					);
					break;

				case OverviewHtml::TAB_ERRORS:
					$data['errors'] = $stats->getErrorGroups($time_from, $time_till, $service, self::ERROR_LIMIT);
					break;

				case OverviewHtml::TAB_UX:
					self::section($data, 'vitals', ['overall' => [], 'pages' => [], 'devices' => [], 'hourly' => []],
						fn() => $stats->getWebVitals($time_from, $time_till, $service)
					);
					self::section($data, 'apdex', ['services' => [], 'endpoints' => []],
						fn() => $stats->getApdex($time_from, $time_till, $service, $filter['apdex_ms'] * 1000000)
					);
					self::section($data, 'histograms', [],
						fn() => $stats->getLatencyHistogram($time_from, $time_till, $service)
					);
					break;

				case OverviewHtml::TAB_HOWTO:
					// "Is it working now": a fixed recent window, whatever the period of the page.
					$data['application'] = $filter['application'];
					$data['signals'] = $stats->getSignalStats(time() - OverviewHtml::CHECK_WINDOW, time());
					$data['services'] = $stats->getServiceNames(SEC_PER_DAY);
					// Services sending right now without service.namespace: they cannot be told apart by application.
					$data['unnamespaced'] = $filter['application'] !== null && $filter['application'] !== ''
						? (new ApmStats())->setApplication('')->getServiceNames(OverviewHtml::CHECK_WINDOW)
						: [];
					$data['host'] = $filter['application'] !== null && $filter['application'] !== ''
						? self::findHost($filter['application'])
						: null;
					break;

				case OverviewHtml::TAB_SLOW:
					self::section($data, 'breakdown', [],
						fn() => $stats->getTimeBreakdown($time_from, $time_till, $service)
					);
					self::section($data, 'slow_traces', [],
						fn() => $stats->getSlowTraces($time_from, $time_till, $service)
					);
					self::section($data, 'db_statements', [],
						fn() => $stats->getDbStatements($time_from, $time_till, $service)
					);
					self::section($data, 'regressions', [],
						fn() => $stats->getRegressions($time_from, $time_till, $service)
					);
					break;
			}
		}
		catch (DBException $e) {
			$data['error'] = $e->getMessage();
		}

		return $data + ['version' => '', 'signals' => []];
	}

	/**
	 * The Zabbix host of an application (apm-<namespace>, see scripts/zabbix-apm-app.py), with the permissions of the
	 * current user.
	 *
	 * @return array|null  ['hostid', 'name', 'template' => bool, 'namespace' => ?string]
	 */
	private static function findHost(string $application): ?array {
		$hosts = API::Host()->get([
			'output' => ['hostid', 'name'],
			'filter' => ['host' => Integration::hostName($application)],
			'selectParentTemplates' => ['host'],
			'selectMacros' => ['macro', 'value']
		]);

		if (!$hosts) {
			return null;
		}

		$host = $hosts[0];
		$macro = array_values(array_filter($host['macros'],
			static fn(array $macro): bool => $macro['macro'] === '{$OTLP.NAMESPACE}'
		));

		return [
			'hostid' => $host['hostid'],
			'name' => $host['name'],
			'template' => in_array('OpenTelemetry application by OTLP', array_column($host['parentTemplates'], 'host'), true),
			'namespace' => $macro ? $macro[0]['value'] : null
		];
	}

	/**
	 * One section of a tab with several queries: if its query fails (e.g. ClickHouse out of memory), the section is
	 * empty and marked in "section_errors", the rest of the tab still shows.
	 */
	private static function section(array &$data, string $key, array $empty, callable $query): void {
		try {
			$data[$key] = $query();
		}
		catch (DBException $e) {
			$data[$key] = $empty;
			$data['section_errors'][$key] = $e->getMessage();
		}
	}

	/**
	 * Describe where the frontend takes the APM data source configuration from. Credentials are never exposed.
	 */
	private static function getSourceInfo(): array {
		global $TELEMETRY_PROVIDERS;

		if ($TELEMETRY_PROVIDERS) {
			return [
				'origin' => 'zabbix.conf.php',
				'url' => $TELEMETRY_PROVIDERS[0]['url'] ?? '',
				'db' => $TELEMETRY_PROVIDERS[0]['db'] ?? ''
			];
		}

		$apm_global_db = CSettingsHelper::getApmGlobalDb();

		return [
			'origin' => $apm_global_db['status'] == APM_GLOBAL_DB_STATUS_NOT_CONFIGURED ? null : 'global',
			'url' => $apm_global_db['url'],
			'db' => $apm_global_db['db']
		];
	}
}

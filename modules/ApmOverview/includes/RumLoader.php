<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

use DBException,
	Modules\ApmOverview\Actions\Overview;

/**
 * Collects what one tab of the Real user monitoring page shows; only the active tab is queried.
 */
class RumLoader {

	public const TAB_OVERVIEW = 'overview';
	public const TAB_PAGES = 'pages';
	public const TAB_RESOURCES = 'resources';
	public const TAB_ERRORS = 'errors';
	public const TAB_SESSIONS = 'sessions';
	public const TAB_HOWTO = 'howto';

	// Display order of the tabs.
	public const TABS = [self::TAB_OVERVIEW, self::TAB_PAGES, self::TAB_RESOURCES, self::TAB_ERRORS, self::TAB_SESSIONS,
		self::TAB_HOWTO
	];

	public const ERROR_LIMIT = 50;

	// Window of the instrumentation checks of How it works.
	public const CHECK_WINDOW = 15 * SEC_PER_MIN;

	/**
	 * @param array $filter  ['application' => ?string, 'page' => string, 'user' => string, 'failed' => bool]
	 */
	public static function load(array $filter, int $time_from, int $time_till, string $tab): array {
		$data = ['source' => OverviewLoader::getSourceInfo(), 'error' => null];

		if ($data['source']['origin'] === null) {
			return $data;
		}

		try {
			$stats = (new RumStats())->setApplication($filter['application']);

			switch ($tab) {
				case self::TAB_OVERVIEW:
					$data['kpis'] = $stats->getKpis($time_from, $time_till);
					OverviewLoader::section($data, 'activity', ['minutes' => [], 'sessions' => [], 'views' => [],
						'load_p75' => []], fn() => $stats->getActivity($time_from, $time_till)
					);
					OverviewLoader::section($data, 'pages', [], fn() => $stats->getPages($time_from, $time_till));
					OverviewLoader::section($data, 'requests', [], fn() => $stats->getRequests($time_from, $time_till));
					OverviewLoader::section($data, 'agents', ['browsers' => [], 'systems' => [], 'devices' => []],
						fn() => $stats->getUserAgents($time_from, $time_till)
					);

					// Web Vitals are log records: only for roles with APM → Logs.
					$no_vitals = ['overall' => [], 'pages' => [], 'devices' => [], 'hourly' => []];
					OverviewLoader::section($data, 'vitals', $no_vitals, fn() => in_array('logs', ApmAccess::signals(), true)
						? $stats->getWebVitals($time_from, $time_till)
						: $no_vitals
					);
					break;

				case self::TAB_PAGES:
					$data['pages'] = $stats->getPages($time_from, $time_till);

					if ($filter['page'] !== '') {
						OverviewLoader::section($data, 'loads', ['median' => null, 'slowest' => null],
							fn() => $stats->getPageLoads($time_from, $time_till, $filter['page'])
						);
					}
					break;

				case self::TAB_RESOURCES:
					OverviewLoader::section($data, 'resources', [], fn() => $stats->getResources($time_from, $time_till));
					OverviewLoader::section($data, 'requests', [], fn() => $stats->getRequests($time_from, $time_till));
					break;

				case self::TAB_ERRORS:
					// Same "now" window as the APM overview: one definition of active and resolved.
					$overview = Overview::getFilter('');
					$stats->setRecent($overview['recent_min'] * SEC_PER_MIN);
					$data['errors'] = $stats->getErrorGroups($time_from, $time_till, '', self::ERROR_LIMIT, true);
					$data['truncated'] = array_keys(array_filter($stats->truncated));
					$data['error_ctx'] = ['resolved' => $overview['resolved'], 'recent_min' => $overview['recent_min'],
						'time_till' => $time_till, 'browser' => true
					];
					OverviewLoader::section($data, 'requests', [], fn() => $stats->getRequests($time_from, $time_till));
					OverviewLoader::section($data, 'problems', self::problems(null, []),
						fn() => self::problems($filter['application'], $stats->getBrowserServices($time_from, $time_till))
					);
					break;

				case self::TAB_SESSIONS:
					$data['sessions'] = $stats->getSessions($time_from, $time_till, $filter);
					break;

				case self::TAB_HOWTO:
					$data['application'] = $filter['application'];
					$data['checks'] = $stats->getInstrumentationChecks(self::CHECK_WINDOW);
					break;
			}
		}
		catch (DBException $e) {
			$data['error'] = $e->getMessage();
		}

		return $data;
	}

	/**
	 * Open Zabbix problems of the browser side of an application: those tagged with one of its browser services
	 * (service = name) and those of the Zabbix host of the application. Same shape as ServiceLoader::problems().
	 *
	 * @param string[] $services  Services that send browser spans.
	 */
	public static function problems(?string $application, array $services): array {
		$own = [];

		foreach (ZabbixProblems::forServices($services) as $summary) {
			foreach ($summary['problems'] as $problem) {
				$own[$problem['eventid']] = $problem;
			}
		}

		$app_host = $application !== null && $application !== '' ? OverviewLoader::findHost($application) : null;

		return [
			'service' => ZabbixProblems::summarize(array_values($own)),
			'application' => $app_host !== null
				? ['host' => $app_host, 'problems' => ZabbixProblems::forHosts([$app_host['hostid']])]
				: null,
			'hosts' => []
		];
	}

	/**
	 * Page loads, actions and requests of one session, in time order, with the backend part of each request.
	 */
	public static function loadSession(?string $application, string $session, int $time_from, int $time_till): array {
		$data = ['source' => OverviewLoader::getSourceInfo(), 'error' => null, 'session' => $session];

		if ($data['source']['origin'] === null) {
			return $data;
		}

		try {
			$timeline = (new RumStats())
				->setApplication($application)
				->getSessionTimeline($session, $time_from, $time_till);

			$data['items'] = Rum::timeline($timeline['spans']);
			$data['spans'] = $timeline['spans'];
			$data['backend'] = $timeline['backend'];
			$data['truncated'] = $timeline['truncated'];
		}
		catch (DBException $e) {
			$data['error'] = $e->getMessage();
		}

		return $data;
	}
}

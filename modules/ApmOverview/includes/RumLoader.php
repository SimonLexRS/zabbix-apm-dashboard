<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

use DBException;

/**
 * Collects what one tab of the Real user monitoring page shows; only the active tab is queried.
 */
class RumLoader {

	public const TAB_OVERVIEW = 'overview';
	public const TAB_PAGES = 'pages';
	public const TAB_RESOURCES = 'resources';
	public const TAB_SESSIONS = 'sessions';
	public const TAB_HOWTO = 'howto';

	// Display order of the tabs.
	public const TABS = [self::TAB_OVERVIEW, self::TAB_PAGES, self::TAB_RESOURCES, self::TAB_SESSIONS, self::TAB_HOWTO];

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

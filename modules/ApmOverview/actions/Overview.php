<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Actions;

use CController,
	CControllerResponseData,
	CControllerResponseFatal,
	CProfile,
	CRoleHelper,
	CUrl;

use Modules\ApmOverview\Includes\{
	I18n,
	ApmStats,
	OverviewHtml
};

/**
 * APM overview page. Only the frame: the content of the active tab is loaded by the page script from
 * apm.overview.data, so a tab is queried when it is shown, not before.
 */
class Overview extends CController {

	public const PROFILE_IDX = 'web.apm.overview';

	public const APDEX_DEFAULT = 500;
	public const APDEX_MIN = 10;
	public const APDEX_MAX = 60000;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'filter_app' => 'string',
			'filter_service' => 'string',
			'filter_sort' => 'in '.implode(',', [ApmStats::SORT_REQUESTS, ApmStats::SORT_ERROR_RATE, ApmStats::SORT_P95]),
			'filter_apdex' => 'int32',
			'filter_set' => 'in 1',
			'filter_rst' => 'in 1',
			'tab' => 'in '.implode(',', OverviewHtml::TABS),
			'endpoint_service' => 'string',
			'from' => 'range_time',
			'to' => 'range_time'
		]) && $this->validateTimeSelectorPeriod();

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_APM_TRACES);
	}

	protected function doAction(): void {
		if ($this->hasInput('filter_set')) {
			// Another application: the service filter of the previous one does not apply.
			if ($this->hasInput('filter_app')) {
				CProfile::update(self::PROFILE_IDX.'.filter_app', $this->getInput('filter_app'), PROFILE_TYPE_STR);
				CProfile::delete(self::PROFILE_IDX.'.filter_service');
			}

			// Only what was sent: a link from the service map changes the service and keeps the rest.
			if ($this->hasInput('filter_service')) {
				CProfile::update(self::PROFILE_IDX.'.filter_service', trim($this->getInput('filter_service')),
					PROFILE_TYPE_STR
				);
			}

			if ($this->hasInput('filter_sort')) {
				CProfile::update(self::PROFILE_IDX.'.filter_sort', $this->getInput('filter_sort'), PROFILE_TYPE_STR);
			}

			if ($this->hasInput('filter_apdex')) {
				CProfile::update(self::PROFILE_IDX.'.filter_apdex',
					min(self::APDEX_MAX, max(self::APDEX_MIN, (int) $this->getInput('filter_apdex'))), PROFILE_TYPE_INT
				);
			}
		}
		elseif ($this->hasInput('filter_rst')) {
			CProfile::delete(self::PROFILE_IDX.'.filter_service');
			CProfile::delete(self::PROFILE_IDX.'.filter_sort');
			CProfile::delete(self::PROFILE_IDX.'.filter_apdex');
		}

		// The applications are listed here only, the real-time refresh (apm.overview.data) reads the stored one.
		try {
			$stats = new ApmStats();
			$applications = $stats->getApplications();
		}
		catch (\DBException $e) {
			$stats = null;
			$applications = [];
		}

		$application = ApmStats::resolveApplication($applications,
			CProfile::get(self::PROFILE_IDX.'.filter_app')
		);

		if ($application !== null) {
			CProfile::update(self::PROFILE_IDX.'.filter_app', $application, PROFILE_TYPE_STR);
		}

		$filter = self::getFilter($this->getInput('endpoint_service', ''));
		$filter['application'] = $application;

		$timeselector_options = [
			'profileIdx' => self::PROFILE_IDX.'.filter',
			'profileIdx2' => 0,
			'from' => $this->hasInput('from') ? $this->getInput('from') : null,
			'to' => $this->hasInput('to') ? $this->getInput('to') : null
		];
		updateTimeSelectorPeriod($timeselector_options);
		$timeselector = getTimeSelectorPeriod($timeselector_options);

		$data_url = (new CUrl('zabbix.php'))
			->setArgument('action', 'apm.overview.data')
			->setArgument('from', $timeselector['from'])
			->setArgument('to', $timeselector['to']);

		if ($filter['endpoint_service'] !== '') {
			$data_url->setArgument('endpoint_service', $filter['endpoint_service']);
		}

		// Suggestions of the service filter; the page works without them.
		try {
			$service_names = $stats !== null ? $stats->setApplication($application)->getServiceNames() : [];
		}
		catch (\DBException $e) {
			$service_names = [];
		}

		$response = new CControllerResponseData([
			'applications' => $applications,
			'service_names' => $service_names,
			'filter' => $filter,
			'filter_options' => [
				'idx' => self::PROFILE_IDX.'.filter',
				'timeselector' => $timeselector
			],
			'active_tab' => CProfile::get(self::PROFILE_IDX.'.filter.active', 1),
			// Without "tab" in the URL the tab view restores the last tab of this browser session.
			'selected_tab' => $this->hasInput('tab')
				? (int) array_search($this->getInput('tab'), OverviewHtml::TABS)
				: null,
			'data_url' => $data_url->getUrl()
		]);
		$response->setTitle(I18n::t('APM overview'));
		$this->setResponse($response);
	}

	/**
	 * Page filter stored in the user profile, plus the exact service of the Endpoints tab. "application" is null until
	 * the page has resolved one (no applications with data).
	 */
	public static function getFilter(string $endpoint_service): array {
		return [
			'application' => CProfile::get(self::PROFILE_IDX.'.filter_app'),
			'service' => CProfile::get(self::PROFILE_IDX.'.filter_service', ''),
			'sort' => CProfile::get(self::PROFILE_IDX.'.filter_sort', ApmStats::SORT_REQUESTS),
			'apdex_ms' => (int) CProfile::get(self::PROFILE_IDX.'.filter_apdex', self::APDEX_DEFAULT),
			'endpoint_service' => $endpoint_service
		];
	}
}

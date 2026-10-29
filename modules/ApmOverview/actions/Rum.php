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
	RumLoader,
	RumStats
};

/**
 * Real user monitoring page. Only the frame: the content of the active tab is loaded by the page script from
 * apm.rum.data, and the timeline of a session from apm.rum.session.
 */
class Rum extends CController {

	public const PROFILE_IDX = 'web.apm.rum';

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'filter_app' => 'string',
			'filter_page' => 'string',
			'filter_user' => 'string',
			'filter_failed' => 'in 0,1',
			'filter_set' => 'in 1',
			'filter_rst' => 'in 1',
			'tab' => 'in '.implode(',', RumLoader::TABS),
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
			if ($this->hasInput('filter_app')) {
				CProfile::update(self::PROFILE_IDX.'.filter_app', $this->getInput('filter_app'), PROFILE_TYPE_STR);
				CProfile::delete(self::PROFILE_IDX.'.filter_page');
			}

			foreach (['filter_page', 'filter_user'] as $field) {
				if ($this->hasInput($field)) {
					CProfile::update(self::PROFILE_IDX.'.'.$field, trim($this->getInput($field)), PROFILE_TYPE_STR);
				}
			}

			// An unchecked box is not sent: the filter form always submits the user field, links never do.
			if ($this->hasInput('filter_user')) {
				CProfile::update(self::PROFILE_IDX.'.filter_failed', (int) $this->getInput('filter_failed', 0),
					PROFILE_TYPE_INT
				);
			}
		}
		elseif ($this->hasInput('filter_rst')) {
			foreach (['filter_page', 'filter_user', 'filter_failed'] as $field) {
				CProfile::delete(self::PROFILE_IDX.'.'.$field);
			}
		}

		// The applications with browser telemetry; all of them when none has it, so that the guide shows.
		try {
			$stats = new RumStats();
			$applications = $stats->getBrowserApplications() ?: $stats->getApplications();
		}
		catch (\DBException $e) {
			$applications = [];
		}

		$application = ApmStats::resolveApplication($applications,
			CProfile::get(self::PROFILE_IDX.'.filter_app')
		);

		if ($application !== null) {
			CProfile::update(self::PROFILE_IDX.'.filter_app', $application, PROFILE_TYPE_STR);
		}

		$filter = self::getFilter();
		$filter['application'] = $application;

		$timeselector_options = [
			'profileIdx' => self::PROFILE_IDX.'.filter',
			'profileIdx2' => 0,
			'from' => $this->hasInput('from') ? $this->getInput('from') : null,
			'to' => $this->hasInput('to') ? $this->getInput('to') : null
		];
		updateTimeSelectorPeriod($timeselector_options);
		$timeselector = getTimeSelectorPeriod($timeselector_options);

		$response = new CControllerResponseData([
			'applications' => $applications,
			'filter' => $filter,
			'filter_options' => [
				'idx' => self::PROFILE_IDX.'.filter',
				'timeselector' => $timeselector
			],
			'active_tab' => CProfile::get(self::PROFILE_IDX.'.filter.active', 1),
			'selected_tab' => $this->hasInput('tab')
				? (int) array_search($this->getInput('tab'), RumLoader::TABS)
				: null,
			'data_url' => (new CUrl('zabbix.php'))
				->setArgument('action', 'apm.rum.data')
				->setArgument('from', $timeselector['from'])
				->setArgument('to', $timeselector['to'])
				->getUrl(),
			'session_url' => (new CUrl('zabbix.php'))
				->setArgument('action', 'apm.rum.session')
				->getUrl()
		]);
		$response->setTitle(I18n::t('Real user monitoring'));
		$this->setResponse($response);
	}

	/**
	 * Page filter stored in the user profile. "application" is null until the page has resolved one.
	 */
	public static function getFilter(): array {
		return [
			'application' => CProfile::get(self::PROFILE_IDX.'.filter_app'),
			'page' => CProfile::get(self::PROFILE_IDX.'.filter_page', ''),
			'user' => CProfile::get(self::PROFILE_IDX.'.filter_user', ''),
			'failed' => (bool) CProfile::get(self::PROFILE_IDX.'.filter_failed', 0)
		];
	}
}

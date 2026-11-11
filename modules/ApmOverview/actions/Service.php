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
	ServiceLoader
};

/**
 * Page of one service, or of one of its endpoints. Only the frame: the content of the active tab is loaded by the page
 * script from apm.service.data. It shares the period and the application of the APM overview page.
 */
class Service extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'service' => 'required|not_empty|string',
			'endpoint' => 'string',
			'app' => 'string',
			'tab' => 'in '.implode(',', ServiceLoader::TABS),
			'va' => 'string',
			'vb' => 'string',
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
		$service = $this->getInput('service');
		$endpoint = $this->getInput('endpoint', '');
		$application = $this->hasInput('app')
			? $this->getInput('app')
			: CProfile::get(Overview::PROFILE_IDX.'.filter_app');

		$timeselector_options = [
			'profileIdx' => Overview::PROFILE_IDX.'.filter',
			'profileIdx2' => 0,
			'from' => $this->hasInput('from') ? $this->getInput('from') : null,
			'to' => $this->hasInput('to') ? $this->getInput('to') : null
		];
		updateTimeSelectorPeriod($timeselector_options);
		$timeselector = getTimeSelectorPeriod($timeselector_options);

		$self = (new CUrl('zabbix.php'))
			->setArgument('action', 'apm.service')
			->setArgument('service', $service);
		$data_url = (new CUrl('zabbix.php'))
			->setArgument('action', 'apm.service.data')
			->setArgument('service', $service)
			->setArgument('from', $timeselector['from'])
			->setArgument('to', $timeselector['to']);

		foreach ([$self, $data_url] as $url) {
			if ($endpoint !== '') {
				$url->setArgument('endpoint', $endpoint);
			}

			if ($application !== null) {
				$url->setArgument('app', $application);
			}
		}

		foreach (['va', 'vb'] as $field) {
			if ($this->hasInput($field)) {
				$data_url->setArgument($field, $this->getInput($field));
			}
		}

		$response = new CControllerResponseData([
			'service' => $service,
			'endpoint' => $endpoint,
			'application' => $application,
			'filter_options' => [
				'idx' => Overview::PROFILE_IDX.'.filter',
				'timeselector' => $timeselector
			],
			'selected_tab' => $this->hasInput('tab') ? (int) array_search($this->getInput('tab'), ServiceLoader::TABS) : 0,
			'self_url' => $self->getUrl(),
			'data_url' => $data_url->getUrl(),
			'live' => str_starts_with((string) $timeselector['to'], 'now')
		]);
		$response->setTitle($endpoint !== '' ? $service.' · '.$endpoint : $service);
		$this->setResponse($response);
	}
}

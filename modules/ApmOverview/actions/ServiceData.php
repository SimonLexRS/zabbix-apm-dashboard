<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Actions;

use CController,
	CControllerResponseData,
	CRoleHelper;

use Modules\ApmOverview\Includes\{
	I18n,
	ServiceHtml,
	ServiceLoader
};

/**
 * Content of one tab of the page of a service or endpoint (JSON), and the service panel of the map (tab = panel).
 * The block comes rendered and escaped by the server.
 */
class ServiceData extends CController {

	public const TAB_PANEL = 'panel';

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'service' => 'required|not_empty|string',
			'endpoint' => 'string',
			'app' => 'string',
			'tab' => 'in '.implode(',', array_merge(ServiceLoader::TABS, [self::TAB_PANEL])),
			'va' => 'string',
			'vb' => 'string',
			'from' => 'range_time',
			'to' => 'range_time'
		]) && $this->validateTimeSelectorPeriod();

		if (!$ret) {
			$this->setResponse(
				(new CControllerResponseData(['main_block' => json_encode([
					'error' => I18n::t('Invalid request.')
				])]))->disableView()
			);
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_APM_TRACES);
	}

	protected function doAction(): void {
		// The service panel is asked for by the map, without a tab.
		$tab = $this->getAction() === 'apm.service.panel' ? self::TAB_PANEL : $this->getInput('tab', ServiceLoader::TAB_OVERVIEW);
		$service = $this->getInput('service');
		$endpoint = $this->getInput('endpoint', '');

		$filter = Overview::getFilter('');

		// The application of the link; without one, the one of the APM overview page.
		if ($this->hasInput('app')) {
			$filter['application'] = $this->getInput('app');
		}

		// Read-only: the period of the APM overview page, which moves forward on every call when it is relative.
		$timeselector = getTimeSelectorPeriod([
			'profileIdx' => Overview::PROFILE_IDX.'.filter',
			'profileIdx2' => 0,
			'from' => $this->hasInput('from') ? $this->getInput('from') : null,
			'to' => $this->hasInput('to') ? $this->getInput('to') : null
		]);

		$ctx = ['from' => $timeselector['from'], 'to' => $timeselector['to'], 'apdex_ms' => $filter['apdex_ms']];

		if ($tab === self::TAB_PANEL) {
			$data = ServiceLoader::panel($filter, $service, $timeselector['from_ts'], $timeselector['to_ts']);
			$html = ServiceHtml::panel($data, $ctx);
		}
		else {
			$data = ServiceLoader::load($filter, $service, $endpoint, $timeselector['from_ts'], $timeselector['to_ts'],
				$tab, ['a' => $this->getInput('va', ''), 'b' => $this->getInput('vb', '')]
			);
			$html = ServiceHtml::renderBlock($tab, $data, $ctx);
		}

		$this->setResponse(
			(new CControllerResponseData(['main_block' => json_encode([
				'tab' => $tab,
				'error' => $data['error'],
				'html' => $html->toString()
			], JSON_INVALID_UTF8_SUBSTITUTE)]))->disableView()
		);
	}
}

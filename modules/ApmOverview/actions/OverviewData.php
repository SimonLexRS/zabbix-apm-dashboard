<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Actions;

use CController,
	CControllerResponseData,
	CRoleHelper;

use Modules\ApmOverview\Includes\{
	I18n,
	ApmStats,
	OverviewHtml,
	OverviewLoader
};

/**
 * Content of one tab of the APM overview page (JSON), for the first load of the tab and the real-time refresh. The
 * block comes already rendered (and escaped) by the server, the page script only swaps it in place.
 */
class OverviewData extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'tab' => 'required|in '.implode(',', OverviewHtml::DATA_TABS),
			'endpoint_service' => 'string',
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
		$tab = $this->getInput('tab');
		$filter = Overview::getFilter($this->getInput('endpoint_service', ''));

		// Read-only: the time selector profile is not updated, relative periods ("now-1h") move forward on every call.
		$timeselector = getTimeSelectorPeriod([
			'profileIdx' => Overview::PROFILE_IDX.'.filter',
			'profileIdx2' => 0,
			'from' => $this->hasInput('from') ? $this->getInput('from') : null,
			'to' => $this->hasInput('to') ? $this->getInput('to') : null
		]);

		$data = OverviewLoader::load($filter, $timeselector['from_ts'], $timeselector['to_ts'], $tab);

		$html = OverviewHtml::renderBlock($tab, $data, [
			'from' => $timeselector['from'],
			'to' => $timeselector['to'],
			'endpoint_service' => $filter['endpoint_service'],
			'apdex_ms' => $filter['apdex_ms'],
			'focus' => $filter['service'],
			'join_capped' => $timeselector['to_ts'] - $timeselector['from_ts'] > ApmStats::JOIN_WINDOW
		]);

		$this->setResponse(
			(new CControllerResponseData(['main_block' => json_encode([
				'tab' => $tab,
				'error' => $data['error'],
				'html' => $html->toString()
			], JSON_INVALID_UTF8_SUBSTITUTE)]))->disableView()
		);
	}
}

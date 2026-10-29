<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Actions;

use CController,
	CControllerResponseData,
	CRoleHelper;

use Modules\ApmOverview\Includes\{
	I18n,
	RumHtml,
	RumLoader
};

/**
 * Content of one tab of the Real user monitoring page (JSON), for the first load of the tab and the real-time refresh.
 */
class RumData extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'tab' => 'required|in '.implode(',', RumLoader::TABS),
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
		$filter = Rum::getFilter();

		// Read-only: the time selector profile is not updated, relative periods ("now-1h") move forward on every call.
		$timeselector = getTimeSelectorPeriod([
			'profileIdx' => Rum::PROFILE_IDX.'.filter',
			'profileIdx2' => 0,
			'from' => $this->hasInput('from') ? $this->getInput('from') : null,
			'to' => $this->hasInput('to') ? $this->getInput('to') : null
		]);

		$data = RumLoader::load($filter, $timeselector['from_ts'], $timeselector['to_ts'], $tab);

		$html = RumHtml::renderBlock($tab, $data, [
			'from' => $timeselector['from'],
			'to' => $timeselector['to'],
			'page' => $filter['page'],
			'application' => $filter['application']
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

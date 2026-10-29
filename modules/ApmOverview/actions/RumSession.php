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
 * Timeline of one session (JSON with rendered HTML), opened from the Sessions tab of the Real user monitoring page.
 */
class RumSession extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'session' => 'required|not_empty|string',
			'from' => 'required|int32',
			'to' => 'required|int32'
		]) && $this->getInput('from') < $this->getInput('to');

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
		// The session's own time span, at most one day: the timeline query reads only those minutes.
		$time_till = (int) $this->getInput('to');
		$time_from = max((int) $this->getInput('from'), $time_till - SEC_PER_DAY);

		$data = RumLoader::loadSession(Rum::getFilter()['application'], $this->getInput('session'), $time_from,
			$time_till
		);

		$this->setResponse(
			(new CControllerResponseData(['main_block' => json_encode([
				'error' => $data['error'],
				'html' => RumHtml::session($data)->toString()
			], JSON_INVALID_UTF8_SUBSTITUTE)]))->disableView()
		);
	}
}

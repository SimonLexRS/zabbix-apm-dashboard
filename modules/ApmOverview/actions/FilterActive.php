<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Actions;

use CController,
	CControllerResponseData,
	CProfile,
	CRoleHelper;

use Modules\ApmOverview\Includes\I18n;

/**
 * Saves the active tab of the overview page filter (0 = collapsed). The core profile.update only accepts the profiles
 * of its own pages, so the page script sends this one here.
 */
class FilterActive extends CController {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'value_int' => 'required|in 0,1,2'
		]);

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
		CProfile::update(Overview::PROFILE_IDX.'.filter.active', $this->getInput('value_int'), PROFILE_TYPE_INT);

		$this->setResponse(
			(new CControllerResponseData(['main_block' => json_encode(['result' => true])]))->disableView()
		);
	}
}

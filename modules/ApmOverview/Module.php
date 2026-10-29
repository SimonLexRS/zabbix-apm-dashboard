<?php declare(strict_types = 0);

namespace Modules\ApmOverview;

use APP,
	CControllerTimeSelectorUpdate,
	CMenuItem,
	CRoleHelper,
	CWebUser;

use Modules\ApmOverview\Actions\Overview;

use Zabbix\Core\CModule;

class Module extends CModule {

	public function init(): void {
		// The core time selector only saves the period of the profiles it lists.
		CControllerTimeSelectorUpdate::$profiles[] = Overview::PROFILE_IDX.'.filter';

		if (!CWebUser::checkAccess(CRoleHelper::UI_APM_TRACES)) {
			return;
		}

		// The native "APM" menu exists only when the user has access to at least one APM section.
		$apm_menu = APP::Component()->get('menu.main')->find(_('APM'));

		if ($apm_menu !== null && $apm_menu->hasSubMenu()) {
			$apm_menu->getSubMenu()->insertBefore(_('Traces'),
				(new CMenuItem(_('Overview')))->setAction('apm.overview')
			);
		}
	}
}

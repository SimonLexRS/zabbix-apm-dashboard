<?php declare(strict_types = 0);

namespace Modules\ApmOverview;

use APP,
	CControllerTimeSelectorUpdate,
	CMenuItem,
	CRoleHelper,
	CWebUser;

use Modules\ApmOverview\Actions\{
	Overview,
	Rum
};

use Modules\ApmOverview\Includes\I18n;

use Zabbix\Core\CModule;

class Module extends CModule {

	public function init(): void {
		// The core time selector only saves the period of the profiles it lists.
		CControllerTimeSelectorUpdate::$profiles[] = Overview::PROFILE_IDX.'.filter';
		CControllerTimeSelectorUpdate::$profiles[] = Rum::PROFILE_IDX.'.filter';

		if (!CWebUser::checkAccess(CRoleHelper::UI_APM_TRACES)) {
			return;
		}

		// The native "APM" menu exists only when the user has access to at least one APM section.
		$apm_menu = APP::Component()->get('menu.main')->find(_('APM'));

		if ($apm_menu !== null && $apm_menu->hasSubMenu()) {
			$sub_menu = $apm_menu->getSubMenu();
			$sub_menu->insertBefore(_('Traces'), (new CMenuItem(_('Overview')))->setAction('apm.overview'));
			$sub_menu->insertBefore(_('Traces'),
				(new CMenuItem(I18n::t('Real user monitoring')))->setAction('apm.rum')
			);
		}
	}
}

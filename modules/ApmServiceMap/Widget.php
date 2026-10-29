<?php declare(strict_types = 0);

namespace Modules\ApmServiceMap;

use Modules\ApmServiceMap\Includes\Lang as I18n;
use Zabbix\Core\CWidget;

class Widget extends CWidget {

	public function getDefaultName(): string {
		return I18n::t('APM service map');
	}
}

<?php declare(strict_types = 0);

namespace Modules\ApmServiceMap\Includes;

use Zabbix\Widgets\{
	CWidgetField,
	CWidgetForm
};

use Zabbix\Widgets\Fields\{
	CWidgetFieldIntegerBox,
	CWidgetFieldNumericBox,
	CWidgetFieldTextBox,
	CWidgetFieldTimePeriod
};

use CWidgetsData;
use Modules\ApmServiceMap\Includes\Lang as I18n;

/**
 * APM service map widget form.
 */
class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
			->addField(
				(new CWidgetFieldTextBox('application', I18n::t('Application')))->setMaxLength(255)
			)
			->addField(
				(new CWidgetFieldTextBox('service', I18n::t('Service')))->setMaxLength(255)
			)
			->addField(
				(new CWidgetFieldIntegerBox('latency_warning', I18n::t('p95 warning (ms)'), 0, ZBX_MAX_INT32))
					->setDefault(1000)
			)
			->addField(
				(new CWidgetFieldIntegerBox('latency_high', I18n::t('p95 problem (ms)'), 0, ZBX_MAX_INT32))
					->setDefault(3000)
			)
			->addField(
				(new CWidgetFieldNumericBox('error_warning', I18n::t('Error rate warning (%)')))->setDefault('1')
			)
			->addField(
				(new CWidgetFieldNumericBox('error_high', I18n::t('Error rate problem (%)')))->setDefault('5')
			)
			->addField(
				(new CWidgetFieldTimePeriod('time_period', I18n::t('Time period')))
					->setDefault([
						CWidgetField::FOREIGN_REFERENCE_KEY => CWidgetField::createTypedReference(
							CWidgetField::REFERENCE_DASHBOARD, CWidgetsData::DATA_TYPE_TIME_PERIOD
						)
					])
					->setDefaultPeriod(['from' => 'now-1h', 'to' => 'now'])
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			);
	}
}

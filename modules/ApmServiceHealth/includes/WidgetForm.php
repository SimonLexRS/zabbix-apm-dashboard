<?php declare(strict_types = 0);

namespace Modules\ApmServiceHealth\Includes;

use Zabbix\Widgets\{
	CWidgetField,
	CWidgetForm
};

use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBoxList,
	CWidgetFieldIntegerBox,
	CWidgetFieldNumericBox,
	CWidgetFieldSelect,
	CWidgetFieldTextBox,
	CWidgetFieldTimePeriod
};

use CWidgetsData;

/**
 * APM service health widget form.
 */
class WidgetForm extends CWidgetForm {

	public const COLUMN_REQUESTS = 0;
	public const COLUMN_RPM = 1;
	public const COLUMN_ERRORS = 2;
	public const COLUMN_ERROR_RATE = 3;
	public const COLUMN_P50 = 4;
	public const COLUMN_P95 = 5;
	public const COLUMN_P99 = 6;

	public const SORT_REQUESTS = 0;
	public const SORT_ERROR_RATE = 1;
	public const SORT_P95 = 2;

	private const DEFAULT_SHOW_LINES = 10;

	public function addFields(): self {
		return $this
			->addField(
				(new CWidgetFieldTextBox('application', I18n::t('Application')))->setMaxLength(255)
			)
			->addField(
				(new CWidgetFieldTextBox('service', I18n::t('Service name')))->setMaxLength(255)
			)
			->addField(
				(new CWidgetFieldCheckBoxList('columns', I18n::t('Columns'), [
					self::COLUMN_REQUESTS => I18n::t('Requests'),
					self::COLUMN_RPM => _('Req/min'),
					self::COLUMN_ERRORS => I18n::t('Errors'),
					self::COLUMN_ERROR_RATE => I18n::t('Error rate'),
					self::COLUMN_P50 => _('p50'),
					self::COLUMN_P95 => _('p95'),
					self::COLUMN_P99 => _('p99')
				]))
					->setDefault([self::COLUMN_REQUESTS, self::COLUMN_RPM, self::COLUMN_ERROR_RATE, self::COLUMN_P50,
						self::COLUMN_P95, self::COLUMN_P99
					])
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldSelect('sort_by', I18n::t('Sort by'), [
					self::SORT_REQUESTS => I18n::t('Requests'),
					self::SORT_ERROR_RATE => I18n::t('Error rate'),
					self::SORT_P95 => I18n::t('Latency p95')
				]))->setDefault(self::SORT_REQUESTS)
			)
			->addField(
				(new CWidgetFieldIntegerBox('show_lines', I18n::t('Service limit'), ZBX_MIN_WIDGET_LINES,
					ZBX_MAX_WIDGET_LINES
				))
					->setDefault(self::DEFAULT_SHOW_LINES)
					->setFlags(CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldIntegerBox('latency_warning', I18n::t('Latency p95 warning (ms)'), 0, ZBX_MAX_INT32))
					->setDefault(500)
			)
			->addField(
				(new CWidgetFieldIntegerBox('latency_high', I18n::t('Latency p95 high (ms)'), 0, ZBX_MAX_INT32))
					->setDefault(1000)
			)
			->addField(
				(new CWidgetFieldNumericBox('error_warning', I18n::t('Error rate warning (%)')))->setDefault('1')
			)
			->addField(
				(new CWidgetFieldNumericBox('error_high', I18n::t('Error rate high (%)')))->setDefault('5')
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

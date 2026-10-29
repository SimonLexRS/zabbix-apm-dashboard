<?php declare(strict_types = 0);

/**
 * APM service map widget form view.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\ApmServiceMap\Includes\Lang as I18n;

(new CWidgetFormView($data))
	->addField(
		(new CWidgetFieldTextBoxView($data['fields']['service']))->setPlaceholder(I18n::t('all; e.g. checkout or demo-*'))
	)
	->addFieldset(
		(new CWidgetFormFieldsetCollapsibleView(I18n::t('Thresholds')))
			->addField(
				new CWidgetFieldIntegerBoxView($data['fields']['latency_warning'])
			)
			->addField(
				new CWidgetFieldIntegerBoxView($data['fields']['latency_high'])
			)
			->addField(
				new CWidgetFieldNumericBoxView($data['fields']['error_warning'])
			)
			->addField(
				new CWidgetFieldNumericBoxView($data['fields']['error_high'])
			)
	)
	->addField(
		(new CWidgetFieldTimePeriodView($data['fields']['time_period']))
			->setDateFormat(ZBX_FULL_DATE_TIME)
			->setFromPlaceholder(_('YYYY-MM-DD hh:mm:ss'))
			->setToPlaceholder(_('YYYY-MM-DD hh:mm:ss'))
	)
	->show();

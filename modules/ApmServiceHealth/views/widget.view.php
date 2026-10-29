<?php declare(strict_types = 0);

/**
 * APM service health widget view.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\ApmServiceHealth\Includes\{
	I18n,
	WidgetForm
};

$fields = $data['fields_values'];
$columns = array_flip($fields['columns']);

$formatDuration = static fn (float $ns): string => convertUnits(['value' => $ns / 1000000000, 'units' => 's']);

$getSeverityStyle = static function (float $value, float $warning, float $high): ?string {
	if ($high > 0 && $value >= $high) {
		return CSeverityHelper::getStyle(TRIGGER_SEVERITY_HIGH);
	}

	if ($warning > 0 && $value >= $warning) {
		return CSeverityHelper::getStyle(TRIGGER_SEVERITY_WARNING);
	}

	return null;
};

// Inline SVG sparkline, values scaled from 0 to the maximum of the series. CTag escapes the title.
$makeSparkline = static function (array $values, string $title): CTag {
	$width = 80;
	$height = 20;
	$padding = 2;

	$svg = (new CTag('svg', true))
		->addClass('apm-shw-spark')
		->setAttribute('width', (string) $width)
		->setAttribute('height', (string) $height)
		->setAttribute('viewBox', '0 0 '.$width.' '.$height)
		->setAttribute('preserveAspectRatio', 'none')
		->setAttribute('role', 'img')
		->setAttribute('aria-label', $title)
		->addItem(new CTag('title', true, $title));

	$values = array_values($values);
	$count = count($values);

	if ($count < 2) {
		return $svg;
	}

	$max = max($values);
	$points = [];

	foreach ($values as $i => $value) {
		$x = round($i * $width / ($count - 1), 1);
		$y = $max > 0 ? round($height - $padding - $value / $max * ($height - 2 * $padding), 1) : $height - $padding;

		$points[] = $x.','.$y;
	}

	return $svg->addItem((new CTag('polyline', true))->setAttribute('points', implode(' ', $points)));
};

$header = [I18n::t('Service')];

foreach ([
	WidgetForm::COLUMN_REQUESTS => I18n::t('Requests'),
	WidgetForm::COLUMN_RPM => _('Req/min'),
	WidgetForm::COLUMN_ERRORS => I18n::t('Errors'),
	WidgetForm::COLUMN_ERROR_RATE => I18n::t('Error rate'),
	WidgetForm::COLUMN_P50 => _('p50'),
	WidgetForm::COLUMN_P95 => _('p95'),
	WidgetForm::COLUMN_P99 => _('p99')
] as $column => $label) {
	if (array_key_exists($column, $columns)) {
		$header[] = $label;
	}
}

$header[] = I18n::t('Trend');

$table = (new CTableInfo())
	->setHeader($header)
	->addClass(ZBX_STYLE_LIST_TABLE_STICKY_HEADER)
	->addClass('apm-service-health');

if ($data['error'] !== null) {
	$table->setNoDataMessage($data['error'], null, ZBX_ICON_SEARCH_LARGE);
}
else {
	$latency_warning = $fields['latency_warning'] * 1000000;
	$latency_high = $fields['latency_high'] * 1000000;
	$error_warning = (float) $fields['error_warning'];
	$error_high = (float) $fields['error_high'];

	$has_overview = class_exists('Modules\ApmOverview\Module');

	foreach ($data['services'] as $service) {
		$trace_url = (new CUrl('zabbix.php'))
			->setArgument('action', 'apm.trace.list')
			->setArgument('filter_service_name', $service['service'])
			->setArgument('filter_set', 1)
			->setArgument('from', $fields['time_period']['from'])
			->setArgument('to', $fields['time_period']['to']);

		// The service opens in the APM overview (where its time goes) when that module is enabled, else its traces.
		$name = $has_overview
			? (new CLink($service['service'], (new CUrl('zabbix.php'))
				->setArgument('action', 'apm.overview')
				->setArgument('tab', 'slow')
				->setArgument('filter_service', '='.$service['service'])
				->setArgument('filter_set', 1)
				->getUrl()
			))->setTitle(I18n::t('Open the service in the APM overview'))
			: (new CLink($service['service'], $trace_url->getUrl()))->setTitle(I18n::t('Open traces of this service'));

		$row = [
			(new CCol([
				$name,
				$has_overview
					? [' ', (new CLink(I18n::t('Traces'), $trace_url->getUrl()))
						->addClass(ZBX_STYLE_GREY)
						->setTitle(I18n::t('Open traces of this service'))
					]
					: null
			]))->addClass(ZBX_STYLE_WORDBREAK)
		];

		if (array_key_exists(WidgetForm::COLUMN_REQUESTS, $columns)) {
			$row[] = number_format($service['requests'], 0, '.', ' ');
		}

		if (array_key_exists(WidgetForm::COLUMN_RPM, $columns)) {
			$row[] = sprintf('%.2f', $service['rpm']);
		}

		if (array_key_exists(WidgetForm::COLUMN_ERRORS, $columns)) {
			$row[] = $service['errors'];
		}

		if (array_key_exists(WidgetForm::COLUMN_ERROR_RATE, $columns)) {
			$row[] = (new CCol(sprintf('%.2f %%', $service['error_rate'])))
				->addClass($getSeverityStyle($service['error_rate'], $error_warning, $error_high));
		}

		if (array_key_exists(WidgetForm::COLUMN_P50, $columns)) {
			$row[] = $formatDuration($service['p50']);
		}

		if (array_key_exists(WidgetForm::COLUMN_P95, $columns)) {
			$row[] = (new CCol($formatDuration($service['p95'])))
				->addClass($getSeverityStyle($service['p95'], $latency_warning, $latency_high));
		}

		if (array_key_exists(WidgetForm::COLUMN_P99, $columns)) {
			$row[] = $formatDuration($service['p99']);
		}

		$series = $data['series'][$service['service']] ?? null;

		$row[] = $series !== null
			? $makeSparkline($series, sprintf(I18n::t('Requests/min, last %d min (max %d)'), count($series), max($series)))
			: '';

		$table->addRow($row);
	}
}

$view = new CWidgetView($data);

if ($data['info']) {
	$view->setVar('info', $data['info']);
}

$view
	->addItem($table)
	->show();

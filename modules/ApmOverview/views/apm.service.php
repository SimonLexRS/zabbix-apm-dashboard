<?php declare(strict_types = 0);

/**
 * Page of one service or endpoint: period, real-time control and tabs. The content of each tab is loaded by
 * apm-overview.js when the tab is shown (apm.service.data).
 *
 * @var CView $this
 * @var array $data
 */

use Modules\ApmOverview\Includes\{
	I18n,
	OverviewHtml,
	ServiceLoader,
	ServiceMap
};

$this->addJsFile('gtlc.js');

$timeselector = $data['filter_options']['timeselector'];

$filter = (new CFilter())
	->setResetUrl(new CUrl($data['self_url']))
	->setProfile($data['filter_options']['idx'])
	->addTimeSelector($timeselector['from'], $timeselector['to']);

$live_intervals = (new CTag('ul', true))
	->setId('apm-live-interval')
	->addClass('radio-list-control');

foreach (['10' => '10 s', '30' => '30 s', '0' => I18n::t('Pause')] as $value => $label) {
	$live_intervals->addItem(new CTag('li', true, [
		(new CInput('radio', 'apm_live_interval', (string) $value))->setId('apm-live-interval-'.$value),
		new CLabel($label, 'apm-live-interval-'.$value)
	]));
}

$live_control = (new CDiv([
	new CSpan(I18n::t('Real time:')),
	$live_intervals,
	(new CSpan())
		->setId('apm-live-status')
		->addClass(ZBX_STYLE_GREY)
		->setAttribute('aria-live', 'polite')
]))->addClass('apm-overview-live');

$block = static fn(string $tab): CDiv => (new CDiv(
	(new CDiv(I18n::t('Loading…')))->addClass('apm-overview-loading')
))->setAttribute('data-apm-block', $tab);

$ctx = ['application' => $data['application']];

// Where this is: application → service → endpoint, each a link.
$path = (new CDiv([
	new CLink($data['application'] !== null && $data['application'] !== '' ? $data['application'] : I18n::t('APM overview'),
		(new CUrl('zabbix.php'))->setArgument('action', 'apm.overview')->setArgument('tab', OverviewHtml::TAB_MAP)
	),
	' / ',
	$data['endpoint'] !== ''
		? new CLink($data['service'], ServiceMap::getServicePageUrl($data['service'], $ctx))
		: new CTag('strong', true, $data['service']),
	$data['endpoint'] !== '' ? [' / ', new CTag('strong', true, $data['endpoint'])] : null
]))->addClass('apm-service-path');

// Tab order must match ServiceLoader::TABS.
$tabs = (new CTabView())
	->addTab('apm-service-overview', I18n::t('Overview'), $block(ServiceLoader::TAB_OVERVIEW))
	->addTab('apm-service-errors', $data['endpoint'] !== '' ? I18n::t('Errors') : I18n::t('Errors and versions'), [
		(new CDiv(I18n::t('Failures of this service, each with its state, and its newest version against the one before: what is slower, and which errors came or went with it.')))
			->addClass(ZBX_STYLE_GREY)
			->addClass('apm-overview-hint'),
		$block(ServiceLoader::TAB_ERRORS)
	])
	->addTab('apm-service-analysis', I18n::t('Why slow, why failing'), $block(ServiceLoader::TAB_ANALYSIS))
	->setSelected($data['selected_tab']);

(new CHtmlPage())
	->setTitle($data['endpoint'] !== '' ? $data['endpoint'] : $data['service'])
	->setControls(
		(new CTag('nav', true, $live_control))->setAttribute('aria-label', I18n::t('Content controls'))
	)
	->addItem($filter)
	->addItem((new CDiv([$path, $tabs]))->addClass('apm-overview'))
	->show();

(new CScriptTag('
	$.subscribe("timeselector.rangeupdate", (e, data) => {
		if (data.idx === '.json_encode($data['filter_options']['idx']).') {
			location.href = '.json_encode($data['self_url']).';
		}
	});

	if (typeof CApmOverviewLive === "function") {
		new CApmOverviewLive('.json_encode([
			'data_url' => $data['data_url'],
			'live' => $data['live'],
			'labels' => [
				'fixed' => I18n::t('fixed period, not refreshed'),
				'stale' => I18n::t('Shown as it was at the last successful refresh.'),
				'updated_s' => I18n::t('Updated %s s ago'),
				'updated_min' => I18n::t('Updated %s min ago'),
				'loading' => I18n::t('loading…'),
				'refreshing' => I18n::t('refreshing…'),
				'paused' => I18n::t('paused'),
				'source_error' => I18n::t('data source error'),
				'no_response' => I18n::t('no response from the server'),
				'failed' => I18n::t('could not refresh')
			]
		]).');
	}
'))
	->setOnDocumentReady()
	->show();

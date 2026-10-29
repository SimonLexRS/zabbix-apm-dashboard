<?php declare(strict_types = 0);

/**
 * Real user monitoring page: filter, real-time control, tabs and the side panel of a session. The content of each tab
 * is loaded by apm-overview.js when the tab is shown (apm.rum.data).
 *
 * @var CView $this
 * @var array $data
 */

use Modules\ApmOverview\Includes\{
	I18n,
	OverviewHtml,
	RumHtml,
	RumLoader
};

$this->addJsFile('gtlc.js');

$timeselector = $data['filter_options']['timeselector'];

$filter = (new CFilter())
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'apm.rum'))
	->setProfile($data['filter_options']['idx'])
	->setActiveTab($data['active_tab'])
	->addVar('action', 'apm.rum', 'filter_action')
	->addTimeSelector($timeselector['from'], $timeselector['to'])
	->addFilterTab(I18n::t('Filter'), [
		(new CFormGrid())
			->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
			->addItem([
				new CLabel(I18n::t('Page'), 'filter_page'),
				new CFormField(
					(new CTextBox('filter_page', $data['filter']['page']))
						->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
						->setAttribute('placeholder', I18n::t('all; e.g. /product/:id'))
				)
			])
			->addItem([
				new CLabel(I18n::t('User (enduser.id)'), 'filter_user'),
				new CFormField(
					(new CTextBox('filter_user', $data['filter']['user']))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
				)
			])
			->addItem([
				new CLabel(I18n::t('Only sessions with failed requests'), 'filter_failed'),
				new CFormField(
					(new CCheckBox('filter_failed'))->setChecked($data['filter']['failed'])
				)
			])
	]);

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

$hint = static fn(string $text): CDiv => (new CDiv($text))
	->addClass(ZBX_STYLE_GREY)
	->addClass('apm-overview-hint');

$block = static fn(string $tab): CDiv => (new CDiv(
	(new CDiv(I18n::t('Loading…')))->addClass('apm-overview-loading')
))->setAttribute('data-apm-block', $tab);

$filter_notes = [];

if ($data['filter']['page'] !== '') {
	$filter_notes[] = sprintf(I18n::t('page %s'), $data['filter']['page']);
}

if ($data['filter']['user'] !== '') {
	$filter_notes[] = sprintf(I18n::t('user %s'), $data['filter']['user']);
}

if ($data['filter']['failed']) {
	$filter_notes[] = I18n::t('sessions with failed requests');
}

$filter_note = $filter_notes
	? (new CDiv([
		new CSpan(I18n::t('Filtering by: ').implode(' · ', $filter_notes).'. '),
		(new CLink(I18n::t('Clear filter'), (new CUrl('zabbix.php'))
			->setArgument('action', 'apm.rum')
			->setArgument('filter_rst', 1)
			->getUrl()
		))->addClass(ZBX_STYLE_BTN_ALT)
	]))->addClass('apm-overview-service-filter')
	: null;

$howto_tab = [
	(new CSection([
		$hint(I18n::t('This page reads the spans of the OpenTelemetry web SDK (telemetry.sdk.language = webjs) that the application already sends: documentLoad for page loads, the user interaction spans for actions, client spans for requests, session.id and enduser.id for sessions and users. Nothing else is installed.')),
		RumHtml::guide($data['filter']['application'])
	]))->setHeader(new CTag('h4', true, I18n::t('Connect the browser of an application'))),
	(new CSection($block(RumLoader::TAB_HOWTO)))->setHeader(new CTag('h4', true, I18n::t('Is it working?'))),
	(new CSection((new CList())
		->addItem(I18n::t('Route changes of single page applications do not create page loads with the standard instrumentation: they appear as actions and requests.'))
		->addItem(I18n::t('Without traceparent propagation, requests show no backend trace.'))
		->addItem(I18n::t('Session replay, browser profiling and geography are not part of OpenTelemetry data.'))
	))->setHeader(new CTag('h4', true, I18n::t('Limits')))
];

// Tab order must match RumLoader::TABS.
$tabs = (new CTabView())
	->addTab('apm-rum-overview', I18n::t('Overview'), $block(RumLoader::TAB_OVERVIEW))
	->addTab('apm-rum-pages', I18n::t('Pages'), [
		$hint(I18n::t('One row per page (path, with ids folded into :id). Open a page to see how its typical and its slowest load spend the time.')),
		$block(RumLoader::TAB_PAGES)
	])
	->addTab('apm-rum-resources', I18n::t('Resources'), $block(RumLoader::TAB_RESOURCES))
	->addTab('apm-rum-sessions', I18n::t('Sessions'), $block(RumLoader::TAB_SESSIONS))
	->addTab('apm-rum-howto', I18n::t('How it works'), $howto_tab);

if ($data['selected_tab'] !== null) {
	$tabs->setSelected($data['selected_tab']);
}

$app_tabs = null;

if ($data['applications']) {
	$app_tabs = (new CList())->addClass('apm-app-tabs');

	foreach ($data['applications'] as $application) {
		$label = $application !== '' ? $application : I18n::t('No application');

		// Applications without browser telemetry stay reachable (to connect them), muted.
		$muted = !in_array($application, $data['browser_applications'], true);

		if ($application === $data['filter']['application']) {
			$app_tabs->addItem((new CSpan($label))->setAttribute('aria-current', 'page'),
				$muted ? 'apm-app-tab-active apm-app-tab-muted' : 'apm-app-tab-active'
			);
		}
		else {
			$app_tabs->addItem(
				(new CLink($label, (new CUrl('zabbix.php'))
					->setArgument('action', 'apm.rum')
					->setArgument('filter_set', 1)
					->setArgument('filter_app', $application)
					->getUrl()
				))->setTitle($muted ? I18n::t('No browser telemetry yet') : null),
				$muted ? 'apm-app-tab-muted' : null
			);
		}
	}

	$app_tabs = (new CTag('nav', true, $app_tabs))->setAttribute('aria-label', I18n::t('Applications'));
}

// Side panel with the timeline of a session, filled by CApmRumSessions.
$panel = (new CTag('aside', true, [
	(new CDiv([
		(new CTag('h4', true, I18n::t('Session timeline')))->setId('apm-rum-panel-title'),
		(new CTag('button', true, '×'))
			->setAttribute('type', 'button')
			->addClass('apm-rum-panel-close')
			->setAttribute('aria-label', I18n::t('Close'))
	]))->addClass('apm-rum-panel-head'),
	(new CDiv())->addClass('apm-rum-panel-body')
]))
	->setId('apm-rum-panel')
	->addClass('apm-rum-panel')
	->setAttribute('aria-labelledby', 'apm-rum-panel-title')
	->setAttribute('hidden', 'hidden');

(new CHtmlPage())
	->setTitle(I18n::t('Real user monitoring'))
	->setDocUrl(OverviewHtml::DOCS_URL.'/blob/main/docs/rum.md')
	->setControls(
		(new CTag('nav', true, $live_control))->setAttribute('aria-label', I18n::t('Content controls'))
	)
	->addItem($app_tabs)
	->addItem($filter)
	->addItem((new CDiv([$filter_note, $tabs]))->addClass('apm-overview'))
	->addItem($panel)
	->show();

(new CScriptTag('
	$.subscribe("timeselector.rangeupdate", (e, data) => {
		if (data.idx === '.json_encode($data['filter_options']['idx']).') {
			location.href = '.json_encode((new CUrl('zabbix.php'))->setArgument('action', 'apm.rum')->getUrl()).';
		}
	});

	// The core profile.update rejects the profiles of module pages: the active filter tab is saved by the module.
	const update_user_profile = window.updateUserProfile;

	window.updateUserProfile = (idx, value, ...args) => {
		if (idx !== '.json_encode($data['filter_options']['idx'].'.active').') {
			return update_user_profile(idx, value, ...args);
		}

		const body = new FormData();
		body.set("value_int", value);
		body.set("page", "rum");
		body.set(CSRF_TOKEN_NAME, '.json_encode(CCsrfTokenHelper::get('apm.overview.filter.active')).');

		return fetch('.json_encode((new CUrl('zabbix.php'))->setArgument('action', 'apm.overview.filter.active')->getUrl()).',
			{method: "POST", body}
		);
	};

	if (typeof CApmOverviewLive === "function") {
		new CApmOverviewLive('.json_encode([
			'data_url' => $data['data_url'],
			'labels' => [
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

	if (typeof CApmOverviewGuide === "function") {
		new CApmOverviewGuide({service_suffix: "-web", storage: "apm.rum.guide"});
	}

	if (typeof CApmRumSessions === "function") {
		new CApmRumSessions('.json_encode([
			'session_url' => $data['session_url'],
			'labels' => [
				'loading' => I18n::t('Loading…'),
				'failed' => I18n::t('The session could not be loaded.')
			]
		]).');
	}
'))
	->setOnDocumentReady()
	->show();

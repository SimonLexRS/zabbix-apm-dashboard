<?php declare(strict_types = 0);

/**
 * APM overview page: filter, real-time control and tabs. The content of each tab is loaded by apm-overview.js when the
 * tab is shown (apm.overview.data), except "Cómo funciona", which is static.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\ApmOverview\Actions\Overview;
use Modules\ApmOverview\Includes\{
	I18n,
	ApmStats,
	Integration,
	OverviewHtml
};

$this->addJsFile('gtlc.js');

$timeselector = $data['filter_options']['timeselector'];

$filter = (new CFilter())
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'apm.overview'))
	->setProfile($data['filter_options']['idx'])
	->setActiveTab($data['active_tab'])
	->addVar('action', 'apm.overview', 'filter_action')
	->addTimeSelector($timeselector['from'], $timeselector['to'])
	->addFilterTab(I18n::t('Filter'), [
		(new CFormGrid())
			->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
			->addItem([
				new CLabel(I18n::t('Service'), 'filter_service'),
				new CFormField(
					(new CTextBox('filter_service', $data['filter']['service']))
						->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
						->setAttribute('list', 'apm-service-names')
						->setAttribute('autocomplete', 'off')
						->setAttribute('placeholder', I18n::t('all; e.g. checkout, demo-* or =api (exact)'))
				)
			])
			->addItem([
				new CLabel(I18n::t('Sort by'), 'label-filter-sort'),
				new CFormField(
					(new CSelect('filter_sort'))
						->setFocusableElementId('label-filter-sort')
						->setValue($data['filter']['sort'])
						->addOptions(CSelect::createOptionsFromArray([
							ApmStats::SORT_REQUESTS => I18n::t('Requests'),
							ApmStats::SORT_ERROR_RATE => I18n::t('Error rate'),
							ApmStats::SORT_P95 => I18n::t('Latency p95')
						]))
				)
			])
			->addItem([
				new CLabel(I18n::t('Apdex threshold T (ms)'), 'filter_apdex'),
				new CFormField(
					(new CNumericBox('filter_apdex', $data['filter']['apdex_ms'], 5))
						->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
						->setTitle(sprintf(I18n::t('Between %d and %d ms. A request is satisfied if it responds in T or less.'),
							Overview::APDEX_MIN, Overview::APDEX_MAX
						))
				)
			])
	]);

// Native suggestions of the service filter: exact names (the "=" prefix) from the last 24 h.
$service_names = (new CTag('datalist', true))->setId('apm-service-names');

foreach ($data['service_names'] as $name) {
	$service_names->addItem((new CTag('option', true))->setAttribute('value', ApmStats::EXACT_PREFIX.$name));
}

/*
 * Real-time refresh control (the interval is stored in the browser, see apm-overview.js).
 */
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

// Refreshable block of a tab, filled by the page script.
$block = static fn(string $tab): CDiv => (new CDiv(
	(new CDiv(I18n::t('Loading…')))->addClass('apm-overview-loading')
))->setAttribute('data-apm-block', $tab);

$service_note = $data['filter']['service'] !== ''
	? (new CDiv([
		new CSpan([
			I18n::t('Filtering by service: '),
			new CTag('strong', true, ltrim($data['filter']['service'], ApmStats::EXACT_PREFIX)),
			str_starts_with($data['filter']['service'], ApmStats::EXACT_PREFIX)
				? I18n::t(' (exact name)')
				: I18n::t(' (names containing the text)'),
			I18n::t('. The service map shows the whole application and highlights this service.')
		]),
		(new CLink(I18n::t('Clear filter'), OverviewHtml::getClearFilterUrl($data['selected_tab'] !== null
			? OverviewHtml::TABS[$data['selected_tab']]
			: OverviewHtml::TAB_MAP
		)))->addClass(ZBX_STYLE_BTN_ALT)
	]))->addClass('apm-overview-service-filter')
	: null;

$status_tab = $block(OverviewHtml::TAB_STATUS);

$map_tab = [
	$hint(I18n::t('What the application is made of and how it connects: from the users to each service and from each service to its databases and external services, with the traffic, errors and latency of each connection. The color shows the health. Click a service to see where its time goes.')),
	$block(OverviewHtml::TAB_MAP)
];

$services_tab = [
	$hint(sprintf(I18n::t('One row per service, calculated from the root spans (one request = one trace). Error rate: warning from 1 %%, problem from 5 %%. The trends show the last %d minutes of the period, minute by minute.'),
		ApmStats::SERIES_MINUTES
	)),
	$block(OverviewHtml::TAB_SERVICES)
];

$endpoints_tab = [
	$hint(I18n::t('Server spans (SpanKind = Server) grouped by service and operation, in the order chosen in the filter. Error: StatusCode = Error.')),
	$block(OverviewHtml::TAB_ENDPOINTS)
];

$errors_tab = [
	$hint(sprintf(I18n::t('Failed requests (root or server spans with status Error) grouped by service, endpoint and message, most recent first (up to %d groups). The link opens the latest trace of the group.'),
		Modules\ApmOverview\Includes\OverviewLoader::ERROR_LIMIT
	)),
	$block(OverviewHtml::TAB_ERRORS)
];

$ux_tab = $block(OverviewHtml::TAB_UX);

$slow_tab = $block(OverviewHtml::TAB_SLOW);

/*
 * Tab "How it works": an interactive guide to connect an application (filled in by CApmOverviewGuide in the browser),
 * the live checks of the application (refreshable block) and the reference of the data flow and configuration.
 */
$guide_values = Integration::defaults($data['filter']['application'] ?? '');
$guide_languages = Integration::languages();
$guide_first = array_key_first($guide_languages);

// Text with placeholders: rendered with the defaults; the page script fills it in again, as text, from the form.
$guide_text = static fn(string $text, string $tag): CTag => (new CTag($tag, true, Integration::fill($text, $guide_values)))
	->setAttribute('data-apm-template', $text);

$guide_form = (new CFormGrid())
	->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
	->addItem([
		new CLabel(I18n::t('Application (service.namespace)'), 'apm-guide-namespace'),
		new CFormField(
			(new CTextBox('apm_guide_namespace', $guide_values['{namespace}']))
				->setId('apm-guide-namespace')
				->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
				->setAttribute('autocomplete', 'off')
		)
	])
	->addItem([
		new CLabel(I18n::t('Service (service.name)'), 'apm-guide-service'),
		new CFormField(
			(new CTextBox('apm_guide_service', $guide_values['{service}']))
				->setId('apm-guide-service')
				->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
				->setAttribute('autocomplete', 'off')
		)
	])
	->addItem([
		new CLabel(I18n::t('Collector host'), 'apm-guide-collector'),
		new CFormField([
			(new CTextBox('apm_guide_collector', ''))
				->setId('apm-guide-collector')
				->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
				->setAttribute('autocomplete', 'off')
				->setAttribute('placeholder', Integration::DEFAULT_COLLECTOR),
			(new CDiv(I18n::t('Host name or address of the OpenTelemetry Collector (or Zabbix proxy) that your applications reach. Remembered in this browser.')))
				->addClass(ZBX_STYLE_GREY)
		])
	]);

$guide_language_list = (new CTag('ul', true))
	->setId('apm-guide-language')
	->addClass('radio-list-control');

foreach ($guide_languages as $key => $language) {
	$radio = (new CInput('radio', 'apm_guide_language', $key))->setId('apm-guide-language-'.$key);

	if ($key === $guide_first) {
		$radio->setAttribute('checked', 'checked');
	}

	$guide_language_list->addItem(new CTag('li', true, [$radio, new CLabel($language['label'], 'apm-guide-language-'.$key)]));
}

$guide_panels = [];

foreach ($guide_languages as $key => $language) {
	$steps = (new CDiv())->addClass('apm-guide-steps');

	foreach (array_values($language['steps']) as $i => $step) {
		$items = [new CTag('h5', true, ($i + 1).'. '.$step['title']), $guide_text($step['text'], 'p')];

		if ($step['code'] !== '') {
			$items[] = (new CDiv([
				(new CTag('pre', true, $guide_text($step['code'], 'code')))->addClass('apm-overview-code'),
				(new CButton('apm_guide_copy', I18n::t('Copy')))
					->addClass(ZBX_STYLE_BTN_ALT)
					->addClass('apm-guide-copy')
					->setAttribute('data-copied', I18n::t('Copied'))
			]))->addClass('apm-guide-code');
		}

		$steps->addItem((new CDiv($items))->addClass('apm-guide-step'));
	}

	$panel = (new CDiv($steps))
		->addClass('apm-guide-panel')
		->setAttribute('data-language', $key);

	if ($key !== $guide_first) {
		$panel->setAttribute('hidden', 'hidden');
	}

	$guide_panels[] = $panel;
}

$guide = (new CDiv([
	$hint(I18n::t('Fill in the application, the service and the collector: the steps of the chosen language are ready to copy. The checks below show whether the application is already sending data and has its Zabbix host.')),
	$guide_form,
	(new CDiv([new CSpan(I18n::t('Language or environment:')), $guide_language_list]))->addClass('apm-guide-languages'),
	$guide_panels
]))->setId('apm-guide');

$flow_steps = [
	[I18n::t('Application'), I18n::t('Instrumented with an OpenTelemetry SDK or agent. Every service sends its name (service.name) and its application (service.namespace).')],
	['OTLP :4317 / :4318', I18n::t('Traces, metrics and logs over OTLP (gRPC or HTTP), directly or through a collector of the application.')],
	[I18n::t('OpenTelemetry Collector or Zabbix proxy'), I18n::t('Receives OTLP and writes it to ClickHouse: an OpenTelemetry Collector with the clickhouse exporter, or the APM collector of a Zabbix proxy (StartAPMCollectors=1). The Zabbix server does not receive telemetry.')],
	['ClickHouse', I18n::t('Telemetry is stored in the otel_traces, otel_logs and otel_metrics_* tables (TelemetryProvider).')],
	[I18n::t('Zabbix frontend'), I18n::t('APM → Traces, Metrics, Logs and this page, one tab per application, query ClickHouse directly.')],
	[I18n::t('Items and triggers'), I18n::t('Host apm-<application> with the template "OpenTelemetry application by OTLP": Telemetry query items, triggers and a dashboard per application.')]
];

$flow = (new CDiv())->addClass('apm-overview-flow');

foreach ($flow_steps as $i => [$title, $description]) {
	if ($i > 0) {
		$flow->addItem((new CDiv('→'))->addClass('apm-overview-flow-arrow'));
	}

	$flow->addItem(
		(new CDiv([
			(new CDiv(($i + 1).'. '.$title))->addClass('apm-overview-flow-title'),
			(new CDiv($description))->addClass(ZBX_STYLE_GREY)
		]))->addClass('apm-overview-flow-step')
	);
}

$config_table = (new CTableInfo())
	->setHeader([I18n::t('Where'), I18n::t('Parameter'), I18n::t('Purpose')])
	->addRow([I18n::t('Application'), 'OTEL_RESOURCE_ATTRIBUTES=service.namespace=…', I18n::t('Required: groups the services of an application (tabs of this page and its Zabbix host).')])
	->addRow(['otelcol config.yaml', 'receivers: otlp / exporters: clickhouse', I18n::t('OpenTelemetry Collector: receives OTLP on :4317 and writes to ClickHouse (see examples/otelcol-config.yaml).')])
	->addRow(['zabbix_proxy.conf', 'StartAPMCollectors=1', I18n::t('Starts the APM (OTLP) collector of the proxy. Needs a proxy built with APM.')])
	->addRow(['zabbix_proxy.conf', 'APMListenIP / APMListenPort=4317', I18n::t('Address and port of the OTLP/gRPC receiver.')])
	->addRow(['zabbix_proxy.conf', 'APMTLSCAFile / APMTLSCertFile / APMTLSKeyFile', I18n::t('Optional TLS for the receiver (there is no token authentication).')])
	->addRow(['zabbix_proxy.conf / zabbix_server.conf', 'TelemetryProvider=clickhouse;url=…,db=…', I18n::t('Where telemetry is written and where Telemetry query items read from.')])
	->addRow(['zabbix_server.conf', 'StartTelemetryQueryPollers', I18n::t('Pollers of the Telemetry query items.')])
	->addRow(['Frontend', _('Administration → Data source → APM'), I18n::t('ClickHouse used by the APM pages (or $TELEMETRY_PROVIDERS in zabbix.conf.php).')])
	->addRow(['Frontend', _('Proxy → APM tab'), I18n::t('Enable collection, message limit and additional resource attributes.')]);

$doc_links = new CList();

foreach ([
	I18n::t('Connect an application, step by step') => OverviewHtml::DOCS_URL.'/blob/main/docs/add-application.md',
	I18n::t('Examples per language and environment') => OverviewHtml::DOCS_URL.'/tree/main/examples/stacks',
	I18n::t('The APM template') => OverviewHtml::DOCS_URL.'/blob/main/docs/apm-template.md',
	I18n::t('APM section') => 'https://www.zabbix.com/documentation/8.0/en/manual/web_interface/frontend_sections/apm',
	I18n::t('APM parameters of the proxy') => 'https://www.zabbix.com/documentation/8.0/en/manual/concepts/proxy/proxy_params',
	I18n::t('Telemetry query items') => 'https://www.zabbix.com/documentation/8.0/en/manual/config/items/itemtypes/telemetry_query',
	I18n::t('OpenTelemetry instrumentation') => 'https://opentelemetry.io/docs/languages/'
] as $label => $url) {
	$doc_links->addItem((new CLink($label, $url))->setTarget('_blank'));
}

$howto_tab = [
	(new CSection($guide))->setHeader(new CTag('h4', true, I18n::t('Connect an application'))),
	(new CSection($block(OverviewHtml::TAB_HOWTO)))->setHeader(new CTag('h4', true, I18n::t('Is it working?'))),
	(new CSection($flow))->setHeader(new CTag('h4', true, I18n::t('Data flow'))),
	(new CSection($config_table))->setHeader(new CTag('h4', true, I18n::t('Configuration'))),
	(new CSection($doc_links))->setHeader(new CTag('h4', true, I18n::t('Documentation')))
];

// Tab order must match OverviewHtml::TABS.
$tabs = (new CTabView())
	->addTab('apm-status', I18n::t('Status'), $status_tab)
	->addTab('apm-map', I18n::t('Service map'), $map_tab)
	->addTab('apm-services', I18n::t('Services'), $services_tab)
	->addTab('apm-endpoints', 'Endpoints', $endpoints_tab)
	->addTab('apm-errors', I18n::t('Errors'), $errors_tab)
	->addTab('apm-ux', I18n::t('User experience'), $ux_tab)
	->addTab('apm-slow', I18n::t('Origin of slowness'), $slow_tab)
	->addTab('apm-howto', I18n::t('How it works'), $howto_tab);

if ($data['selected_tab'] !== null) {
	$tabs->setSelected($data['selected_tab']);
}

// One tab per application (service.namespace): the page never mixes them. Without "tab" in the link the content tab
// of this browser session is kept.
$app_tabs = null;

if ($data['applications']) {
	$app_tabs = (new CList())->addClass('apm-app-tabs');

	foreach ($data['applications'] as $application) {
		$label = $application !== '' ? $application : I18n::t('No application');

		if ($application === $data['filter']['application']) {
			$app_tabs->addItem((new CSpan($label))->setAttribute('aria-current', 'page'), 'apm-app-tab-active');
		}
		else {
			$app_tabs->addItem(new CLink($label, (new CUrl('zabbix.php'))
				->setArgument('action', 'apm.overview')
				->setArgument('filter_set', 1)
				->setArgument('filter_app', $application)
				->getUrl()
			));
		}
	}

	$app_tabs = (new CTag('nav', true, $app_tabs))->setAttribute('aria-label', I18n::t('Applications'));
}

(new CHtmlPage())
	->setTitle(I18n::t('APM overview'))
	->setDocUrl('https://www.zabbix.com/documentation/8.0/en/manual/web_interface/frontend_sections/apm')
	->setControls(
		(new CTag('nav', true, $live_control))->setAttribute('aria-label', I18n::t('Content controls'))
	)
	->addItem($app_tabs)
	->addItem($filter)
	->addItem($service_names)
	->addItem((new CDiv([$service_note, $tabs]))->addClass('apm-overview'))
	->show();

$reload_url = (new CUrl('zabbix.php'))->setArgument('action', 'apm.overview');

if ($data['filter']['endpoint_service'] !== '') {
	$reload_url = OverviewHtml::getEndpointsUrl($data['filter']['endpoint_service']);
}

(new CScriptTag('
	$.subscribe("timeselector.rangeupdate", (e, data) => {
		if (data.idx === '.json_encode($data['filter_options']['idx']).') {
			location.href = '.json_encode($reload_url->getUrl()).';
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
		body.set(CSRF_TOKEN_NAME, '.json_encode(CCsrfTokenHelper::get('apm.overview.filter.active')).');

		return fetch('.json_encode((new CUrl('zabbix.php'))->setArgument('action', 'apm.overview.filter.active')->getUrl()).',
			{method: "POST", body}
		);
	};

	if (typeof CApmOverviewGuide === "function") {
		new CApmOverviewGuide();
	}

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
'))
	->setOnDocumentReady()
	->show();

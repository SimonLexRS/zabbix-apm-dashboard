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
 * Tab "How it works": how APM works (static).
 */
$flow_steps = [
	[I18n::t('Application'), I18n::t('Instrumented with an OpenTelemetry SDK or auto-instrumentation (OTEL_* variables).')],
	[_('OTLP gRPC :4317'), I18n::t('Traces, metrics and logs are sent over OTLP/gRPC, directly or through an OpenTelemetry Collector.')],
	[I18n::t('Zabbix proxy or OpenTelemetry Collector'), I18n::t('Receives the data: the APM collector of the proxy (StartAPMCollectors=1, packages for Ubuntu 24.04+) or an OpenTelemetry Collector with the clickhouse exporter. The Zabbix server cannot receive telemetry.')],
	[_('ClickHouse'), I18n::t('Telemetry is stored in the otel_traces, otel_logs and otel_metrics_* tables (TelemetryProvider).')],
	[I18n::t('Zabbix frontend'), I18n::t('APM → Traces, Metrics, Logs and this overview query ClickHouse directly.')],
	[I18n::t('Items and triggers'), I18n::t('"Telemetry query" items aggregate telemetry into regular metrics for triggers, graphs and dashboards.')]
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
	->addRow(['otelcol config.yaml', 'receivers: otlp / exporters: clickhouse', I18n::t('OpenTelemetry Collector: receives OTLP on :4317 and writes to ClickHouse (see examples/otelcol-config.yaml).')])
	->addRow(['zabbix_proxy.conf', 'StartAPMCollectors=1', I18n::t('Starts the APM (OTLP) collector of the proxy. Needs a proxy built with APM.')])
	->addRow(['zabbix_proxy.conf', 'APMListenIP / APMListenPort=4317', I18n::t('Address and port of the OTLP/gRPC receiver.')])
	->addRow(['zabbix_proxy.conf', 'APMTLSCAFile / APMTLSCertFile / APMTLSKeyFile', I18n::t('Optional TLS for the receiver (there is no token authentication).')])
	->addRow(['zabbix_proxy.conf / zabbix_server.conf', 'TelemetryProvider=clickhouse;url=…,db=…', I18n::t('Where telemetry is written and where Telemetry query items read from.')])
	->addRow(['zabbix_server.conf', 'StartTelemetryQueryPollers', I18n::t('Pollers of the Telemetry query items.')])
	->addRow(['Frontend', _('Administration → Data source → APM'), I18n::t('ClickHouse used by the APM pages (or $TELEMETRY_PROVIDERS in zabbix.conf.php).')])
	->addRow(['Frontend', _('Proxy → APM tab'), I18n::t('Enable collection, message limit and additional resource attributes.')]);

$otel_example = (new CPre(implode("\n", [
	'OTEL_SERVICE_NAME=my-service',
	'OTEL_EXPORTER_OTLP_ENDPOINT=http://<zabbix-proxy>:4317',
	'OTEL_EXPORTER_OTLP_PROTOCOL=grpc',
	'OTEL_EXPORTER_OTLP_INSECURE=true',
	'opentelemetry-instrument python app.py'
])))->addClass('apm-overview-code');

$doc_links = new CList();

foreach ([
	I18n::t('APM section') => 'https://www.zabbix.com/documentation/8.0/en/manual/web_interface/frontend_sections/apm',
	I18n::t('APM parameters of the proxy') => 'https://www.zabbix.com/documentation/8.0/en/manual/concepts/proxy/proxy_params',
	I18n::t('Telemetry query items') => 'https://www.zabbix.com/documentation/8.0/en/manual/config/items/itemtypes/telemetry_query',
	_('API: apmtrace.get') => 'https://www.zabbix.com/documentation/8.0/en/manual/api/reference/apmtrace/get',
	I18n::t('OpenTelemetry instrumentation') => 'https://opentelemetry.io/docs/languages/'
] as $label => $url) {
	$doc_links->addItem((new CLink($label, $url))->setTarget('_blank'));
}

$howto_tab = [
	(new CSection($flow))->setHeader(new CTag('h4', true, I18n::t('Data flow'))),
	(new CSection($config_table))->setHeader(new CTag('h4', true, I18n::t('Configuration'))),
	(new CSection($otel_example))->setHeader(new CTag('h4', true, I18n::t('Instrument an application'))),
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

(new CHtmlPage())
	->setTitle(I18n::t('APM overview'))
	->setDocUrl('https://www.zabbix.com/documentation/8.0/en/manual/web_interface/frontend_sections/apm')
	->setControls(
		(new CTag('nav', true, $live_control))->setAttribute('aria-label', I18n::t('Content controls'))
	)
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

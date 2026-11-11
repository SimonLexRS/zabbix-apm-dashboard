<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

use CCol,
	CDiv,
	CFormField,
	CFormGrid,
	CLabel,
	CLink,
	CRow,
	CSection,
	CSpan,
	CTableInfo,
	CTag,
	CUrl;

/**
 * Renders the dynamic blocks of the APM overview page, one per tab. The same markup is used for the initial page and
 * for the real-time refresh (apm.overview.data), so there is a single implementation of every block.
 *
 * All user and telemetry data goes through CTag, which escapes it.
 */
class OverviewHtml {

	public const TAB_STATUS = 'status';
	public const TAB_MAP = 'map';
	public const TAB_SERVICES = 'services';
	public const TAB_ENDPOINTS = 'endpoints';
	public const TAB_ERRORS = 'errors';
	public const TAB_KPI = 'kpi';
	public const TAB_UX = 'ux';
	public const TAB_SLOW = 'slow';
	public const TAB_HOWTO = 'howto';

	// Display order of the tabs.
	public const TABS = [self::TAB_STATUS, self::TAB_MAP, self::TAB_SERVICES, self::TAB_ENDPOINTS, self::TAB_ERRORS,
		self::TAB_KPI, self::TAB_UX, self::TAB_SLOW, self::TAB_HOWTO
	];

	// Tabs with a refreshable block (in "How it works" only the checks of the application refresh, not the guide).
	public const DATA_TABS = [self::TAB_STATUS, self::TAB_MAP, self::TAB_SERVICES, self::TAB_ENDPOINTS,
		self::TAB_ERRORS, self::TAB_KPI, self::TAB_UX, self::TAB_SLOW, self::TAB_HOWTO
	];

	// Window of the "is it working" checks of the How it works tab.
	public const CHECK_WINDOW = 15 * SEC_PER_MIN;

	private const ERROR_RATE_WARNING = 1;
	private const ERROR_RATE_HIGH = 5;

	private const MESSAGE_SHORT_LENGTH = 120;
	private const STATEMENT_SHORT_LENGTH = 160;

	// Seconds before and after the span used as the time period of the trace link.
	private const TRACE_LINK_WINDOW = 300;

	// Core Web Vitals: label, unit, good and poor thresholds (Google, 75th percentile).
	public const WEB_VITALS = [
		'LCP' => ['Main content load (LCP)', 'ms', 2500, 4000],
		'INP' => ['Interaction response (INP)', 'ms', 200, 500],
		'CLS' => ['Visual stability (CLS)', '', 0.1, 0.25],
		'FCP' => ['First content visible (FCP)', 'ms', 1800, 3000],
		'TTFB' => ['Server response (TTFB)', 'ms', 800, 1800]
	];

	public const DOCS_URL = 'https://github.com/SimonLexRS/zabbix-apm-dashboard';

	public const WEB_VITAL_RATINGS = ['good' => 'Good', 'warn' => 'Needs improvement', 'bad' => 'Poor'];

	/**
	 * Render the refreshable block of a tab.
	 *
	 * @param array $data  Output of OverviewLoader::load() for that tab.
	 * @param array $ctx   ['from', 'to', 'endpoint_service', 'apdex_ms', 'join_capped']
	 */
	public static function renderBlock(string $tab, array $data, array $ctx): CDiv {
		if ($tab !== self::TAB_STATUS && ($notice = self::sourceNotice($data)) !== null) {
			return $notice;
		}

		return match ($tab) {
			self::TAB_STATUS => self::status($data, $ctx),
			self::TAB_MAP => self::map($data, $ctx),
			self::TAB_SERVICES => self::services($data, $ctx),
			self::TAB_ENDPOINTS => self::endpoints($data, $ctx),
			self::TAB_ERRORS => self::errors($data, $ctx),
			self::TAB_KPI => self::kpis($data, $ctx),
			self::TAB_UX => self::userExperience($data, $ctx),
			self::TAB_SLOW => self::slowness($data, $ctx),
			self::TAB_HOWTO => self::howto($data, $ctx),
			default => new CDiv()
		};
	}

	/**
	 * "Is it working?" checks of the application of the page: signals received, services, services without a
	 * namespace and its Zabbix host.
	 *
	 * @param array $data  ['application' => ?string, 'signals', 'services' => string[], 'unnamespaced' => string[],
	 *                     'host' => ?array]
	 */
	public static function howto(array $data, array $ctx): CDiv {
		$ok = static fn(string $text): CSpan => (new CSpan($text))->addClass(ZBX_STYLE_STATUS_GREEN);
		$bad = static fn(string $text): CSpan => (new CSpan($text))->addClass(ZBX_STYLE_STATUS_RED);
		$warn = static fn(string $text): CSpan => (new CSpan($text))->addClass(ZBX_STYLE_STATUS_YELLOW);

		$application = $data['application'];
		$table = (new CTableInfo())->setHeader([I18n::t('Check'), I18n::t('State'), I18n::t('Detail')]);

		if ($application === null) {
			$table->addRow([I18n::t('Application'), $bad(I18n::t('None')),
				I18n::t('No telemetry with service.namespace in the last 7 days: follow the steps above.')
			]);

			return (new CDiv($table))->addClass('apm-guide-checks');
		}

		$signal_names = array_intersect_key(
			['traces' => I18n::t('Traces'), 'logs' => 'Logs', 'metrics' => I18n::t('Metrics')],
			array_flip(ApmAccess::signals())
		);

		foreach ($signal_names as $signal => $name) {
			$stats = $data['signals'][$signal] ?? null;

			if ($stats === null || $stats['error'] !== null) {
				$state = $bad('Error');
				$detail = $stats['error'] ?? '';
			}
			elseif ($stats['rows'] > 0) {
				$state = $ok(I18n::t('Receiving'));
				$detail = sprintf(I18n::t('%s records in the last %d minutes, last at %s'), self::formatCount($stats['rows']),
					self::CHECK_WINDOW / SEC_PER_MIN, zbx_date2str(DATE_TIME_FORMAT_SECONDS, $stats['last'])
				);
			}
			else {
				$state = $signal === 'traces' ? $bad(I18n::t('No data')) : $warn(I18n::t('No data'));
				$detail = $signal === 'traces'
					? I18n::t('Nothing in the last 15 minutes: check the endpoint, the port and the network (step 1).')
					: I18n::t('Optional, but expected from most SDKs and agents.');
			}

			$table->addRow([$name, $state, $detail]);
		}

		$table->addRow([I18n::t('Services'),
			$data['services'] ? $ok((string) count($data['services'])) : $bad('0'),
			$data['services'] ? implode(', ', $data['services']) : I18n::t('No service of this application in the last 24 hours.')
		]);

		if ($data['unnamespaced']) {
			$table->addRow([I18n::t('Without application'), $warn((string) count($data['unnamespaced'])),
				sprintf(I18n::t('Services sending without service.namespace in the last 15 minutes (of any application): %s. If one belongs to this application, add the attribute (step 1).'),
					implode(', ', $data['unnamespaced'])
				)
			]);
		}

		$host_name = Integration::hostName($application);
		$host = $data['host'];

		if ($application === '') {
			$table->addRow([I18n::t('Zabbix host'), $warn('—'),
				I18n::t('These services have no application, so they have no Zabbix host: add service.namespace to them (step 1).')
			]);
		}
		elseif ($host === null) {
			$table->addRow([I18n::t('Zabbix host'), $bad(I18n::t('Not found')),
				sprintf(I18n::t('No host %s (or no permission to see it): create it with step 3.'), $host_name)
			]);
		}
		else {
			$problems = [];

			if (!$host['template']) {
				$problems[] = I18n::t('the template "OpenTelemetry application by OTLP" is not linked');
			}

			if ($host['namespace'] !== $application) {
				$problems[] = sprintf(I18n::t('{$OTLP.NAMESPACE} is "%s"'), (string) $host['namespace']);
			}

			$link = new CLink($host['name'], (new CUrl('zabbix.php'))
				->setArgument('action', 'latest.view')
				->setArgument('hostids', [$host['hostid']])
				->setArgument('filter_set', 1)
			);

			$table->addRow([I18n::t('Zabbix host'),
				$problems ? $warn(I18n::t('Check')) : $ok(I18n::t('OK')),
				$problems ? [$link, ': '.implode('; ', $problems).'.'] : [$link, ' — '.I18n::t('template linked, macro set.')]
			]);
		}

		return (new CDiv([
			(new CDiv(sprintf(I18n::t('Application %s — updated with the page.'), $application)))
				->addClass(ZBX_STYLE_GREY),
			$table
		]))->addClass('apm-guide-checks');
	}

	/**
	 * Data source and signal cards.
	 */
	public static function status(array $data, array $ctx): CDiv {
		$block = new CDiv();

		if (($notice = self::sourceNotice($data)) !== null) {
			$block->addItem($notice);
		}

		$source_grid = (new CFormGrid())
			->addItem([
				new CLabel(I18n::t('Configuration')),
				new CFormField(match ($data['source']['origin']) {
					'zabbix.conf.php' => I18n::t('Frontend configuration file (zabbix.conf.php, $TELEMETRY_PROVIDERS)'),
					'global' => I18n::t('Global data source (Administration → Data source → APM)'),
					default => (new CSpan(I18n::t('Not configured')))->addClass(ZBX_STYLE_COLOR_NEGATIVE)
				})
			])
			->addItem([
				new CLabel(I18n::t('Database type')),
				new CFormField('ClickHouse'.($data['version'] !== '' ? ' '.$data['version'] : ''))
			])
			->addItem([
				new CLabel('URL'),
				new CFormField($data['source']['url'] !== '' ? $data['source']['url'] : '—')
			])
			->addItem([
				new CLabel(I18n::t('Database')),
				new CFormField($data['source']['db'] !== '' ? $data['source']['db'] : '—')
			])
			->addItem([
				new CLabel(I18n::t('Connection')),
				new CFormField($data['source']['origin'] === null
					? (new CSpan(I18n::t('Not configured')))->addClass(ZBX_STYLE_STATUS_GREY)
					: ($data['error'] === null
						? (new CSpan(I18n::t('OK')))->addClass(ZBX_STYLE_STATUS_GREEN)
						: (new CSpan('Error'))->addClass(ZBX_STYLE_STATUS_RED)
					)
				)
			]);

		if ($data['can_edit_source']) {
			$source_grid->addItem([
				new CLabel(''),
				new CFormField(
					new CLink(I18n::t('Configure the data source'), (new CUrl('zabbix.php'))->setArgument('action', 'apm.db.edit'))
				)
			]);
		}

		$block->addItem((new CSection($source_grid))->setHeader(new CTag('h4', true, I18n::t('Data source'))));

		$signal_names = array_intersect_key(
			['traces' => I18n::t('Traces'), 'logs' => 'Logs', 'metrics' => I18n::t('Metrics')],
			array_flip(ApmAccess::signals())
		);
		$signal_actions = ['traces' => 'apm.trace.list', 'logs' => 'apm.log.list', 'metrics' => 'apm.metric.list'];

		$signals = (new CDiv())->addClass('apm-overview-signals');

		foreach ($signal_names as $signal => $name) {
			$stats = $data['signals'][$signal] ?? null;

			if ($stats === null) {
				$state = (new CSpan(I18n::t('Unknown')))->addClass(ZBX_STYLE_STATUS_GREY);
			}
			elseif ($stats['error'] !== null) {
				$state = (new CSpan('Error'))->addClass(ZBX_STYLE_STATUS_RED)->setTitle($stats['error']);
			}
			elseif ($stats['rows'] > 0) {
				$state = (new CSpan(I18n::t('Receiving')))->addClass(ZBX_STYLE_STATUS_GREEN);
			}
			else {
				$state = (new CSpan(I18n::t('No data')))->addClass(ZBX_STYLE_STATUS_YELLOW);
			}

			$signals->addItem(
				(new CDiv([
					(new CDiv([
						new CLink($name, (new CUrl('zabbix.php'))
							->setArgument('action', $signal_actions[$signal])
							->setArgument('from', $ctx['from'])
							->setArgument('to', $ctx['to'])
						),
						$state
					]))->addClass('apm-overview-signal-header'),
					(new CDiv($stats !== null ? self::formatCount($stats['rows']) : '—'))
						->addClass('apm-overview-signal-value'),
					(new CDiv(I18n::t('records in the selected period')))->addClass(ZBX_STYLE_GREY),
					(new CDiv(
						$stats !== null && $stats['last'] !== null
							? I18n::t('Last received: ').zbx_date2str(DATE_TIME_FORMAT_SECONDS, $stats['last'])
							: I18n::t('No data in this period')
					))->addClass(ZBX_STYLE_GREY)
				]))->addClass('apm-overview-signal')
			);
		}

		$block->addItem((new CSection($signals))->setHeader(new CTag('h4', true, I18n::t('Received signals'))));

		return $block;
	}

	/**
	 * State of the system now, above the map: entry traffic, errors, the slowest service, health counts and what needs
	 * attention (ServiceMap::summary()).
	 */
	private static function mapSummary(array $summary, ?array $app_problems = null): CDiv {
		$health = $summary['health'];

		$line = (new CDiv([
			new CTag('strong', true, self::formatRate($summary['rpm']).'/min'),
			' '.I18n::t('from users').' · ',
			(new CTag('strong', true, sprintf('%.2f %%', $summary['error_rate'])))->addClass(
				$summary['error_rate'] >= self::ERROR_RATE_HIGH
					? 'apm-map-kpi-bad'
					: ($summary['error_rate'] >= self::ERROR_RATE_WARNING ? 'apm-map-kpi-warn' : null)
			),
			' '.I18n::t('errors').' · ',
			sprintf(I18n::t('%d services healthy'), $health['ok']),
			$health['warn'] > 0
				? [', ', (new CSpan(sprintf(I18n::t('%d with a warning'), $health['warn'])))->addClass('apm-map-kpi-warn')]
				: null,
			$health['bad'] > 0
				? [', ', (new CSpan(sprintf(I18n::t('%d with a problem'), $health['bad'])))->addClass('apm-map-kpi-bad')]
				: null,
			$summary['slowest'] !== null
				? ' · '.sprintf(I18n::t('slowest: %s (p95 %s)'), $summary['slowest']['name'],
					ServiceMap::shortDuration($summary['slowest']['p95'])
				)
				: null,
			$app_problems !== null && $app_problems['problems']['count'] > 0
				? [' · ', new CLink(
					sprintf(I18n::t('%d open problems in Zabbix (%s)'), $app_problems['problems']['count'],
						$app_problems['problems']['worst']
					),
					(new CUrl('zabbix.php'))
						->setArgument('action', 'problem.view')
						->setArgument('hostids', [$app_problems['host']['hostid']])
						->setArgument('filter_set', 1)
				)]
				: null
		]))->addClass('apm-map-line-summary');

		$attention = null;

		if ($summary['attention']) {
			$attention = (new CTag('ul', true))->addClass('apm-map-attention');

			foreach ($summary['attention'] as $item) {
				$attention->addItem(new CTag('li', true, [
					(new CSpan())->addClass('apm-map-dot')->addClass('apm-map-'.$item['severity']),
					$item['service'] !== null
						? new CLink($item['label'], ServiceMap::getServicePageUrl($item['service']))
						: new CSpan($item['label']),
					(new CSpan(' '.$item['reason']))->addClass(ZBX_STYLE_GREY)
				]));
			}
		}

		return (new CDiv([$line, $attention]))->addClass('apm-map-summary');
	}

	/**
	 * Controls of the map, applied in the browser (CApmServiceMapInteraction): nothing is asked of the server.
	 */
	private static function mapTools(): CDiv {
		$check = static fn(string $attribute, string $label): CTag => new CTag('label', true, [
			(new CTag('input', false))->setAttribute('type', 'checkbox')->setAttribute($attribute, ''),
			' '.$label
		]);

		return (new CDiv([
			(new CTag('input', false))
				->setAttribute('type', 'search')
				->setAttribute('data-apm-map-search', '')
				->setAttribute('placeholder', I18n::t('Find a service'))
				->setAttribute('aria-label', I18n::t('Find a service')),
			$check('data-apm-map-problems', I18n::t('Only what has a problem')),
			$check('data-apm-map-dependencies', I18n::t('Hide databases, queues and external services'))
		]))->addClass('apm-map-tools');
	}

	/**
	 * Service map with the list of its connections.
	 */
	public static function map(array $data, array $ctx): CDiv {
		$graph = ServiceMap::build($data['services'], $data['calls'], $data['call_minutes']);

		// Open Zabbix problems of each service (tag service = name): a mark on its node and part of its health.
		foreach ($data['problems'] ?? [] as $service => $problems) {
			if (array_key_exists('svc:'.$service, $graph['nodes'])) {
				$graph['nodes']['svc:'.$service]['problems'] = $problems;
			}
		}

		$table = (new CTableInfo())
			->setHeader([I18n::t('Source'), I18n::t('Destination'), I18n::t('Type'), I18n::t('Calls'), I18n::t('Calls/min'), I18n::t('Errors'), 'p95'])
			->setNoDataMessage(I18n::t('No calls in the selected period.'));

		$focus = $ctx['focus'] ?? '';
		$focused = static fn(array $edge): bool => $focus !== '' && (
			($graph['nodes'][$edge['from']]['kind'] === 'service'
				&& ServiceMap::matches($graph['nodes'][$edge['from']]['name'], $focus))
			|| ($graph['nodes'][$edge['to']]['kind'] === 'service'
				&& ServiceMap::matches($graph['nodes'][$edge['to']]['name'], $focus))
		);

		// Connections of the filtered services first, then by volume.
		$edges = $graph['edges'];
		usort($edges, static fn(array $a, array $b): int => [$focused($b), $b['calls']] <=> [$focused($a), $a['calls']]);

		foreach ($edges as $edge) {
			$from = $graph['nodes'][$edge['from']];
			$to = $graph['nodes'][$edge['to']];
			$error_rate = $edge['calls'] > 0 ? $edge['errors'] / $edge['calls'] * 100 : 0;

			$table->addRow([
				$from['kind'] === 'service' ? new CLink($from['name'], ServiceMap::getServicePageUrl($from['name'], $ctx)) : $from['name'],
				$to['kind'] === 'service' ? new CLink($to['name'], ServiceMap::getServicePageUrl($to['name'], $ctx)) : $to['name'],
				match ($to['kind']) {
					'database' => I18n::t('Database'),
					'queue' => I18n::t('Queue'),
					'external' => I18n::t('External service'),
					default => $from['kind'] === 'users' ? I18n::t('User request') : I18n::t('Service call')
				},
				self::formatCount($edge['calls']),
				sprintf('%.2f', $edge['rpm']),
				self::errorRatePill($error_rate),
				ApmStats::formatDuration($edge['p95'])
			]);
		}

		return new CDiv([
			$focus !== '' ? (new CDiv([
				I18n::t('Highlighted: '),
				new CTag('strong', true, str_starts_with($focus, ApmStats::EXACT_PREFIX)
					? substr($focus, strlen(ApmStats::EXACT_PREFIX))
					: $focus
				),
				I18n::t(' and its connections. The rest of the application is dimmed. '),
				new CLink(I18n::t('Clear filter'), self::getClearFilterUrl(self::TAB_MAP, $ctx['transient'] ?? false))
			]))->addClass('apm-overview-hint') : null,
			$graph['nodes'] ? self::mapSummary(ServiceMap::summary($graph), $data['app_problems'] ?? null) : null,
			$graph['nodes'] ? self::mapTools() : null,
			ServiceMap::render($graph, $ctx),
			$ctx['join_capped'] ? self::hint(sprintf(
				I18n::t('Calls between services and to their dependencies are calculated over the last %d h of the period.'),
				ApmStats::JOIN_WINDOW / SEC_PER_HOUR
			)) : null,
			array_intersect(['services', 'calls'], $data['truncated'] ?? []) ? self::hint(
				I18n::t('This application has more services or connections than the map reads: the least used ones are missing.')
			) : null,
			self::hint(sprintf(
				I18n::t('Colors show the last %d min; the figures on the map are those of the whole period. Background work (consumers, timers) is not counted as user traffic.'),
				$ctx['recent_min'] ?? 15
			)),
			(new CSection($table))->setHeader(new CTag('h4', true, I18n::t('Connections')))
		]);
	}

	/**
	 * Per-service RED statistics; each trend in its own column.
	 */
	public static function services(array $data, array $ctx): CDiv {
		$series_minutes = 0;

		foreach ($data['series'] as $series) {
			$series_minutes = count($series['requests']);
			break;
		}

		$trend = sprintf(I18n::t('Trend (%d min)'), $series_minutes ?: ApmStats::SERIES_MINUTES);

		$table = (new CTableInfo())
			->setHeader([
				I18n::t('Service'),
				I18n::t('Requests'),
				'Req/min',
				$trend,
				I18n::t('Errors'),
				I18n::t('Error rate'),
				$trend,
				'p50',
				'p95',
				$trend,
				'p99',
				''
			])
			->setNoDataMessage(I18n::t('No traces in the selected period.'));

		foreach ($data['services'] as $service) {
			$series = $data['series'][$service['service']] ?? null;

			$table->addRow([
				(new CCol([
					(new CLink($service['service'], ServiceMap::getServicePageUrl($service['service'], $ctx)))
						->setTitle(I18n::t('Open the page of this service')),
					($service['browser'] ?? false)
						? [' ', (new CSpan(I18n::t('browser')))->addClass('apm-pill apm-pill-info')]
						: null
				]))->addClass(ZBX_STYLE_WORDBREAK),
				self::formatCount($service['requests']),
				sprintf('%.2f', $service['rpm']),
				$series === null ? '' : self::sparkline($series['requests'], 'apm-spark-requests',
					sprintf(I18n::t('Requests per minute (max %s)'), max($series['requests']))
				),
				$service['errors'] > 0
					? new CLink(self::formatCount($service['errors']),
						self::getTracesUrl($ctx, $service['service'], '', true)
					)
					: 0,
				self::errorRatePill($service['error_rate']),
				$series === null ? '' : self::sparkline($series['error_rate'], 'apm-spark-errors',
					sprintf(I18n::t('Error rate per minute (max %.2f %%)'), max($series['error_rate']))
				),
				ApmStats::formatDuration($service['p50']),
				ApmStats::formatDuration($service['p95']),
				$series === null ? '' : self::sparkline($series['p95'], 'apm-spark-p95',
					sprintf(I18n::t('p95 per minute (max %s)'), ApmStats::formatDuration(max($series['p95'])))
				),
				ApmStats::formatDuration($service['p99']),
				(new CCol([
					new CLink('Endpoints', self::getEndpointsUrl($service['service'])),
					' · ',
					new CLink(I18n::t('Traces'), self::getTracesUrl($ctx, $service['service'])),
					' · ',
					in_array('logs', ApmAccess::signals(), true)
						? [new CLink('Logs', self::getLogsUrl($ctx, $service['service'])), ' · ']
						: null,
					(new CLink(I18n::t('Slowness'), ServiceMap::getServiceUrl($service['service'])))
						->setTitle(I18n::t('Where the time of this service goes'))
				]))->addClass(ZBX_STYLE_NOWRAP)
			]);
		}

		return new CDiv($table);
	}

	/**
	 * Server spans grouped by service and span name.
	 */
	public static function endpoints(array $data, array $ctx): CDiv {
		$block = new CDiv();

		if ($ctx['endpoint_service'] !== '') {
			$block->addItem(
				(new CDiv([
					new CSpan([I18n::t('Service: '), new CTag('strong', true, $ctx['endpoint_service'])]),
					new CLink(I18n::t('Show all services'), self::getEndpointsUrl())
				]))->addClass('apm-overview-endpoint-filter')
			);
		}

		$table = (new CTableInfo())
			->setHeader([I18n::t('Service'), 'Endpoint', I18n::t('Requests'), 'Req/min', I18n::t('Errors'), I18n::t('Error rate'), 'p50', 'p95',
				'p99', ''
			])
			->setNoDataMessage(I18n::t('No server spans (SpanKind = Server) in the selected period.'));

		foreach ($data['endpoints'] as $endpoint) {
			$table->addRow([
				(new CCol(
					(new CLink($endpoint['service'], self::getEndpointsUrl($endpoint['service'])))
						->setTitle(I18n::t('Show only the endpoints of this service'))
				))->addClass(ZBX_STYLE_WORDBREAK),
				(new CCol(
					(new CLink(self::endpointLabel($endpoint['endpoint'], $endpoint['codes']),
						ServiceMap::getServicePageUrl($endpoint['service'], $ctx, $endpoint['endpoint'])
					))->setTitle(I18n::t('Open the page of this endpoint'))
				))->addClass(ZBX_STYLE_WORDBREAK),
				self::formatCount($endpoint['requests']),
				sprintf('%.2f', $endpoint['rpm']),
				$endpoint['errors'] > 0
					? new CLink(self::formatCount($endpoint['errors']),
						self::getTracesUrl($ctx, $endpoint['service'], $endpoint['endpoint'], true)
					)
					: 0,
				self::errorRatePill($endpoint['error_rate']),
				ApmStats::formatDuration($endpoint['p50']),
				ApmStats::formatDuration($endpoint['p95']),
				ApmStats::formatDuration($endpoint['p99']),
				new CLink(I18n::t('Traces'), self::getTracesUrl($ctx, $endpoint['service'], $endpoint['endpoint']))
			]);
		}

		$block->addItem($table);

		if (count($data['endpoints']) >= OverviewLoader::ENDPOINT_LIMIT) {
			$block->addItem(self::hint(sprintf(I18n::t('The first %d endpoints in the selected order are shown.'),
				OverviewLoader::ENDPOINT_LIMIT
			)));
		}

		return $block;
	}

	/**
	 * Failures grouped into issues, each with its state: active ones first, resolved ones dimmed.
	 */
	public static function errors(array $data, array $ctx): CDiv {
		return new CDiv(self::errorTable($data['errors'], $ctx, $data['truncated'] ?? []));
	}

	/**
	 * Table of error groups (ApmStats::getErrorGroups()), shared by the APM overview and the RUM page.
	 *
	 * @param array $ctx  ['resolved' => bool, 'time_till' => int, 'recent_min' => int], optional 'browser' => true for
	 *                    the errors of the browser (page instead of service).
	 */
	public static function errorTable(array $errors, array $ctx, array $truncated = []): array {
		$now = min($ctx['time_till'] ?? time(), time());
		$browser = $ctx['browser'] ?? false;
		$counts = array_count_values(array_column($errors, 'state')) + [
			ApmStats::STATE_NEW => 0, ApmStats::STATE_REGRESSED => 0, ApmStats::STATE_ACTIVE => 0,
			ApmStats::STATE_RESOLVED => 0
		];
		$hidden = 0;

		$table = (new CTableInfo())
			->setHeader([I18n::t('State'), $browser ? I18n::t('Page') : I18n::t('Service'),
				$browser ? I18n::t('Where') : 'Endpoint', I18n::t('Error'), 'HTTP', I18n::t('Count'), I18n::t('Trend'),
				I18n::t('Sessions'), I18n::t('Last seen'), I18n::t('Latest trace')
			])
			->setNoDataMessage(I18n::t('No errors in the selected period.'));

		foreach ($errors as $error) {
			$resolved = $error['state'] === ApmStats::STATE_RESOLVED;

			if ($resolved && !($ctx['resolved'] ?? true)) {
				$hidden++;
				continue;
			}

			$codes = [];

			foreach ($error['codes'] as $code) {
				$codes[] = (new CSpan($code))->addClass((int) $code >= 500 ? ZBX_STYLE_COLOR_NEGATIVE : ZBX_STYLE_GREY);
				$codes[] = ' ';
			}

			$message = $error['message'] !== ''
				? (new CSpan(self::truncate($error['message'], self::MESSAGE_SHORT_LENGTH)))->setTitle($error['message'])
				: (new CSpan(I18n::t('(no message)')))->addClass(ZBX_STYLE_GREY);

			$first = $browser && $error['page'] !== ''
				? new CLink($error['page'], RumHtml::pageUrl($error['page']))
				: [
					new CLink($error['service'], self::getEndpointsUrl($error['service'])),
					$error['browser'] ? [' ', (new CSpan(I18n::t('browser')))->addClass('apm-pill apm-pill-info')] : null
				];

			$table->addRow(
				(new CRow([
					(new CCol([
						self::statePill($error['state']),
						// The version that brought it, or brought it back.
						($error['version'] ?? '') !== '' && $error['state'] !== ApmStats::STATE_RESOLVED
							? (new CDiv(sprintf(I18n::t('since %s'), $error['version'])))->addClass(ZBX_STYLE_GREY)
							: null
					]))->addClass(ZBX_STYLE_NOWRAP),
					(new CCol($first))->addClass(ZBX_STYLE_WORDBREAK),
					(new CCol(self::endpointLabel($error['endpoint'], $error['codes'])))->addClass(ZBX_STYLE_WORDBREAK),
					(new CCol([
						$error['type'] !== '' ? [new CTag('strong', true, $error['type']), ' '] : null,
						$message
					]))->addClass('apm-overview-message'),
					(new CCol($codes ?: '—'))->addClass(ZBX_STYLE_NOWRAP),
					(new CSpan(self::formatCount($error['count'])))->addClass('apm-overview-count'),
					self::sparkline($error['series'], 'apm-spark-errors',
						sprintf(I18n::t('Occurrences over the period (max %s)'), max($error['series']))
					),
					$error['sessions'] > 0
						? (new CSpan(self::formatCount($error['sessions'])))
							->setTitle(I18n::t('Browser sessions whose requests ended in this error'))
						: (new CSpan('—'))->addClass(ZBX_STYLE_GREY),
					(new CCol([
						self::ago($error['last'], $now),
						(new CSpan(' · '.self::formatTime($error['last'])))->addClass(ZBX_STYLE_GREY)
					]))->addClass(ZBX_STYLE_NOWRAP),
					(new CCol(self::traceLink($error['traceid'], $error['last'])))->addClass(ZBX_STYLE_NOWRAP)
				]))->addClass($resolved ? 'apm-error-resolved' : null)
			);
		}

		$summary = (new CDiv([
			(new CSpan(sprintf(I18n::t('%d new'), $counts[ApmStats::STATE_NEW])))->addClass('apm-pill apm-pill-bad'), ' ',
			(new CSpan(sprintf(I18n::t('%d came back'), $counts[ApmStats::STATE_REGRESSED])))
				->addClass('apm-pill apm-pill-bad'), ' ',
			(new CSpan(sprintf(I18n::t('%d active'), $counts[ApmStats::STATE_ACTIVE])))->addClass('apm-pill apm-pill-warn'),
			' ',
			(new CSpan(sprintf(I18n::t('%d resolved'), $counts[ApmStats::STATE_RESOLVED])))
				->addClass('apm-pill apm-pill-good'),
			(new CSpan(sprintf(I18n::t(' · active = seen in the last %d min'), $ctx['recent_min'] ?? 15)))
				->addClass(ZBX_STYLE_GREY)
		]))->addClass('apm-error-summary');

		return [
			$errors ? $summary : null,
			$table,
			$hidden > 0 ? self::hint(sprintf(
				I18n::t('%d resolved errors are hidden (filter: Show resolved errors).'), $hidden
			)) : null,
			in_array('errors', $truncated, true) ? self::hint(
				I18n::t('There are more error groups than shown: the active and most recent ones come first.')
			) : null
		];
	}

	/**
	 * What people feel: Web Vitals from real browsers, Apdex and the latency distribution of the server.
	 */
	public static function userExperience(array $data, array $ctx): CDiv {
		$block = new CDiv(
			(new CDiv([
				I18n::t('Page loads, sessions, user actions and the requests of each page, from the browser spans: '),
				new CLink(I18n::t('Real user monitoring'), (new CUrl('zabbix.php'))->setArgument('action', 'apm.rum'))
			]))->addClass('apm-overview-hint')
		);
		$vitals = $data['vitals'];

		// Web Vitals cards.
		if ($vitals['overall']) {
			$cards = self::vitalCards($vitals);

			$pages = $vitals['pages'];
			uasort($pages, static fn(array $a, array $b): int =>
				max(array_column($b, 'samples')) <=> max(array_column($a, 'samples'))
			);

			$header = [I18n::t('Page'), I18n::t('Visits')];

			foreach (array_keys(self::WEB_VITALS) as $metric) {
				$header[] = $metric;
			}

			$page_table = (new CTableInfo())->setHeader($header);

			foreach (array_slice($pages, 0, 25, true) as $page => $metrics) {
				$page_table->addRow(array_merge(
					[(new CCol($page))->addClass(ZBX_STYLE_WORDBREAK), self::formatCount(max(array_column($metrics, 'samples')))],
					self::vitalCells($metrics)
				));
			}

			$device_table = (new CTableInfo())->setHeader(array_merge([I18n::t('Device'), I18n::t('Visits')], array_slice($header, 2)));

			foreach ($vitals['devices'] as $device => $metrics) {
				$device_table->addRow(array_merge(
					[$device === 'mobile' ? I18n::t('Mobile') : ($device === 'desktop' ? I18n::t('Desktop') : $device),
						self::formatCount(max(array_column($metrics, 'samples')))
					],
					self::vitalCells($metrics)
				));
			}

			$block->addItem(
				(new CSection([
					self::hint(I18n::t('Measured in the browsers of real users (75th percentile, as Google evaluates it). Each visit counts once.')),
					$cards,
					(new CDiv([
						(new CDiv($page_table))->addClass('apm-ux-pages'),
						(new CDiv($device_table))->addClass('apm-ux-devices')
					]))->addClass('apm-ux-tables')
				]))->setHeader(new CTag('h4', true, I18n::t('Real browser experience (Web Vitals)')))
			);
		}
		else {
			$block->addItem(
				(new CSection(self::sectionNotice($data, 'vitals') ?? new CDiv([
					self::hint(I18n::t('No browser measurements in this period. The application must send its Web Vitals as OTLP log records with the rum.* attributes (rum.metric, rum.value, rum.rating, rum.page).')),
					new CLink(I18n::t('How to send Web Vitals'), self::DOCS_URL.'/blob/main/docs/rum.md')
				])))->setHeader(new CTag('h4', true, I18n::t('Real browser experience (Web Vitals)')))
			);
		}

		// Apdex.
		$threshold_ns = $ctx['apdex_ms'] * 1000000;
		$apdex_header = [I18n::t('Score'), I18n::t('Satisfied'), I18n::t('Tolerating'), I18n::t('Frustrated'), I18n::t('Requests')];

		$service_table = (new CTableInfo())
			->setHeader(array_merge([I18n::t('Service')], $apdex_header))
			->setNoDataMessage(I18n::t('No traces in the selected period.'));

		foreach ($data['apdex']['services'] as $row) {
			$service_table->addRow(array_merge([new CLink($row['service'], ServiceMap::getServiceUrl($row['service']))],
				self::apdexCells($row)
			));
		}

		$endpoint_table = (new CTableInfo())
			->setHeader(array_merge([I18n::t('Service'), 'Endpoint'], $apdex_header))
			->setNoDataMessage(I18n::t('No endpoints with at least 5 requests.'));

		foreach ($data['apdex']['endpoints'] as $row) {
			$endpoint_table->addRow(array_merge([$row['service'], (new CCol($row['endpoint']))->addClass(ZBX_STYLE_WORDBREAK)],
				self::apdexCells($row)
			));
		}

		$block->addItem(
			(new CSection([
				self::sectionNotice($data, 'apdex'),
				self::hint(sprintf(I18n::t('Satisfied: responds in %1$s or less. Tolerating: up to %2$s. Frustrated: slower or failed. Apdex = (satisfied + tolerating / 2) / total. The threshold T is set in the filter.'),
					ApmStats::formatDuration($threshold_ns), ApmStats::formatDuration(4 * $threshold_ns)
				)),
				$service_table,
				(new CTag('h5', true, I18n::t('Endpoints with the worst experience')))->addClass('apm-overview-subtitle'),
				$endpoint_table
			]))->setHeader(new CTag('h4', true, sprintf(I18n::t('Server Apdex (T = %s)'),
				ApmStats::formatDuration($threshold_ns)
			)))
		);

		// Latency distribution.
		$histograms = (new CDiv())->addClass('apm-ux-histograms');

		foreach ($data['histograms'] as $service => $buckets) {
			$histograms->addItem(self::histogram($service, $buckets, $threshold_ns));
		}

		$block->addItem(
			(new CSection([
				self::sectionNotice($data, 'histograms'),
				self::hint(I18n::t('How many requests take each time range (busiest services; axis in milliseconds up to 512, then seconds). Green: satisfied, yellow: tolerating, red: frustrated, by the Apdex threshold.')),
				$data['histograms'] ? $histograms : self::noTraces()
			]))->setHeader(new CTag('h4', true, I18n::t('Latency distribution')))
		);

		return $block;
	}

	/**
	 * Core Web Vitals cards: p75, rating, share of poor visits, thresholds and the hourly trend.
	 *
	 * @param array $vitals  Output of ApmStats::getWebVitals().
	 */
	public static function vitalCards(array $vitals): CDiv {
		$cards = (new CDiv())->addClass('apm-ux-cards');

		foreach (self::WEB_VITALS as $metric => [$label, $unit, $good, $poor]) {
			$row = $vitals['overall'][$metric] ?? null;

			if ($row === null) {
				$cards->addItem(
					(new CDiv([
						(new CDiv(I18n::t($label)))->addClass('apm-ux-card-title'),
						(new CDiv('—'))->addClass('apm-ux-card-value'),
						(new CDiv(I18n::t('No measurements')))->addClass(ZBX_STYLE_GREY)
					]))->addClass('apm-ux-card')
				);
				continue;
			}

			$rating = self::vitalRating($metric, $row['p75']);
			$hourly = $vitals['hourly'][$metric] ?? [];

			$cards->addItem(
				(new CDiv([
					(new CDiv(I18n::t($label)))->addClass('apm-ux-card-title'),
					(new CDiv([
						(new CSpan(self::formatVital($metric, $row['p75'])))->addClass('apm-ux-'.$rating),
						(new CSpan(I18n::t(self::WEB_VITAL_RATINGS[$rating])))->addClass('apm-ux-badge')->addClass('apm-ux-'.$rating)
					]))->addClass('apm-ux-card-value'),
					(new CDiv(sprintf(I18n::t('p75 of %s visits · %.0f %% poor'), self::formatCount($row['samples']),
						$row['samples'] > 0 ? $row['poor'] / $row['samples'] * 100 : 0
					)))->addClass(ZBX_STYLE_GREY),
					(new CDiv(sprintf(I18n::t('Good ≤ %s · Poor > %s'), self::formatVital($metric, $good),
						self::formatVital($metric, $poor)
					)))->addClass(ZBX_STYLE_GREY),
					count($hourly) > 1
						? self::sparkline($hourly, 'apm-spark-p95', I18n::t('p75 per hour in the period'))
						: null
				]))->addClass('apm-ux-card')
			);
		}

		return $cards;
	}

	/**
	 * Where the time goes: breakdown per endpoint, slowest traces, database statements and regressions.
	 */
	public static function slowness(array $data, array $ctx): CDiv {
		$block = new CDiv();

		// Time breakdown.
		$legend = (new CDiv([
			(new CSpan(I18n::t('Own code')))->addClass('apm-slow-key apm-slow-own'),
			(new CSpan(I18n::t('Database')))->addClass('apm-slow-key apm-slow-db'),
			(new CSpan(I18n::t('Other services and external')))->addClass('apm-slow-key apm-slow-calls')
		]))->addClass('apm-slow-legend');

		$breakdown = (new CTableInfo())
			->setHeader([I18n::t('Service'), 'Endpoint', I18n::t('Requests'), I18n::t('Average'), 'p95', I18n::t('Average time breakdown')])
			->setNoDataMessage(I18n::t('No traces in the selected period.'));

		foreach ($data['breakdown'] as $row) {
			$parts = ['own' => I18n::t('Own code'), 'db' => I18n::t('Database'), 'calls' => I18n::t('Other services and external')];
			$bar = (new CDiv())->addClass('apm-slow-bar');
			$title = [];

			foreach ($parts as $part => $label) {
				$share = $row['total'] > 0 ? $row[$part] / $row['total'] * 100 : 0;
				$title[] = sprintf('%s: %s (%.0f %%)', $label, ApmStats::formatDuration($row[$part]), $share);

				if ($share > 0) {
					$bar->addItem(
						(new CDiv($share >= 12 ? sprintf('%.0f %%', $share) : ''))
							->addClass('apm-slow-'.$part)
							->addStyle(sprintf('width: %.2f%%;', $share))
					);
				}
			}

			$breakdown->addRow([
				new CLink($row['service'], ServiceMap::getServiceUrl($row['service'])),
				(new CCol($row['endpoint']))->addClass(ZBX_STYLE_WORDBREAK),
				self::formatCount($row['requests']),
				ApmStats::formatDuration($row['total']),
				ApmStats::formatDuration($row['p95']),
				(new CCol($bar->setTitle(implode("\n", $title))))->addClass('apm-slow-bar-cell')
			]);
		}

		$block->addItem(
			(new CSection([
				self::sectionNotice($data, 'breakdown'),
				self::hint(I18n::t('Endpoints that consume most time in total (requests × average). The bar splits the average time between the work of the service itself, its database queries and its calls to other services. Hover the bar for the times.').
					($ctx['join_capped'] ? sprintf(I18n::t(' Calculated over the last %d h of the period.'), ApmStats::JOIN_WINDOW / SEC_PER_HOUR) : '')
				),
				$legend,
				$breakdown
			]))->setHeader(new CTag('h4', true, I18n::t('Where does the time go?')))
		);

		// Slowest traces with waterfall.
		$traces = new CDiv();

		foreach ($data['slow_traces'] as $index => $trace) {
			$traces->addItem(self::waterfall($trace, $index === 0));
		}

		$block->addItem(
			(new CSection([
				self::sectionNotice($data, 'slow_traces'),
				self::hint(I18n::t('The slowest requests of the period. Expand each one to see where the time went; the link opens the whole trace in the trace viewer.')),
				$data['slow_traces'] ? $traces : self::noTraces()
			]))->setHeader(new CTag('h4', true, I18n::t('Slowest requests')))
		);

		// Database statements.
		$db_total = array_sum(array_column($data['db_statements'], 'total'));

		$statements = (new CTableInfo())
			->setHeader([I18n::t('Service'), I18n::t('Database'), I18n::t('Statement'), I18n::t('Calls'), I18n::t('Total time'), I18n::t('Share'), 'p95', I18n::t('Errors')])
			->setNoDataMessage(I18n::t('No database calls (client spans with db.system or db.system.name) in the period.'));

		foreach ($data['db_statements'] as $row) {
			$share = $db_total > 0 ? $row['total'] / $db_total * 100 : 0;

			$statements->addRow([
				$row['service'],
				$row['db_system'],
				(new CCol(
					(new CSpan(self::truncate($row['statement'], self::STATEMENT_SHORT_LENGTH)))->setTitle($row['statement'])
				))->addClass('apm-slow-statement'),
				self::formatCount($row['calls']),
				ApmStats::formatDuration($row['total']),
				(new CDiv((new CDiv())->addStyle(sprintf('width: %.2f%%;', $share))))
					->addClass('apm-slow-share')
					->setTitle(sprintf(I18n::t('%.1f %% of the database time'), $share)),
				ApmStats::formatDuration($row['p95']),
				$row['errors'] > 0 ? (new CSpan(self::formatCount($row['errors'])))->addClass(ZBX_STYLE_COLOR_NEGATIVE) : 0
			]);
		}

		$block->addItem(
			(new CSection([
				self::sectionNotice($data, 'db_statements'),
				self::hint(I18n::t('Statements grouped by shape (values replaced by ?), ordered by the total time they take.')),
				$statements
			]))->setHeader(new CTag('h4', true, I18n::t('Most expensive statements')))
		);

		// Regressions.
		$regressions = (new CTableInfo())
			->setHeader([I18n::t('Service'), 'Endpoint', I18n::t('Previous p95'), I18n::t('Current p95'), I18n::t('Change'), I18n::t('Requests (previous → current)')])
			->setNoDataMessage(I18n::t('No endpoint got more than 10 % slower than in the previous period.'));

		foreach ($data['regressions'] as $row) {
			$change = ($row['p95'] / $row['previous_p95'] - 1) * 100;

			if ($change < 10) {
				continue;
			}

			$regressions->addRow([
				$row['service'],
				(new CCol($row['endpoint']))->addClass(ZBX_STYLE_WORDBREAK),
				ApmStats::formatDuration($row['previous_p95']),
				ApmStats::formatDuration($row['p95']),
				(new CSpan(sprintf('+%.0f %%', $change)))
					->addClass('apm-pill')
					->addClass($change >= 30 ? 'apm-pill-bad' : 'apm-pill-warn'),
				self::formatCount($row['previous_requests']).' → '.self::formatCount($row['requests'])
			]);
		}

		$block->addItem(
			(new CSection([
				self::sectionNotice($data, 'regressions'),
				self::hint(I18n::t('p95 of each endpoint against the previous period of the same length (at least 5 requests in both). Red: more than 30 % slower.')),
				$regressions
			]))->setHeader(new CTag('h4', true, I18n::t('Slower than the previous period')))
		);

		return $block;
	}

	/**
	 * Business metrics (KPIs) of the application: one table per service, each metric with its total of the period
	 * (or current value), its trend and its breakdown by attribute (e.g. orders by store).
	 *
	 * @param array $data  ['kpis' => ApmStats::getBusinessMetrics(), 'denied' => bool, 'host' => ?array,
	 *                     'minutes' => float, 'application' => ?string]
	 */
	public static function kpis(array $data, array $ctx): CDiv {
		$block = new CDiv();

		if ($data['denied'] ?? false) {
			return $block->addItem(self::hint(I18n::t('Your user role has no access to APM → Metrics, where the business metrics come from.')));
		}

		$block->addItem(self::kpiHost($data));

		if (!$data['kpis']) {
			return $block->addItem(self::kpiEmpty());
		}

		$services = [];

		foreach ($data['kpis'] as $metric) {
			$services[$metric['service']][] = $metric;
		}

		$minutes = max(1, $data['minutes']);
		$series_minutes = 0;

		foreach ($data['kpis'] as $metric) {
			$series_minutes = $metric['series'] !== null ? count($metric['series']) : 0;
			break;
		}

		foreach ($services as $service => $metrics) {
			$table = (new CTableInfo())
				->setHeader([
					I18n::t('Metric'),
					I18n::t('Period total'),
					I18n::t('Per minute'),
					$series_minutes > 0 ? sprintf(I18n::t('Trend (%d min)'), $series_minutes) : I18n::t('Trend'),
					I18n::t('Breakdown'),
					''
				])
				->addClass('apm-kpi-table');

			foreach ($metrics as $metric) {
				$table->addRow([
					(new CCol(self::kpiName($metric)))->addClass('apm-kpi-name'),
					(new CCol(self::kpiValue($metric)))->addClass(ZBX_STYLE_NOWRAP),
					BusinessMetrics::isTotal($metric['kind'])
						? BusinessMetrics::format($metric['value'] / $minutes, $metric['kind'] === BusinessMetrics::KIND_HISTOGRAM ? '' : $metric['unit'])
						: '—',
					$metric['series'] !== null
						? self::sparkline($metric['series'], 'apm-spark-requests', sprintf(
							BusinessMetrics::isTotal($metric['kind'])
								? I18n::t('Per minute (max %s)')
								: I18n::t('Value per minute (max %s)'),
							BusinessMetrics::format(max($metric['series']), $metric['kind'] === BusinessMetrics::KIND_HISTOGRAM ? '' : $metric['unit'])
						))
						: '',
					(new CCol(self::kpiBreakdown($metric)))->addClass('apm-kpi-breakdown-cell'),
					self::kpiItemsLink($data, $metric)
				]);
			}

			$block->addItem(
				(new CSection($table))
					->setHeader(new CTag('h4', true, $service))
					->addClass('apm-kpi-service')
			);
		}

		return $block;
	}

	private static function kpiName(array $metric): array {
		$kinds = [
			BusinessMetrics::KIND_COUNTER => I18n::t('counter'),
			BusinessMetrics::KIND_UPDOWN => I18n::t('up-down counter'),
			BusinessMetrics::KIND_GAUGE => I18n::t('gauge'),
			BusinessMetrics::KIND_HISTOGRAM => I18n::t('histogram')
		];
		$details = array_filter([$kinds[$metric['kind']] ?? $metric['kind'],
			$metric['description'] !== '' && $metric['description'] !== $metric['metric'] ? $metric['description'] : null
		]);

		return [
			(new CSpan($metric['metric']))->addClass('apm-kpi-metric'),
			(new CDiv(implode(' · ', $details)))->addClass(ZBX_STYLE_GREY)
		];
	}

	private static function kpiValue(array $metric): array {
		if ($metric['kind'] === BusinessMetrics::KIND_HISTOGRAM) {
			$count = (int) round($metric['value']);

			return [
				(new CSpan(sprintf(I18n::t('%s measurements'), self::formatCount($count))))->addClass('apm-kpi-value'),
				(new CDiv(sprintf(I18n::t('sum %s · average %s'),
					BusinessMetrics::format($metric['sum'], $metric['unit']),
					BusinessMetrics::format($count > 0 ? $metric['sum'] / $count : 0, $metric['unit'])
				)))->addClass(ZBX_STYLE_GREY)
			];
		}

		$value = (new CSpan(BusinessMetrics::format($metric['value'], $metric['unit'])))->addClass('apm-kpi-value');

		return BusinessMetrics::isTotal($metric['kind'])
			? [$value]
			: [$value, (new CDiv(I18n::t('current value')))->addClass(ZBX_STYLE_GREY)];
	}

	/**
	 * One bar list per attribute of the metric; the first one open.
	 */
	private static function kpiBreakdown(array $metric): array {
		if (!$metric['dims']) {
			return [(new CSpan(I18n::t('no attributes')))->addClass(ZBX_STYLE_GREY)];
		}

		$unit = $metric['kind'] === BusinessMetrics::KIND_HISTOGRAM ? '' : $metric['unit'];
		$lists = [];

		foreach ($metric['dims'] as $key => $values) {
			$total = array_sum(array_column($values, 'total'));
			$max = max(array_column($values, 'total')) ?: 1;
			$rows = (new CDiv())->addClass('apm-kpi-shares');

			foreach ($values as $value) {
				$rows->addItem(
					(new CDiv([
						(new CDiv($value['value'] === '' ? I18n::t('(empty)') : $value['value']))
							->addClass('apm-kpi-share-name'),
						(new CDiv(
							(new CDiv())
								->addClass('apm-kpi-share-bar')
								->setAttribute('style', sprintf('width: %.1f%%;', max(0, $value['total']) / $max * 100))
						))->addClass('apm-kpi-share-track'),
						(new CDiv([
							BusinessMetrics::format($value['total'], $unit),
							// The share of a percentage (e.g. occupancy) means nothing.
							$total > 0 && $unit !== '%'
								? (new CSpan(sprintf(' · %.0f %%', $value['total'] / $total * 100)))->addClass(ZBX_STYLE_GREY)
								: null
						]))->addClass('apm-kpi-share-value')
					]))
						->addClass('apm-kpi-share')
						->setTitle($total > 0
							? sprintf(I18n::t('%s = %s: %s (%.1f %% of the values shown)'), $key, $value['value'],
								BusinessMetrics::format($value['total'], $unit), $value['total'] / $total * 100
							)
							: $key.' = '.$value['value']
						)
				);
			}

			$details = (new CTag('details', true, [
				new CTag('summary', true, [$key, ' ', (new CSpan('('.count($values).')'))->addClass(ZBX_STYLE_GREY)]),
				$rows
			]))->addClass('apm-kpi-dim');

			if (!$lists) {
				$details->setAttribute('open', 'open');
			}

			$lists[] = $details;
		}

		return $lists;
	}

	/**
	 * Link to the Zabbix items of a metric (template "APM business metrics by ClickHouse HTTP"), when the host has it.
	 */
	private static function kpiItemsLink(array $data, array $metric): CLink|string {
		if (($data['host']['kpi'] ?? false) !== true) {
			return '';
		}

		return (new CLink(I18n::t('Items'), (new CUrl('zabbix.php'))
			->setArgument('action', 'latest.view')
			->setArgument('hostids', [$data['host']['hostid']])
			->setArgument('tags', [['tag' => 'kpi', 'value' => $metric['metric'], 'operator' => TAG_OPERATOR_EQUAL]])
			->setArgument('filter_set', 1)
		))->setTitle(I18n::t('History of this metric in Zabbix (Latest data)'));
	}

	/**
	 * What the page shows and whether the KPIs are also Zabbix items (history, triggers, the assistant).
	 */
	private static function kpiHost(array $data): CDiv {
		$text = I18n::t('Counters, gauges and histograms that the application sends besides those of the instrumentation (runtime, HTTP, database): orders, reservations, payments... Counters and histograms add up over the period; up-down counters and gauges show their current value. The breakdown shows the largest values of each attribute.');
		$host = $data['host'] ?? null;
		$application = $data['application'] ?? null;

		if ($application === null || $application === '') {
			return self::hint($text);
		}

		if ($host !== null && $host['kpi']) {
			return self::hint($text.' '.sprintf(I18n::t('Zabbix keeps them minute by minute as items of %s, with the totals of the last hour, today and yesterday.'), $host['name']));
		}

		return (new CDiv([
			$text.' ',
			new CTag('strong', true, I18n::t('To keep them as Zabbix items')),
			' ',
			sprintf(I18n::t('(history, triggers, dashboards and the questions of the assistant), link the template "APM business metrics by ClickHouse HTTP" to %s:'), Integration::hostName($application)),
			' ',
			new CTag('code', true, 'python3 scripts/zabbix-apm-app.py add '.$application.' --kpi')
		]))
			->addClass(ZBX_STYLE_GREY)
			->addClass('apm-overview-hint');
	}

	private static function kpiEmpty(): CDiv {
		$code = implode("\n", [
			'// Go: one counter, with a few known values per attribute',
			'orders, _ := otel.Meter("shop").Int64Counter("shop.orders", metric.WithUnit("{order}"))',
			'orders.Add(ctx, 1, metric.WithAttributes(attribute.String("shop.store", store)))',
			'',
			'# Python',
			'orders = metrics.get_meter("shop").create_counter("shop.orders", unit="{order}")',
			'orders.add(1, {"shop.store": store})'
		]);

		return (new CDiv([
			self::hint(I18n::t('No business metrics in this period. Send them with the metrics API of the OpenTelemetry SDK, through the same exporter as the traces: counters for what happens (orders, reservations, payments), gauges for current levels. Each attribute (store, hotel, currency) becomes a breakdown; use attributes with a few known values, never IDs or e-mails.')),
			(new CTag('pre', true, $code))->addClass('apm-overview-code')->addClass('apm-kpi-code'),
			new CLink(I18n::t('Guide: business KPIs'), self::DOCS_URL.'/blob/main/docs/business-kpis.md')
		]))->addClass('apm-kpi-empty');
	}

	/**
	 * Inline SVG sparkline. Values are scaled from 0 to the maximum of the series.
	 */
	public static function sparkline(array $values, string $class, string $title): CTag {
		$width = 80;
		$height = 20;
		$padding = 2;

		$svg = (new CTag('svg', true))
			->addClass('apm-spark')
			->addClass($class)
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
			$y = $max > 0
				? round($height - $padding - $value / $max * ($height - 2 * $padding), 1)
				: $height - $padding;

			$points[] = $x.','.$y;
		}

		return $svg->addItem((new CTag('polyline', true))->setAttribute('points', implode(' ', $points)));
	}

	/**
	 * The page without the service filter, on the given tab.
	 */
	public static function getClearFilterUrl(string $tab, bool $transient = false): CUrl {
		$url = (new CUrl('zabbix.php'))
			->setArgument('action', 'apm.overview')
			->setArgument('tab', $tab);

		// A service picked on the map lives in the URL: leaving it out is enough. The stored filter is emptied.
		return $transient ? $url : $url->setArgument('filter_set', 1)->setArgument('filter_service', '');
	}

	public static function getEndpointsUrl(string $service = ''): CUrl {
		$url = (new CUrl('zabbix.php'))
			->setArgument('action', 'apm.overview')
			->setArgument('tab', self::TAB_ENDPOINTS);

		if ($service !== '') {
			$url->setArgument('endpoint_service', $service);
		}

		return $url;
	}

	/**
	 * Native trace list (action "apm.trace.list") filtered by service and, optionally, operation name and status.
	 */
	public static function getTracesUrl(array $ctx, string $service, string $span_name = '',
			bool $errors_only = false): CUrl {
		return (new CUrl('zabbix.php'))
			->setArgument('action', 'apm.trace.list')
			->setArgument('filter_service_name', $service)
			->setArgument('filter_span_name', $span_name !== '' ? $span_name : null)
			->setArgument('filter_statuses', $errors_only ? [APM_TRACE_STATUS_ERROR] : null)
			->setArgument('filter_set', 1)
			->setArgument('from', $ctx['from'])
			->setArgument('to', $ctx['to']);
	}

	/**
	 * Native trace list (action "apm.trace.list") filtered by trace ID, in a short absolute window around the span, so
	 * the list shows exactly that trace; clicking the row opens the trace view.
	 */
	public static function getTraceUrl(string $traceid, int $clock): CUrl {
		$time_to = min($clock + self::TRACE_LINK_WINDOW, time());
		$time_from = min($clock - self::TRACE_LINK_WINDOW, $time_to - 2 * self::TRACE_LINK_WINDOW);

		return (new CUrl('zabbix.php'))
			->setArgument('action', 'apm.trace.list')
			->setArgument('filter_traceid', $traceid)
			->setArgument('filter_set', 1)
			->setArgument('from', zbx_date2str(ZBX_FULL_DATE_TIME, $time_from))
			->setArgument('to', zbx_date2str(ZBX_FULL_DATE_TIME, $time_to));
	}

	public static function getLogsUrl(array $ctx, string $service): CUrl {
		return (new CUrl('zabbix.php'))
			->setArgument('action', 'apm.log.list')
			->setArgument('filter_service_name', $service)
			->setArgument('filter_set', 1)
			->setArgument('from', $ctx['from'])
			->setArgument('to', $ctx['to']);
	}

	/**
	 * Notice in a section whose query failed (the rest of the tab still shows), null when it worked.
	 */
	public static function sectionNotice(array $data, string $key): ?CDiv {
		if (!array_key_exists($key, $data['section_errors'] ?? [])) {
			return null;
		}

		return (new CDiv([
			(new CSpan(I18n::t('This section could not be calculated: ClickHouse did not answer (usually lack of memory). It will be retried on the next refresh.')))
				->setTitle($data['section_errors'][$key])
		]))->addClass('apm-overview-section-error');
	}

	/**
	 * Notice when the data source is missing or failing, null when it works.
	 */
	public static function sourceNotice(array $data): ?CDiv {
		if ($data['source']['origin'] === null) {
			return new CDiv(makeMessageBox(ZBX_STYLE_MSG_WARNING, [], I18n::t('The APM data source is not configured.'), false));
		}

		if ($data['error'] !== null) {
			return new CDiv(makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $data['error']]],
				I18n::t('Cannot query the APM data source.'), false
			));
		}

		return null;
	}

	/**
	 * Spans in tree order, each with a bar placed on the time line of the trace (or of the request they belong to).
	 * Rows of failed spans carry apm-wf-row-error, so that a view can show only those.
	 *
	 * @param array $spans  ApmStats::getTraceSpans(), start relative to the start of the time line.
	 * @param float $total  Length of the time line, nanoseconds.
	 */
	public static function spanRows(array $spans, float $total): CDiv {
		$ids = array_flip(array_column($spans, 'id'));
		$children = [];

		foreach ($spans as $i => $span) {
			$parent = array_key_exists($span['parent'], $ids) && $span['parent'] !== $span['id'] ? $span['parent'] : '';
			$children[$parent][] = $i;
		}

		// Depth-first, siblings by start time (spans are already ordered by start).
		$ordered = [];
		$stack = array_map(static fn(int $i): array => [$i, 0], array_reverse($children[''] ?? []));

		while ($stack) {
			[$i, $depth] = array_pop($stack);
			$ordered[] = [$i, $depth];

			foreach (array_reverse($children[$spans[$i]['id']] ?? []) as $child) {
				$stack[] = [$child, $depth + 1];
			}
		}

		$total = max(1, $total);
		$services = array_values(array_unique(array_column($spans, 'service')));
		$rows = (new CDiv())->addClass('apm-wf');

		foreach (array_slice($ordered, 0, count($spans)) as [$i, $depth]) {
			$span = $spans[$i];
			$browser = $span['browser'] ?? false;
			$category = $browser
				? 'browser'
				: ($span['db_system'] !== ''
					? 'db'
					: (($span['kind'] === 'Client' || $span['kind'] === 'Producer')
						? 'calls'
						: 'svc'.(array_search($span['service'], $services) % 6)));

			$left = min(100, $span['start'] / $total * 100);
			$width = max(0.3, min(100 - $left, $span['duration'] / $total * 100));

			$rows->addItem(
				(new CDiv([
					(new CDiv([
						(new CSpan($span['service']))->addClass('apm-wf-service'),
						$browser ? [' ', (new CSpan(I18n::t('browser')))->addClass('apm-pill apm-pill-info')] : null,
						' ',
						(new CSpan($span['name']))->addClass($span['error'] ? ZBX_STYLE_COLOR_NEGATIVE : null)
					]))
						->addClass('apm-wf-name')
						->addStyle('padding-left: '.min(12, $depth) * 12 .'px;')
						->setTitle($span['service'].' · '.$span['name'].' · '.$span['kind']),
					(new CDiv(
						(new CDiv())
							->addClass('apm-wf-span')
							->addClass('apm-wf-'.$category)
							->addClass($span['error'] ? 'apm-wf-error' : null)
							->addStyle(sprintf('left: %.2f%%; width: %.2f%%;', $left, $width))
					))->addClass('apm-wf-track'),
					(new CDiv(ApmStats::formatDuration($span['duration'])))->addClass('apm-wf-duration')
				]))
					->addClass('apm-wf-row')
					->addClass($span['error'] ? 'apm-wf-row-error' : null)
			);
		}

		return $rows;
	}

	/**
	 * Waterfall of one trace: spans in tree order, bars placed on the trace's time line.
	 */
	public static function waterfall(array $trace, bool $open): CTag {
		$spans = $trace['spans'];
		$rows = self::spanRows($spans, (float) $trace['duration']);

		$summary = new CTag('summary', true, [
			(new CSpan(ApmStats::formatDuration($trace['duration'])))->addClass('apm-wf-total'),
			' ',
			new CTag('strong', true, $trace['service']),
			' · ',
			$trace['endpoint'],
			$trace['error'] ? [' ', (new CSpan('error'))->addClass('apm-pill apm-pill-bad')] : null,
			(new CSpan(self::formatTime($trace['clock']).' · '.$trace['span_count'].' spans'))
				->addClass(ZBX_STYLE_GREY)
				->addClass('apm-wf-meta')
		]);

		return (new CTag('details', true, [
			$summary,
			$rows,
			(new CDiv([
				$trace['span_count'] > count($spans)
					? sprintf(I18n::t('%d of %d spans shown. '), count($spans), $trace['span_count'])
					: '',
				self::traceLink($trace['traceid'], $trace['clock'], I18n::t('Open the whole trace in the viewer'))
			]))->addClass('apm-wf-footer')
		]))
			->addClass('apm-wf-trace')
			->setAttribute('data-trace', $trace['traceid'])
			->setAttribute('open', $open ? 'open' : null);
	}

	/**
	 * Latency histogram of one service, bars colored by Apdex zone, p50 and p95 marked.
	 */
	public static function histogram(string $service, array $buckets, int $threshold_ns): CDiv {
		$total = array_sum($buckets);
		$max = max(1, max($buckets));
		$labels = ['≤1 ms', '≤2 ms', '≤4 ms', '≤8 ms', '≤16 ms', '≤32 ms', '≤64 ms', '≤128 ms', '≤256 ms', '≤512 ms',
			'≤1 s', '≤2 s', '≤4 s', '>4 s'
		];
		// Axis: upper bound in ms up to 512, then seconds (the full range is in each bar's tooltip).
		$axis = ['1', '2', '4', '8', '16', '32', '64', '128', '256', '512', '1 s', '2 s', '4 s', '>4 s'];

		// Bucket of the 50th and 95th percentiles, from the cumulative counts.
		$marks = [];
		$cumulative = 0;

		foreach ($buckets as $bucket => $count) {
			$cumulative += $count;

			foreach (['p50' => 0.5, 'p95' => 0.95] as $name => $quantile) {
				if (!array_key_exists($name, $marks) && $cumulative >= $quantile * $total) {
					$marks[$name] = $bucket;
				}
			}
		}

		$bars = (new CDiv())->addClass('apm-hist-bars');

		foreach ($buckets as $bucket => $count) {
			$upper_ns = 2 ** $bucket * 1000000;
			$zone = $upper_ns <= $threshold_ns ? 'ok' : ($upper_ns <= 4 * $threshold_ns ? 'warn' : 'bad');
			$tags = array_keys($marks, $bucket, true);

			$bars->addItem(
				(new CDiv([
					(new CDiv($tags ? implode(' ', $tags) : ''))->addClass('apm-hist-mark'),
					(new CDiv(
						(new CDiv())
							->addClass('apm-hist-bar')
							->addClass('apm-map-'.$zone)
							->addStyle($count > 0 ? sprintf('height: max(2px, %.1f%%);', $count / $max * 100) : 'height: 0;')
					))->addClass('apm-hist-track'),
					(new CDiv($axis[$bucket]))->addClass('apm-hist-label')
				]))
					->addClass('apm-hist-col')
					->addClass($tags ? 'apm-hist-marked' : null)
					->setTitle(sprintf(I18n::t('%s: %s requests (%.1f %%)'), $labels[$bucket], self::formatCount($count),
						$total > 0 ? $count / $total * 100 : 0
					))
			);
		}

		return (new CDiv([
			(new CDiv([new CTag('strong', true, $service), ' · '.sprintf(I18n::t('%s requests'), self::formatCount($total))]))
				->addClass('apm-hist-title'),
			$bars
		]))->addClass('apm-hist');
	}

	private static function vitalCells(array $metrics): array {
		$cells = [];

		foreach (array_keys(self::WEB_VITALS) as $metric) {
			$row = $metrics[$metric] ?? null;

			$cells[] = $row === null
				? (new CSpan('—'))->addClass(ZBX_STYLE_GREY)
				: (new CSpan(self::formatVital($metric, $row['p75'])))
					->addClass('apm-pill')
					->addClass('apm-pill-'.self::vitalRating($metric, $row['p75']))
					->setTitle(sprintf(I18n::t('p75 of %s visits, %d poor'), self::formatCount($row['samples']), $row['poor']));
		}

		return $cells;
	}

	public static function vitalRating(string $metric, float $value): string {
		[, , $good, $poor] = self::WEB_VITALS[$metric];

		return $value <= $good ? 'good' : ($value <= $poor ? 'warn' : 'bad');
	}

	public static function formatVital(string $metric, float $value): string {
		return self::WEB_VITALS[$metric][1] === 'ms'
			? ApmStats::formatDuration($value * 1000000)
			: sprintf('%.2f', $value);
	}

	private static function apdexCells(array $row): array {
		$score = $row['apdex'];
		[$label, $class] = match (true) {
			$score >= 0.94 => [I18n::t('Excellent'), 'good'],
			$score >= 0.85 => [I18n::t('Good'), 'good'],
			$score >= 0.7 => [I18n::t('Fair'), 'warn'],
			$score >= 0.5 => [I18n::t('Poor'), 'bad'],
			default => [I18n::t('Unacceptable'), 'bad']
		};

		return [
			(new CSpan(sprintf('%.2f %s', $score, $label)))->addClass('apm-pill')->addClass('apm-pill-'.$class),
			self::formatCount($row['satisfied']),
			self::formatCount($row['tolerating']),
			self::formatCount($row['requests'] - $row['satisfied'] - $row['tolerating']),
			self::formatCount($row['requests'])
		];
	}

	/**
	 * Span name of a server span; a bare HTTP method means the router matched no route (404 and the like).
	 */
	public static function endpointLabel(string $endpoint, array $codes): array|string {
		if (preg_match('/^[A-Z]+$/', $endpoint) !== 1) {
			return $endpoint;
		}

		return [
			$endpoint.' ',
			(new CSpan('('.I18n::t('no route').($codes ? ' · '.implode(', ', $codes) : '').')'))
				->addClass(ZBX_STYLE_GREY)
				->setTitle(I18n::t('The request matched no route of the application (e.g. a URL that does not exist).'))
		];
	}

	public static function errorRatePill(float $error_rate): CSpan {
		$pill = (new CSpan(sprintf('%.2f %%', $error_rate)))->addClass('apm-pill');

		if ($error_rate >= self::ERROR_RATE_HIGH) {
			$pill->addClass('apm-pill-bad');
		}
		elseif ($error_rate >= self::ERROR_RATE_WARNING) {
			$pill->addClass('apm-pill-warn');
		}

		return $pill;
	}

	/**
	 * Link to a trace: opens the trace panel of the module (apm.trace.panel, see CApmTracePanel); without JavaScript,
	 * or with a modified click, the native trace list filtered by that trace.
	 */
	public static function traceLink(string $traceid, int $clock, ?string $label = null): CLink {
		return (new CLink($label ?? substr($traceid, 0, 8).'…', self::getTraceUrl($traceid, $clock)))
			->addClass($label === null ? 'apm-overview-traceid' : null)
			->setAttribute('data-apm-trace', $traceid)
			->setAttribute('data-clock', (string) $clock)
			->setTitle(sprintf(I18n::t('Trace ID %s · open the trace'), $traceid));
	}

	/**
	 * State of an error group as a pill.
	 */
	public static function statePill(string $state): CSpan {
		[$label, $class, $title] = match ($state) {
			ApmStats::STATE_NEW => [I18n::t('New'), 'apm-pill-bad',
				I18n::t('First seen in the recent window: it had not happened before in the time read.')
			],
			ApmStats::STATE_REGRESSED => [I18n::t('Came back'), 'apm-pill-bad',
				I18n::t('It had stopped happening and is happening again.')
			],
			ApmStats::STATE_RESOLVED => [I18n::t('Resolved'), 'apm-pill-good',
				I18n::t('It has not happened in the recent window.')
			],
			default => [I18n::t('Active'), 'apm-pill-warn', I18n::t('It keeps happening.')]
		};

		return (new CSpan($label))->addClass('apm-pill')->addClass($class)->setTitle($title);
	}

	/**
	 * "5 min ago", "2 h ago": how long since a moment, against the end of the period.
	 */
	public static function ago(int $clock, int $now): string {
		$seconds = max(0, $now - $clock);

		return match (true) {
			$seconds < SEC_PER_MIN => I18n::t('just now'),
			$seconds < SEC_PER_HOUR => sprintf(I18n::t('%d min ago'), intdiv($seconds, SEC_PER_MIN)),
			$seconds < SEC_PER_DAY => sprintf(I18n::t('%d h ago'), intdiv($seconds, SEC_PER_HOUR)),
			default => sprintf(I18n::t('%d d ago'), intdiv($seconds, SEC_PER_DAY))
		};
	}

	/**
	 * Empty state without traces: what to do about it.
	 */
	private static function noTraces(): CDiv {
		return new CDiv([
			self::hint(I18n::t('No traces in the selected period.')),
			new CLink(I18n::t('How to send traces to Zabbix'), self::DOCS_URL.'/blob/main/docs/installation.md')
		]);
	}

	public static function hint(string $text): CDiv {
		return (new CDiv($text))
			->addClass(ZBX_STYLE_GREY)
			->addClass('apm-overview-hint');
	}

	public static function formatTime(int $clock): string {
		return zbx_date2str(date('Ymd', $clock) === date('Ymd') ? 'H:i:s' : DATE_TIME_FORMAT_SECONDS, $clock);
	}

	public static function formatRate(float $rpm): string {
		return $rpm >= 10 ? (string) round($rpm) : sprintf('%.1f', $rpm);
	}

	public static function formatCount(int $value): string {
		return number_format($value, 0, '.', ' ');
	}

	public static function truncate(string $text, int $length): string {
		return mb_strlen($text) > $length ? mb_substr($text, 0, $length).'…' : $text;
	}
}

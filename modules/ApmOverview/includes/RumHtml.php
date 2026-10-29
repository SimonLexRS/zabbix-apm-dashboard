<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

use CCol,
	CDiv,
	CLink,
	CSection,
	CSpan,
	CTableInfo,
	CTag,
	CUrl;

/**
 * Renders the blocks of the Real user monitoring page, one per tab, and the timeline of a session. All user and
 * telemetry data goes through CTag, which escapes it.
 */
class RumHtml {

	private const PHASE_LABELS = [
		'redirect' => 'Redirect',
		'dns' => 'DNS lookup',
		'connection' => 'Connection',
		'tls' => 'TLS',
		'request' => 'Waiting for the server',
		'response' => 'Page download',
		'dom' => 'Processing until interactive',
		'resources' => 'Loading resources',
		'load_event' => 'Load event'
	];

	private const RESOURCE_LABELS = [
		'script' => 'Scripts',
		'stylesheet' => 'Styles',
		'image' => 'Images',
		'font' => 'Fonts',
		'other' => 'Other'
	];

	/**
	 * @param array $ctx  ['from', 'to', 'page', 'application']
	 */
	public static function renderBlock(string $tab, array $data, array $ctx): CDiv {
		$notice = OverviewHtml::sourceNotice($data);

		if ($notice !== null) {
			return $notice;
		}

		return match ($tab) {
			RumLoader::TAB_OVERVIEW => self::overview($data, $ctx),
			RumLoader::TAB_PAGES => self::pages($data, $ctx),
			RumLoader::TAB_RESOURCES => self::resources($data, $ctx),
			RumLoader::TAB_SESSIONS => self::sessions($data, $ctx),
			RumLoader::TAB_HOWTO => self::howto($data),
			default => new CDiv()
		};
	}

	public static function overview(array $data, array $ctx): CDiv {
		$kpis = $data['kpis'];

		if ($kpis['views'] == 0 && $kpis['requests'] == 0 && $kpis['actions'] == 0) {
			return self::noBrowserData();
		}

		$card = static fn(string $label, string $value, string $note, ?string $class = null): CDiv => (new CDiv([
			(new CDiv($label))->addClass(ZBX_STYLE_GREY),
			(new CDiv($value))->addClass('apm-map-kpi-value')->addClass($class),
			(new CDiv($note))->addClass(ZBX_STYLE_GREY)
		]))->addClass('apm-map-kpi');

		$failed_rate = $kpis['requests'] > 0 ? $kpis['failed'] / $kpis['requests'] * 100 : 0;

		$cards = (new CDiv([
			$card(I18n::t('Sessions'), OverviewHtml::formatCount($kpis['sessions']),
				sprintf(I18n::t('%s users'), OverviewHtml::formatCount($kpis['users']))
			),
			$card(I18n::t('Page loads'), OverviewHtml::formatCount($kpis['views']),
				$kpis['load_p75'] !== null
					? sprintf(I18n::t('load p75 %s'), ApmStats::formatDuration($kpis['load_p75']))
					: I18n::t('no page loads')
			),
			$card(I18n::t('User actions'), OverviewHtml::formatCount($kpis['actions']),
				$kpis['sessions'] > 0
					? sprintf(I18n::t('%.1f per session'), $kpis['actions'] / $kpis['sessions'])
					: ''
			),
			$card(I18n::t('Requests from the browser'), OverviewHtml::formatCount($kpis['requests']),
				sprintf(I18n::t('%s failed (%.2f %%)'), OverviewHtml::formatCount($kpis['failed']), $failed_rate),
				$failed_rate >= 5 ? 'apm-map-kpi-bad' : ($failed_rate >= 1 ? 'apm-map-kpi-warn' : null)
			),
			$card(I18n::t('First content visible'),
				$kpis['fcp_p75'] !== null ? sprintf('%.0f ms', $kpis['fcp_p75']) : '—',
				I18n::t('p75, from the page loads')
			)
		]))
			->addClass('apm-map-kpis')
			->addClass('apm-rum-kpis');

		$block = new CDiv($cards);

		// Core Web Vitals, measured by the application (log records with rum.*).
		$vitals = $data['vitals'];

		$block->addItem(
			(new CSection($vitals['overall']
				? OverviewHtml::vitalCards($vitals)
				: (OverviewHtml::sectionNotice($data, 'vitals') ?? OverviewHtml::hint(
					in_array('logs', ApmAccess::signals(), true)
						? I18n::t('No Core Web Vitals in this period. They come from the application as OTLP log records with rum.* attributes; the first content visible figure above comes from the page loads instead.')
						: I18n::t('Core Web Vitals are log records: your role needs APM → Logs to see them.')
				))
			))->setHeader(new CTag('h4', true, I18n::t('Core Web Vitals')))
		);

		$block->addItem(
			(new CSection(OverviewHtml::sectionNotice($data, 'activity') ?? self::activityChart($data['activity'])))
				->setHeader(new CTag('h4', true, I18n::t('Activity per minute')))
		);

		// Slowest pages: the ones with several loads, by p75.
		$pages = array_filter($data['pages'], static fn(array $page): bool => $page['views'] >= 2);
		usort($pages, static fn(array $a, array $b): int => $b['p75'] <=> $a['p75']);

		$page_table = (new CTableInfo())
			->setHeader([I18n::t('Page'), I18n::t('Load p75'), I18n::t('Page loads')])
			->setNoDataMessage(I18n::t('No page loads in this period.'));

		foreach (array_slice($pages, 0, 6) as $page) {
			$page_table->addRow([
				(new CCol(new CLink($page['page'], self::url(RumLoader::TAB_PAGES, ['filter_page' => $page['page']]))))
					->addClass(ZBX_STYLE_WORDBREAK),
				ApmStats::formatDuration($page['p75']),
				OverviewHtml::formatCount($page['views'])
			]);
		}

		$failing = array_filter($data['requests'], static fn(array $request): bool => $request['failed'] > 0);

		$request_table = (new CTableInfo())
			->setHeader([I18n::t('Request'), I18n::t('Failed'), I18n::t('Latest trace')])
			->setNoDataMessage(I18n::t('No failed requests in this period.'));

		foreach (array_slice($failing, 0, 6) as $request) {
			$request_table->addRow([
				(new CCol($request['method'].' '.$request['path']))->addClass(ZBX_STYLE_WORDBREAK),
				(new CSpan(OverviewHtml::formatCount($request['failed']).' / '.OverviewHtml::formatCount($request['count'])))
					->addClass('apm-pill')
					->addClass('apm-pill-bad'),
				OverviewHtml::traceLink($request['traceid'], $request['clock'])
			]);
		}

		$agents = $data['agents'];

		$block->addItem(
			(new CDiv([
				(new CSection(OverviewHtml::sectionNotice($data, 'pages') ?? $page_table))
					->setHeader(new CTag('h4', true, I18n::t('Slowest pages'))),
				(new CSection(OverviewHtml::sectionNotice($data, 'requests') ?? $request_table))
					->setHeader(new CTag('h4', true, I18n::t('Requests that fail'))),
				(new CSection(OverviewHtml::sectionNotice($data, 'agents') ?? self::shares($agents['browsers'])))
					->setHeader(new CTag('h4', true, I18n::t('Browsers'))),
				(new CSection(OverviewHtml::sectionNotice($data, 'agents') ?? self::shares($agents['systems'])))
					->setHeader(new CTag('h4', true, I18n::t('Operating systems')))
			]))->addClass('apm-rum-grid')
		);

		return $block;
	}

	public static function pages(array $data, array $ctx): CDiv {
		$block = new CDiv();

		if ($ctx['page'] !== '') {
			$loads = $data['loads'];
			$waterfalls = [];

			foreach (['median' => I18n::t('Typical load (median)'), 'slowest' => I18n::t('Slowest load')] as $key => $title) {
				if ($loads[$key] !== null) {
					$waterfalls[] = self::loadWaterfall($loads[$key], $title);
				}
			}

			$block->addItem(
				(new CSection([
					OverviewHtml::sectionNotice($data, 'loads'),
					$waterfalls
						? (new CDiv($waterfalls))->addClass('apm-rum-waterfalls')
						: OverviewHtml::hint(I18n::t('No loads of this page in this period.')),
					(new CLink(I18n::t('All pages'), self::url(RumLoader::TAB_PAGES, ['filter_page' => ''])))
						->addClass(ZBX_STYLE_BTN_ALT)
				]))->setHeader(new CTag('h4', true, sprintf(I18n::t('How %s loads'), $ctx['page'])))
			);
		}

		$table = (new CTableInfo())
			->setHeader([I18n::t('Page'), I18n::t('Page loads'), I18n::t('Sessions'), I18n::t('Load p75'),
				I18n::t('Load p95'), I18n::t('First content visible p75')
			])
			->setNoDataMessage(I18n::t('No page loads in this period.'));

		foreach ($data['pages'] as $page) {
			$table->addRow([
				(new CCol(new CLink($page['page'], self::url(RumLoader::TAB_PAGES, ['filter_page' => $page['page']]))))
					->addClass(ZBX_STYLE_WORDBREAK),
				OverviewHtml::formatCount($page['views']),
				OverviewHtml::formatCount($page['sessions']),
				ApmStats::formatDuration($page['p75']),
				ApmStats::formatDuration($page['p95']),
				$page['fcp_p75'] !== null ? sprintf('%.0f ms', $page['fcp_p75']) : '—'
			]);
		}

		return $block->addItem($table);
	}

	public static function resources(array $data, array $ctx): CDiv {
		$resource_table = (new CTableInfo())
			->setHeader([I18n::t('Kind'), I18n::t('Host'), I18n::t('Loads'), 'p75', I18n::t('DNS'),
				I18n::t('Connection'), I18n::t('Waiting'), I18n::t('Download')
			])
			->setNoDataMessage(I18n::t('No resources in this period.'));

		foreach ($data['resources'] as $resource) {
			$resource_table->addRow([
				I18n::t(self::RESOURCE_LABELS[$resource['type']] ?? 'Other'),
				self::host($resource['host']),
				OverviewHtml::formatCount($resource['count']),
				ApmStats::formatDuration($resource['p75']),
				sprintf('%.1f ms', $resource['dns']),
				sprintf('%.1f ms', $resource['connection']),
				sprintf('%.1f ms', $resource['waiting']),
				sprintf('%.1f ms', $resource['download'])
			]);
		}

		$request_table = (new CTableInfo())
			->setHeader([I18n::t('Method'), I18n::t('Path'), I18n::t('Host'), I18n::t('Status'), I18n::t('Requests'),
				I18n::t('Failed'), 'p75', I18n::t('Latest trace')
			])
			->setNoDataMessage(I18n::t('No requests from the browser in this period.'));

		foreach ($data['requests'] as $request) {
			$request_table->addRow([
				$request['method'],
				(new CCol($request['path']))->addClass(ZBX_STYLE_WORDBREAK),
				self::host($request['host']),
				(new CSpan($request['status']))
					->addClass('apm-pill')
					->addClass(str_starts_with($request['status'], '2') || str_starts_with($request['status'], '3')
						? 'apm-pill-good'
						: ($request['failed'] > 0 ? 'apm-pill-bad' : 'apm-pill-warn')
					),
				OverviewHtml::formatCount($request['count']),
				$request['failed'] > 0
					? (new CSpan(OverviewHtml::formatCount($request['failed'])))->addClass(ZBX_STYLE_RED)
					: '0',
				ApmStats::formatDuration($request['p75']),
				OverviewHtml::traceLink($request['traceid'], $request['clock'])
			]);
		}

		return new CDiv([
			(new CSection([
				OverviewHtml::hint(I18n::t('Files the pages load (resource timing): average time of each phase. A cached file has no network phases.')),
				OverviewHtml::sectionNotice($data, 'resources') ?? $resource_table
			]))->setHeader(new CTag('h4', true, I18n::t('Resources'))),
			(new CSection([
				OverviewHtml::hint(I18n::t('Calls made by the page code (fetch, XMLHttpRequest), grouped by method, path and status. The trace continues in the backend services.')),
				OverviewHtml::sectionNotice($data, 'requests') ?? $request_table
			]))->setHeader(new CTag('h4', true, I18n::t('Requests from the browser')))
		]);
	}

	public static function sessions(array $data, array $ctx): CDiv {
		$table = (new CTableInfo())
			->setHeader([I18n::t('Start'), I18n::t('User'), I18n::t('Pages'), I18n::t('Page loads'),
				I18n::t('Actions'), I18n::t('Requests'), I18n::t('Failed'), I18n::t('Duration'), I18n::t('Browser'), ''
			])
			->setNoDataMessage(I18n::t('No sessions in this period. Sessions need the session.id attribute on the browser spans (see How it works).'));

		foreach ($data['sessions'] as $session) {
			$agent = Rum::userAgent($session['ua']);
			$from = intdiv($session['start'], 1000000000) - SEC_PER_MIN;
			$till = intdiv($session['end'], 1000000000) + SEC_PER_MIN;

			$table->addRow([
				OverviewHtml::formatTime(intdiv($session['start'], 1000000000)),
				(new CSpan(OverviewHtml::truncate($session['user'] !== '' ? $session['user'] : '—', 14)))
					->setTitle($session['user']),
				(new CCol(implode(', ', array_slice($session['pages'], 0, 3)).(count($session['pages']) > 3 ? ', …' : '')))
					->addClass(ZBX_STYLE_WORDBREAK),
				OverviewHtml::formatCount($session['views']),
				OverviewHtml::formatCount($session['actions']),
				OverviewHtml::formatCount($session['requests']),
				$session['failed'] > 0
					? (new CSpan(OverviewHtml::formatCount($session['failed'])))->addClass('apm-pill')->addClass('apm-pill-bad')
					: '0',
				ApmStats::formatDuration($session['end'] - $session['start']),
				(new CSpan($agent['browser'].' · '.$agent['os']))->setTitle($session['ua']),
				(new CTag('button', true, I18n::t('Timeline')))
					->setAttribute('type', 'button')
					->addClass(ZBX_STYLE_BTN_LINK)
					->setAttribute('data-apm-session', $session['session'])
					->setAttribute('data-from', (string) $from)
					->setAttribute('data-to', (string) $till)
			]);
		}

		return new CDiv([
			OverviewHtml::hint(sprintf(I18n::t('The %d most recent sessions of the period. Open one to follow it step by step, down to the backend services of each request.'),
				RumStats::SESSION_LIMIT
			)),
			$table
		]);
	}

	public static function howto(array $data): CDiv {
		$checks = $data['checks'];
		$table = (new CTableInfo())->setHeader([I18n::t('Check'), I18n::t('State'), I18n::t('Detail')]);

		$state = static fn(bool $ok, bool $required = true): CSpan => (new CSpan($ok
			? I18n::t('OK')
			: ($required ? I18n::t('Missing') : I18n::t('Optional'))
		))->addClass($ok ? ZBX_STYLE_GREEN : ($required ? ZBX_STYLE_RED : ZBX_STYLE_GREY));

		$minutes = RumLoader::CHECK_WINDOW / SEC_PER_MIN;

		$table
			->addRow([I18n::t('Browser telemetry'), $state($checks['spans'] > 0),
				$checks['spans'] > 0
					? sprintf(I18n::t('%s spans in the last %d minutes from %s'), OverviewHtml::formatCount($checks['spans']),
						$minutes, implode(', ', $checks['services'])
					)
					: sprintf(I18n::t('No spans with telemetry.sdk.language = webjs in the last %d minutes.'), $minutes)
			])
			->addRow([I18n::t('Sessions'), $state($checks['spans'] > 0 && $checks['with_session'] == $checks['spans']),
				sprintf(I18n::t('%s of %s spans carry session.id'), OverviewHtml::formatCount($checks['with_session']),
					OverviewHtml::formatCount($checks['spans'])
				)
			])
			->addRow([I18n::t('Page loads'), $state($checks['views'] > 0),
				sprintf(I18n::t('%s documentLoad spans'), OverviewHtml::formatCount($checks['views']))
			])
			->addRow([I18n::t('User actions'), $state($checks['actions'] > 0, false),
				sprintf(I18n::t('%s spans of the user interaction instrumentation'), OverviewHtml::formatCount($checks['actions']))
			])
			->addRow([I18n::t('Requests linked to the backend'),
				$state($checks['request_traces'] > 0 && $checks['linked'] == $checks['request_traces'], false),
				$checks['request_traces'] > 0
					? sprintf(I18n::t('%s of %s request traces continue in a backend service'),
						OverviewHtml::formatCount($checks['linked']), OverviewHtml::formatCount($checks['request_traces'])
					)
					: I18n::t('No requests from the browser.')
			]);

		return new CDiv($table);
	}

	/**
	 * Timeline of one session: page loads, actions with the requests they made, and the other requests, each request
	 * with the backend services of its trace.
	 */
	public static function session(array $data): CDiv {
		$notice = OverviewHtml::sourceNotice($data);

		if ($notice !== null) {
			return $notice;
		}

		$spans = $data['spans'];

		if (!$spans) {
			return OverviewHtml::hint(I18n::t('Nothing recorded for this session in the period.'));
		}

		$first = $spans[0];
		$last_end = max(array_map(static fn(array $span): float => $span['start'] + $span['duration'], $spans));
		$counts = array_count_values(array_column($spans, 'type'));
		$failed = count(array_filter($spans, static fn(array $span): bool => $span['failed']));

		$header = (new CDiv([
			(new CDiv([new CTag('strong', true, I18n::t('Session')), ' ', $data['session']]))->addClass(ZBX_STYLE_WORDBREAK),
			(new CDiv(sprintf(I18n::t('%s · %s · %d page loads · %d actions · %d requests · %d failed'),
				OverviewHtml::formatTime($first['clock']), ApmStats::formatDuration($last_end - $first['start']),
				$counts['view'] ?? 0, $counts['action'] ?? 0, $counts['request'] ?? 0, $failed
			)))->addClass(ZBX_STYLE_GREY)
		]))->addClass('apm-rum-session-head');

		$list = (new CTag('ol', true))->addClass('apm-rum-timeline');

		foreach ($data['items'] as $item) {
			$row = self::timelineRow($item, $data['backend']);

			if ($item['type'] === 'action' && $item['requests']) {
				$nested = (new CTag('ol', true))->addClass('apm-rum-timeline-nested');

				foreach ($item['requests'] as $request) {
					$nested->addItem(self::timelineRow($request, $data['backend']));
				}

				$row->addItem($nested);
			}

			$list->addItem($row);
		}

		return new CDiv([
			$header,
			$data['truncated']
				? OverviewHtml::hint(sprintf(I18n::t('Only the first %d events of the session are shown.'), RumStats::TIMELINE_LIMIT))
				: null,
			$list
		]);
	}

	private static function timelineRow(array $item, array $backend): CTag {
		$offset = (new CSpan(sprintf('+%.1f s', $item['offset_ms'] / 1000)))->addClass('apm-rum-offset');

		switch ($item['type']) {
			case 'view':
				$content = [
					(new CSpan(I18n::t('Page load')))->addClass('apm-rum-kind'),
					new CTag('strong', true, $item['page']),
					(new CSpan(sprintf(I18n::t('load %s'), ApmStats::formatDuration($item['duration']))
						.($item['fcp'] !== null ? sprintf(I18n::t(' · first content %.0f ms'), $item['fcp']) : '')
					))->addClass(ZBX_STYLE_GREY)
				];
				break;

			case 'action':
				$content = [
					(new CSpan(I18n::t('Action')))->addClass('apm-rum-kind'),
					(new CSpan(trim($item['event'].' '.($item['element'] !== '' ? $item['element'] : ''))))
						->setTitle($item['xpath'])
				];
				break;

			default:
				$trace = $backend[$item['trace']] ?? null;
				$status = $item['status'] !== '' ? $item['status'] : '—';

				$content = [
					(new CSpan(I18n::t('Request')))->addClass('apm-rum-kind'),
					(new CSpan($item['method'].' '.$item['page']))->setTitle($item['url']),
					(new CSpan($status))->addClass('apm-pill')->addClass($item['failed'] ? 'apm-pill-bad' : 'apm-pill-good'),
					(new CSpan(ApmStats::formatDuration($item['duration'])))->addClass(ZBX_STYLE_GREY),
					$trace !== null
						? (new CSpan([
							'→ ',
							implode(' → ', $trace['services']),
							$trace['error_service'] !== null
								? (new CSpan(sprintf(I18n::t(' · fails in %s'), $trace['error_service'])))->addClass(ZBX_STYLE_RED)
								: null
						]))->addClass('apm-rum-backend')
						: (new CSpan(I18n::t('no backend trace')))->addClass(ZBX_STYLE_GREY),
					OverviewHtml::traceLink($item['trace'], $item['clock'])
				];
		}

		return (new CTag('li', true, [$offset, (new CSpan($content))->addClass('apm-rum-event')]))
			->addClass('apm-rum-'.$item['type']);
	}

	/**
	 * Waterfall of one page load: each phase as a bar on the time of the load, and the first content visible mark.
	 */
	private static function loadWaterfall(array $load, string $title): CDiv {
		$phases = Rum::loadPhases($load['events']);
		$total = max($phases['total'], $load['duration'] / 1e6, 1);
		$rows = (new CDiv())->addClass('apm-rum-wf');

		foreach ($phases['phases'] as $phase) {
			$rows->addItem(
				(new CDiv([
					(new CDiv(I18n::t(self::PHASE_LABELS[$phase['key']])))->addClass('apm-rum-wf-name'),
					(new CDiv(
						(new CDiv())
							->addClass('apm-rum-wf-bar')
							->addClass('apm-rum-phase-'.$phase['key'])
							->setAttribute('style', sprintf('left: %.2f%%; width: %.2f%%;', $phase['start'] / $total * 100,
								max(0.4, ($phase['end'] - $phase['start']) / $total * 100)
							))
					))->addClass('apm-rum-wf-track'),
					(new CDiv(sprintf('%.0f ms', $phase['end'] - $phase['start'])))->addClass('apm-rum-wf-ms')
				]))->addClass('apm-rum-wf-row')
			);
		}

		$fcp = $phases['markers']['firstContentfulPaint'] ?? null;

		if ($fcp !== null) {
			$rows->addItem(
				(new CDiv([
					(new CDiv(I18n::t('First content visible')))->addClass('apm-rum-wf-name'),
					(new CDiv(
						(new CDiv())
							->addClass('apm-rum-wf-mark')
							->setAttribute('style', sprintf('left: %.2f%%;', $fcp / $total * 100))
					))->addClass('apm-rum-wf-track'),
					(new CDiv(sprintf('%.0f ms', $fcp)))->addClass('apm-rum-wf-ms')
				]))->addClass('apm-rum-wf-row')
			);
		}

		return (new CDiv([
			(new CDiv([
				new CTag('strong', true, $title),
				' · ',
				ApmStats::formatDuration($load['duration']),
				' · ',
				OverviewHtml::traceLink($load['traceid'], $load['clock'])
			]))->addClass('apm-rum-wf-title'),
			$phases['phases'] ? $rows : OverviewHtml::hint(I18n::t('This load has no timing events.'))
		]))->addClass('apm-rum-waterfall');
	}

	/**
	 * Sessions per minute (bars) and load time p75 per minute (line), on the same time axis.
	 */
	private static function activityChart(array $activity): CTag|CDiv {
		$count = count($activity['minutes']);

		if ($count < 2) {
			return OverviewHtml::hint(I18n::t('The period is too short for a per-minute chart.'));
		}

		$width = 720;
		$height = 120;
		$max_sessions = max(1, max($activity['sessions']));
		$max_load = max(1, max($activity['load_p75']));
		$step = $width / $count;
		$title = I18n::t('Sessions per minute (bars) and load p75 (line)');

		$svg = (new CTag('svg', true))
			->addClass('apm-rum-activity')
			->setAttribute('viewBox', '0 0 '.$width.' '.$height)
			->setAttribute('preserveAspectRatio', 'none')
			->setAttribute('role', 'img')
			->setAttribute('aria-label', $title)
			->addItem(new CTag('title', true, $title));

		$points = [];

		foreach ($activity['minutes'] as $i => $minute) {
			$bar = $activity['sessions'][$i] / $max_sessions * ($height - 10);

			$svg->addItem(
				(new CTag('rect', true, new CTag('title', true, sprintf(I18n::t('%s: %d sessions, %d page loads, load p75 %s'),
					zbx_date2str('H:i', $minute), $activity['sessions'][$i], $activity['views'][$i],
					ApmStats::formatDuration($activity['load_p75'][$i])
				))))
					->addClass('apm-rum-activity-bar')
					->setAttribute('x', sprintf('%.1f', $i * $step + 1))
					->setAttribute('y', sprintf('%.1f', $height - $bar))
					->setAttribute('width', sprintf('%.1f', max(1, $step - 2)))
					->setAttribute('height', sprintf('%.1f', $bar))
			);

			if ($activity['load_p75'][$i] > 0) {
				$points[] = sprintf('%.1f,%.1f', ($i + 0.5) * $step,
					$height - 5 - $activity['load_p75'][$i] / $max_load * ($height - 15)
				);
			}
		}

		if (count($points) > 1) {
			$svg->addItem((new CTag('polyline', true))->addClass('apm-rum-activity-line')->setAttribute('points', implode(' ', $points)));
		}

		return (new CDiv([
			$svg,
			(new CDiv([
				(new CSpan(sprintf(I18n::t('Sessions (max %d per minute)'), $max_sessions)))->addClass('apm-rum-key-bar'),
				(new CSpan(sprintf(I18n::t('Load p75 (max %s)'), ApmStats::formatDuration($max_load))))->addClass('apm-rum-key-line'),
				(new CSpan(sprintf(I18n::t('%s to %s'), zbx_date2str('H:i', $activity['minutes'][0]),
					zbx_date2str('H:i', end($activity['minutes']) + SEC_PER_MIN)
				)))->addClass(ZBX_STYLE_GREY)
			]))->addClass('apm-rum-activity-legend')
		]))->addClass('apm-rum-activity-wrap');
	}

	/**
	 * Share of the sessions per name, as horizontal bars.
	 */
	private static function shares(array $counts): CDiv {
		$total = array_sum($counts);

		if ($total == 0) {
			return OverviewHtml::hint(I18n::t('No user agents in this period.'));
		}

		$list = (new CDiv())->addClass('apm-rum-shares');

		foreach (array_slice($counts, 0, 6, true) as $name => $count) {
			$share = $count / $total * 100;

			$list->addItem(
				(new CDiv([
					(new CDiv($name === 'Other' ? I18n::t('Other') : $name))->addClass('apm-rum-share-name'),
					(new CDiv((new CDiv())->addClass('apm-rum-share-bar')->setAttribute('style', sprintf('width: %.1f%%;', $share))))
						->addClass('apm-rum-share-track'),
					(new CDiv(sprintf('%.0f %%', $share)))->addClass('apm-rum-share-value')
				]))
					->addClass('apm-rum-share')
					->setTitle(sprintf(I18n::t('%s sessions'), OverviewHtml::formatCount($count)))
			);
		}

		return $list;
	}

	private static function noBrowserData(): CDiv {
		return new CDiv([
			OverviewHtml::hint(I18n::t('No browser telemetry for this application in the period. This page shows the spans of the OpenTelemetry web SDK (telemetry.sdk.language = webjs): page loads, user actions and the requests of the page. The How it works tab shows how to instrument a web application.'))
		]);
	}

	private static function host(string $host): string|CSpan {
		return $host !== '' ? $host : (new CSpan(I18n::t('same origin')))->addClass(ZBX_STYLE_GREY);
	}

	/**
	 * Link to a tab of this page, keeping the period and changing some filter fields.
	 */
	private static function url(string $tab, array $filter = []): string {
		$url = (new CUrl('zabbix.php'))
			->setArgument('action', 'apm.rum')
			->setArgument('tab', $tab);

		if ($filter) {
			$url->setArgument('filter_set', 1);

			foreach ($filter as $name => $value) {
				$url->setArgument($name, $value);
			}
		}

		return $url->getUrl();
	}
}

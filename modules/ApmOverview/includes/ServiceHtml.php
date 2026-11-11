<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

use CCol,
	CDiv,
	CLink,
	CRow,
	CSection,
	CSpan,
	CTableInfo,
	CTag,
	CUrl;

/**
 * Page of one service or one of its endpoints, and the service panel of the map. Every value comes from telemetry and
 * goes through CTag, which escapes it.
 */
class ServiceHtml {

	private const SEVERITY_CLASSES = [
		0 => ZBX_STYLE_NA_BG, 1 => ZBX_STYLE_INFO_BG, 2 => ZBX_STYLE_WARNING_BG, 3 => ZBX_STYLE_AVERAGE_BG,
		4 => ZBX_STYLE_HIGH_BG, 5 => ZBX_STYLE_DISASTER_BG
	];

	/**
	 * @param array $data  ServiceLoader::load() for that tab.
	 * @param array $ctx   ['from', 'to'] period of the links to the native pages, 'apdex_ms'.
	 */
	public static function renderBlock(string $tab, array $data, array $ctx): CDiv {
		if (($notice = OverviewHtml::sourceNotice($data)) !== null) {
			return $notice;
		}

		return match ($tab) {
			ServiceLoader::TAB_OVERVIEW => self::overview($data, $ctx),
			ServiceLoader::TAB_ERRORS => self::errors($data, $ctx),
			ServiceLoader::TAB_ANALYSIS => self::analysis($data, $ctx),
			default => new CDiv()
		};
	}

	private static function overview(array $data, array $ctx): CDiv {
		if ($data['stats'] === null) {
			return new CDiv(OverviewHtml::hint(I18n::t('No requests in the selected period.')));
		}

		$block = new CDiv([self::header($data, $ctx), self::problems($data['problems'])]);
		$series = $data['timeseries'] ?? [];

		if ($series) {
			$minutes = $series['step'] / SEC_PER_MIN;
			$marks = array_map(static fn(array $version): array => [
				'clock' => $version['first'],
				'label' => sprintf(I18n::t('Version %s first seen at %s'), $version['version'],
					OverviewHtml::formatTime($version['first'])
				)
			], array_filter($data['versions'] ?? [], static fn(array $version): bool => $version['deployed']));

			$compare = static function (string $key, callable $value) use ($series): array {
				$lines = [['label' => I18n::t('This period'), 'class' => 'apm-chart-now',
					'values' => array_map($value, $series['now'][$key], array_keys($series['now'][$key]))
				]];

				foreach (['day' => I18n::t('Yesterday'), 'week' => I18n::t('Last week')] as $name => $label) {
					if ($series[$name] !== null) {
						$lines[] = ['label' => $label, 'class' => 'apm-chart-'.$name, 'dashed' => true,
							'values' => array_map($value, $series[$name][$key], array_keys($series[$name][$key]))
						];
					}
				}

				return $lines;
			};

			$error_rate = static function (string $name) use ($series): array {
				return array_map(static fn(int $errors, int $requests): float => $requests > 0 ? $errors / $requests * 100 : 0.0,
					$series[$name]['errors'], $series[$name]['requests']
				);
			};

			$errors = [['label' => I18n::t('This period'), 'class' => 'apm-chart-errors', 'values' => $error_rate('now')]];

			foreach (['day' => I18n::t('Yesterday'), 'week' => I18n::t('Last week')] as $name => $label) {
				if ($series[$name] !== null) {
					$errors[] = ['label' => $label, 'class' => 'apm-chart-'.$name, 'dashed' => true, 'values' => $error_rate($name)];
				}
			}

			$block->addItem(
				(new CDiv([
					Chart::timeseries(I18n::t('Requests per minute'), $series['times'],
						$compare('requests', static fn(int $requests): float => $requests / $minutes),
						static fn(float $value): string => OverviewHtml::formatRate($value).'/min', $marks
					),
					Chart::timeseries(I18n::t('Error rate'), $series['times'], $errors,
						static fn(float $value): string => sprintf('%.2f %%', $value), $marks
					),
					Chart::timeseries(I18n::t('Latency p95'), $series['times'],
						array_merge($compare('p95', static fn(float $ns): float => $ns), [[
							'label' => 'p50', 'class' => 'apm-chart-p50', 'values' => $series['now']['p50']
						]]),
						static fn(float $value): string => ApmStats::formatDuration($value), $marks
					)
				]))->addClass('apm-chart-row')
			);
		}

		$block->addItem(OverviewHtml::sectionNotice($data, 'timeseries'));

		if ($data['endpoint'] === '') {
			$block
				->addItem(self::section(I18n::t('Who calls it and what it calls'), [
					OverviewHtml::sectionNotice($data, 'calls'), self::neighbours($data['calls'], $data)
				]))
				->addItem(self::section(I18n::t('Versions'), [self::versions($data['versions'] ?? [], $data)]))
				->addItem(self::section('Endpoints', [
					OverviewHtml::sectionNotice($data, 'endpoints'), self::endpoints($data['endpoints'], $data, $ctx)
				]))
				->addItem(self::section(I18n::t('Where does the time go?'), [
					OverviewHtml::sectionNotice($data, 'breakdown'), self::breakdown($data['breakdown'], $data)
				]));
		}
		else {
			$histogram = $data['histograms'] ? OverviewHtml::histogram($data['service'], reset($data['histograms']),
				($ctx['apdex_ms'] ?? 500) * 1000000
			) : OverviewHtml::hint(I18n::t('No requests in the selected period.'));

			$block
				->addItem(self::section(I18n::t('Latency distribution'), [$histogram]))
				->addItem(self::section(I18n::t('What a request of this endpoint is made of'), [
					OverviewHtml::sectionNotice($data, 'span_summary'), self::spanSummary($data['span_summary'], $data)
				]));
		}

		return $block;
	}

	/**
	 * Health, figures of the period and of now, language, version and hosts; with the ways out to the native pages.
	 */
	private static function header(array $data, array $ctx): CDiv {
		$stats = $data['stats'];
		$info = $data['info'];
		$recent = $stats['recent'] ?? null;
		$versions = $data['versions'] ?? [];
		$deployed = array_values(array_filter($versions, static fn(array $version): bool => $version['deployed']));

		$figure = static fn(string $label, string $value, string $note = '', ?string $class = null): CDiv => (new CDiv([
			(new CSpan($label))->addClass(ZBX_STYLE_GREY),
			(new CSpan($value))->addClass('apm-service-figure-value')->addClass($class),
			$note !== '' ? (new CSpan($note))->addClass(ZBX_STYLE_GREY) : null
		]))->addClass('apm-service-figure');

		$facts = array_filter([
			$info['browser'] ? I18n::t('browser') : ($info['language'] !== '' ? $info['language'] : null),
			$versions ? sprintf(I18n::t('version %s'), $versions[0]['version']) : null,
			$deployed ? sprintf(I18n::t('deployed %s'), OverviewHtml::ago($deployed[0]['first'], min(time(), $data['time_till']))) : null,
			$info['hosts'] ? sprintf(I18n::t('on %s'), implode(', ', array_slice($info['hosts'], 0, 3))) : null
		]);

		return (new CDiv([
			(new CDiv([
				$figure(I18n::t('Requests'), OverviewHtml::formatRate($stats['rpm']).'/min',
					sprintf(I18n::t('%s in the period'), OverviewHtml::formatCount($stats['requests']))
				),
				$figure(I18n::t('Error rate'), sprintf('%.2f %%', $stats['error_rate']),
					$recent !== null ? sprintf(I18n::t('now %.2f %%'), $recent['error_rate']) : '',
					$stats['error_rate'] >= 5 ? 'apm-map-kpi-bad' : ($stats['error_rate'] >= 1 ? 'apm-map-kpi-warn' : null)
				),
				$figure('p95', ApmStats::formatDuration($stats['p95']),
					$recent !== null && $recent['requests'] > 0
						? sprintf(I18n::t('now %s'), ApmStats::formatDuration($recent['p95']))
						: ''
				),
				$figure('p50', ApmStats::formatDuration($stats['p50']))
			]))->addClass('apm-service-figures'),
			(new CDiv([
				$facts ? new CSpan(implode(' · ', $facts)) : null,
				(new CSpan([
					new CLink(I18n::t('Traces'), OverviewHtml::getTracesUrl($ctx, $data['service'], $data['endpoint'])),
					in_array('logs', ApmAccess::signals(), true)
						? [' · ', new CLink('Logs', OverviewHtml::getLogsUrl($ctx, $data['service']))]
						: null,
					' · ',
					new CLink(I18n::t('On the service map'), ServiceMap::getServiceUrl($data['service'], OverviewHtml::TAB_MAP))
				]))->addClass('apm-service-links')
			]))->addClass('apm-service-facts')
		]))->addClass('apm-service-header');
	}

	/**
	 * Open Zabbix problems: of the service, of the host of its application and of the hosts it runs on.
	 *
	 * @param string|null $service_title  Title of the problems tagged with the service, when they are not those of
	 *                                    one service (Real user monitoring: every browser service of the application).
	 */
	public static function problems(array $problems, ?string $service_title = null): ?CDiv {
		$groups = [];

		if ($problems['service']['count'] > 0) {
			$groups[] = [$service_title ?? I18n::t('Problems of this service (tag service)'), $problems['service'], null];
		}

		if ($problems['application'] !== null && $problems['application']['problems']['count'] > 0) {
			$groups[] = [sprintf(I18n::t('Problems of the application host %s'), $problems['application']['host']['name']),
				$problems['application']['problems'], $problems['application']['host']['hostid']
			];
		}

		foreach ($problems['hosts'] as $host) {
			if ($host['problems']['count'] > 0) {
				$groups[] = [sprintf(I18n::t('Problems of the host %s, where it runs'), $host['host']['name']),
					$host['problems'], $host['host']['hostid']
				];
			}
		}

		if (!$groups) {
			return null;
		}

		$block = (new CDiv())->addClass('apm-service-problems');
		$now = time();

		foreach ($groups as [$title, $summary, $hostid]) {
			$list = (new CTag('ul', true))->addClass('apm-service-problem-list');

			foreach (array_slice($summary['problems'], 0, 8) as $problem) {
				$list->addItem(new CTag('li', true, [
					(new CSpan(\CSeverityHelper::getName($problem['severity'])))
						->addClass('apm-pill')
						->addClass(self::SEVERITY_CLASSES[$problem['severity']] ?? null),
					' ',
					new CLink($problem['name'], (new CUrl('tr_events.php'))
						->setArgument('triggerid', $problem['triggerid'] ?? 0)
						->setArgument('eventid', $problem['eventid'])
					),
					(new CSpan(' · '.OverviewHtml::ago($problem['clock'], $now)))->addClass(ZBX_STYLE_GREY)
				]));
			}

			$block->addItem(new CDiv([
				new CTag('strong', true, $title),
				$hostid !== null
					? [' ', new CLink(I18n::t('All problems'), (new CUrl('zabbix.php'))
						->setArgument('action', 'problem.view')
						->setArgument('hostids', [$hostid])
						->setArgument('filter_set', 1)
					)]
					: null,
				$list,
				$summary['count'] > 8
					? OverviewHtml::hint(sprintf(I18n::t('%d more.'), $summary['count'] - 8))
					: null
			]));
		}

		return $block;
	}

	private static function neighbours(array $calls, array $data): CDiv {
		$table = static function (string $title, array $rows, string $empty) use ($data): CDiv {
			$table = (new CTableInfo())
				->setHeader([$title, I18n::t('Calls/min'), I18n::t('Errors'), 'p95'])
				->setNoDataMessage($empty);

			foreach ($rows as $row) {
				$table->addRow([
					$row['kind'] === 'service'
						? new CLink($row['name'], ServiceMap::getServicePageUrl($row['name'], $data))
						: [$row['name'], ' ', (new CSpan(I18n::t(match ($row['kind']) {
							'database' => 'Database', 'queue' => 'Queue', 'users' => 'Users', default => 'External service'
						})))->addClass(ZBX_STYLE_GREY)],
					sprintf('%.2f', $row['rpm']),
					OverviewHtml::errorRatePill($row['error_rate']),
					ApmStats::formatDuration($row['p95'] ?? 0)
				]);
			}

			return new CDiv($table);
		};

		return (new CDiv([
			$table(I18n::t('Called by'), $calls['callers'], I18n::t('Nothing calls it in this period.')),
			$table(I18n::t('It calls'), $calls['callees'], I18n::t('It calls nothing in this period.'))
		]))->addClass('apm-service-two');
	}

	/**
	 * Versions of the service, latest first; the ones deployed in the period are marked.
	 */
	public static function versions(array $versions, array $data): CTag {
		if (!$versions) {
			return OverviewHtml::hint(I18n::t('This service does not send its version. Add service.version to its resource attributes (OTEL_RESOURCE_ATTRIBUTES=service.version=1.4.2) to see deployments, compare versions and know which one brought an error.'));
		}

		$table = (new CTableInfo())->setHeader([I18n::t('Version'), I18n::t('First seen'), I18n::t('Last seen'),
			I18n::t('Requests'), I18n::t('Share'), I18n::t('Error rate'), 'p95'
		]);
		$now = min(time(), $data['time_till']);

		foreach ($versions as $version) {
			$table->addRow([
				[
					new CTag('strong', true, $version['version']),
					$version['deployed']
						? [' ', (new CSpan(I18n::t('deployed in this period')))->addClass('apm-pill apm-pill-info')]
						: null
				],
				OverviewHtml::ago($version['first'], $now),
				OverviewHtml::ago($version['last'], $now),
				OverviewHtml::formatCount($version['requests']),
				sprintf('%.0f %%', $version['share']),
				OverviewHtml::errorRatePill($version['error_rate']),
				ApmStats::formatDuration($version['p95'])
			]);
		}

		return $table;
	}

	private static function endpoints(array $endpoints, array $data, array $ctx): CTableInfo {
		$table = (new CTableInfo())
			->setHeader(['Endpoint', I18n::t('Requests'), 'Req/min', I18n::t('Error rate'), 'p50', 'p95', 'p99'])
			->setNoDataMessage(I18n::t('No server spans (SpanKind = Server) in the selected period.'));

		foreach ($endpoints as $endpoint) {
			$table->addRow([
				(new CCol(new CLink(OverviewHtml::endpointLabel($endpoint['endpoint'], $endpoint['codes']),
					ServiceMap::getServicePageUrl($data['service'], $data, $endpoint['endpoint'])
				)))->addClass(ZBX_STYLE_WORDBREAK),
				OverviewHtml::formatCount($endpoint['requests']),
				sprintf('%.2f', $endpoint['rpm']),
				OverviewHtml::errorRatePill($endpoint['error_rate']),
				ApmStats::formatDuration($endpoint['p50']),
				ApmStats::formatDuration($endpoint['p95']),
				ApmStats::formatDuration($endpoint['p99'])
			]);
		}

		return $table;
	}

	private static function breakdown(array $rows, array $data): CTableInfo {
		$parts = ['own' => I18n::t('Own code'), 'db' => I18n::t('Database'), 'calls' => I18n::t('Other services and external')];
		$table = (new CTableInfo())
			->setHeader(['Endpoint', I18n::t('Requests'), I18n::t('Average'), I18n::t('Average time breakdown')])
			->setNoDataMessage(I18n::t('No requests in the selected period.'));

		foreach ($rows as $row) {
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

			$table->addRow([
				(new CCol(new CLink($row['endpoint'],
					ServiceMap::getServicePageUrl($data['service'], $data, $row['endpoint'])
				)))->addClass(ZBX_STYLE_WORDBREAK),
				OverviewHtml::formatCount($row['requests']),
				ApmStats::formatDuration($row['total']),
				(new CCol($bar->setTitle(implode("\n", $title))))->addClass('apm-slow-bar-cell')
			]);
		}

		return $table;
	}

	private static function spanSummary(array $rows, array $data): CTableInfo {
		$table = (new CTableInfo())
			->setHeader([I18n::t('Service'), I18n::t('Operation'), I18n::t('Per request'), I18n::t('In requests'),
				I18n::t('Average'), I18n::t('Share of the time')
			])
			->setNoDataMessage(I18n::t('The requests of this endpoint have no spans below them.'));

		foreach ($rows as $row) {
			$table->addRow([
				$row['service'] === $data['service']
					? $row['service']
					: new CLink($row['service'], ServiceMap::getServicePageUrl($row['service'], $data)),
				(new CCol([$row['name'], ' ', (new CSpan($row['kind']))->addClass(ZBX_STYLE_GREY)]))
					->addClass(ZBX_STYLE_WORDBREAK),
				sprintf('%.1f', $row['per_request']),
				sprintf('%.0f %%', $row['share_requests']),
				ApmStats::formatDuration($row['avg']),
				(new CCol(
					(new CDiv([
						(new CDiv(
							(new CDiv())->addClass('apm-slow-calls')->addStyle(sprintf('width: %.2f%%;', max(0.5, $row['share_time'])))
						))->addClass('apm-slow-bar'),
						(new CSpan(sprintf('%.0f %%', $row['share_time'])))->addClass('apm-share-value')
					]))->addClass('apm-share-cell')
				))->addClass('apm-slow-bar-cell')
			]);
		}

		return $table;
	}

	private static function errors(array $data, array $ctx): CDiv {
		$block = new CDiv(OverviewHtml::errorTable($data['errors'], [
			'resolved' => $data['resolved'], 'recent_min' => $data['recent_min'], 'time_till' => $data['time_till']
		], $data['truncated'] ?? []));

		if ($data['endpoint'] !== '' || ($data['compare'] ?? null) === null) {
			return $block;
		}

		$compare = $data['compare'];
		$comparison = $data['comparison'];
		$choices = (new CDiv(I18n::t('Compare: ')))->addClass('apm-service-compare');

		// Every other version against the newest one, one click each.
		foreach ($compare['all'] as $version) {
			if ($version === $compare['a']) {
				continue;
			}

			$url = ServiceMap::getServicePageUrl($data['service'], $data)
				->setArgument('tab', ServiceLoader::TAB_ERRORS)
				->setArgument('va', $compare['a'])
				->setArgument('vb', $version);

			$choices->addItem($version === $compare['b']
				? (new CSpan($compare['a'].' ↔ '.$version))->addClass('apm-pill apm-pill-info')
				: new CLink($compare['a'].' ↔ '.$version, $url)
			);
			$choices->addItem(' ');
		}

		$endpoints = (new CTableInfo())->setHeader(['Endpoint', sprintf(I18n::t('Requests %s'), $compare['a']),
			sprintf(I18n::t('Requests %s'), $compare['b']), sprintf(I18n::t('Errors %s'), $compare['a']),
			sprintf(I18n::t('Errors %s'), $compare['b']), 'p95 '.$compare['a'], 'p95 '.$compare['b'], I18n::t('p95 change')
		]);

		foreach ($comparison['endpoints'] as $row) {
			$change = $row['b']['p95'] > 0 && $row['a']['requests'] > 0 && $row['b']['requests'] > 0
				? ($row['a']['p95'] - $row['b']['p95']) / $row['b']['p95'] * 100
				: null;

			$endpoints->addRow([
				(new CCol($row['endpoint']))->addClass(ZBX_STYLE_WORDBREAK),
				OverviewHtml::formatCount($row['a']['requests']),
				OverviewHtml::formatCount($row['b']['requests']),
				$row['a']['requests'] > 0 ? OverviewHtml::errorRatePill($row['a']['error_rate']) : '—',
				$row['b']['requests'] > 0 ? OverviewHtml::errorRatePill($row['b']['error_rate']) : '—',
				$row['a']['requests'] > 0 ? ApmStats::formatDuration($row['a']['p95']) : '—',
				$row['b']['requests'] > 0 ? ApmStats::formatDuration($row['b']['p95']) : '—',
				$change === null
					? '—'
					: (new CSpan(sprintf('%+.0f %%', $change)))->addClass($change >= 30 ? ZBX_STYLE_RED : null)
			]);
		}

		$errors = (new CTableInfo())
			->setHeader([I18n::t('Where it happens'), 'Endpoint', I18n::t('Error'), $compare['a'], $compare['b']])
			->setNoDataMessage(I18n::t('Neither version has errors in this period.'));

		foreach (self::diffErrors($comparison['errors']) as $row) {
			$errors->addRow([
				(new CSpan(match ($row['where']) {
					'a' => sprintf(I18n::t('Only in %s'), $compare['a']),
					'b' => sprintf(I18n::t('Only in %s'), $compare['b']),
					default => I18n::t('In both')
				}))->addClass('apm-pill')->addClass($row['where'] === 'a' ? 'apm-pill-bad' : ($row['where'] === 'b' ? 'apm-pill-good' : 'apm-pill-warn')),
				(new CCol($row['endpoint']))->addClass(ZBX_STYLE_WORDBREAK),
				(new CCol([
					$row['type'] !== '' ? [new CTag('strong', true, $row['type']), ' '] : null,
					$row['message'] !== '' ? $row['message'] : (new CSpan(I18n::t('(no message)')))->addClass(ZBX_STYLE_GREY)
				]))->addClass('apm-overview-message'),
				OverviewHtml::formatCount($row['a']),
				OverviewHtml::formatCount($row['b'])
			]);
		}

		return $block->addItem(self::section(sprintf(I18n::t('Version %s against %s'), $compare['a'], $compare['b']), [
			$choices,
			OverviewHtml::sectionNotice($data, 'comparison'),
			$errors,
			$endpoints
		]));
	}

	/**
	 * Errors of two versions, the ones that only the newer one (a) has first, then the ones in both, then the ones
	 * that the older one (b) had and the newer one does not.
	 *
	 * @return array  The rows with 'where' => 'a', 'both' or 'b'.
	 */
	public static function diffErrors(array $errors): array {
		$rank = ['a' => 0, 'both' => 1, 'b' => 2];

		foreach ($errors as &$error) {
			$error['where'] = $error['a'] > 0 && $error['b'] > 0 ? 'both' : ($error['a'] > 0 ? 'a' : 'b');
		}
		unset($error);

		usort($errors, static fn(array $x, array $y): int =>
			[$rank[$x['where']], -($x['a'] + $x['b'])] <=> [$rank[$y['where']], -($y['a'] + $y['b'])]
		);

		return $errors;
	}

	private static function analysis(array $data, array $ctx): CDiv {
		$block = new CDiv();

		foreach ([
			ApmStats::TAGS_SLOW => [I18n::t('Why are some requests slow?'), I18n::t('In the slow ones')],
			ApmStats::TAGS_FAILED => [I18n::t('Why do some requests fail?'), I18n::t('In the failed ones')]
		] as $mode => [$title, $column]) {
			$analysis = $data['tags_'.$mode];
			$intro = $mode === ApmStats::TAGS_SLOW
				? sprintf(I18n::t('The slowest 5 %% of the requests (%s or more: %s requests) against the other %s. An attribute value listed here is in a much larger share of the slow ones.'),
					ApmStats::formatDuration($analysis['threshold']), OverviewHtml::formatCount($analysis['outliers']),
					OverviewHtml::formatCount($analysis['rest'])
				)
				: sprintf(I18n::t('The %s requests that failed against the %s that did not.'),
					OverviewHtml::formatCount($analysis['outliers']), OverviewHtml::formatCount($analysis['rest'])
				);

			$table = (new CTableInfo())
				->setHeader([I18n::t('Attribute'), I18n::t('Value'), $column, I18n::t('In the rest'), I18n::t('Times more')])
				->setNoDataMessage($analysis['outliers'] == 0
					? ($mode === ApmStats::TAGS_SLOW
						? I18n::t('No requests in the selected period.')
						: I18n::t('No request failed in the selected period.'))
					: I18n::t('No attribute sets these requests apart: they look like the rest.')
				);

			foreach ($analysis['tags'] as $tag) {
				$table->addRow([
					(new CCol($tag['key']))->addClass(ZBX_STYLE_NOWRAP),
					(new CCol(OverviewHtml::truncate($tag['value'], 120)))->addClass(ZBX_STYLE_WORDBREAK),
					(new CCol([
						new CTag('strong', true, sprintf('%.0f %%', $tag['share_outliers'])),
						(new CSpan(' ('.OverviewHtml::formatCount($tag['outliers']).')'))->addClass(ZBX_STYLE_GREY)
					]))->addClass(ZBX_STYLE_NOWRAP),
					sprintf('%.0f %%', $tag['share_rest']),
					$tag['lift'] !== null ? sprintf('×%.1f', $tag['lift']) : I18n::t('only here')
				]);
			}

			$block->addItem(self::section($title, [
				OverviewHtml::hint($intro), OverviewHtml::sectionNotice($data, 'tags_'.$mode), $table
			]));
		}

		$traces = (new CDiv())->addClass('apm-wf-list');

		foreach ($data['slow_traces'] as $i => $trace) {
			$traces->addItem(OverviewHtml::waterfall($trace, $i == 0));
		}

		return $block->addItem(self::section(I18n::t('Slowest requests'), [
			OverviewHtml::sectionNotice($data, 'slow_traces'),
			$data['slow_traces'] ? $traces : OverviewHtml::hint(I18n::t('No requests in the selected period.'))
		]));
	}

	/**
	 * Service panel of the map: what is wrong, the figures, who is around, and the ways further in.
	 */
	public static function panel(array $data, array $ctx): CDiv {
		if (($notice = OverviewHtml::sourceNotice($data)) !== null) {
			return $notice;
		}

		$page = new CLink(I18n::t('Open the page of this service'), ServiceMap::getServicePageUrl($data['service'], $data));

		if ($data['stats'] === null) {
			return new CDiv([OverviewHtml::hint(I18n::t('No requests in the selected period.')), $page]);
		}

		$series = $data['series'];
		$trends = $series === null ? null : (new CDiv([
			new CDiv([(new CSpan(I18n::t('Requests')))->addClass(ZBX_STYLE_GREY),
				OverviewHtml::sparkline($series['requests'], 'apm-spark-requests', I18n::t('Requests per minute'))
			]),
			new CDiv([(new CSpan(I18n::t('Error rate')))->addClass(ZBX_STYLE_GREY),
				OverviewHtml::sparkline($series['error_rate'], 'apm-spark-errors', I18n::t('Error rate per minute'))
			]),
			new CDiv([(new CSpan('p95'))->addClass(ZBX_STYLE_GREY),
				OverviewHtml::sparkline($series['p95'], 'apm-spark-p95', I18n::t('p95 per minute'))
			])
		]))->addClass('apm-service-trends');

		$list = static function (string $title, array $rows) use ($data): ?CDiv {
			if (!$rows) {
				return null;
			}

			$items = (new CTag('ul', true))->addClass('apm-service-neighbours');

			foreach (array_slice($rows, 0, 8) as $row) {
				$items->addItem(new CTag('li', true, [
					$row['kind'] === 'service'
						? new CLink($row['name'], ServiceMap::getServicePageUrl($row['name'], $data))
						: new CSpan($row['name']),
					(new CSpan(sprintf(' %s/min · p95 %s', OverviewHtml::formatRate($row['rpm']),
						ServiceMap::shortDuration($row['p95'] ?? 0)
					)))->addClass(ZBX_STYLE_GREY),
					$row['error_rate'] >= 1 ? [' ', OverviewHtml::errorRatePill($row['error_rate'])] : null
				]));
			}

			return new CDiv([new CTag('h5', true, $title.' ('.count($rows).')'), $items]);
		};

		return (new CDiv([
			self::header($data, $ctx),
			$trends,
			self::problems($data['problems']),
			$list(I18n::t('Called by'), $data['calls']['callers']),
			$list(I18n::t('It calls'), $data['calls']['callees']),
			(new CDiv([
				$page, ' · ',
				new CLink('Endpoints', OverviewHtml::getEndpointsUrl($data['service'])), ' · ',
				new CLink(I18n::t('Errors'), ServiceMap::getServicePageUrl($data['service'], $data)
					->setArgument('tab', ServiceLoader::TAB_ERRORS)
				), ' · ',
				new CLink(I18n::t('Why slow'), ServiceMap::getServicePageUrl($data['service'], $data)
					->setArgument('tab', ServiceLoader::TAB_ANALYSIS)
				)
			]))->addClass('apm-service-panel-links')
		]))->addClass('apm-service-panel-body');
	}

	private static function section(string $title, array $content): CSection {
		return (new CSection($content))->setHeader(new CTag('h4', true, $title));
	}
}

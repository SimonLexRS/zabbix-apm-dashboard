<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

use CDiv,
	CLink,
	CSpan,
	CTableInfo,
	CTag,
	CUrl;

/**
 * Trace panel: one trace from the browser to the databases, with where it came from (session, user, page), the
 * services it went through, its spans and its log records. Opened from any trace link of the module.
 *
 * Every value comes from telemetry and goes through CTag, which escapes it.
 */
class TraceHtml {

	/**
	 * @param array $data  ['traceid', 'clock', 'error' => ?string, 'trace' => TraceStats::getTrace(),
	 *                     'origin' => ?array, 'logs' => ?array (null: the role has no access to logs), 'host' => ?array]
	 */
	public static function panel(array $data): CDiv {
		if ($data['error'] !== null) {
			return new CDiv(makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $data['error']]],
				I18n::t('Cannot query the APM data source.'), false
			));
		}

		$trace = $data['trace'];
		$spans = $trace['spans'];
		$native = (new CLink(I18n::t('Open in the Zabbix trace viewer'),
			OverviewHtml::getTraceUrl($data['traceid'], $data['clock'])
		))->setTarget('_blank');

		if (!$spans) {
			return new CDiv([
				OverviewHtml::hint(I18n::t('No spans of this trace were found around that time. It may be older than the data kept in ClickHouse, or belong to an application you cannot see.')),
				$native
			]);
		}

		$root = $spans[0];
		$errors = count(array_filter(array_column($spans, 'error')));
		$services = TraceStats::services($spans);

		$head = (new CDiv([
			(new CDiv([
				new CTag('strong', true, $root['service']),
				' · ',
				$root['name'],
				' ',
				$errors > 0
					? (new CSpan(sprintf(I18n::t('%d failed spans'), $errors)))->addClass('apm-pill apm-pill-bad')
					: (new CSpan(I18n::t('no errors')))->addClass('apm-pill apm-pill-good')
			]))->addClass('apm-trace-title'),
			(new CDiv([
				sprintf(I18n::t('%s · %s · %d spans · %d services'),
					OverviewHtml::formatTime(intdiv($trace['start'], 1000000000)),
					ApmStats::formatDuration($trace['duration']), $trace['span_count'], count($services)
				),
				' · ',
				(new CSpan($data['traceid']))->addClass('apm-overview-traceid'),
				' · ',
				$native
			]))->addClass(ZBX_STYLE_GREY)
		]))->addClass('apm-trace-head');

		return (new CDiv([
			$head,
			self::section(I18n::t('Where it came from'), self::origin($data['origin'], $data['traceid'], $spans)),
			self::section(I18n::t('Services'), self::services($services, $data['host'])),
			self::section(I18n::t('Spans'), self::spans($trace, $errors)),
			self::section('Logs', self::logs($data['logs']))
		]))->addClass('apm-trace');
	}

	/**
	 * The browser session, user and page that started the trace, with the way back to Real user monitoring.
	 */
	private static function origin(?array $origin, string $traceid, array $spans): array {
		if ($origin === null) {
			return [OverviewHtml::hint(I18n::t('This trace did not start in an instrumented browser: it comes from another system, a job, or a page that does not send its traces.'))];
		}

		$agent = Rum::userAgent($origin['agent']);
		$rows = (new CTag('dl', true))->addClass('apm-trace-origin');

		$add = static function (string $label, $value) use ($rows): void {
			$rows->addItem([new CTag('dt', true, $label), new CTag('dd', true, $value)]);
		};

		$add(I18n::t('Session'), $origin['session'] !== ''
			? [
				(new CSpan($origin['session']))->addClass('apm-overview-traceid'),
				' ',
				new CLink(I18n::t('Open the session timeline at this request'),
					RumHtml::sessionUrl($origin['application'], $origin['session'], $origin['session_from'],
						$origin['session_to'], $traceid
					)
				)
			]
			: (new CSpan(I18n::t('the browser does not send session.id')))->addClass(ZBX_STYLE_GREY)
		);

		if ($origin['user'] !== '') {
			$add(I18n::t('User'), $origin['user']);
		}

		$add(I18n::t('Page'), $origin['page'] !== ''
			? [
				new CTag('strong', true, $origin['page']),
				' ',
				new CLink(I18n::t('See this page in Real user monitoring'),
					RumHtml::pageUrl($origin['page'], $origin['application'])
				)
			]
			: (new CSpan(I18n::t('unknown')))->addClass(ZBX_STYLE_GREY)
		);

		$add(I18n::t('Browser'), (new CSpan(trim($agent['browser'].' · '.$agent['os'], ' ·')))->setTitle($origin['agent']));

		$split = TraceStats::split($spans);
		$bar = null;

		if ($split !== null) {
			$backend = $split['backend'] / $split['total'] * 100;

			$bar = (new CDiv([
				(new CDiv(sprintf(
					I18n::t('The browser waited %s: %s in the backend, %s in the network and waiting for a connection.'),
					ApmStats::formatDuration($split['total']), ApmStats::formatDuration($split['backend']),
					ApmStats::formatDuration($split['network'])
				))),
				(new CDiv([
					(new CDiv($backend >= 15 ? I18n::t('Backend') : ''))
						->addClass('apm-slow-own')
						->addStyle(sprintf('width: %.2f%%;', $backend)),
					(new CDiv(100 - $backend >= 15 ? I18n::t('Network') : ''))
						->addClass('apm-slow-calls')
						->addStyle(sprintf('width: %.2f%%;', 100 - $backend))
				]))->addClass('apm-slow-bar')
			]))->addClass('apm-trace-split');
		}

		return [$rows, $bar];
	}

	/**
	 * The services of the trace in the order they joined it; the ones that failed are marked. With the Zabbix host of
	 * the application when there is one.
	 */
	private static function services(array $services, ?array $host): array {
		$list = (new CDiv())->addClass('apm-trace-services');

		foreach ($services as $i => $service) {
			if ($i > 0) {
				$list->addItem((new CSpan('→'))->addClass(ZBX_STYLE_GREY));
			}

			$list->addItem(
				(new CTag('a', true, [
					new CTag('strong', true, $service['service']),
					$service['browser'] ? [' ', (new CSpan(I18n::t('browser')))->addClass('apm-pill apm-pill-info')] : null,
					(new CSpan(' '.ApmStats::formatDuration($service['duration'])))->addClass(ZBX_STYLE_GREY),
					$service['errors'] > 0
						? [' ', (new CSpan(sprintf(I18n::t('%d failed'), $service['errors'])))->addClass('apm-pill apm-pill-bad')]
						: null
				]))
					->setAttribute('href', ServiceMap::getServiceUrl($service['service'])->getUrl())
					->addClass('apm-trace-service')
					->addClass($service['errors'] > 0 ? 'apm-trace-service-bad' : null)
					->setTitle(sprintf(I18n::t('%d spans. Click to see where the time of this service goes.'),
						$service['spans']
					))
			);
		}

		$host_links = null;

		if ($host !== null) {
			$host_links = (new CDiv([
				I18n::t('Zabbix host: '),
				new CTag('strong', true, $host['name']),
				' · ',
				new CLink(I18n::t('Problems'), (new CUrl('zabbix.php'))
					->setArgument('action', 'problem.view')
					->setArgument('hostids', [$host['hostid']])
					->setArgument('filter_set', 1)
				),
				' · ',
				new CLink(I18n::t('Latest data'), (new CUrl('zabbix.php'))
					->setArgument('action', 'latest.view')
					->setArgument('hostids', [$host['hostid']])
					->setArgument('filter_set', 1)
				)
			]))->addClass('apm-trace-host');
		}

		return [$list, $host_links];
	}

	/**
	 * Waterfall of every span; a switch leaves only the ones that failed.
	 */
	private static function spans(array $trace, int $errors): array {
		$id = 'apm-trace-errors-'.substr(md5((string) $trace['start']), 0, 8);

		return [
			$errors > 0
				? [
					(new CTag('input', false))
						->setAttribute('type', 'checkbox')
						->setId($id)
						->addClass('apm-trace-only-errors'),
					(new CTag('label', true, sprintf(I18n::t('Only the %d spans that failed'), $errors)))
						->setAttribute('for', $id)
				]
				: null,
			OverviewHtml::spanRows($trace['spans'], (float) $trace['duration']),
			$trace['truncated']
				? OverviewHtml::hint(sprintf(I18n::t('Only the first %d spans are shown.'), TraceStats::SPAN_LIMIT))
				: null
		];
	}

	private static function logs(?array $logs): array {
		if ($logs === null) {
			return [OverviewHtml::hint(I18n::t('Your role has no access to APM → Logs.'))];
		}

		if (!$logs['logs']) {
			return [OverviewHtml::hint(I18n::t('No log records carry this trace ID. They are linked when the application writes its logs through OpenTelemetry while a span is active.'))];
		}

		$table = (new CTableInfo())->setHeader([I18n::t('Time'), I18n::t('Severity'), I18n::t('Service'), I18n::t('Message')]);

		foreach ($logs['logs'] as $log) {
			$bad = $log['number'] >= 17;

			$table->addRow([
				(new CSpan(zbx_date2str('H:i:s', $log['clock']).sprintf('.%03d', $log['ms'])))->addClass(ZBX_STYLE_NOWRAP),
				(new CSpan($log['severity'] !== '' ? $log['severity'] : (string) $log['number']))
					->addClass('apm-pill')
					->addClass($bad ? 'apm-pill-bad' : ($log['number'] >= 13 ? 'apm-pill-warn' : 'apm-pill-info')),
				$log['service'],
				(new CSpan($log['body']))->addClass('apm-trace-log')->addClass($bad ? ZBX_STYLE_COLOR_NEGATIVE : null)
			]);
		}

		return [
			$table,
			$logs['truncated']
				? OverviewHtml::hint(sprintf(I18n::t('Only the first %d log records are shown.'), TraceStats::LOG_LIMIT))
				: null
		];
	}

	private static function section(string $title, array $content): CTag {
		return (new CTag('section', true, [new CTag('h5', true, $title), $content]))->addClass('apm-trace-section');
	}
}

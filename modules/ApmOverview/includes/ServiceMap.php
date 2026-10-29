<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

use CDiv,
	CSpan,
	CTag,
	CUrl;

/**
 * Service map: who calls whom, built from the root spans (users → service) and the client spans (service → service,
 * database or external host), drawn as an inline SVG in columns from the users to the dependencies. Shared by the APM
 * overview page and the APM service map widget.
 *
 * All names come from telemetry and go through CTag, which escapes them.
 */
class ServiceMap {

	public const DEFAULT_THRESHOLDS = [
		'latency_warning' => 1000000000,	// p95, ns
		'latency_high' => 3000000000,
		'error_warning' => 1,				// %
		'error_high' => 5
	];

	private const NODE_WIDTH = 190;
	private const NODE_HEIGHT = 58;
	private const COLUMN_GAP = 210;
	private const ROW_GAP = 26;
	private const PADDING = 16;
	private const NAME_MAX_LENGTH = 24;

	private const KIND_LABELS = [
		'users' => 'USERS',
		'service' => 'SERVICE',
		'database' => 'DATABASE',
		'external' => 'EXTERNAL'
	];

	/**
	 * @param array $services      Root span statistics per service (ApmStats::getServiceStats()).
	 * @param array $calls         Client span statistics (ApmStats::getServiceCalls()).
	 * @param float $call_minutes  Length of the window of $calls, in minutes (rates per minute).
	 *
	 * @return array  ['nodes' => [id => node], 'edges' => [edge]]
	 */
	public static function build(array $services, array $calls, float $call_minutes): array {
		$nodes = [];
		$edges = [];

		$addNode = static function (string $id, string $kind, string $name) use (&$nodes): void {
			$nodes[$id] ??= ['id' => $id, 'kind' => $kind, 'name' => $name, 'requests' => 0, 'errors' => 0,
				'rpm' => 0, 'p95' => null, 'layer' => $kind === 'users' ? 0 : 1
			];
		};

		if ($services) {
			$addNode('users', 'users', I18n::t('Users'));
		}

		foreach ($services as $service) {
			$id = 'svc:'.$service['service'];
			$addNode($id, 'service', $service['service']);
			$nodes[$id] = array_merge($nodes[$id], [
				'requests' => $service['requests'],
				'errors' => $service['errors'],
				'rpm' => $service['rpm'],
				'p95' => $service['p95']
			]);
			$nodes['users']['requests'] += $service['requests'];
			$nodes['users']['rpm'] += $service['rpm'];

			$edges['users>'.$id] = ['from' => 'users', 'to' => $id, 'calls' => $service['requests'],
				'errors' => $service['errors'], 'rpm' => $service['rpm'], 'p95' => $service['p95']
			];
		}

		// A client span that was not matched to a server span (the child fell outside the window) but goes to the
		// same host as matched ones is a call to that service, not to an external host.
		$host_targets = [];

		foreach ($calls as $call) {
			if ($call['target'] !== '' && $call['host'] !== '' && $call['db_system'] === '') {
				$host_targets[$call['source']][$call['host']][$call['target']] = true;
			}
		}

		foreach ($calls as $call) {
			$source = 'svc:'.$call['source'];
			$target = $call['target'];
			$candidates = $host_targets[$call['source']][$call['host']] ?? [];

			if ($target === '' && $call['db_system'] === '' && count($candidates) == 1) {
				$target = array_key_first($candidates);
			}

			if ($target !== '' && $target !== $call['source']) {
				$to = 'svc:'.$target;
				$addNode($to, 'service', $target);
			}
			elseif ($target === $call['source']) {
				continue;
			}
			elseif ($call['db_system'] !== '') {
				$to = 'db:'.$call['db_system'];
				$addNode($to, 'database', $call['db_system']);
			}
			else {
				$host = $call['host'] !== '' ? $call['host'] : I18n::t('(unknown)');
				$to = 'ext:'.$host;
				$addNode($to, 'external', $host);
			}

			$addNode($source, 'service', $call['source']);

			$key = $source.'>'.$to;
			$edges[$key] ??= ['from' => $source, 'to' => $to, 'calls' => 0, 'errors' => 0, 'rpm' => 0, 'p95' => 0];
			$edges[$key]['calls'] += $call['calls'];
			$edges[$key]['errors'] += $call['errors'];
			$edges[$key]['rpm'] += $call['calls'] / max(1, $call_minutes);
			// Several groups (hosts, systems) on one edge: the slowest p95 stands for the edge.
			$edges[$key]['p95'] = max($edges[$key]['p95'], $call['p95']);
		}

		// Dependencies and services only known as callees: their figures are what their callers saw.
		foreach ($edges as $edge) {
			$node = &$nodes[$edge['to']];

			if ($edge['from'] !== 'users' && ($node['kind'] !== 'service' || !self::hasRoots($services, $node['name']))) {
				$node['requests'] += $edge['calls'];
				$node['errors'] += $edge['errors'];
				$node['rpm'] += $edge['rpm'];
				$node['p95'] = max($node['p95'] ?? 0, $edge['p95']);
			}

			unset($node);
		}

		self::assignLayers($nodes, $edges);

		return ['nodes' => $nodes, 'edges' => array_values($edges)];
	}

	/**
	 * Map as SVG with a legend.
	 *
	 * @param array $graph       Output of build().
	 * @param array $ctx         ['from' => string, 'to' => string] time period of the trace links, optional
	 *                           'focus' => service pattern (as the page filter) to highlight; the rest is dimmed.
	 * @param array $thresholds  See DEFAULT_THRESHOLDS.
	 */
	public static function render(array $graph, array $ctx, array $thresholds = self::DEFAULT_THRESHOLDS): CDiv {
		$nodes = $graph['nodes'];

		if (!$nodes) {
			return (new CDiv(I18n::t('No traces in the selected period.')))->addClass('apm-map-empty');
		}

		// Highlight: the focused services, their connections and whoever is at the other end of them.
		$focus = $ctx['focus'] ?? '';
		$lit_nodes = [];
		$lit_edges = [];

		if ($focus !== '') {
			foreach ($graph['edges'] as $i => $edge) {
				foreach ([$edge['from'], $edge['to']] as $id) {
					if ($nodes[$id]['kind'] === 'service' && self::matches($nodes[$id]['name'], $focus)) {
						$lit_edges[$i] = true;
						$lit_nodes[$edge['from']] = true;
						$lit_nodes[$edge['to']] = true;
					}
				}
			}
		}

		$dim_node = static fn(string $id): bool => $focus !== '' && !array_key_exists($id, $lit_nodes);

		// Columns, each sorted by the average row of the nodes that call it (fewer crossing arrows).
		$columns = [];

		foreach ($nodes as $id => $node) {
			$columns[$node['layer']][] = $id;
		}

		ksort($columns);
		$rows = [];

		foreach ($columns as $layer => &$ids) {
			$order = [];

			foreach ($ids as $id) {
				$parents = [];

				foreach ($graph['edges'] as $edge) {
					if ($edge['to'] === $id && array_key_exists($edge['from'], $rows)) {
						$parents[] = $rows[$edge['from']];
					}
				}

				$order[$id] = [$parents ? array_sum($parents) / count($parents) : 0, -$nodes[$id]['requests'],
					$nodes[$id]['name']
				];
			}

			uasort($order, static fn(array $a, array $b): int => $a <=> $b);
			$ids = array_keys($order);

			foreach ($ids as $row => $id) {
				$rows[$id] = $row;
			}
		}
		unset($ids);

		$max_rows = max(array_map('count', $columns));
		$width = 2 * self::PADDING + count($columns) * self::NODE_WIDTH + (count($columns) - 1) * self::COLUMN_GAP;
		$height = 2 * self::PADDING + $max_rows * self::NODE_HEIGHT + ($max_rows - 1) * self::ROW_GAP;

		$positions = [];
		$column_index = 0;

		foreach ($columns as $ids) {
			$column_height = count($ids) * self::NODE_HEIGHT + (count($ids) - 1) * self::ROW_GAP;
			$top = ($height - $column_height) / 2;

			foreach ($ids as $row => $id) {
				$positions[$id] = [
					self::PADDING + $column_index * (self::NODE_WIDTH + self::COLUMN_GAP),
					$top + $row * (self::NODE_HEIGHT + self::ROW_GAP)
				];
			}

			$column_index++;
		}

		$svg = (new CTag('svg', true))
			->addClass('apm-map-svg')
			->setAttribute('width', (string) $width)
			->setAttribute('height', (string) $height)
			->setAttribute('viewBox', '0 0 '.$width.' '.$height)
			->setAttribute('role', 'img')
			->setAttribute('aria-label', I18n::t('Service map: users, services and their dependencies'))
			->addItem(
				(new CTag('defs', true))->addItem(
					(new CTag('marker', true))
						->setId('apm-map-arrow')
						->setAttribute('viewBox', '0 0 10 10')
						->setAttribute('refX', '9')
						->setAttribute('refY', '5')
						->setAttribute('markerUnits', 'userSpaceOnUse')
						->setAttribute('markerWidth', '10')
						->setAttribute('markerHeight', '10')
						->setAttribute('orient', 'auto-start-reverse')
						->addItem((new CTag('path', true))->setAttribute('d', 'M0,0 L10,5 L0,10 z'))
				)
			);

		$edge_layer = (new CTag('g', true))->addClass('apm-map-edges');
		$label_layer = (new CTag('g', true))->addClass('apm-map-labels');

		foreach ($graph['edges'] as $i => $edge) {
			$dim = $focus !== '' && !array_key_exists($i, $lit_edges);
			[$x1, $y1] = $positions[$edge['from']];
			[$x2, $y2] = $positions[$edge['to']];

			$x1 += self::NODE_WIDTH;
			$y1 += self::NODE_HEIGHT / 2;
			$y2 += self::NODE_HEIGHT / 2;
			$dx = max(40, abs($x2 - $x1) / 2);

			$error_rate = $edge['calls'] > 0 ? $edge['errors'] / $edge['calls'] * 100 : 0;
			$health = self::health($error_rate, $edge['p95'], $thresholds);
			$summary = self::summary($edge['rpm'], $error_rate, $edge['p95']);
			$title = $nodes[$edge['from']]['name'].' → '.$nodes[$edge['to']]['name'].': '.
				sprintf(I18n::t('%s calls (%s)'), self::formatCount($edge['calls']), $summary);

			$path = (new CTag('path', true))
				->setAttribute('d', sprintf('M%.1f,%.1f C%.1f,%.1f %.1f,%.1f %.1f,%.1f',
					$x1, $y1, $x1 + $dx, $y1, $x2 - $dx, $y2, $x2 - 2, $y2
				))
				->setAttribute('stroke-width', (string) round(1.5 + min(5, log10(1 + $edge['rpm']) * 2), 1))
				->setAttribute('marker-end', 'url(#apm-map-arrow)');

			$edge_layer->addItem(
				self::link(self::edgeUrl($edge, $nodes, $ctx), $title,
					(new CTag('g', true))
						->addClass('apm-map-edge')
						->addClass('apm-map-'.$health)
						->addClass($dim ? 'apm-map-dim' : null)
						->addItem($path)
				)
			);

			$label_layer->addItem(
				(new CTag('text', true, $summary))
					->addClass('apm-map-edge-label')
					->addClass($dim ? 'apm-map-dim' : null)
					->setAttribute('x', (string) round(($x1 + $x2) / 2, 1))
					->setAttribute('y', (string) round(($y1 + $y2) / 2 - 5, 1))
					->setAttribute('text-anchor', 'middle')
			);
		}

		$node_layer = (new CTag('g', true))->addClass('apm-map-nodes');

		foreach ($nodes as $id => $node) {
			[$x, $y] = $positions[$id];

			$error_rate = $node['requests'] > 0 ? $node['errors'] / $node['requests'] * 100 : 0;
			$health = $node['kind'] === 'users' ? 'neutral' : self::health($error_rate, $node['p95'], $thresholds);
			$summary = $node['kind'] === 'users'
				? self::formatRate($node['rpm']).'/min'
				: self::summary($node['rpm'], $error_rate, $node['p95']);
			$name = mb_strlen($node['name']) > self::NAME_MAX_LENGTH
				? mb_substr($node['name'], 0, self::NAME_MAX_LENGTH - 1).'…'
				: $node['name'];

			$group = (new CTag('g', true))
				->addClass('apm-map-node')
				->addClass('apm-map-'.$health)
				->addClass('apm-map-kind-'.$node['kind'])
				->addClass($dim_node($id) ? 'apm-map-dim' : null)
				->addClass($focus !== '' && $node['kind'] === 'service' && self::matches($node['name'], $focus)
					? 'apm-map-focus'
					: null
				)
				->setAttribute('transform', sprintf('translate(%.1f,%.1f)', $x, $y))
				->addItem(
					(new CTag('rect', true))
						->setAttribute('width', (string) self::NODE_WIDTH)
						->setAttribute('height', (string) self::NODE_HEIGHT)
						->setAttribute('rx', '6')
				)
				->addItem(
					(new CTag('text', true, I18n::t(self::KIND_LABELS[$node['kind']])))
						->addClass('apm-map-node-kind')
						->setAttribute('x', '10')
						->setAttribute('y', '15')
				)
				->addItem(
					(new CTag('text', true, $name))
						->addClass('apm-map-node-name')
						->setAttribute('x', '10')
						->setAttribute('y', '32')
				)
				->addItem(
					(new CTag('text', true, $summary))
						->addClass('apm-map-node-stats')
						->setAttribute('x', '10')
						->setAttribute('y', '49')
				);

			$title = $node['name'].' ('.mb_strtolower(I18n::t(self::KIND_LABELS[$node['kind']])).'): '.
				sprintf(I18n::t('%s requests, %s'), self::formatCount($node['requests']), $summary);

			$node_layer->addItem(self::link(self::nodeUrl($node), $title, $group));
		}

		$svg
			->addItem($edge_layer)
			->addItem($node_layer)
			->addItem($label_layer);

		return (new CDiv([
			(new CDiv($svg))->addClass('apm-map-canvas'),
			self::legend($thresholds)
		]))->addClass('apm-map');
	}

	/**
	 * Overview page filtered by one service, on the tab that explains where its time goes.
	 */
	public static function getServiceUrl(string $service, string $tab = OverviewHtml::TAB_SLOW): CUrl {
		return (new CUrl('zabbix.php'))
			->setArgument('action', 'apm.overview')
			->setArgument('tab', $tab)
			->setArgument('filter_service', ApmStats::EXACT_PREFIX.$service)
			->setArgument('filter_set', 1);
	}

	/**
	 * Whether a service name matches a filter pattern, the same way as the queries (ApmStats::toLikePattern()):
	 * "=name" exactly, "*" as wildcard, otherwise contains the text.
	 */
	public static function matches(string $name, string $pattern): bool {
		if (str_starts_with($pattern, ApmStats::EXACT_PREFIX)) {
			return $name === substr($pattern, strlen(ApmStats::EXACT_PREFIX));
		}

		return str_contains($pattern, '*')
			? fnmatch($pattern, $name, FNM_NOESCAPE)
			: str_contains($name, $pattern);
	}

	public static function health(float $error_rate, ?float $p95, array $thresholds): string {
		if ($error_rate >= $thresholds['error_high'] || ($p95 !== null && $p95 >= $thresholds['latency_high'])) {
			return 'bad';
		}

		if ($error_rate >= $thresholds['error_warning'] || ($p95 !== null && $p95 >= $thresholds['latency_warning'])) {
			return 'warn';
		}

		return 'ok';
	}

	private static function legend(array $thresholds): CDiv {
		$item = static fn(string $class, string $text): CSpan => new CSpan([
			(new CSpan())->addClass('apm-map-dot')->addClass('apm-map-'.$class),
			$text
		]);

		return (new CDiv([
			$item('ok', I18n::t('Healthy')),
			$item('warn', sprintf(I18n::t('Warning: errors ≥ %s %% or p95 ≥ %s'), $thresholds['error_warning'],
				ApmStats::formatDuration($thresholds['latency_warning'])
			)),
			$item('bad', sprintf(I18n::t('Problem: errors ≥ %s %% or p95 ≥ %s'), $thresholds['error_high'],
				ApmStats::formatDuration($thresholds['latency_high'])
			)),
			new CSpan(I18n::t('Arrows: calls/min · errors · p95 (thicker = more traffic). Click a service: where its time goes.'))
		]))->addClass('apm-map-legend');
	}

	private static function link(?CUrl $url, string $title, CTag $content): CTag {
		$content->addItem(new CTag('title', true, $title));

		if ($url === null) {
			return $content;
		}

		return (new CTag('a', true, $content))
			->setAttribute('href', $url->getUrl())
			->setAttribute('aria-label', $title);
	}

	private static function nodeUrl(array $node): ?CUrl {
		return $node['kind'] === 'service' ? self::getServiceUrl($node['name']) : null;
	}

	/**
	 * Users → service: its traces. Service → anything: the caller's time breakdown and database statements.
	 */
	private static function edgeUrl(array $edge, array $nodes, array $ctx): CUrl {
		return $edge['from'] === 'users'
			? OverviewHtml::getTracesUrl($ctx, $nodes[$edge['to']]['name'])
			: self::getServiceUrl($nodes[$edge['from']]['name']);
	}

	/**
	 * Users in column 0, services by call depth, each dependency right after the deepest service that calls it (no
	 * arrow crosses a column of services).
	 */
	private static function assignLayers(array &$nodes, array $edges): void {
		// Longest call chain, bounded so that call cycles cannot loop forever.
		for ($i = 0, $changed = true; $changed && $i < count($nodes); $i++) {
			$changed = false;

			foreach ($edges as $edge) {
				$from = $nodes[$edge['from']];
				$to = &$nodes[$edge['to']];

				if ($to['kind'] === 'service' && $to['layer'] < $from['layer'] + 1) {
					$to['layer'] = $from['layer'] + 1;
					$changed = true;
				}

				unset($to);
			}
		}

		foreach ($edges as $edge) {
			$to = &$nodes[$edge['to']];

			if ($to['kind'] === 'database' || $to['kind'] === 'external') {
				$to['layer'] = max($to['layer'], $nodes[$edge['from']]['layer'] + 1);
			}

			unset($to);
		}
	}

	private static function hasRoots(array $services, string $name): bool {
		return in_array($name, array_column($services, 'service'), true);
	}

	private static function summary(float $rpm, float $error_rate, ?float $p95): string {
		return self::formatRate($rpm).'/min · '.sprintf('%.1f %%', $error_rate).
			($p95 !== null ? ' · p95 '.ApmStats::formatDuration($p95) : '');
	}

	private static function formatRate(float $rpm): string {
		return $rpm >= 10 ? (string) round($rpm) : sprintf('%.1f', $rpm);
	}

	private static function formatCount(int $value): string {
		return number_format($value, 0, '.', ' ');
	}
}

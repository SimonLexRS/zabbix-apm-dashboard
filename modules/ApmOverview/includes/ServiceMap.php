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
 * Edges are colored by health, as thick as their share of the traffic, and carry moving particles (the live flow); the
 * figures are in the tooltips and the connections table, not written on the arrows. Hovering highlights a path and shows the figures (apm-overview.js,
 * CApmServiceMapInteraction), from the data-* attributes written here.
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

	private const NODE_WIDTH = 160;
	private const NODE_HEIGHT = 54;
	private const COLUMN_GAP = 80;
	private const ROW_GAP = 18;
	private const PADDING = 16;
	private const NAME_MAX_LENGTH = 20;

	// The map is shrunk at most to this share of its natural size; wider maps scroll instead of becoming unreadable.
	private const MIN_SCALE = 0.8;

	// Barycenter sweeps (forward and backward) used to order the nodes of each column.
	private const ORDER_SWEEPS = 4;

	private const EDGE_WIDTH_MIN = 1.5;
	private const EDGE_WIDTH_MAX = 8;

	// Calls per minute from which the particles of an edge move at medium and fast speed.
	private const FLOW_MEDIUM = 5;
	private const FLOW_FAST = 60;

	private const ATTENTION_LIMIT = 8;

	private const KIND_LABELS = [
		'users' => 'Users',
		'service' => 'Service',
		'database' => 'Database',
		'queue' => 'Queue',
		'external' => 'External service'
	];

	// Messaging systems (messaging.system): drawn as queues, not as databases.
	private const QUEUE_SYSTEMS = ['kafka', 'rabbitmq', 'activemq', 'artemis', 'aws_sqs', 'aws.sqs', 'aws_sns',
		'gcp_pubsub', 'nats', 'pulsar', 'rocketmq', 'servicebus', 'eventhubs', 'eventgrid', 'jms', 'mqtt', 'redis_streams'
	];

	// 16 × 16 pictograms drawn at the left of each node.
	private const KIND_ICONS = [
		'users' => 'M8 1.5a3 3 0 1 1 0 6 3 3 0 0 1 0-6zM2 14.5c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5z',
		'service' => 'M8 1 14.5 4.75v6.5L8 15 1.5 11.25v-6.5z',
		'database' => 'M2 3.5C2 2.1 4.7 1 8 1s6 1.1 6 2.5v9C14 13.9 11.3 15 8 15s-6-1.1-6-2.5zM2 3.5C2 4.9 4.7 6 8 6s6-1.1 6-2.5',
		'queue' => 'M1.5 4h13v8h-13zM5.5 4v8M9.5 4v8',
		'external' => 'M8 1a7 7 0 1 1 0 14A7 7 0 0 1 8 1zM1 8h14M8 1c2 2 2.6 4.3 2.6 7S10 13 8 15C6 13 5.4 10.7 5.4 8S6 3 8 1z'
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
				'rpm' => 0, 'p95' => null, 'now' => null, 'measured' => false, 'browser' => false,
				'layer' => $kind === 'users' ? 0 : 1
			];
		};

		foreach ($services as $service) {
			$id = 'svc:'.$service['service'];
			$addNode($id, 'service', $service['service']);
			$nodes[$id] = array_merge($nodes[$id], [
				'requests' => $service['requests'],
				'errors' => $service['errors'],
				'rpm' => $service['rpm'],
				'p95' => $service['p95'],
				'now' => $service['recent'] ?? null,
				'measured' => true,
				'browser' => $service['browser'] ?? false
			]);

			// Only what came from outside is user traffic: a consumer or a timer that starts its own trace is not.
			$user = $service['user'] ?? ['requests' => $service['requests'], 'errors' => $service['errors']];

			if ($user['requests'] > 0) {
				$addNode('users', 'users', I18n::t('Users'));
				$rpm = $service['requests'] > 0 ? $service['rpm'] * $user['requests'] / $service['requests'] : 0;
				$nodes['users']['requests'] += $user['requests'];
				$nodes['users']['rpm'] += $rpm;

				$edges['users>'.$id] = ['from' => 'users', 'to' => $id, 'calls' => $user['requests'],
					'errors' => $user['errors'], 'rpm' => $rpm, 'p95' => $service['p95'], 'now' => $nodes[$id]['now']
				];
			}
		}

		// Users first, whatever the order of the services.
		if (array_key_exists('users', $nodes)) {
			$nodes = ['users' => $nodes['users']] + $nodes;
		}

		// A client span that was not matched to a server span (the child fell outside the window) but goes to the
		// same host as matched ones is a call to that service, not to an external host.
		$host_targets = [];

		foreach ($calls as $call) {
			if ($call['target'] !== '' && $call['host'] !== '' && $call['db_system'] === '') {
				$host_targets[$call['source']][$call['host']][$call['target']] = true;
			}
		}

		$service_names = array_flip(array_column($services, 'service'));

		foreach ($calls as $call) {
			$source = 'svc:'.$call['source'];
			$target = $call['target'];
			$candidates = $host_targets[$call['source']][$call['host']] ?? [];

			if ($target === '' && $call['db_system'] === '') {
				if (count($candidates) == 1) {
					$target = array_key_first($candidates);
				}
				// The host is the name of a known service (http://cart:8080 inside Docker or Kubernetes).
				elseif (array_key_exists($call['host'], $service_names)) {
					$target = $call['host'];
				}
			}

			if ($target !== '' && $target !== $call['source']) {
				$to = 'svc:'.$target;
				$addNode($to, 'service', $target);
			}
			elseif ($target === $call['source']) {
				continue;
			}
			elseif ($call['db_system'] !== '') {
				$kind = in_array(strtolower($call['db_system']), self::QUEUE_SYSTEMS, true) ? 'queue' : 'database';
				$to = ($kind === 'queue' ? 'mq:' : 'db:').$call['db_system'];
				$addNode($to, $kind, $call['db_system']);
			}
			else {
				$host = $call['host'] !== '' ? $call['host'] : I18n::t('(unknown)');
				$to = 'ext:'.$host;
				$addNode($to, 'external', $host);
			}

			$addNode($source, 'service', $call['source']);

			$key = $source.'>'.$to;
			$edges[$key] ??= ['from' => $source, 'to' => $to, 'calls' => 0, 'errors' => 0, 'rpm' => 0, 'p95' => 0,
				'now' => array_key_exists('recent', $call) ? ['requests' => 0, 'errors' => 0, 'p95' => 0] : null
			];
			$edges[$key]['calls'] += $call['calls'];
			$edges[$key]['errors'] += $call['errors'];
			$edges[$key]['rpm'] += $call['calls'] / max(1, $call_minutes);
			// Several groups (hosts, systems) on one edge: the slowest p95 stands for the edge.
			$edges[$key]['p95'] = max($edges[$key]['p95'], $call['p95']);

			if ($edges[$key]['now'] !== null && array_key_exists('recent', $call)) {
				$edges[$key]['now']['requests'] += $call['recent']['calls'];
				$edges[$key]['now']['errors'] += $call['recent']['errors'];
				$edges[$key]['now']['p95'] = max($edges[$key]['now']['p95'], $call['recent']['p95']);
			}
		}

		// Dependencies, and services that sent no spans of their own in the period: what their callers saw.
		foreach ($edges as $edge) {
			$node = &$nodes[$edge['to']];

			if ($edge['from'] !== 'users' && !$node['measured']) {
				$node['requests'] += $edge['calls'];
				$node['errors'] += $edge['errors'];
				$node['rpm'] += $edge['rpm'];
				$node['p95'] = max($node['p95'] ?? 0, $edge['p95']);

				if ($edge['now'] !== null) {
					$node['now'] ??= ['requests' => 0, 'errors' => 0, 'p95' => 0];
					$node['now']['requests'] += $edge['now']['requests'];
					$node['now']['errors'] += $edge['now']['errors'];
					$node['now']['p95'] = max($node['now']['p95'], $edge['now']['p95']);
				}
			}

			unset($node);
		}

		self::assignLayers($nodes, $edges);

		return ['nodes' => $nodes, 'edges' => array_values($edges)];
	}

	/**
	 * Error rate and p95 that decide the health of a node or an edge: those of the recent window when it was measured
	 * (a problem that was fixed stops coloring the map within minutes, not when it leaves the period), otherwise those
	 * of the whole period.
	 *
	 * @return array  [float $error_rate, ?float $p95]
	 */
	public static function current(array $item): array {
		$now = $item['now'] ?? null;

		if ($now === null) {
			return [self::errorRate($item['errors'], $item['requests'] ?? $item['calls']), $item['p95']];
		}

		return [self::errorRate($now['errors'], $now['requests']), $now['requests'] > 0 ? $now['p95'] : null];
	}

	/**
	 * What matters now, from the map: entry traffic and errors, the slowest service, health counts and the services and
	 * connections that need attention (most severe first).
	 *
	 * @return array  ['rpm', 'requests', 'error_rate', 'slowest' => ?['name', 'p95'], 'health' => ['ok', 'warn', 'bad'],
	 *                'attention' => [['severity' => 'bad'|'warn', 'label', 'reason', 'service' => ?string]]]
	 */
	public static function summary(array $graph, array $thresholds = self::DEFAULT_THRESHOLDS): array {
		$nodes = $graph['nodes'];
		$result = ['rpm' => 0.0, 'requests' => 0, 'error_rate' => 0.0, 'slowest' => null,
			'health' => ['ok' => 0, 'warn' => 0, 'bad' => 0], 'attention' => []
		];
		$entry_errors = 0;

		foreach ($graph['edges'] as $edge) {
			if ($edge['from'] === 'users') {
				$result['requests'] += $edge['calls'];
				$entry_errors += $edge['errors'];
				$result['rpm'] += $edge['rpm'];
			}
		}

		$result['error_rate'] = $result['requests'] > 0 ? $entry_errors / $result['requests'] * 100 : 0.0;
		$attention = [];

		foreach ($nodes as $node) {
			if ($node['kind'] !== 'service') {
				continue;
			}

			[$error_rate, $p95] = self::current($node);
			$health = self::health($error_rate, $p95, $thresholds);
			$result['health'][$health]++;

			if ($node['p95'] !== null && ($result['slowest'] === null || $node['p95'] > $result['slowest']['p95'])) {
				$result['slowest'] = ['name' => $node['name'], 'p95' => $node['p95']];
			}

			if ($health !== 'ok') {
				$attention[] = [$health, $error_rate, $p95 ?? 0, $node['name'],
					self::reason($error_rate, $p95, $thresholds), $node['name']
				];
			}
		}

		// Connections between services and to dependencies; users → service is the service itself.
		foreach ($graph['edges'] as $edge) {
			if ($edge['from'] === 'users') {
				continue;
			}

			[$error_rate, $p95] = self::current($edge);
			$health = self::health($error_rate, $p95, $thresholds);

			if ($health !== 'ok') {
				$attention[] = [$health, $error_rate, $p95 ?? 0, $nodes[$edge['from']]['name'].' → '.
					$nodes[$edge['to']]['name'], self::reason($error_rate, $p95, $thresholds),
					$nodes[$edge['from']]['name']
				];
			}
		}

		// Problems before warnings, then more errors, then slower, then by name.
		usort($attention, static fn(array $a, array $b): int =>
			[$b[0] === 'bad', $b[1], $b[2], $a[3]] <=> [$a[0] === 'bad', $a[1], $a[2], $b[3]]
		);

		foreach (array_slice($attention, 0, self::ATTENTION_LIMIT) as [$health, , , $label, $reason, $service]) {
			$result['attention'][] = ['severity' => $health, 'label' => $label, 'reason' => $reason, 'service' => $service];
		}

		return $result;
	}

	/**
	 * Width of an edge from its share of the traffic of the busiest edge (square root: small edges stay visible).
	 */
	public static function edgeWidth(float $rpm, float $max_rpm): float {
		if ($max_rpm <= 0) {
			return self::EDGE_WIDTH_MIN;
		}

		return round(self::EDGE_WIDTH_MIN + (self::EDGE_WIDTH_MAX - self::EDGE_WIDTH_MIN) * sqrt(min(1, $rpm / $max_rpm)), 1);
	}

	/**
	 * Speed of the particles of an edge: slow, medium or fast.
	 */
	public static function flowSpeed(float $rpm): string {
		return $rpm >= self::FLOW_FAST ? 'fast' : ($rpm >= self::FLOW_MEDIUM ? 'medium' : 'slow');
	}

	/**
	 * Order of the nodes of each column: barycenter sweeps, forward by the callers and backward by the callees, so
	 * that fewer arrows cross. Deterministic for the same graph.
	 *
	 * @param array $nodes  [id => node with 'layer', 'requests', 'name']
	 * @param array $edges  [['from', 'to'], ...]
	 *
	 * @return array  [layer => [id, ...]], layers in ascending order.
	 */
	public static function orderColumns(array $nodes, array $edges): array {
		$columns = [];

		foreach ($nodes as $id => $node) {
			$columns[$node['layer']][] = $id;
		}

		ksort($columns);

		foreach ($columns as &$ids) {
			usort($ids, static fn(string $a, string $b): int =>
				[-$nodes[$a]['requests'], $nodes[$a]['name']] <=> [-$nodes[$b]['requests'], $nodes[$b]['name']]
			);
		}
		unset($ids);

		$parents = [];
		$children = [];

		foreach ($edges as $edge) {
			$parents[$edge['to']][] = $edge['from'];
			$children[$edge['from']][] = $edge['to'];
		}

		$layers = array_keys($columns);

		for ($sweep = 0; $sweep < self::ORDER_SWEEPS; $sweep++) {
			$forward = $sweep % 2 == 0;
			$neighbours = $forward ? $parents : $children;

			foreach ($forward ? $layers : array_reverse($layers) as $layer) {
				$y = self::rowPositions($columns);
				$keys = [];

				foreach ($columns[$layer] as $id) {
					$others = array_filter($neighbours[$id] ?? [],
						static fn(string $other): bool => $forward
							? $nodes[$other]['layer'] < $layer
							: $nodes[$other]['layer'] > $layer
					);
					$keys[$id] = $others
						? array_sum(array_map(static fn(string $other): float => $y[$other], $others)) / count($others)
						: $y[$id];
				}

				usort($columns[$layer], static fn(string $a, string $b): int =>
					[$keys[$a], $y[$a], $nodes[$a]['name']] <=> [$keys[$b], $y[$b], $nodes[$b]['name']]
				);
			}
		}

		return $columns;
	}

	/**
	 * Arrows that cross between the same pair of columns (to compare orderings).
	 */
	public static function crossings(array $columns, array $nodes, array $edges): int {
		$y = self::rowPositions($columns);
		$count = 0;

		foreach ($edges as $i => $a) {
			foreach (array_slice($edges, $i + 1) as $b) {
				if ($nodes[$a['from']]['layer'] == $nodes[$b['from']]['layer']
						&& $nodes[$a['to']]['layer'] == $nodes[$b['to']]['layer']
						&& ($y[$a['from']] - $y[$b['from']]) * ($y[$a['to']] - $y[$b['to']]) < 0) {
					$count++;
				}
			}
		}

		return $count;
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

		$columns = self::orderColumns($nodes, $graph['edges']);
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

		$defs = new CTag('defs', true);

		foreach (['ok', 'warn', 'bad'] as $health) {
			$defs->addItem(
				(new CTag('marker', true))
					->setId('apm-map-arrow-'.$health)
					->addClass('apm-map-arrow')
					->addClass('apm-map-'.$health)
					->setAttribute('viewBox', '0 0 10 10')
					->setAttribute('refX', '9')
					->setAttribute('refY', '5')
					->setAttribute('markerUnits', 'userSpaceOnUse')
					->setAttribute('markerWidth', '9')
					->setAttribute('markerHeight', '9')
					->setAttribute('orient', 'auto-start-reverse')
					->addItem((new CTag('path', true))->setAttribute('d', 'M0,0 L10,5 L0,10 z'))
			);
		}

		$svg = (new CTag('svg', true))
			->addClass('apm-map-svg')
			->setAttribute('width', (string) $width)
			->setAttribute('height', (string) $height)
			->setAttribute('viewBox', '0 0 '.$width.' '.$height)
			->setAttribute('style', 'min-width: '.round($width * self::MIN_SCALE).'px')
			->setAttribute('role', 'img')
			->setAttribute('aria-label', I18n::t('Service map: users, services and their dependencies'))
			->addItem($defs);

		$max_rpm = max(array_column($graph['edges'], 'rpm') ?: [0]);
		$callers = [];
		$callees = [];
		$slowest_dependency = [];

		foreach ($graph['edges'] as $edge) {
			$callers[$edge['to']] = ($callers[$edge['to']] ?? 0) + 1;
			$callees[$edge['from']] = ($callees[$edge['from']] ?? 0) + 1;

			if ($edge['from'] !== 'users'
					&& $edge['p95'] > ($slowest_dependency[$edge['from']]['p95'] ?? -1)) {
				$slowest_dependency[$edge['from']] = ['name' => $nodes[$edge['to']]['name'], 'p95' => $edge['p95']];
			}
		}

		$edge_layer = (new CTag('g', true))->addClass('apm-map-edges');

		foreach ($graph['edges'] as $i => $edge) {
			$dim = $focus !== '' && !array_key_exists($i, $lit_edges);
			[$x1, $y1] = $positions[$edge['from']];
			[$x2, $y2] = $positions[$edge['to']];

			$x1 += self::NODE_WIDTH;
			$y1 += self::NODE_HEIGHT / 2;
			$y2 += self::NODE_HEIGHT / 2;
			$dx = max(40, abs($x2 - $x1) / 2);
			$d = sprintf('M%.1f,%.1f C%.1f,%.1f %.1f,%.1f %.1f,%.1f', $x1, $y1, $x1 + $dx, $y1, $x2 - $dx, $y2, $x2 - 2, $y2);

			$error_rate = self::errorRate($edge['errors'], $edge['calls']);
			[$now_error_rate, $now_p95] = self::current($edge);
			$health = self::health($now_error_rate, $now_p95, $thresholds);
			$from_name = $nodes[$edge['from']]['name'];
			$to_name = $nodes[$edge['to']]['name'];
			$width_px = self::edgeWidth($edge['rpm'], $max_rpm);

			$group = (new CTag('g', true))
				->addClass('apm-map-edge')
				->addClass('apm-map-'.$health)
				->addClass('apm-map-flow-'.self::flowSpeed($edge['rpm']))
				->addClass($now_error_rate > 0 ? 'apm-map-erroring' : null)
				->addClass($dim ? 'apm-map-dim' : null)
				->setAttribute('data-apm-edge', $edge['from'].'>'.$edge['to'])
				->setAttribute('data-from', $edge['from'])
				->setAttribute('data-to', $edge['to'])
				->setAttribute('data-tip-title', $from_name.' → '.$to_name)
				->setAttribute('data-tip', implode("\n", array_filter([
					sprintf(I18n::t('Calls: %s (%s/min)'), self::formatCount($edge['calls']), self::formatRate($edge['rpm'])),
					sprintf(I18n::t('Errors: %s'), sprintf('%.1f %%', $error_rate)),
					'p95: '.ApmStats::formatDuration($edge['p95'] ?? 0),
					self::nowLine($edge)
				])))
				->addItem(
					(new CTag('path', true))
						->addClass('apm-map-line')
						->setAttribute('d', $d)
						->setAttribute('stroke-width', (string) $width_px)
						->setAttribute('marker-end', 'url(#apm-map-arrow-'.$health.')')
				)
				->addItem(
					(new CTag('path', true))
						->addClass('apm-map-flow')
						->setAttribute('d', $d)
						->setAttribute('stroke-width', (string) max(2.5, min(5, $width_px)))
				);

			$edge_layer->addItem(self::link(self::edgeUrl($edge, $nodes, $ctx),
				$from_name.' → '.$to_name, $group
			));
		}

		$node_layer = (new CTag('g', true))->addClass('apm-map-nodes');

		foreach ($nodes as $id => $node) {
			[$x, $y] = $positions[$id];

			$error_rate = self::errorRate($node['errors'], $node['requests']);
			[$now_error_rate, $now_p95] = self::current($node);
			$health = $node['kind'] === 'users' ? 'neutral' : self::health($now_error_rate, $now_p95, $thresholds);
			$name = mb_strlen($node['name']) > self::NAME_MAX_LENGTH
				? mb_substr($node['name'], 0, self::NAME_MAX_LENGTH - 1).'…'
				: $node['name'];

			$stats = (new CTag('text', true))
				->addClass('apm-map-node-stats')
				->setAttribute('x', '34')
				->setAttribute('y', '41');

			if ($node['kind'] === 'users') {
				$stats->addItem(self::formatRate($node['rpm']).'/min');
			}
			else {
				$stats
					->addItem(self::formatRate($node['rpm']).'/min · ')
					->addItem((new CTag('tspan', true, sprintf('%.1f%%', $error_rate)))
						->addClass($error_rate >= $thresholds['error_warning'] ? 'apm-map-hot' : null)
					)
					->addItem($node['p95'] !== null ? ' · ' : '')
					->addItem($node['p95'] !== null
						? (new CTag('tspan', true, self::shortDuration($node['p95'])))
							->addClass($node['p95'] >= $thresholds['latency_warning'] ? 'apm-map-hot' : null)
						: null
					);
			}

			$tip = [
				sprintf(I18n::t('Requests: %s (%s/min)'), self::formatCount($node['requests']), self::formatRate($node['rpm']))
			];

			if ($node['kind'] !== 'users') {
				$tip[] = sprintf(I18n::t('Errors: %s'), sprintf('%.1f %%', $error_rate));
				$tip[] = 'p95: '.($node['p95'] !== null ? ApmStats::formatDuration($node['p95']) : '—');
				$tip[] = self::nowLine($node);
			}

			$tip[] = sprintf(I18n::t('Called by: %d · Calls: %d'), $callers[$id] ?? 0, $callees[$id] ?? 0);

			if (array_key_exists($id, $slowest_dependency)) {
				$tip[] = sprintf(I18n::t('Slowest dependency: %s (p95 %s)'), $slowest_dependency[$id]['name'],
					ApmStats::formatDuration($slowest_dependency[$id]['p95'])
				);
			}

			$group = (new CTag('g', true))
				->addClass('apm-map-node')
				->addClass('apm-map-'.$health)
				->addClass('apm-map-kind-'.$node['kind'])
				->addClass($focus !== '' && !array_key_exists($id, $lit_nodes) ? 'apm-map-dim' : null)
				->addClass($focus !== '' && $node['kind'] === 'service' && self::matches($node['name'], $focus)
					? 'apm-map-focus'
					: null
				)
				->setAttribute('transform', sprintf('translate(%.1f,%.1f)', $x, $y))
				->setAttribute('data-apm-node', $id)
				->setAttribute('data-tip-title', $node['name'].' · '.I18n::t(self::KIND_LABELS[$node['kind']]))
				->setAttribute('data-tip', implode("\n", array_filter($tip)))
				->addItem(
					(new CTag('rect', true))
						->addClass('apm-map-card')
						->setAttribute('width', (string) self::NODE_WIDTH)
						->setAttribute('height', (string) self::NODE_HEIGHT)
						->setAttribute('rx', '8')
				)
				->addItem(
					(new CTag('rect', true))
						->addClass('apm-map-bar')
						->setAttribute('width', '4')
						->setAttribute('height', (string) (self::NODE_HEIGHT - 12))
						->setAttribute('x', '4')
						->setAttribute('y', '6')
						->setAttribute('rx', '2')
				)
				->addItem(
					(new CTag('path', true))
						->addClass('apm-map-icon')
						->setAttribute('d', self::KIND_ICONS[$node['kind']])
						->setAttribute('transform', 'translate(12,12)')
				)
				->addItem(
					(new CTag('text', true, $name))
						->addClass('apm-map-node-name')
						->setAttribute('x', '34')
						->setAttribute('y', '23')
				)
				->addItem($stats);

			$node_layer->addItem(self::link(self::nodeUrl($node), $node['name'], $group));
		}

		$svg
			->addItem($edge_layer)
			->addItem($node_layer);

		return (new CDiv([
			(new CDiv($svg))->addClass('apm-map-canvas'),
			self::legend($thresholds)
		]))->addClass('apm-map');
	}

	/**
	 * Overview page looking at one service, on the tab that explains where its time goes. The service travels in the
	 * URL and is not stored: it lasts as long as that page view.
	 */
	public static function getServiceUrl(string $service, string $tab = OverviewHtml::TAB_SLOW): CUrl {
		return (new CUrl('zabbix.php'))
			->setArgument('action', 'apm.overview')
			->setArgument('tab', $tab)
			->setArgument('service', $service);
	}

	/**
	 * Whether a service name matches a filter pattern, the same way as the queries (ApmStats::toLikePattern()):
	 * "=name" exactly, "*" as wildcard, otherwise contains the text.
	 */
	public static function matches(string $name, string $pattern): bool {
		if (str_starts_with($pattern, ApmStats::EXACT_PREFIX)) {
			return $name === substr($pattern, strlen(ApmStats::EXACT_PREFIX));
		}

		// "*" is the only wildcard, as in the queries: "?" and "[" are plain characters.
		return str_contains($pattern, '*')
			? (bool) preg_match('/^'.str_replace('\\*', '.*', preg_quote($pattern, '/')).'$/su', $name)
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

	/**
	 * Duration for small spaces (nodes, cards): 0.4ms, 26ms, 1.5s, 1m 5s.
	 */
	public static function shortDuration(float $ns): string {
		$ms = $ns / 1000000;

		if ($ms < 10) {
			return sprintf('%.1fms', $ms);
		}

		if ($ms < 1000) {
			return round($ms).'ms';
		}

		if ($ms < 60000) {
			return sprintf('%.1fs', $ms / 1000);
		}

		$seconds = (int) round($ms / 1000);

		return intdiv($seconds, 60).'m '.($seconds % 60).'s';
	}

	/**
	 * Why something is not healthy: the error rate and/or the p95 over their warning thresholds.
	 */
	private static function reason(float $error_rate, ?float $p95, array $thresholds): string {
		$parts = [];

		if ($error_rate >= $thresholds['error_warning']) {
			$parts[] = sprintf(I18n::t('%s errors'), sprintf('%.1f %%', $error_rate));
		}

		if ($p95 !== null && $p95 >= $thresholds['latency_warning']) {
			$parts[] = 'p95 '.self::shortDuration($p95);
		}

		return implode(' · ', $parts);
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
			new CSpan(I18n::t('Thickness = share of the traffic · moving dots = live flow (faster = more calls). Hover a service to follow its path; click it to see where its time goes.'))
		]))->addClass('apm-map-legend');
	}

	private static function link(?CUrl $url, string $label, CTag $content): CTag {
		$content->setAttribute('aria-label', $label);

		if ($url === null) {
			return $content;
		}

		return (new CTag('a', true, $content))->setAttribute('href', $url->getUrl());
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

			if ($to['kind'] !== 'service' && $to['kind'] !== 'users') {
				$to['layer'] = max($to['layer'], $nodes[$edge['from']]['layer'] + 1);
			}

			unset($to);
		}
	}

	/**
	 * Vertical position of every node as laid out (columns are centered): row minus the middle of its column.
	 */
	private static function rowPositions(array $columns): array {
		$y = [];

		foreach ($columns as $ids) {
			foreach ($ids as $row => $id) {
				$y[$id] = $row - (count($ids) - 1) / 2;
			}
		}

		return $y;
	}

	/**
	 * Tooltip line with the figures of the recent window, the ones the color comes from.
	 */
	private static function nowLine(array $item): string {
		$now = $item['now'] ?? null;

		if ($now === null) {
			return '';
		}

		if ($now['requests'] == 0) {
			return I18n::t('Now: no traffic');
		}

		return sprintf(I18n::t('Now: %s errors, p95 %s'),
			sprintf('%.1f %%', self::errorRate($now['errors'], $now['requests'])), ApmStats::formatDuration($now['p95'])
		);
	}

	private static function errorRate(int $errors, int $requests): float {
		return $requests > 0 ? $errors / $requests * 100 : 0.0;
	}

	private static function formatRate(float $rpm): string {
		return $rpm >= 10 ? (string) round($rpm) : sprintf('%.1f', $rpm);
	}

	private static function formatCount(int $value): string {
		return number_format($value, 0, '.', ' ');
	}
}

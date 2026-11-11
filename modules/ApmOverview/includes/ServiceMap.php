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

	// Nodes: circles from RADIUS_MIN (no traffic) to RADIUS_MAX (the busiest), with two lines of text below.
	public const RADIUS_MIN = 15;
	public const RADIUS_MAX = 30;
	private const LABEL_HEIGHT = 34;
	private const COLUMN_WIDTH = 190;
	private const ROW_GAP = 14;
	// Height of the lane a connection takes in a column it only crosses.
	private const WAYPOINT_HEIGHT = 16;
	private const ARROW_GAP = 5;
	private const PADDING = 20;
	private const NAME_MAX_LENGTH = 22;

	// Zabbix trigger severities from which an open problem makes a node a warning or a problem.
	public const SEVERITY_WARNING = 2;
	public const SEVERITY_PROBLEM = 3;

	// The map is shrunk at most to this share of its natural size; wider maps scroll instead of becoming unreadable.
	private const MIN_SCALE = 0.8;

	// Barycenter sweeps (forward and backward) used to order the nodes of each column.
	private const ORDER_SWEEPS = 4;

	private const EDGE_WIDTH_MIN = 1.5;
	private const EDGE_WIDTH_MAX = 8;
	// As drawn: thinner than the scale above, so that the lines do not compete with the nodes.
	private const EDGE_DRAW_SCALE = 0.7;

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
				'browser' => $service['browser'] ?? false,
				'language' => $service['language'] ?? ''
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
			$health = self::nodeHealth($node, $thresholds);
			$result['health'][$health]++;

			if ($node['p95'] !== null && ($result['slowest'] === null || $node['p95'] > $result['slowest']['p95'])) {
				$result['slowest'] = ['name' => $node['name'], 'p95' => $node['p95']];
			}

			if ($health !== 'ok') {
				$problems = $node['problems'] ?? null;

				$attention[] = [$health, $error_rate, $p95 ?? 0, $node['name'], implode(' · ', array_filter([
					self::reason($error_rate, $p95, $thresholds),
					$problems !== null && $problems['count'] > 0
						? sprintf(I18n::t('Zabbix: %s'), $problems['worst']).($problems['count'] > 1 ? ' +'.($problems['count'] - 1) : '')
						: ''
				])), $node['name']];
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
	 * Radius of a node from its share of the traffic of the busiest node (square root: area follows traffic, and small
	 * services stay visible).
	 */
	public static function nodeRadius(float $rpm, float $max_rpm): float {
		if ($max_rpm <= 0) {
			return self::RADIUS_MIN;
		}

		return round(self::RADIUS_MIN + (self::RADIUS_MAX - self::RADIUS_MIN) * sqrt(min(1, max(0, $rpm) / $max_rpm)), 1);
	}

	/**
	 * Where everything goes: one column per layer, and, for a connection that skips columns, a waypoint in each column
	 * it crosses, so that its line runs between the nodes instead of behind them. Connections that leave the same node
	 * share their waypoints (one lane per source).
	 *
	 * @return array  ['positions' => [id => [x, y]] (centers, waypoints included), 'routes' => [edge index => [id, ...]]
	 *                (from, waypoints, to), 'radius' => [id => float], 'width' => float, 'height' => float]
	 */
	public static function layout(array $graph): array {
		$nodes = $graph['nodes'];
		$routes = [];
		$segments = [];

		foreach ($graph['edges'] as $i => $edge) {
			$from = $nodes[$edge['from']]['layer'];
			$to = $nodes[$edge['to']]['layer'];
			$route = [$edge['from']];

			for ($layer = $from + 1; $layer < $to; $layer++) {
				$id = 'wp:'.$edge['from'].':'.$layer;
				$nodes[$id] ??= ['id' => $id, 'kind' => 'waypoint', 'name' => $id, 'requests' => 0, 'rpm' => 0,
					'layer' => $layer
				];
				$route[] = $id;
			}

			$route[] = $edge['to'];
			$routes[$i] = $route;

			for ($j = 1; $j < count($route); $j++) {
				$segments[$route[$j - 1].'>'.$route[$j]] = ['from' => $route[$j - 1], 'to' => $route[$j]];
			}
		}

		$columns = self::orderColumns($nodes, array_values($segments));
		$max_rpm = max(array_map(static fn(array $node): float => $node['kind'] === 'users' ? 0 : $node['rpm'], $nodes) ?: [0]);
		$radius = [];
		$heights = [];

		foreach ($nodes as $id => $node) {
			$radius[$id] = match ($node['kind']) {
				'waypoint' => 0.0,
				'users' => self::RADIUS_MAX * 0.75,
				'service' => self::nodeRadius($node['rpm'], $max_rpm),
				default => self::RADIUS_MIN
			};
			$heights[$id] = $node['kind'] === 'waypoint' ? self::WAYPOINT_HEIGHT : 2 * $radius[$id] + self::LABEL_HEIGHT;
		}

		$column_heights = [];

		foreach ($columns as $layer => $ids) {
			$column_heights[$layer] = array_sum(array_map(static fn(string $id): float => $heights[$id], $ids))
				+ (count($ids) - 1) * self::ROW_GAP;
		}

		$height = 2 * self::PADDING + max($column_heights);
		$width = 2 * self::PADDING + count($columns) * self::COLUMN_WIDTH;
		$positions = [];
		$column_index = 0;

		foreach ($columns as $layer => $ids) {
			$y = ($height - $column_heights[$layer]) / 2;

			foreach ($ids as $id) {
				$positions[$id] = [
					self::PADDING + ($column_index + 0.5) * self::COLUMN_WIDTH,
					// The center of the shape; the label hangs below it.
					$nodes[$id]['kind'] === 'waypoint' ? $y + $heights[$id] / 2 : $y + $radius[$id]
				];
				$y += $heights[$id] + self::ROW_GAP;
			}

			$column_index++;
		}

		return ['positions' => $positions, 'routes' => $routes, 'radius' => $radius, 'width' => $width, 'height' => $height];
	}

	/**
	 * Line of a connection through its waypoints: horizontal at both ends and at every waypoint, from the edge of one
	 * shape to the edge of the other.
	 */
	public static function routePath(array $route, array $positions, array $radius): string {
		$points = [];

		foreach ($route as $i => $id) {
			[$x, $y] = $positions[$id];

			if ($i == 0) {
				$x += $radius[$id] + 1;
			}
			elseif ($i == count($route) - 1) {
				$x -= $radius[$id] + self::ARROW_GAP;
			}

			$points[] = [$x, $y];
		}

		$d = sprintf('M%.1f,%.1f', ...$points[0]);

		for ($i = 1; $i < count($points); $i++) {
			[$x1, $y1] = $points[$i - 1];
			[$x2, $y2] = $points[$i];
			$dx = max(24, abs($x2 - $x1) / 2);

			// A connection that goes back (a call cycle) loops out to the right and comes in from the left.
			$d .= sprintf(' C%.1f,%.1f %.1f,%.1f %.1f,%.1f', $x1 + $dx, $y1, $x2 - $dx, $y2, $x2, $y2);
		}

		return $d;
	}

	/**
	 * The one figure written under a node: what is wrong with it now, or, when nothing is, its traffic.
	 *
	 * @return array  [string $text, bool $hot]
	 */
	public static function nodeFigure(array $node, array $thresholds = self::DEFAULT_THRESHOLDS): array {
		if ($node['kind'] === 'users') {
			return [self::formatRate($node['rpm']).'/min', false];
		}

		[$error_rate, $p95] = self::current($node);

		if ($error_rate >= $thresholds['error_warning']) {
			return [sprintf(I18n::t('%s errors'), sprintf('%.1f %%', $error_rate)), true];
		}

		if ($p95 !== null && $p95 >= $thresholds['latency_warning']) {
			return ['p95 '.self::shortDuration($p95), true];
		}

		return [self::formatRate($node['rpm']).'/min', false];
	}

	/**
	 * Health of a node: the worst of what telemetry says now and of its open Zabbix problems.
	 */
	public static function nodeHealth(array $node, array $thresholds = self::DEFAULT_THRESHOLDS): string {
		[$error_rate, $p95] = self::current($node);
		$health = self::health($error_rate, $p95, $thresholds);
		$severity = $node['problems']['severity'] ?? -1;

		if ($severity >= self::SEVERITY_PROBLEM) {
			return 'bad';
		}

		return $severity >= self::SEVERITY_WARNING && $health === 'ok' ? 'warn' : $health;
	}

	/**
	 * Map as SVG with a legend: a graph that reads from the users, on the left, to the dependencies, on the right.
	 * A node is as large as its traffic and its ring is its health; a line is grey unless something is wrong on it now.
	 * Nothing moves until a node is pointed at or picked (CApmServiceMapInteraction).
	 *
	 * @param array $graph       Output of build(); nodes may carry 'problems' (ZabbixProblems::forServices()).
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

		$layout = self::layout($graph);
		$positions = $layout['positions'];
		$radius = $layout['radius'];

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
					->setAttribute('markerWidth', '8')
					->setAttribute('markerHeight', '8')
					->setAttribute('orient', 'auto-start-reverse')
					->addItem((new CTag('path', true))->setAttribute('d', 'M1,1 L9,5 L1,9 z'))
			);
		}

		$svg = (new CTag('svg', true))
			->addClass('apm-map-svg')
			->setAttribute('width', (string) round($layout['width']))
			->setAttribute('height', (string) round($layout['height']))
			->setAttribute('viewBox', sprintf('0 0 %d %d', round($layout['width']), round($layout['height'])))
			->setAttribute('style', 'min-width: '.round($layout['width'] * self::MIN_SCALE).'px')
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
			$d = self::routePath($layout['routes'][$i], $positions, $radius);
			$error_rate = self::errorRate($edge['errors'], $edge['calls']);
			[$now_error_rate, $now_p95] = self::current($edge);
			$health = self::health($now_error_rate, $now_p95, $thresholds);
			$from_name = $nodes[$edge['from']]['name'];
			$to_name = $nodes[$edge['to']]['name'];
			$width_px = round(self::edgeWidth($edge['rpm'], $max_rpm) * self::EDGE_DRAW_SCALE, 1);

			$edge_layer->addItem(
				(new CTag('g', true))
					->addClass('apm-map-edge')
					->addClass('apm-map-'.$health)
					->addClass('apm-map-flow-'.self::flowSpeed($edge['rpm']))
					->addClass($focus !== '' && !array_key_exists($i, $lit_edges) ? 'apm-map-dim' : null)
					->setAttribute('data-apm-edge', $edge['from'].'>'.$edge['to'])
					->setAttribute('data-from', $edge['from'])
					->setAttribute('data-to', $edge['to'])
					->setAttribute('data-health', $health)
					->setAttribute('aria-label', $from_name.' → '.$to_name)
					->setAttribute('data-tip-title', $from_name.' → '.$to_name)
					->setAttribute('data-tip', implode("\n", array_filter([
						sprintf(I18n::t('Calls: %s (%s/min)'), self::formatCount($edge['calls']), self::formatRate($edge['rpm'])),
						sprintf(I18n::t('Errors: %s'), sprintf('%.1f %%', $error_rate)),
						'p95: '.ApmStats::formatDuration($edge['p95'] ?? 0),
						self::nowLine($edge)
					])))
					// A wide transparent line makes a thin connection easy to point at.
					->addItem((new CTag('path', true))->addClass('apm-map-hit')->setAttribute('d', $d))
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
							->setAttribute('stroke-width', (string) max(2, min(4, $width_px)))
					)
			);
		}

		$node_layer = (new CTag('g', true))->addClass('apm-map-nodes');

		foreach ($nodes as $id => $node) {
			[$x, $y] = $positions[$id];
			$r = $radius[$id];

			$error_rate = self::errorRate($node['errors'], $node['requests']);
			[$now_error_rate, $now_p95] = self::current($node);
			$health = $node['kind'] === 'users' ? 'neutral' : self::nodeHealth($node, $thresholds);
			$name = mb_strlen($node['name']) > self::NAME_MAX_LENGTH
				? mb_substr($node['name'], 0, self::NAME_MAX_LENGTH - 1).'…'
				: $node['name'];
			[$figure, $hot] = self::nodeFigure($node, $thresholds);
			$problems = $node['problems'] ?? null;

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

			if ($problems !== null && $problems['count'] > 0) {
				$tip[] = sprintf(I18n::t('Zabbix problems: %d (%s)'), $problems['count'], $problems['worst']);
			}

			$group = (new CTag('g', true))
				->addClass('apm-map-node')
				->addClass('apm-map-'.$health)
				->addClass('apm-map-kind-'.$node['kind'])
				->addClass(($node['browser'] ?? false) ? 'apm-map-browser' : null)
				->addClass($focus !== '' && !array_key_exists($id, $lit_nodes) ? 'apm-map-dim' : null)
				->addClass($focus !== '' && $node['kind'] === 'service' && self::matches($node['name'], $focus)
					? 'apm-map-focus'
					: null
				)
				->setAttribute('transform', sprintf('translate(%.1f,%.1f)', $x, $y))
				->setAttribute('data-apm-node', $id)
				->setAttribute('data-name', $node['name'])
				->setAttribute('data-kind', $node['kind'])
				->setAttribute('data-health', $health)
				->setAttribute('data-apm-service', $node['kind'] === 'service' ? $node['name'] : null)
				->setAttribute('data-tip-title', implode(' · ', array_filter([$node['name'],
					I18n::t(self::KIND_LABELS[$node['kind']]), self::language($node)['name']
				])))
				->setAttribute('data-tip', implode("\n", array_filter($tip)))
				->addItem(self::shape($node, $r, $health, $now_error_rate, $now_p95, $thresholds));

			if ($problems !== null && $problems['count'] > 0) {
				$group
					->addItem(
						(new CTag('circle', true))
							->addClass('apm-map-badge')
							->addClass('apm-map-severity-'.$problems['severity'])
							->setAttribute('cx', sprintf('%.1f', $r * 0.75))
							->setAttribute('cy', sprintf('%.1f', -$r * 0.75))
							->setAttribute('r', '8')
					)
					->addItem(
						(new CTag('text', true, (string) min(99, $problems['count'])))
							->addClass('apm-map-badge-text')
							->setAttribute('x', sprintf('%.1f', $r * 0.75))
							->setAttribute('y', sprintf('%.1f', -$r * 0.75 + 3.5))
							->setAttribute('text-anchor', 'middle')
					);
			}

			$group
				->addItem(
					(new CTag('text', true, $name))
						->addClass('apm-map-node-name')
						->setAttribute('y', sprintf('%.1f', $r + 15))
						->setAttribute('text-anchor', 'middle')
				)
				->addItem(
					(new CTag('text', true, $figure))
						->addClass('apm-map-node-stats')
						->addClass($hot ? 'apm-map-hot' : null)
						->setAttribute('y', sprintf('%.1f', $r + 29))
						->setAttribute('text-anchor', 'middle')
				);

			$node_layer->addItem(self::link(self::nodeUrl($node, $ctx), $node['name'], $group));
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
	 * Shape of a node by what it is: a circle for a service, a cylinder for a database, a capsule for a queue, a
	 * diamond for something outside, and a glyph inside the circle for the users and for a browser.
	 */
	private static function shape(array $node, float $r, string $health, float $error_rate, ?float $p95,
			array $thresholds): array {
		$f = static fn(float $value): string => sprintf('%.1f', $value);
		$kind = $node['kind'];

		switch ($kind) {
			case 'database':
				$w = $r * 0.9;
				$h = $r;
				$e = $r * 0.32;

				return [
					(new CTag('path', true))
						->addClass('apm-map-shape')
						->setAttribute('d', 'M'.$f(-$w).','.$f(-$h + $e).' A'.$f($w).','.$f($e).' 0 0 1 '.$f($w).','.$f(-$h + $e)
							.' V'.$f($h - $e).' A'.$f($w).','.$f($e).' 0 0 1 '.$f(-$w).','.$f($h - $e).' Z'
						),
					(new CTag('path', true))
						->addClass('apm-map-shape-detail')
						->setAttribute('d', 'M'.$f(-$w).','.$f(-$h + $e).' A'.$f($w).','.$f($e).' 0 0 0 '.$f($w).','.$f(-$h + $e))
				];

			case 'queue':
				return [
					(new CTag('rect', true))
						->addClass('apm-map-shape')
						->setAttribute('x', $f(-$r * 1.25))
						->setAttribute('y', $f(-$r * 0.7))
						->setAttribute('width', $f($r * 2.5))
						->setAttribute('height', $f($r * 1.4))
						->setAttribute('rx', $f($r * 0.7)),
					(new CTag('path', true))
						->addClass('apm-map-shape-detail')
						->setAttribute('d', 'M'.$f(-$r * 0.4).','.$f(-$r * 0.7).' V'.$f($r * 0.7)
							.' M'.$f($r * 0.4).','.$f(-$r * 0.7).' V'.$f($r * 0.7)
						)
				];

			case 'external':
				return [
					(new CTag('path', true))
						->addClass('apm-map-shape')
						->setAttribute('d', 'M0,'.$f(-$r).' L'.$f($r).',0 L0,'.$f($r).' L'.$f(-$r).',0 Z')
				];

			default:
				$language = self::language($node);

				// The ring: all of it takes the color of the health when the service is slow or has open problems;
				// when what is wrong is errors, it stays green and the share that fails now is a red arc from the top.
				$slow = self::health(0.0, $p95, $thresholds) !== 'ok';
				$problems = ($node['problems']['severity'] ?? -1) >= self::SEVERITY_WARNING;
				$ring = $kind === 'users' ? 'neutral' : ($slow || $problems ? $health : 'ok');
				$arc = $kind === 'users' ? null : self::errorArc($error_rate, $r);

				$inside = $kind === 'users'
					? (new CTag('path', true))
						->addClass('apm-map-glyph-solid')
						->setAttribute('d', 'M0,-9 a4.5,4.5 0 1 1 0,9 a4.5,4.5 0 0 1 0,-9 z M-9,10 c0,-5 4,-8 9,-8 s9,3 9,8 z')
					: ($language['mono'] !== ''
						? (new CTag('text', true, $language['mono']))
							->addClass('apm-map-mono')
							->setAttribute('text-anchor', 'middle')
							->setAttribute('dominant-baseline', 'central')
							->setAttribute('font-size', $f(self::monoSize($language['mono'], $r)))
						: (new CTag('path', true))
							->addClass('apm-map-shape-detail')
							->setAttribute('d', $language['key'] === 'webjs'
								? 'M-8,-7 h16 v11 h-16 z M-8,-3.5 h16 M-4,8 h8'
								// No known technology (a proxy, a web server): a plain stack.
								: 'M-7,-6 h14 v4 h-14 z M-7,-1 h14 v4 h-14 z M-7,4 h14 v4 h-14 z'
							));

				return [
					// Opaque under the tint: a line that passes behind a node does not show through it.
					(new CTag('circle', true))->addClass('apm-map-back')->setAttribute('r', $f($r)),
					(new CTag('circle', true))
						->addClass('apm-map-fill')
						->addClass('apm-map-lang-'.($kind === 'users' ? 'users' : $language['key']))
						->setAttribute('r', $f($r)),
					(new CTag('circle', true))
						->addClass('apm-map-shape')
						->addClass('apm-map-ring')
						->addClass('apm-map-ring-'.$ring)
						->setAttribute('r', $f($r)),
					$arc !== null
						? (new CTag('circle', true))
							->addClass('apm-map-arc')
							->setAttribute('r', $f($r))
							->setAttribute('transform', 'rotate(-90)')
							->setAttribute('stroke-dasharray', $f($arc[0]).' '.$f($arc[1]))
						: null,
					$inside
				];
		}
	}

	// Technologies (telemetry.sdk.language): the letters drawn inside the node and the name for the tooltip. Letters,
	// not logos: they read at any size and belong to nobody.
	private const LANGUAGES = [
		'go' => ['Go', 'Go'],
		'java' => ['Java', 'Java'],
		'dotnet' => ['.NET', '.NET'],
		'nodejs' => ['JS', 'Node.js'],
		'python' => ['Py', 'Python'],
		'php' => ['PHP', 'PHP'],
		'ruby' => ['Rb', 'Ruby'],
		'rust' => ['Rs', 'Rust'],
		'cpp' => ['C++', 'C++'],
		'erlang' => ['Ex', 'Erlang / Elixir'],
		'swift' => ['Sw', 'Swift'],
		'webjs' => ['', 'Browser']
	];

	/**
	 * Technology of a node.
	 *
	 * @return array  ['key' => string (a known technology or 'other'), 'mono' => string ('' = drawn as a glyph),
	 *                'name' => string ('' for an unknown one)]
	 */
	public static function language(array $node): array {
		$key = ($node['browser'] ?? false) ? 'webjs' : strtolower((string) ($node['language'] ?? ''));

		if ($node['kind'] !== 'service' || !array_key_exists($key, self::LANGUAGES)) {
			return ['key' => 'other', 'mono' => '', 'name' => ''];
		}

		return ['key' => $key, 'mono' => self::LANGUAGES[$key][0], 'name' => self::LANGUAGES[$key][1]];
	}

	/**
	 * The share of the requests that fail, as an arc of the ring: [length of the arc, length of the rest], for
	 * stroke-dasharray. A share above zero is always visible; null when nothing fails.
	 */
	public static function errorArc(float $error_rate, float $radius): ?array {
		if ($error_rate <= 0 || $radius <= 0) {
			return null;
		}

		$circumference = 2 * M_PI * $radius;
		$length = min($circumference, max(self::ARC_MIN, $circumference * min(100, $error_rate) / 100));

		return [round($length, 1), round($circumference - $length, 1)];
	}

	// Shortest arc drawn for a share of errors above zero, px.
	private const ARC_MIN = 5;

	/**
	 * Size of the letters inside a node: as large as its circle allows for that many letters.
	 */
	private static function monoSize(string $mono, float $r): float {
		return round(min(15, $r * 1.55 / max(2, mb_strlen($mono) * 0.62 + 0.6)), 1);
	}

	/**
	 * Overview page looking at one service	/**
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
			new CSpan(I18n::t('Size = traffic · inside, the technology · ring = health now, with the share of the requests that fail as a red arc · line = calls, colored only when something is wrong. Point at a service to follow its calls; click it to keep only its path and open its details.'))
		]))->addClass('apm-map-legend');
	}

	private static function link(?CUrl $url, string $label, CTag $content): CTag {
		$content->setAttribute('aria-label', $label);

		if ($url === null) {
			return $content;
		}

		return (new CTag('a', true, $content))->setAttribute('href', $url->getUrl());
	}

	/**
	 * A service links to its page; the page script opens the service panel instead (data-apm-service).
	 */
	private static function nodeUrl(array $node, array $ctx = []): ?CUrl {
		return $node['kind'] === 'service' ? self::getServicePageUrl($node['name'], $ctx) : null;
	}

	/**
	 * Page of one service (apm.service), in the period of the context when it has one.
	 */
	public static function getServicePageUrl(string $service, array $ctx = [], string $endpoint = ''): CUrl {
		$url = (new CUrl('zabbix.php'))
			->setArgument('action', 'apm.service')
			->setArgument('service', $service);

		if ($endpoint !== '') {
			$url->setArgument('endpoint', $endpoint);
		}

		if (($ctx['application'] ?? null) !== null) {
			$url->setArgument('app', $ctx['application']);
		}

		return $url;
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

	public static function formatRate(float $rpm): string {
		return $rpm >= 10 ? (string) round($rpm) : sprintf('%.1f', $rpm);
	}

	public static function formatCount(int $value): string {
		return number_format($value, 0, '.', ' ');
	}
}

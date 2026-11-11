<?php declare(strict_types = 0);

/*
 * Checks that run without Zabbix (php tests/run.php): service patterns, service map graph and layers, business metrics
 * and their template, translations.
 * Exit code 1 on the first failure.
 */

namespace {
	foreach (['SEC_PER_MIN' => 60, 'SEC_PER_HOUR' => 3600, 'SEC_PER_DAY' => 86400] as $name => $value) {
		define($name, $value);
	}

	$root = dirname(__DIR__).'/modules';

	require $root.'/ApmOverview/includes/I18n.php';
	require $root.'/ApmOverview/includes/ApmAccess.php';
	require $root.'/ApmOverview/includes/ApmStats.php';
	require $root.'/ApmOverview/includes/Rum.php';
	require $root.'/ApmOverview/includes/RumStats.php';
	require $root.'/ApmOverview/includes/TraceStats.php';
	require $root.'/ApmOverview/includes/ZabbixProblems.php';
	require $root.'/ApmOverview/includes/Chart.php';
	require $root.'/ApmOverview/includes/ServiceLoader.php';
	require $root.'/ApmOverview/includes/ServiceHtml.php';
	require $root.'/ApmOverview/includes/ServiceMap.php';
	require $root.'/ApmOverview/includes/Integration.php';
	require $root.'/ApmOverview/includes/BusinessMetrics.php';

	$failures = 0;

	function check(string $name, $actual, $expected): void {
		global $failures;

		if ($actual !== $expected) {
			$failures++;
			echo "FAIL $name\n  expected: ".var_export($expected, true)."\n  actual:   ".var_export($actual, true)."\n";
		}
	}
}

namespace Modules\ApmOverview\Includes {

	// Service filter: substring, "*" wildcard, "=" exact; LIKE metacharacters escaped.
	check('like substring', ApmStats::toLikePattern('checkout'), '%checkout%');
	check('like wildcard', ApmStats::toLikePattern('demo-*'), 'demo-%');
	check('like exact', ApmStats::toLikePattern('=api'), 'api');
	check('like escapes _', ApmStats::toLikePattern('=my_api'), 'my\\_api');

	// Application tab: the stored one while it has data, otherwise the first; '' (no namespace) is an application.
	check('app stored', ApmStats::resolveApplication(['apm-demo', 'flowspock'], 'flowspock'), 'flowspock');
	check('app gone falls back to first', ApmStats::resolveApplication(['apm-demo', 'flowspock'], 'old'), 'apm-demo');
	check('app no namespace', ApmStats::resolveApplication(['', 'flowspock'], ''), '');
	check('app none', ApmStats::resolveApplication([], 'flowspock'), null);
	check('app never chosen is not the no-namespace tab', ApmStats::resolveApplication(['flowspock', ''], null), 'flowspock');

	// Real user monitoring: user agents, load phases, session timeline.
	check('rum ua headless', Rum::userAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/156.0.0.0 Safari/537.36'),
		['browser' => 'Chrome (headless)', 'os' => 'Linux', 'device' => 'desktop']
	);
	check('rum ua edge', Rum::userAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0')['browser'], 'Edge');
	check('rum ua firefox', Rum::userAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 14.6; rv:132.0) Gecko/20100101 Firefox/132.0'),
		['browser' => 'Firefox', 'os' => 'macOS', 'device' => 'desktop']
	);
	check('rum ua iphone', Rum::userAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1'),
		['browser' => 'Safari', 'os' => 'iOS', 'device' => 'mobile']
	);
	check('rum ua android', Rum::userAgent('Mozilla/5.0 (Linux; Android 15; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36'),
		['browser' => 'Chrome', 'os' => 'Android', 'device' => 'mobile']
	);
	check('rum ua unknown', Rum::userAgent('curl/8.5'), ['browser' => 'Other', 'os' => 'Other', 'device' => 'desktop']);

	$load = Rum::loadPhases(['fetchStart' => 0, 'domainLookupStart' => 1, 'domainLookupEnd' => 3, 'connectStart' => 3,
		'connectEnd' => 8, 'requestStart' => 8, 'responseStart' => 40, 'responseEnd' => 45, 'domInteractive' => 350,
		'domComplete' => 640, 'loadEventStart' => 640, 'loadEventEnd' => 642, 'firstContentfulPaint' => 379
	]);
	check('rum phases without tls', array_column($load['phases'], 'key'),
		['dns', 'connection', 'request', 'response', 'dom', 'resources', 'load_event']
	);
	check('rum phase connection to connectEnd', $load['phases'][1], ['key' => 'connection', 'start' => 3.0, 'end' => 8.0]);
	check('rum phases total and marker', [$load['total'], $load['markers']], [642.0, ['firstContentfulPaint' => 379.0]]);
	check('rum phases render only', array_column(Rum::loadPhases(['fetchStart' => 0, 'domInteractive' => 350,
		'domComplete' => 641, 'loadEventStart' => 641, 'loadEventEnd' => 642])['phases'], 'key'),
		['dom', 'resources', 'load_event']
	);
	check('rum phases none', Rum::loadPhases([]), ['phases' => [], 'markers' => [], 'total' => 0.0]);

	$timeline = Rum::timeline([
		['type' => 'request', 'trace' => 't2', 'start' => 3000000000],
		['type' => 'view', 'trace' => 't0', 'start' => 1000000000],
		['type' => 'action', 'trace' => 't2', 'start' => 2900000000],
		['type' => 'request', 'trace' => 't9', 'start' => 2000000000]
	]);
	check('rum timeline order', array_column($timeline, 'type'), ['view', 'request', 'action']);
	check('rum timeline offsets', array_column($timeline, 'offset_ms'), [0.0, 1000.0, 1900.0]);
	check('rum timeline nesting', array_column($timeline[2]['requests'], 'trace'), ['t2']);
	check('rum timeline empty', Rum::timeline([]), []);
	check('rum timeline same instant: action before request', array_column(Rum::timeline([
		['type' => 'request', 'trace' => 'a', 'start' => 5],
		['type' => 'action', 'trace' => 'b', 'start' => 5]
	]), 'type'), ['action', 'request']);

	// APM filter of the user groups: no filters, no condition; values of one key in one IN; keys joined with OR; every
	// value is a parameter.
	check('access none', ApmAccess::condition([], 'a'), ['', []]);
	[$sql, $params] = ApmAccess::condition([
		['key' => 'service.namespace', 'value' => 'go-example'],
		['key' => 'service.namespace', 'value' => 'shop\' OR 1=1'],
		['key' => 'deployment.environment.name', 'value' => 'lab']
	], 'a');
	check('access sql', $sql, '((mapContains(ResourceAttributes,{a_k0:String})'.
		' AND ResourceAttributes[{a_k0:String}] IN {a_v0:Array(String)})'.
		' OR (mapContains(ResourceAttributes,{a_k1:String})'.
		' AND ResourceAttributes[{a_k1:String}] IN {a_v1:Array(String)}))'
	);
	check('access params', $params, [
		'a_k0' => ['String', 'service.namespace'],
		'a_v0' => ['String', ['go-example', 'shop\' OR 1=1']],
		'a_k1' => ['String', 'deployment.environment.name'],
		'a_v1' => ['String', ['lab']]
	]);
	check('access duplicates', ApmAccess::condition([['key' => 'k', 'value' => 'v'], ['key' => 'k', 'value' => 'v']],
		'a')[1]['a_v0'], ['String', ['v']]
	);

	// Integration guide: the agreed languages, four steps each, only known placeholders, host name as the scripts.
	I18n::setLanguage('en');
	$languages = Integration::languages();
	check('guide languages', array_keys($languages),
		['go', 'python', 'java', 'nodejs', 'dotnet', 'php', 'ruby', 'compose', 'kubernetes', 'test']
	);

	foreach ($languages as $key => $language) {
		check("guide $key has 4 steps", count($language['steps']), 4);

		foreach ($language['steps'] as $i => $step) {
			check("guide $key step $i has title and text", $step['title'] !== '' && $step['text'] !== '', true);

			preg_match_all('/\{[a-z_]+\}/', $step['text'].$step['code'], $m);
			check("guide $key step $i placeholders", array_values(array_diff($m[0], Integration::PLACEHOLDERS)), []);
		}
	}

	// Browser guide: three methods, the same tracing code in each, only known placeholders, pinned package versions.
	$methods = Integration::browserMethods();
	check('browser guide methods', array_keys($methods), ['script', 'npm', 'nextjs']);

	foreach ($methods as $key => $method) {
		foreach ($method['steps'] as $i => $step) {
			check("browser guide $key step $i has title and text", $step['title'] !== '' && $step['text'] !== '', true);

			// Placeholders: a lowercase word in braces.
			preg_match_all('/\{[a-z_]+\}/', $step['text'].$step['code'], $m);
			check("browser guide $key step $i placeholders", array_values(array_diff($m[0], Integration::PLACEHOLDERS)), []);
		}

		check("browser guide $key sets the service and session",
			str_contains(implode("\n", array_column($method['steps'], 'code')), "'session.id': sessionId")
				&& str_contains(implode("\n", array_column($method['steps'], 'code')), "'service.namespace': '{namespace}'"),
			true
		);
	}

	// Exact versions: digits and dots only.
	check('browser guide pinned versions', array_filter(Integration::BROWSER_PACKAGES,
		static fn(string $version): bool => !preg_match('/^\d+\.\d+\.\d+$/', $version)
	), []);

	// The lab page uses the same packages as the guide.
	$lab_page = file_get_contents(dirname(__DIR__).'/examples/lab/web/index.html');
	check('lab page packages match the guide', array_filter(array_keys(Integration::BROWSER_PACKAGES),
		static fn(string $package): bool => !str_contains($lab_page,
			'"'.$package.'": "https://esm.sh/'.$package.'@'.Integration::BROWSER_PACKAGES[$package].'?'
		)
	), []);

	check('guide host name', Integration::hostName('my shop/v2'), 'apm-my shop-v2');
	check('guide defaults', Integration::fill('{service} → {zabbix_host}', Integration::defaults('shop')),
		'shop-api → apm-shop'
	);
	check('guide defaults without application', Integration::defaults('')['{namespace}'], 'my-app');

	check('matches substring', ServiceMap::matches('flowspock-api', 'spock'), true);
	check('matches wildcard', ServiceMap::matches('demo-backend', 'demo-*'), true);
	check('matches wildcard miss', ServiceMap::matches('backend-demo', 'demo-*'), false);
	check('matches exact', ServiceMap::matches('api', '=api'), true);
	check('matches exact miss', ServiceMap::matches('api-gw', '=api'), false);

	// Graph: users -> web -> api -> postgresql, plus an unmatched call to the api's host that must go to the api.
	I18n::setLanguage('en');
	$services = [
		['service' => 'web', 'requests' => 600, 'errors' => 6, 'rpm' => 10.0, 'p95' => 2e8],
		['service' => 'api', 'requests' => 60, 'errors' => 0, 'rpm' => 1.0, 'p95' => 1e7]
	];
	$calls = [
		['source' => 'web', 'target' => 'api', 'db_system' => '', 'host' => 'api.local', 'calls' => 120, 'errors' => 0, 'p95' => 3e7],
		['source' => 'web', 'target' => '', 'db_system' => '', 'host' => 'api.local', 'calls' => 3, 'errors' => 0, 'p95' => 3e7],
		['source' => 'api', 'target' => '', 'db_system' => 'postgresql', 'host' => 'api.local', 'calls' => 900, 'errors' => 0, 'p95' => 5e6],
		['source' => 'web', 'target' => '', 'db_system' => '', 'host' => 'pay.example.com', 'calls' => 10, 'errors' => 1, 'p95' => 9e8]
	];
	$graph = ServiceMap::build($services, $calls, 60);
	$layers = array_map(static fn(array $node): int => $node['layer'], $graph['nodes']);
	ksort($layers);
	check('layers', $layers, ['db:postgresql' => 3, 'ext:pay.example.com' => 2, 'svc:api' => 2, 'svc:web' => 1, 'users' => 0]);

	$edges = [];
	foreach ($graph['edges'] as $edge) {
		$edges[$edge['from'].'>'.$edge['to']] = $edge['calls'];
	}
	ksort($edges);
	check('edges (unmatched call to the api host joins the api, the db keeps its own node)', $edges, [
		'svc:api>db:postgresql' => 900, 'svc:web>ext:pay.example.com' => 10, 'svc:web>svc:api' => 123,
		'users>svc:api' => 60, 'users>svc:web' => 600
	]);
	check('health warn', ServiceMap::health(1.0, null, ServiceMap::DEFAULT_THRESHOLDS), 'warn');
	check('health bad by p95', ServiceMap::health(0.0, 4e9, ServiceMap::DEFAULT_THRESHOLDS), 'bad');

	check('matches: only * is a wildcard', [ServiceMap::matches('a?c', 'a?c'), ServiceMap::matches('abc', 'a?c'),
		ServiceMap::matches('api[1]', 'api[*')], [true, false, true]);

	// Who is user traffic: only what came from outside. A consumer that starts its own trace gets no users edge, a
	// queue is not a database, a host that is the name of a known service is that service, and a problem that stopped
	// (errors in the period, none in the recent window) does not color the map.
	$recent = static fn(int $requests, int $errors, float $p95 = 1e7): array =>
		['requests' => $requests, 'errors' => $errors, 'error_rate' => 0, 'p95' => $p95];
	$graph2 = ServiceMap::build([
		['service' => 'web', 'requests' => 100, 'errors' => 10, 'rpm' => 10.0, 'p95' => 1e8, 'browser' => false,
			'user' => ['requests' => 100, 'errors' => 10], 'recent' => $recent(20, 0)],
		['service' => 'worker', 'requests' => 50, 'errors' => 0, 'rpm' => 5.0, 'p95' => 1e8, 'browser' => false,
			'user' => ['requests' => 0, 'errors' => 0], 'recent' => $recent(10, 0)],
		['service' => 'cart', 'requests' => 30, 'errors' => 0, 'rpm' => 3.0, 'p95' => 1e7, 'browser' => false,
			'user' => ['requests' => 0, 'errors' => 0], 'recent' => $recent(5, 5)]
	], [
		['source' => 'web', 'target' => '', 'db_system' => 'kafka', 'host' => 'kafka', 'calls' => 50, 'errors' => 0,
			'p95' => 1e6, 'recent' => ['calls' => 10, 'errors' => 0, 'p95' => 1e6]],
		['source' => 'web', 'target' => '', 'db_system' => '', 'host' => 'cart', 'calls' => 30, 'errors' => 0,
			'p95' => 1e6, 'recent' => ['calls' => 5, 'errors' => 0, 'p95' => 1e6]]
	], 10);
	$edges2 = array_map(static fn(array $edge): string => $edge['from'].'>'.$edge['to'], $graph2['edges']);
	sort($edges2);
	check('map: users edge only for user traffic, queue node, host named as a service',
		$edges2, ['svc:web>mq:kafka', 'svc:web>svc:cart', 'users>svc:web']
	);
	check('map: queue kind', $graph2['nodes']['mq:kafka']['kind'], 'queue');
	check('map: a measured service keeps its own figures', $graph2['nodes']['svc:cart']['requests'], 30);
	check('map: health now, a problem that stopped is healthy',
		ServiceMap::health(...[...ServiceMap::current($graph2['nodes']['svc:web']), ServiceMap::DEFAULT_THRESHOLDS]), 'ok'
	);
	check('map: health now, a problem that is happening',
		ServiceMap::health(...[...ServiceMap::current($graph2['nodes']['svc:cart']), ServiceMap::DEFAULT_THRESHOLDS]), 'bad'
	);
	check('map: without a recent window the period decides',
		ServiceMap::current(['errors' => 5, 'requests' => 100, 'p95' => 2.0, 'now' => null]), [5.0, 2.0]
	);
	$summary2 = ServiceMap::summary($graph2);
	check('map summary: only user traffic is incoming', $summary2['requests'], 100);
	check('map summary: attention follows now', array_column($summary2['attention'], 'label'), ['cart']);

	// The graph as drawn: nodes as large as their traffic, and a connection that skips a column gets a waypoint in it,
	// so its line runs between the nodes. Lanes are shared by the connections that leave the same node.
	check('node radius', [ServiceMap::nodeRadius(0, 100), ServiceMap::nodeRadius(100, 100), ServiceMap::nodeRadius(25, 100),
		ServiceMap::nodeRadius(5, 0)], [15.0, 30.0, 22.5, 15.0]);

	// Inside a node, its technology; around it, the share of the requests that fail as an arc of the ring.
	check('technology of a node', array_map(static fn(array $node): array => array_values(ServiceMap::language($node)), [
		['kind' => 'service', 'language' => 'go'],
		['kind' => 'service', 'language' => 'DotNet'],
		['kind' => 'service', 'language' => 'nodejs', 'browser' => true],
		['kind' => 'service', 'language' => 'cobol'],
		['kind' => 'service', 'language' => ''],
		['kind' => 'database', 'language' => 'go']
	]), [['go', 'Go', 'Go'], ['dotnet', '.NET', '.NET'], ['webjs', '', 'Browser'], ['other', '', ''], ['other', '', ''],
		['other', '', '']
	]);
	check('error arc: nothing fails', ServiceMap::errorArc(0.0, 20), null);
	check('error arc: a third', ServiceMap::errorArc(30.0, 20), [37.7, 88.0]);
	check('error arc: everything', ServiceMap::errorArc(100.0, 20), [125.7, 0.0]);
	check('error arc: a small share is still visible', ServiceMap::errorArc(0.1, 20), [5.0, 120.7]);
	$layout = ServiceMap::layout($graph);
	$route = static function (string $from, string $to) use ($graph, $layout): array {
		foreach ($graph['edges'] as $i => $edge) {
			if ($edge['from'] === $from && $edge['to'] === $to) {
				return $layout['routes'][$i];
			}
		}

		return [];
	};
	check('route between neighbouring columns is direct', $route('svc:web', 'svc:api'), ['svc:web', 'svc:api']);
	check('route across a column goes through a waypoint', $route('users', 'svc:api'), ['users', 'wp:users:1', 'svc:api']);
	check('every node and waypoint has a place', count($layout['positions']), count($graph['nodes']) + 1);
	check('columns go left to right', $layout['positions']['users'][0] < $layout['positions']['svc:web'][0]
		&& $layout['positions']['svc:web'][0] < $layout['positions']['svc:api'][0], true);
	check('a waypoint sits in the column it crosses',
		$layout['positions']['wp:users:1'][0] === $layout['positions']['svc:web'][0], true
	);
	check('a line starts at the edge of its node', str_starts_with(
		ServiceMap::routePath(['a', 'b'], ['a' => [100, 50], 'b' => [300, 80]], ['a' => 20, 'b' => 10]), 'M121.0,50.0 C'
	), true);

	// One figure under a node: what is wrong now, else its traffic.
	$figure = static fn(array $now, float $rpm = 12): array => ServiceMap::nodeFigure(['kind' => 'service', 'rpm' => $rpm,
		'errors' => 0, 'requests' => 100, 'p95' => 1e7, 'now' => $now
	]);
	check('node figure healthy', $figure(['requests' => 10, 'errors' => 0, 'p95' => 1e7]), ['12/min', false]);
	check('node figure errors', $figure(['requests' => 10, 'errors' => 2, 'p95' => 1e7]), ['20.0 % errors', true]);
	check('node figure slow', $figure(['requests' => 10, 'errors' => 0, 'p95' => 2.7e9]), ['p95 2.7s', true]);

	// Health with Zabbix problems: an Average problem makes a healthy service a problem, a Warning a warning.
	$healthy = ['kind' => 'service', 'errors' => 0, 'requests' => 10, 'p95' => 1e7, 'now' => null];
	check('health with problems', [
		ServiceMap::nodeHealth($healthy),
		ServiceMap::nodeHealth($healthy + ['problems' => ['severity' => 2, 'count' => 1]]),
		ServiceMap::nodeHealth($healthy + ['problems' => ['severity' => 3, 'count' => 1]]),
		ServiceMap::nodeHealth($healthy + ['problems' => ['severity' => 1, 'count' => 1]])
	], ['ok', 'warn', 'bad', 'ok']);
	$grouped = ZabbixProblems::group([
		['eventid' => '1', 'objectid' => '9', 'name' => 'Queue is filling', 'severity' => '2', 'clock' => '100',
			'tags' => [['tag' => 'service', 'value' => 'api'], ['tag' => 'scope', 'value' => 'x']]],
		['eventid' => '2', 'objectid' => '9', 'name' => 'Down', 'severity' => '4', 'clock' => '90',
			'tags' => [['tag' => 'service', 'value' => 'api']]],
		['eventid' => '3', 'objectid' => '9', 'name' => 'Other', 'severity' => '5', 'clock' => '80',
			'tags' => [['tag' => 'service', 'value' => 'unknown-service']]]
	], ['api', 'web']);
	check('problems by service tag', [array_keys($grouped), $grouped['api']['count'], $grouped['api']['severity'],
		$grouped['api']['worst']], [['api'], 2, 4, 'Down']);

	// Versions: latest first; deployed = first seen in the period while another one was there before.
	$versions = ApmStats::groupVersions([
		['service' => 'api', 'version' => '1.0', 'first' => 100, 'last' => 900, 'requests' => 30, 'errors' => 0, 'p95' => 1.0],
		['service' => 'api', 'version' => '1.1', 'first' => 600, 'last' => 990, 'requests' => 10, 'errors' => 5, 'p95' => 2.0],
		['service' => 'web', 'version' => '7', 'first' => 550, 'last' => 990, 'requests' => 10, 'errors' => 0, 'p95' => 1.0]
	], 500);
	check('versions order and deployment', array_map(static fn(array $v): array => [$v['version'], $v['deployed'],
		round($v['share']), round($v['error_rate'])], $versions['api']), [['1.1', true, 25.0, 50.0], ['1.0', false, 75.0, 0.0]]
	);
	check('a first version is not a deployment', $versions['web'][0]['deployed'], false);
	check('versions to compare', ServiceLoader::pickVersions($versions['api'], ['a' => '', 'b' => '']),
		['a' => '1.1', 'b' => '1.0', 'all' => ['1.1', '1.0']]
	);
	check('one version: nothing to compare', ServiceLoader::pickVersions($versions['web'], ['a' => '', 'b' => '']), null);
	check('errors of two versions', array_column(ServiceHtml::diffErrors([
		['endpoint' => 'e', 'type' => '', 'message' => 'old', 'a' => 0, 'b' => 4],
		['endpoint' => 'e', 'type' => '', 'message' => 'both', 'a' => 2, 'b' => 2],
		['endpoint' => 'e', 'type' => '', 'message' => 'new', 'a' => 9, 'b' => 0]
	]), 'where', 'message'), ['new' => 'a', 'both' => 'both', 'old' => 'b']);

	// Tag analysis: values much more frequent among the outliers, largest difference first; attributes with many
	// values (ids) and rare values are dropped.
	$tag_rows = [
		['key' => 'http.route', 'value' => '/slow', 'outliers' => 80, 'rest' => 50],
		['key' => 'http.route', 'value' => '/fast', 'outliers' => 20, 'rest' => 950],
		['key' => 'service.version', 'value' => '2.0', 'outliers' => 60, 'rest' => 100],
		['key' => 'rare', 'value' => 'x', 'outliers' => 3, 'rest' => 0]
	];
	for ($i = 0; $i < 40; $i++) {
		$tag_rows[] = ['key' => 'order.id', 'value' => 'o'.$i, 'outliers' => 10, 'rest' => 0];
	}
	$ranked = ApmStats::rankTags($tag_rows, 100, 1000);
	check('tag analysis order', array_map(static fn(array $tag): string => $tag['key'].'='.$tag['value'], $ranked),
		['http.route=/slow', 'service.version=2.0']
	);
	check('tag analysis shares', [round($ranked[0]['share_outliers']), round($ranked[0]['share_rest']), round($ranked[0]['lift'])],
		[80.0, 5.0, 16.0]
	);
	check('tag analysis without outliers', ApmStats::rankTags($tag_rows, 0, 1000), []);

	// Charts and time series.
	check('chart scale', array_map([Chart::class, 'niceMax'], [0, 0.07, 3, 42, 180, 1000]), [0.0, 0.1, 5.0, 50.0, 200.0, 1000.0]);
	check('time series step', [ApmStats::timeseriesStep(0, 3600), ApmStats::timeseriesStep(0, 86400), ApmStats::timeseriesStep(0, 300)],
		[60, 1440, 60]
	);
	check('neighbours of a service', array_map(static fn(array $rows): array => array_column($rows, 'name'),
		ServiceLoader::pickNeighbours($graph, 'web')), ['callers' => ['Users'], 'callees' => ['api', 'pay.example.com']]
	);

	// State of an error group: resolved when it stopped, new when never seen before, back after a gap, else active.
	check('error state resolved', ApmStats::errorState(0, 0, 1000, 900), ApmStats::STATE_RESOLVED);
	check('error state new', ApmStats::errorState(3, 5000, 0, 900), ApmStats::STATE_NEW);
	check('error state came back', ApmStats::errorState(3, 5000, 4000, 900), ApmStats::STATE_REGRESSED);
	check('error state active', ApmStats::errorState(3, 5000, 4500, 900), ApmStats::STATE_ACTIVE);
	check('error message without its changing parts', ApmStats::normalizeMessage(
		'order 4711 of user 7f3c2a10-1b2c-4d5e-8f90-123456789abc failed at 0x1F after 30 ms'
	), 'order ? of user ? failed at ? after ? ms');
	check('error message keeps words', ApmStats::normalizeMessage('stream closed due to server-side timeout'),
		'stream closed due to server-side timeout'
	);

	// A request and everything below it, whatever the order of the spans.
	$tree = [
		['id' => 'a', 'parent' => ''], ['id' => 'c', 'parent' => 'b'], ['id' => 'b', 'parent' => 'a'],
		['id' => 'd', 'parent' => 'a'], ['id' => 'e', 'parent' => 'c']
	];
	check('subtree', array_column(ApmStats::subtree($tree, 'b'), 'id'), ['c', 'b', 'e']);
	check('subtree of a span that is not there: everything', count(ApmStats::subtree($tree, 'zz')), 5);

	// Trace panel: services in the order they joined, and the browser/backend split of a request.
	$trace_spans = [
		['service' => 'web', 'kind' => 'Client', 'duration' => 100.0, 'error' => false, 'browser' => true],
		['service' => 'api', 'kind' => 'Server', 'duration' => 60.0, 'error' => true, 'browser' => false],
		['service' => 'api', 'kind' => 'Client', 'duration' => 20.0, 'error' => false, 'browser' => false]
	];
	check('trace services', array_map(static fn(array $s): array => [$s['service'], $s['spans'], $s['errors'], $s['browser']],
		TraceStats::services($trace_spans)
	), [['web', 1, 0, true], ['api', 2, 1, false]]);
	check('trace split', TraceStats::split($trace_spans), ['total' => 100.0, 'backend' => 60.0, 'network' => 40.0]);
	check('trace split without a browser', TraceStats::split(array_slice($trace_spans, 1)), null);

	// A view collects what it loaded; resources are not rows of the timeline.
	$views = Rum::timeline([
		['type' => 'view', 'trace' => 'v', 'start' => 0, 'duration' => 500.0],
		['type' => 'resource', 'trace' => 'v', 'start' => 100, 'duration' => 50.0, 'url' => 'https://x/app.js', 'kind' => 'script'],
		['type' => 'request', 'trace' => 'r', 'start' => 200, 'duration' => 80.0, 'url' => 'https://x/api', 'failed' => true]
	]);
	check('rum view: resources are not rows', array_column($views, 'type'), ['view', 'request']);
	check('rum view: what it loaded', array_map(static fn(array $l): array => [$l['type'], $l['start'], $l['failed']],
		$views[0]['loaded']
	), [['resource', 100, false], ['request', 200, true]]);

	// Map drawing: width by share of the busiest edge, particle speed by calls per minute.
	check('edge width busiest', ServiceMap::edgeWidth(100, 100), 8.0);
	check('edge width idle', ServiceMap::edgeWidth(0, 100), 1.5);
	check('edge width without traffic', ServiceMap::edgeWidth(5, 0), 1.5);
	check('flow speed', [ServiceMap::flowSpeed(1), ServiceMap::flowSpeed(10), ServiceMap::flowSpeed(600)],
		['slow', 'medium', 'fast']
	);
	check('short durations', array_map([ServiceMap::class, 'shortDuration'], [4e5, 2.614e7, 1.53e9, 6.4826e10]),
		['0.4ms', '26ms', '1.5s', '1m 5s']
	);

	// State of the system: entry traffic and errors, slowest service, health counts, problems before warnings.
	$summary = ServiceMap::summary($graph);
	check('summary requests', $summary['requests'], 660);
	check('summary rpm', $summary['rpm'], 11.0);
	check('summary error rate', round($summary['error_rate'], 2), 0.91);
	check('summary slowest', $summary['slowest']['name'], 'web');
	check('summary health', $summary['health'], ['ok' => 1, 'warn' => 1, 'bad' => 0]);
	check('summary attention order', array_column($summary['attention'], 'label'), ['web → pay.example.com', 'web']);
	check('summary attention severity', array_column($summary['attention'], 'severity'), ['bad', 'warn']);

	// Barycenter ordering removes the crossing of a → y / b → x and is deterministic.
	$order_nodes = [
		'u' => ['layer' => 0, 'requests' => 10, 'name' => 'u'],
		'a' => ['layer' => 1, 'requests' => 9, 'name' => 'a'],
		'b' => ['layer' => 1, 'requests' => 8, 'name' => 'b'],
		'x' => ['layer' => 2, 'requests' => 7, 'name' => 'x'],
		'y' => ['layer' => 2, 'requests' => 6, 'name' => 'y']
	];
	$order_edges = [['from' => 'u', 'to' => 'a'], ['from' => 'u', 'to' => 'b'], ['from' => 'a', 'to' => 'y'],
		['from' => 'b', 'to' => 'x']
	];
	check('crossings before', ServiceMap::crossings([0 => ['u'], 1 => ['a', 'b'], 2 => ['x', 'y']], $order_nodes,
		$order_edges
	), 1);
	$ordered = ServiceMap::orderColumns($order_nodes, $order_edges);
	check('crossings after ordering', ServiceMap::crossings($ordered, $order_nodes, $order_edges), 0);
	check('ordering deterministic', ServiceMap::orderColumns($order_nodes, $order_edges), $ordered);

	// Business metrics: the tab and the template measure with the same default filters.
	$kpi_template = json_decode(file_get_contents(dirname(__DIR__).'/templates/apm_business_metrics_clickhouse_http.json'),
		true
	)['zabbix_export']['templates'][0];
	$kpi_macros = array_column($kpi_template['macros'], 'value', 'macro');
	check('kpi metric filter = template', $kpi_macros['{$APM.KPI.METRIC.NOT_MATCHES}'] ?? null,
		BusinessMetrics::METRIC_NOT_MATCHES
	);
	check('kpi dimension filter = template', $kpi_macros['{$APM.KPI.DIMENSION.NOT_MATCHES}'] ?? null,
		BusinessMetrics::DIMENSION_NOT_MATCHES
	);
	check('kpi metric filter excludes the instrumentation', array_map(
		static fn(string $name): bool => (bool) preg_match('/'.BusinessMetrics::METRIC_NOT_MATCHES.'/', $name),
		['http.server.request.duration', 'go.memory.used', 'otel.sdk.span.live', 'shop.orders', 'hotel.reservations',
			'demo.payment.transactions'
		]
	), [true, true, true, false, false, false]);
	check('kpi dimension filter excludes identifiers', array_map(
		static fn(string $key): bool => (bool) preg_match('/'.BusinessMetrics::DIMENSION_NOT_MATCHES.'/', $key),
		['user.id', 'session_id', 'client.address', 'shop.store', 'hotel.name', 'demo.payment.currency']
	), [true, true, true, false, false, false]);

	check('kpi totals master', array_column($kpi_template['items'], 'key'),
		['apm.kpi.get', 'apm.kpi.totals.get', 'apm.kpi.rows']
	);
	check('kpi time zone macro', $kpi_macros['{$APM.KPI.TIMEZONE}'] ?? null, 'UTC');
	$rules = array_column($kpi_template['discovery_rules'], null, 'key');
	$prototype_keys = static fn(string $rule): array => array_map(
		static fn(array $item): string => strstr($item['key'], '[', true), $rules[$rule]['item_prototypes']
	);
	check('kpi share and rank only per value', [
		array_intersect(['apm.kpi.share.today', 'apm.kpi.rank.today'], $prototype_keys('apm.kpi.discovery')),
		array_values(array_intersect(['apm.kpi.share.today', 'apm.kpi.rank.today'],
			$prototype_keys('apm.kpi.dimension.discovery')
		))
	], [[], ['apm.kpi.share.today', 'apm.kpi.rank.today']]);
	$totals_master = array_column($rules['apm.kpi.discovery']['item_prototypes'], null, 'key')
		['apm.kpi.today["{#SERVICE}","{#METRIC}"]']['master_item']['key'] ?? null;
	check('kpi today from ClickHouse', $totals_master, 'apm.kpi.totals.get');
	check('kpi discovery rules', array_keys($rules), ['apm.kpi.discovery', 'apm.kpi.dimension.discovery']);
	check('kpi per-value item keys', array_column($rules['apm.kpi.dimension.discovery']['item_prototypes'], 'key')[0],
		'apm.kpi.value["{#SERVICE}","{#METRIC}","{#DIM}","{#DIMVALUE}"]'
	);
	check('kpi items tagged for the assistant', in_array(['tag' => 'component', 'value' => 'kpi'],
		$rules['apm.kpi.discovery']['item_prototypes'][0]['tags'], true
	), true);

	foreach ([BusinessMetrics::totalsSql(' AND x=1', ''), BusinessMetrics::seriesSql(' AND x=1', '')] as $i => $sql) {
		check('kpi sql '.$i.' has the access condition in the three tables', substr_count($sql, ' AND x=1'), 3);
		check('kpi sql '.$i.' placeholders replaced', preg_match('/\{(access|points|service)\}/', $sql), 0);
	}

	$kpi_rows = [
		['service' => 'shop', 'metric' => 'shop.orders', 'kind' => 'counter', 'unit' => '', 'description' => 'Orders',
			'dim_key' => '', 'dim_value' => '', 'value' => '12', 'sum' => '0', 'last' => '100'],
		['service' => 'shop', 'metric' => 'shop.orders', 'kind' => 'counter', 'unit' => '', 'description' => 'Orders',
			'dim_key' => 'shop.store', 'dim_value' => 'Centro', 'value' => '8', 'sum' => '0', 'last' => '100'],
		['service' => 'shop', 'metric' => 'shop.orders', 'kind' => 'counter', 'unit' => '', 'description' => 'Orders',
			'dim_key' => 'shop.store', 'dim_value' => 'Sur', 'value' => '4', 'sum' => '0', 'last' => '100'],
		// A value whose metric total is not in the rows (cut by the limit) is ignored.
		['service' => 'shop', 'metric' => 'other', 'kind' => 'gauge', 'unit' => '', 'description' => '',
			'dim_key' => 'k', 'dim_value' => 'v', 'value' => '1', 'sum' => '0', 'last' => '100']
	];
	$kpi_totals = BusinessMetrics::groupTotals($kpi_rows);
	check('kpi totals grouped', array_keys($kpi_totals), [BusinessMetrics::key('shop', 'shop.orders', 'counter')]);
	check('kpi breakdown', reset($kpi_totals)['dims'], ['shop.store' => [
		['value' => 'Centro', 'total' => 8.0], ['value' => 'Sur', 'total' => 4.0]
	]]);
	check('kpi series: counters 0 when quiet, gauges keep their value', BusinessMetrics::groupSeries([
		['service' => 's', 'metric' => 'c', 'kind' => 'counter', 'bucket' => '60', 'value' => '3'],
		['service' => 's', 'metric' => 'g', 'kind' => 'gauge', 'bucket' => '60', 'value' => '7']
	], [0, 60, 120]), [
		BusinessMetrics::key('s', 'c', 'counter') => [0.0, 3.0, 0.0],
		BusinessMetrics::key('s', 'g', 'gauge') => [0.0, 7.0, 7.0]
	]);
	check('kpi format', array_map(static fn(float $v): string => BusinessMetrics::format($v, 'USD'),
		[12.0, 12.345, 1234.5, 0.00134, 2500000.0]
	), ['12 USD', '12.35 USD', '1 235 USD', '0.00134 USD', '2.50 M USD']);

	// Translations: Spanish by catalog, English as is.
	I18n::setLanguage('es');
	check('es', I18n::t('Service map'), 'Mapa de servicios');
	check('es missing stays English', I18n::t('No such text'), 'No such text');
	I18n::setLanguage('en');
	check('en', I18n::t('Service map'), 'Service map');

	// Every text the code translates has a Spanish entry (per module catalog).
	foreach (['ApmOverview' => ['ApmOverview', 'ApmServiceMap'], 'ApmServiceHealth' => ['ApmServiceHealth']] as $owner => $dirs) {
		$catalog = require $root.'/'.$owner.'/lang/es.php';

		foreach ($dirs as $dir) {
			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir));

			foreach ($files as $file) {
				if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR)) {
					continue;
				}

				preg_match_all("/I18n::t\\('((?:[^'\\\\]|\\\\.)*)'\\)/", file_get_contents($file->getPathname()), $m);

				foreach ($m[1] as $text) {
					$text = stripslashes($text);
					check('es entry for "'.$text.'" ('.$dir.')', array_key_exists($text, $catalog), true);
				}
			}
		}
	}

	echo $failures === 0 ? "OK\n" : "$failures failure(s)\n";
	exit($failures === 0 ? 0 : 1);
}

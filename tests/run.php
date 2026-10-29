<?php declare(strict_types = 0);

/*
 * Checks that run without Zabbix (php tests/run.php): service patterns, service map graph and layers, translations.
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
	require $root.'/ApmOverview/includes/ServiceMap.php';
	require $root.'/ApmOverview/includes/Integration.php';

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
			str_contains(implode("\n", array_column($method['steps'], 'code')), "span.setAttribute('session.id', sessionId)")
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

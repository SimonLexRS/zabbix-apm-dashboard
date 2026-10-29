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
	require $root.'/ApmOverview/includes/ApmStats.php';
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

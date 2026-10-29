<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

/**
 * Pure helpers of the Real user monitoring page: what a browser, a load waterfall and a session timeline are, from the
 * spans of the OpenTelemetry web SDK. No Zabbix or ClickHouse here, so they are tested by tests/run.php.
 */
class Rum {

	/**
	 * Phases of a page load: [key, start event, end event]. The navigation timing events of the documentLoad span
	 * and of its documentFetch child, as offsets from the start of the load.
	 */
	public const PHASES = [
		['redirect', 'redirectStart', 'redirectEnd'],
		['dns', 'domainLookupStart', 'domainLookupEnd'],
		['connection', 'connectStart', 'secureConnectionStart'],
		['tls', 'secureConnectionStart', 'connectEnd'],
		['request', 'requestStart', 'responseStart'],
		['response', 'responseStart', 'responseEnd'],
		['dom', 'responseEnd', 'domInteractive'],
		['resources', 'domInteractive', 'domComplete'],
		['load_event', 'loadEventStart', 'loadEventEnd']
	];

	/**
	 * Events marked as a point on the waterfall.
	 */
	public const MARKERS = ['firstPaint', 'firstContentfulPaint'];

	/**
	 * Browser, operating system and kind of device of a user agent string; "Other" when not recognised.
	 *
	 * @return array  ['browser' => string, 'os' => string, 'device' => 'desktop'|'mobile'|'tablet']
	 */
	public static function userAgent(string $ua): array {
		// Order matters: Edge and Opera also say "Chrome", Chrome also says "Safari", iPad says "Mac OS X".
		$browsers = [
			'Edge' => '/\bEdg(e|A|iOS)?\//',
			'Opera' => '/\b(OPR|Opera)\//',
			'Chrome (headless)' => '/\bHeadlessChrome\//',
			'Chrome' => '/\b(Chrome|CriOS)\//',
			'Firefox' => '/\b(Firefox|FxiOS)\//',
			'Safari' => '/\bVersion\/[\d.]+.*\bSafari\//'
		];
		$systems = [
			'Windows' => '/\bWindows\b/',
			'iOS' => '/\b(iPhone|iPad|iPod)\b/',
			'Android' => '/\bAndroid\b/',
			'ChromeOS' => '/\bCrOS\b/',
			'macOS' => '/\b(Macintosh|Mac OS X)\b/',
			'Linux' => '/\b(Linux|X11)\b/'
		];

		$match = static function (array $rules) use ($ua): string {
			foreach ($rules as $name => $pattern) {
				if (preg_match($pattern, $ua)) {
					return $name;
				}
			}

			return 'Other';
		};

		// "Mobi" (or an iPhone) means a phone; an iPad, or Android without "Mobile", a tablet.
		$device = preg_match('/\b(Mobi|iPhone|iPod)/', $ua)
			? 'mobile'
			: (preg_match('/\b(iPad|Tablet|Android)\b/', $ua) ? 'tablet' : 'desktop');

		return ['browser' => $match($browsers), 'os' => $match($systems), 'device' => $device];
	}

	/**
	 * Phases of a page load that have both events, in order, and the markers.
	 *
	 * @param array $events  [event name => ms from the start of the load]
	 *
	 * @return array  ['phases' => [['key', 'start', 'end'], ...], 'markers' => [name => ms], 'total' => ms]
	 */
	public static function loadPhases(array $events): array {
		// Without TLS, the connection ends at connectEnd; without the network events, DOM processing starts at once.
		if (!array_key_exists('secureConnectionStart', $events) || $events['secureConnectionStart'] <= 0) {
			unset($events['secureConnectionStart']);
			$phases = array_map(
				static fn(array $p): array => $p[0] === 'connection' ? ['connection', 'connectStart', 'connectEnd'] : $p,
				self::PHASES
			);
		}
		else {
			$phases = self::PHASES;
		}

		$events += ['responseEnd' => $events['fetchStart'] ?? null];
		$result = ['phases' => [], 'markers' => [], 'total' => 0.0];

		foreach ($phases as [$key, $from, $to]) {
			$start = $events[$from] ?? null;
			$end = $events[$to] ?? null;

			if ($start === null || $end === null || $end < $start) {
				continue;
			}

			$result['phases'][] = ['key' => $key, 'start' => (float) $start, 'end' => (float) $end];
			$result['total'] = max($result['total'], (float) $end);
		}

		foreach (self::MARKERS as $marker) {
			if (array_key_exists($marker, $events)) {
				$result['markers'][$marker] = (float) $events[$marker];
			}
		}

		$result['total'] = max([$result['total'], ...array_values($result['markers']), (float) ($events['loadEventEnd'] ?? 0)]);

		return $result;
	}

	/**
	 * Timeline of a session: page loads, user actions and requests in time order. A request made by an action (same
	 * trace) is placed under it; the others stand on their own.
	 *
	 * @param array $spans  Rows with 'type' (view, action, request), 'trace', 'start' (ns) and any other fields.
	 *
	 * @return array  The rows, with 'offset_ms' from the first one and 'requests' under each action.
	 */
	public static function timeline(array $spans): array {
		// In time order; at the same instant, the page load, then the action, then the request it made.
		$rank = ['view' => 0, 'action' => 1, 'request' => 2];
		usort($spans, static fn(array $a, array $b): int =>
			[$a['start'], $rank[$a['type']] ?? 3] <=> [$b['start'], $rank[$b['type']] ?? 3]
		);

		if (!$spans) {
			return [];
		}

		$first = $spans[0]['start'];
		$actions = [];
		$items = [];

		foreach ($spans as $span) {
			$span['offset_ms'] = ($span['start'] - $first) / 1e6;

			if ($span['type'] === 'action') {
				$span['requests'] = [];
				$items[] = $span;
				$actions[$span['trace']] = array_key_last($items);
			}
			elseif ($span['type'] === 'request' && array_key_exists($span['trace'], $actions)) {
				$items[$actions[$span['trace']]]['requests'][] = $span;
			}
			else {
				$items[] = $span;
			}
		}

		return $items;
	}
}

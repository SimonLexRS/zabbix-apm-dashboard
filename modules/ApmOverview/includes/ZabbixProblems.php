<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

use API;

/**
 * Open Zabbix problems of the services of an application, read with the permissions of the current user.
 *
 * A problem belongs to a service when it carries the tag service = <service name> (on the trigger, the item, the
 * template or the host), the same rule that links a monitor to a service elsewhere. The problems of the Zabbix host of
 * the application (apm-<namespace>) and of the hosts a service runs on (host.name) are listed apart: they say something
 * about the service, they are not necessarily its own.
 */
class ZabbixProblems {

	public const TAG = 'service';
	public const LIMIT = 500;

	private const TAG_OPERATOR_EQUAL = 1;
	private const TAG_EVAL_TYPE_OR = 2;

	/**
	 * @param string[] $services  Service names.
	 *
	 * @return array  [service => ['count' => int, 'severity' => int, 'worst' => string, 'problems' => [...]]], only
	 *                the services with problems; each problem ['eventid', 'name', 'severity', 'clock'].
	 */
	public static function forServices(array $services): array {
		$services = array_values(array_unique(array_filter($services, 'strlen')));

		if (!$services) {
			return [];
		}

		$problems = API::Problem()->get([
			'output' => ['eventid', 'objectid', 'name', 'severity', 'clock'],
			'selectTags' => ['tag', 'value'],
			'evaltype' => self::TAG_EVAL_TYPE_OR,
			'tags' => array_map(static fn(string $service): array => [
				'tag' => self::TAG, 'value' => $service, 'operator' => self::TAG_OPERATOR_EQUAL
			], $services),
			'suppressed' => false,
			'sortfield' => ['eventid'],
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => self::LIMIT
		]);

		return self::group($problems ?: [], $services);
	}

	/**
	 * Problems per service, from their "service" tags.
	 *
	 * @param array $problems  Rows of problem.get with 'tags'.
	 */
	public static function group(array $problems, array $services): array {
		$known = array_flip($services);
		$result = [];

		foreach ($problems as $problem) {
			foreach ($problem['tags'] ?? [] as $tag) {
				if ($tag['tag'] !== self::TAG || !array_key_exists($tag['value'], $known)) {
					continue;
				}

				$result[$tag['value']]['problems'][$problem['eventid']] = [
					'eventid' => (string) $problem['eventid'],
					'triggerid' => (string) ($problem['objectid'] ?? 0),
					'name' => (string) $problem['name'],
					'severity' => (int) $problem['severity'],
					'clock' => (int) $problem['clock']
				];
			}
		}

		foreach ($result as &$service) {
			$service = self::summarize(array_values($service['problems']));
		}
		unset($service);

		return $result;
	}

	/**
	 * Problems of some hosts, by host ID.
	 *
	 * @return array  Same shape as one entry of forServices().
	 */
	public static function forHosts(array $hostids): array {
		if (!$hostids) {
			return self::summarize([]);
		}

		$problems = API::Problem()->get([
			'output' => ['eventid', 'objectid', 'name', 'severity', 'clock'],
			'hostids' => array_values($hostids),
			'suppressed' => false,
			'sortfield' => ['eventid'],
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => self::LIMIT
		]);

		return self::summarize(array_map(static fn(array $problem): array => [
			'eventid' => (string) $problem['eventid'],
			'triggerid' => (string) ($problem['objectid'] ?? 0),
			'name' => (string) $problem['name'],
			'severity' => (int) $problem['severity'],
			'clock' => (int) $problem['clock']
		], $problems ?: []));
	}

	/**
	 * Zabbix hosts named as the hosts a service runs on (host.name), by technical or visible name.
	 *
	 * @return array  [['hostid', 'name'], ...]
	 */
	public static function hostsNamed(array $names): array {
		$names = array_values(array_unique(array_filter($names, 'strlen')));

		if (!$names) {
			return [];
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'name'],
			'filter' => ['host' => $names],
			'preservekeys' => true
		]) + API::Host()->get([
			'output' => ['hostid', 'name'],
			'filter' => ['name' => $names],
			'preservekeys' => true
		]);

		return array_values($hosts);
	}

	/**
	 * @param array $problems  [['eventid', 'name', 'severity', 'clock'], ...]
	 */
	public static function summarize(array $problems): array {
		usort($problems, static fn(array $a, array $b): int => [$b['severity'], $b['clock']] <=> [$a['severity'], $a['clock']]);

		return [
			'count' => count($problems),
			'severity' => $problems ? $problems[0]['severity'] : -1,
			'worst' => $problems ? $problems[0]['name'] : '',
			'problems' => $problems
		];
	}
}

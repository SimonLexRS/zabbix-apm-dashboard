<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

use CRoleHelper,
	CWebUser;

/**
 * APM filter of the user's groups (Users → User groups → APM filter), applied the way the native APM pages apply it:
 * Super admins see everything; other users see the telemetry whose resource attributes match any filter of their
 * groups for that signal, and everything if their groups have none.
 */
class ApmAccess {

	public const TRACES = 0;
	public const METRICS = 1;
	public const LOGS = 2;

	private const ALL_SIGNALS = -1;

	private static array $filters = [];

	/**
	 * Signals whose UI element the user's role allows (APM → Traces, Logs, Metrics), as in the native menu.
	 *
	 * @return string[]  Subset of 'traces', 'logs', 'metrics'.
	 */
	public static function signals(): array {
		return array_keys(array_filter([
			'traces' => CWebUser::checkAccess(CRoleHelper::UI_APM_TRACES),
			'logs' => CWebUser::checkAccess(CRoleHelper::UI_APM_LOGS),
			'metrics' => CWebUser::checkAccess(CRoleHelper::UI_APM_METRICS)
		]));
	}

	/**
	 * @return array  [['key' => string, 'value' => string], ...]
	 */
	public static function filters(int $signal): array {
		if (CWebUser::$data['type'] == USER_TYPE_SUPER_ADMIN) {
			return [];
		}

		if (!array_key_exists($signal, self::$filters)) {
			self::$filters[$signal] = [];

			$result = DBselect(
				'SELECT DISTINCT uaf.attribute_key,uaf.value'.
				' FROM users_groups ug'.
				' JOIN usrgrp_apm_filter uaf ON ug.usrgrpid=uaf.usrgrpid'.
				' WHERE ug.userid='.zbx_dbstr(CWebUser::$data['userid']).
					' AND '.dbConditionInt('uaf.signal_type', [self::ALL_SIGNALS, $signal])
			);

			while ($row = DBfetch($result)) {
				self::$filters[$signal][] = ['key' => $row['attribute_key'], 'value' => $row['value']];
			}
		}

		return self::$filters[$signal];
	}

	/**
	 * SQL condition and parameters for the filters: any key matches one of its values. Empty condition without filters.
	 *
	 * @return array  [string $sql, array $params]
	 */
	public static function condition(array $filters, string $prefix, string $field = 'ResourceAttributes'): array {
		$values = [];

		foreach ($filters as $filter) {
			$values[$filter['key']][] = $filter['value'];
		}

		$or = [];
		$params = [];

		foreach (array_keys($values) as $index => $key) {
			$key_param = $prefix.'_k'.$index;
			$values_param = $prefix.'_v'.$index;
			$or[] = '(mapContains('.$field.',{'.$key_param.':String})'.
				' AND '.$field.'[{'.$key_param.':String}] IN {'.$values_param.':Array(String)})';
			$params[$key_param] = ['String', (string) $key];
			$params[$values_param] = ['String', array_values(array_unique($values[$key]))];
		}

		return [$or ? '('.implode(' OR ', $or).')' : '', $params];
	}
}

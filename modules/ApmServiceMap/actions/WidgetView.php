<?php declare(strict_types = 0);

namespace Modules\ApmServiceMap\Actions;

use CControllerDashboardWidgetView,
	CControllerResponseData,
	CRoleHelper,
	CWebUser,
	DBException;

use Modules\ApmServiceMap\Includes\Lang as I18n;
use Modules\ApmOverview\Includes\{
	ApmStats,
	ServiceMap
};

class WidgetView extends CControllerDashboardWidgetView {

	protected function init(): void {
		parent::init();

		$this->addValidationRules([
			'has_custom_time_period' => 'in 1'
		]);
	}

	protected function doAction(): void {
		$data = [
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'graph' => null,
			'ctx' => [
				'from' => $this->fields_values['time_period']['from'],
				'to' => $this->fields_values['time_period']['to']
			],
			'thresholds' => [
				'latency_warning' => $this->fields_values['latency_warning'] * 1000000,
				'latency_high' => $this->fields_values['latency_high'] * 1000000,
				'error_warning' => (float) $this->fields_values['error_warning'],
				'error_high' => (float) $this->fields_values['error_high']
			],
			'error' => null,
			'info' => $this->makeWidgetInfo(),
			'user' => [
				'debug_mode' => $this->getDebugMode()
			]
		];

		if ($this->isTemplateDashboard()) {
			$data['error'] = _('No data found');
		}
		elseif (!CWebUser::checkAccess(CRoleHelper::UI_APM_TRACES)) {
			$data['error'] = _('No permissions to referred object or it does not exist!');
		}
		elseif (!class_exists(ServiceMap::class)) {
			$data['error'] = 'This widget needs the APM Overview module enabled (Administration → General → Modules).';
		}
		else {
			$time_from = $this->fields_values['time_period']['from_ts'];
			$time_till = $this->fields_values['time_period']['to_ts'];
			$service = $this->fields_values['service'];

			try {
				$stats = new ApmStats();
				[$call_from, $call_till] = ApmStats::joinWindow($time_from, $time_till);

				$data['graph'] = ServiceMap::build(
					$stats->getServiceStats($time_from, $time_till, $service),
					$stats->getServiceCalls($time_from, $time_till, $service),
					max(1, ($call_till - $call_from) / SEC_PER_MIN)
				);
			}
			catch (DBException $e) {
				$data['error'] = I18n::t('The APM data source is not available: ').$e->getMessage();
			}
		}

		$this->setResponse(new CControllerResponseData($data));
	}

	private function makeWidgetInfo(): array {
		$info = [];

		if ($this->hasInput('has_custom_time_period')) {
			$info[] = [
				'icon' => ZBX_ICON_TIME_PERIOD,
				'hint' => relativeDateToText($this->fields_values['time_period']['from'],
					$this->fields_values['time_period']['to']
				)
			];
		}

		return $info;
	}
}

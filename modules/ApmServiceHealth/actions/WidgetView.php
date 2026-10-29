<?php declare(strict_types = 0);

namespace Modules\ApmServiceHealth\Actions;

use CControllerDashboardWidgetView,
	CControllerResponseData,
	CRoleHelper,
	CWebUser,
	DBException;

use Modules\ApmServiceHealth\Includes\I18n;
use Modules\ApmServiceHealth\Includes\ServiceStats;

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
			'fields_values' => $this->fields_values,
			'services' => [],
			'series' => [],
			'error' => null,
			'info' => $this->makeWidgetInfo(),
			'user' => [
				'debug_mode' => $this->getDebugMode()
			]
		];

		if ($this->isTemplateDashboard()) {
			$data['error'] = I18n::t('No data found');
		}
		elseif (!CWebUser::checkAccess(CRoleHelper::UI_APM_TRACES)) {
			$data['error'] = I18n::t('No permissions to referred object or it does not exist!');
		}
		else {
			try {
				$data['services'] = ServiceStats::get(
					$this->fields_values['time_period']['from_ts'],
					$this->fields_values['time_period']['to_ts'],
					$this->fields_values['service'],
					$this->fields_values['sort_by'],
					$this->fields_values['show_lines']
				);

				$data['series'] = ServiceStats::getRequestSeries(
					$this->fields_values['time_period']['from_ts'],
					$this->fields_values['time_period']['to_ts'],
					array_column($data['services'], 'service')
				);
			}
			catch (DBException $e) {
				$data['error'] = I18n::t('APM data source is not available').': '.$e->getMessage();
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

<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Actions;

use CController,
	CControllerResponseData,
	CRoleHelper;

use Modules\ApmOverview\Includes\{
	ApmAccess,
	I18n,
	OverviewLoader,
	TraceHtml,
	TraceStats
};

/**
 * One trace for the trace panel (JSON with rendered HTML): spans, where it came from, services and logs. Opened from
 * the trace links of the APM overview, Real user monitoring and the widgets.
 */
class TracePanel extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'traceid' => 'required|not_empty|string',
			'clock' => 'required|int32'
		]) && preg_match('/^[0-9a-fA-F]{1,64}$/', $this->getInput('traceid'));

		if (!$ret) {
			$this->setResponse(
				(new CControllerResponseData(['main_block' => json_encode([
					'error' => I18n::t('Invalid request.')
				])]))->disableView()
			);
		}

		return (bool) $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_APM_TRACES);
	}

	protected function doAction(): void {
		$traceid = $this->getInput('traceid');
		$clock = (int) $this->getInput('clock');
		$data = ['traceid' => $traceid, 'clock' => $clock, 'error' => null, 'trace' => null, 'origin' => null,
			'logs' => null, 'host' => null
		];

		if (OverviewLoader::getSourceInfo()['origin'] === null) {
			$data['error'] = I18n::t('The APM data source is not configured.');
		}
		else {
			try {
				// Every application of the trace the user may see, as in the native trace view.
				$stats = new TraceStats();
				$data['trace'] = $stats->getTrace($traceid, $clock);

				if ($data['trace']['spans']) {
					$moment = intdiv($data['trace']['start'], 1000000000);
					$data['origin'] = $stats->getOrigin($traceid, $moment);

					if (in_array('logs', ApmAccess::signals(), true)) {
						$data['logs'] = $stats->getLogs($traceid, $moment);
					}

					$application = $stats->getApplicationOf($traceid, $moment);

					if ($application !== '') {
						$data['host'] = OverviewLoader::findHost($application);
					}
				}
			}
			catch (\DBException $e) {
				$data['error'] = $e->getMessage();
			}
		}

		$this->setResponse(
			(new CControllerResponseData(['main_block' => json_encode([
				'error' => $data['error'],
				'title' => I18n::t('Trace'),
				'html' => TraceHtml::panel($data)->toString()
			], JSON_INVALID_UTF8_SUBSTITUTE)]))->disableView()
		);
	}
}

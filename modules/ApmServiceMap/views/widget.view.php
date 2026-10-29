<?php declare(strict_types = 0);

/**
 * APM service map widget view.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\ApmOverview\Includes\ServiceMap;

$view = new CWidgetView($data);

if ($data['error'] !== null) {
	$view->addItem((new CTableInfo())->setNoDataMessage($data['error']));
}
else {
	$view->addItem(ServiceMap::render($data['graph'], $data['ctx'], $data['thresholds']));
}

$view
	->setVar('info', $data['info'])
	->show();

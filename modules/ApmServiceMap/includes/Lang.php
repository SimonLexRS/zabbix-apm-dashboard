<?php declare(strict_types = 0);

namespace Modules\ApmServiceMap\Includes;

use Modules\ApmOverview\Includes\I18n;

/**
 * Texts of the widget, translated by the APM Overview module when it is enabled (the widget needs it anyway); English
 * otherwise, so the widget form still opens and explains what is missing.
 */
class Lang {

	public static function t(string $text): string {
		return class_exists(I18n::class) ? I18n::t($text) : $text;
	}
}

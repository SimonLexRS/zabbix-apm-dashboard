<?php declare(strict_types = 0);

namespace Modules\ApmServiceHealth\Includes;

use CWebUser;

/**
 * Translations of the widget (it works without the other modules, so it has its own catalog): English texts looked up
 * in lang/<language>.php by the language of the user. Missing entries stay in English.
 */
class I18n {

	private static array $catalogs = [];

	public static function t(string $text): string {
		$lang = class_exists(CWebUser::class, false) ? CWebUser::getLang() : 'en';

		if (!array_key_exists($lang, self::$catalogs)) {
			$file = __DIR__.'/../lang/'.basename($lang).'.php';
			self::$catalogs[$lang] = is_file($file) ? require $file : [];
		}

		return self::$catalogs[$lang][$text] ?? $text;
	}
}

<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

use CWebUser;

/**
 * Translations of the module. Zabbix modules have no gettext domain of their own, so the texts are written in English
 * and looked up in lang/<language>.php by the language of the user (Spanish for es_*). Missing entries stay in English.
 */
class I18n {

	private static ?string $lang = null;
	private static array $catalogs = [];

	public static function t(string $text): string {
		$lang = self::$lang ?? (class_exists(CWebUser::class, false) ? CWebUser::getLang() : 'en');

		if (!array_key_exists($lang, self::$catalogs)) {
			$file = __DIR__.'/../lang/'.basename($lang).'.php';
			self::$catalogs[$lang] = is_file($file) ? require $file : [];
		}

		return self::$catalogs[$lang][$text] ?? $text;
	}

	/**
	 * Force a language (tests); null goes back to the user's language.
	 */
	public static function setLanguage(?string $lang): void {
		self::$lang = $lang;
	}
}

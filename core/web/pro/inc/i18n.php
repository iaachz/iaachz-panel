<?php
// iaachz-panel: traducciones. El texto fuente está en español y hace de clave: __("Guardar") devuelve
// la traducción de web/pro/lang/<idioma>.json o, si falta, el propio texto en español.
// Las mismas listas las usan PHP (__), JavaScript (HP.t) y Python (hp.i18n.tr).
// Este archivo es independiente de pro.php porque también lo cargan panel.php y custom_scripts en páginas de Hestia.

const HP_LANG_DEFAULT = "es";
const HP_LANG_DIR = __DIR__ . "/../lang";

// Idiomas conocidos (nombre en su propio idioma). Solo se ofrecen los que tienen archivo (es es el idioma fuente).
const HP_LANG_NAMES = [
	"es" => "Español",
	"en" => "English",
	"pt" => "Português",
	"fr" => "Français",
	"de" => "Deutsch",
	"it" => "Italiano",
];

function hp_languages(): array {
	$out = [];
	foreach (HP_LANG_NAMES as $code => $name) {
		if ($code === HP_LANG_DEFAULT || is_file(HP_LANG_DIR . "/$code.json")) {
			$out[$code] = $name;
		}
	}
	return $out;
}

// "pt-br", "PT_BR" -> "pt"; devuelve "" si no hay traducción para ese idioma.
function hp_lang_normalize(string $code): string {
	$code = strtolower(substr(preg_split("/[-_]/", trim($code))[0], 0, 5));
	return isset(hp_languages()[$code]) ? $code : "";
}

// Ajuste de idioma del servidor ("auto" = el idioma del perfil de cada usuario de Hestia).
// Se guarda 60 s en la sesión para no lanzar v-hp en cada página.
function hp_lang_setting(): string {
	$c = $_SESSION["hp_lang_cfg"] ?? null;
	if (is_array($c) && time() - ($c[1] ?? 0) < 60) {
		return (string) $c[0];
	}
	$val = HP_LANG_DEFAULT;
	if (defined("HESTIA_CMD") && function_exists("exec")) {
		exec(HESTIA_CMD . "v-hp settings-get 2>/dev/null", $o, $rc);
		$j = $rc === 0 ? json_decode(implode("\n", $o), true) : null;
		if (is_array($j) && isset($j["language"])) {
			$val = (string) $j["language"];
		}
	}
	$_SESSION["hp_lang_cfg"] = [$val, time()];
	return $val;
}

function hp_lang_forget(): void {
	unset($_SESSION["hp_lang_cfg"]);
}

function hp_lang(): string {
	static $lang = null;
	if ($lang === null) {
		$cfg = hp_lang_setting();
		$lang = $cfg === "auto" ? hp_lang_normalize((string) ($_SESSION["language"] ?? "")) : hp_lang_normalize($cfg);
		$lang = $lang ?: HP_LANG_DEFAULT;
	}
	return $lang;
}

function hp_dict(string $lang): array {
	static $cache = [];
	if (!isset($cache[$lang])) {
		$f = HP_LANG_DIR . "/" . preg_replace("/[^a-z]/", "", $lang) . ".json";
		$cache[$lang] = $lang !== HP_LANG_DEFAULT && is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
	}
	return $cache[$lang];
}

// Traduce un texto fuente; con argumentos sustituye %s / %d (sprintf).
function __(string $s, ...$args): string {
	$r = hp_dict(hp_lang())[$s] ?? $s;
	return $args ? vsprintf($r, $args) : $r;
}

// Traduce con htmlspecialchars.
function __e(string $s, ...$args): string {
	return htmlspecialchars(__($s, ...$args), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

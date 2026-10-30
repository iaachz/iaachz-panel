<?php
// Agregar subdominio (personalizacion; se reaplica con /opt/hestia-subdominio/aplicar.sh)
use function Hestiacp\quoteshellarg\quoteshellarg;

ob_start();
$TAB = "WEB";

// Main include
include $_SERVER["DOCUMENT_ROOT"] . "/inc/main.php";

$sd_es = function_exists("detect_user_language")
	? detect_user_language() === "es"
	: ($_SESSION["LANGUAGE"] ?? "") === "es";
function sd_t($en, $es_txt) {
	global $sd_es;
	return $sd_es ? $es_txt : $en;
}

// Zona DNS (la mas especifica) a la que pertenece un dominio, o null
function sd_zone_for($domain, $zones) {
	$best = null;
	foreach ($zones as $z) {
		if ($domain === $z || substr($domain, -strlen($z) - 1) === "." . $z) {
			if ($best === null || strlen($z) > strlen($best)) {
				$best = $z;
			}
		}
	}
	return $best;
}

function sd_json($cmd) {
	exec(HESTIA_CMD . $cmd, $out, $rc);
	$data = json_decode(implode("", $out), true);
	return is_array($data) ? $data : [];
}

// Datos del usuario
$ips = sd_json("v-list-user-ips " . $user . " json");
$web_domains = array_keys(sd_json("v-list-web-domains " . $user . " json"));
$zones = array_keys(sd_json("v-list-dns-domains " . $user . " json"));

// Dominios padre: zonas DNS + dominios web que no pertenecen a ninguna zona
$parents = $zones;
foreach ($web_domains as $wd) {
	if (sd_zone_for($wd, $zones) === null) {
		$parents[] = $wd;
	}
}
sort($parents);

$v_sub = "";
$v_parent = $_POST["v_parent"] ?? ($_GET["parent"] ?? "");

if (!empty($_POST["ok"]) && $read_only !== true) {
	verify_csrf($_POST);

	$v_sub = strtolower(trim($_POST["v_sub"] ?? "", " ."));
	$v_sub = preg_replace("/^www\./", "", $v_sub);
	$v_ip_raw = $_POST["v_ip"] ?? "";

	if ($v_sub === "") {
		$_SESSION["error_msg"] = sprintf(_('Field "%s" can not be blank.'), sd_t("Subdomain", "Subdominio"));
	} elseif (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $v_sub)) {
		$_SESSION["error_msg"] = sd_t("Invalid subdomain name.", "Nombre de subdominio no valido.");
	} elseif (!in_array($v_parent, $parents, true)) {
		$_SESSION["error_msg"] = sd_t("Invalid parent domain.", "Dominio padre no valido.");
	} elseif (!isset($ips[$v_ip_raw])) {
		$_SESSION["error_msg"] = sprintf(_('Field "%s" can not be blank.'), _("IP Address"));
	}

	$v_full = $v_sub . "." . $v_parent;
	if (empty($_SESSION["error_msg"]) && in_array($v_full, $web_domains, true)) {
		$_SESSION["error_msg"] = sprintf(
			sd_t("The domain %s already exists.", "El dominio %s ya existe."),
			htmlentities($v_full),
		);
	}

	if (empty($_SESSION["error_msg"])) {
		$v_ip = quoteshellarg($v_ip_raw);
		$public_ip = $v_ip_raw;
		if (isset($ips[$v_ip_raw]["NAT"]) && trim($ips[$v_ip_raw]["NAT"]) != "") {
			$public_ip = trim($ips[$v_ip_raw]["NAT"]);
		}

		// 1) Dominio web
		exec(
			HESTIA_CMD . "v-add-web-domain " . $user . " " . quoteshellarg($v_full) . " " . $v_ip . " 'yes'",
			$output,
			$return_var,
		);
		check_return_code($return_var, $output);
		unset($output);
		$notes = [];

		// 2) Registros DNS dentro de la zona del dominio padre (sin crear zona aparte)
		$zone = sd_zone_for($v_full, $zones);
		if (empty($_SESSION["error_msg"]) && !empty($_POST["v_dns"]) && $zone !== null) {
			$rec = substr($v_full, 0, -strlen($zone) - 1);
			$existing = [];
			foreach (sd_json("v-list-dns-records " . $user . " " . quoteshellarg($zone) . " json") as $r) {
				$existing[$r["RECORD"]] = true;
			}
			if (empty($existing[$rec])) {
				exec(
					HESTIA_CMD . "v-add-dns-record " . $user . " " . quoteshellarg($zone) . " " . quoteshellarg($rec) . " A " . quoteshellarg($public_ip),
					$output,
					$return_var,
				);
				check_return_code($return_var, $output);
				unset($output);
			} else {
				$notes[] = sd_t("DNS record already existed.", "El registro DNS ya existia.");
			}
			if (empty($_SESSION["error_msg"]) && empty($existing["www." . $rec])) {
				exec(
					HESTIA_CMD . "v-add-dns-record " . $user . " " . quoteshellarg($zone) . " " . quoteshellarg("www." . $rec) . " CNAME " . quoteshellarg($v_full . "."),
					$output,
					$return_var,
				);
				unset($output);
			}
		} elseif (empty($_SESSION["error_msg"]) && !empty($_POST["v_dns"]) && $zone === null) {
			$notes[] = sd_t("No DNS zone for this domain; record not created.", "No hay zona DNS para este dominio; no se creo registro.");
		}

		// 3) SSL Let's Encrypt (no bloqueante)
		if (empty($_SESSION["error_msg"]) && !empty($_POST["v_ssl"])) {
			exec(
				HESTIA_CMD . "v-add-letsencrypt-domain " . $user . " " . quoteshellarg($v_full) . " " . quoteshellarg("www." . $v_full),
				$output,
				$return_var,
			);
			if ($return_var != 0) {
				$notes[] = sd_t("SSL could not be issued yet (check DNS and try again from the domain's edit page): ", "No se pudo emitir el SSL aun (revisa el DNS y reintenta desde la edicion del dominio): ") . htmlentities(implode(" ", $output));
			}
			unset($output);
		}

		if (empty($_SESSION["error_msg"])) {
			$msg = sprintf(
				sd_t("Subdomain %s has been created successfully.", "El subdominio %s se creo correctamente."),
				'<a href="/edit/web/?domain=' . htmlentities($v_full) . '">' . htmlentities($v_full) . "</a>",
			);
			if ($notes) {
				$msg .= "<br>" . implode("<br>", $notes);
			}
			$_SESSION["ok_msg"] = $msg;
			$v_sub = "";
		}
	}
}

// Render page
render_page($user, $TAB, "add_subdomain");

// Flush session messages
unset($_SESSION["error_msg"]);
unset($_SESSION["ok_msg"]);

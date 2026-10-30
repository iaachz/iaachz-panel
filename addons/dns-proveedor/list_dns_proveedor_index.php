<?php
use function Hestiacp\quoteshellarg\quoteshellarg;
$TAB = "DNS";

// Main include
include $_SERVER["DOCUMENT_ROOT"] . "/inc/main.php";
include $_SERVER["DOCUMENT_ROOT"] . "/inc/dns_proveedor.php";

if (empty($_GET["domain"])) {
	header("Location: /list/dns/");
	exit();
}

$v_domain = $_GET["domain"];
$v_domain_q = quoteshellarg($v_domain);

// Crear una zona DNS editable a partir del sitio web (IP, NS elegidos, alias y correo).
if (!empty($_POST["crear_zona"])) {
	verify_csrf($_POST);
	$ns = [];
	foreach ([$_POST["ns1"] ?? "", $_POST["ns2"] ?? ""] as $n) {
		$n = strtolower(trim(rtrim(trim($n), ".")));
		if (!preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $n)) {
			$_SESSION["error_msg"] = "Servidor de nombres no válido: " . $n;
			header("Location: /list/dns-proveedor/?" . http_build_query(["domain" => $v_domain]));
			exit();
		}
		$ns[] = $n;
	}

	exec(HESTIA_CMD . "v-list-web-domain " . $user . " " . $v_domain_q . " 'json'", $output, $return_var);
	$web = $return_var == 0 ? json_decode(implode("", $output), true)[$v_domain] : null;
	unset($output);
	if ($web === null) {
		header("Location: /list/web/");
		exit();
	}

	exec(
		HESTIA_CMD . "v-add-dns-domain " . $user . " " . $v_domain_q . " " . quoteshellarg($web["IP"]) . " " .
			quoteshellarg($ns[0]) . " " . quoteshellarg($ns[1]) . " '' '' '' '' '' '' 'no'",
		$output,
		$return_var,
	);
	check_return_code($return_var, $output);
	unset($output);

	if ($return_var == 0) {
		// Correo: si el sitio no tiene, se usa la plantilla sin MX/SPF; si tiene, se publica su DKIM actual.
		exec(HESTIA_CMD . "v-list-mail-domain-dkim-dns " . $user . " " . $v_domain_q . " 'json'", $output, $rv_dkim);
		$dk = $rv_dkim == 0 ? json_decode(implode("", $output), true) : null;
		unset($output);
		exec(HESTIA_CMD . "v-list-mail-domain " . $user . " " . $v_domain_q . " 'json'", $output, $rv_mail);
		unset($output);
		if ($rv_mail != 0) {
			exec(HESTIA_CMD . "v-change-dns-domain-tpl " . $user . " " . $v_domain_q . " 'default-nomail' 'no'", $output, $rv);
			unset($output);
		} elseif (!empty($dk["mail._domainkey"]["TXT"])) {
			$p = preg_replace('/^.*p=/', "", trim($dk["mail._domainkey"]["TXT"], "'\""));
			exec(
				HESTIA_CMD . "v-add-dns-record " . $user . " " . $v_domain_q . " 'mail._domainkey' 'TXT' " .
					quoteshellarg('"v=DKIM1\; k=rsa\; p=' . $p . '"') . " '' '' 'no'",
				$output,
				$rv,
			);
			unset($output);
		}
		// Alias del sitio que son subdominios (www ya viene en la plantilla).
		foreach (array_filter(explode(",", $web["ALIAS"] ?? "")) as $alias) {
			if ($alias !== "www." . $v_domain && str_ends_with($alias, "." . $v_domain)) {
				exec(HESTIA_CMD . "v-add-dns-on-web-alias " . $user . " " . quoteshellarg($alias) . " " . quoteshellarg($web["IP"]) . " 'no'", $output, $rv);
				unset($output);
			}
		}
		exec(HESTIA_CMD . "v-rebuild-dns-domain " . $user . " " . $v_domain_q . " 'yes'", $output, $rv);
		unset($output);
		header("Location: /list/dns/?" . http_build_query(["domain" => $v_domain]));
		exit();
	}
	header("Location: /list/dns-proveedor/?" . http_build_query(["domain" => $v_domain]));
	exit();
}
$origen = "zona";
$sitio_puerto = "";
$alias_externos = [];
$volver = "/list/dns/?" . http_build_query(["domain" => $v_domain]);

// 1) Si el dominio tiene zona DNS en este servidor, se usan sus registros.
exec(HESTIA_CMD . "v-list-dns-records " . $user . " " . $v_domain_q . " 'json'", $output, $return_var);
$data = $return_var == 0 ? json_decode(implode("", $output), true) ?: [] : null;
unset($output);

// 2) Si no, se generan desde el sitio web (IP, alias y correo).
if ($data === null) {
	$TAB = "WEB";
	$origen = "web";
	$volver = "/list/web/";
	exec(HESTIA_CMD . "v-list-web-domain " . $user . " " . $v_domain_q . " 'json'", $output, $return_var);
	if ($return_var != 0) {
		header("Location: /list/web/");
		exit();
	}
	$web = json_decode(implode("", $output), true)[$v_domain];
	unset($output);

	if (preg_match("/-p([0-9]+)$/", $web["TPL"] ?? "", $m)) {
		$sitio_puerto = "http://" . $web["IP"] . ":" . $m[1] . "/";
		$data = [];
	} else {
		$mail = false;
		$dkim = "";
		exec(HESTIA_CMD . "v-list-mail-domain " . $user . " " . $v_domain_q . " 'json'", $output, $return_var);
		if ($return_var == 0) {
			$mail = true;
			unset($output);
			exec(HESTIA_CMD . "v-list-mail-domain-dkim-dns " . $user . " " . $v_domain_q . " 'json'", $output, $return_var);
			if ($return_var == 0) {
				$dk = json_decode(implode("", $output), true);
				$dkim = trim($dk["mail._domainkey"]["TXT"] ?? "", "'\"");
			}
		}
		unset($output);
		$data = dnsp_desde_web($v_domain, $web, $mail, $dkim);
		$alias_externos = dnsp_alias_externos($v_domain, $web);

		// NS propuestos para crear la zona: los del paquete del usuario, avisando si no resuelven.
		exec(HESTIA_CMD . "v-list-user " . $user . " 'json'", $output, $return_var);
		$u = json_decode(implode("", $output), true);
		unset($output);
		$ns_propuestos = [];
		foreach (array_slice(array_pad(explode(",", reset($u)["NS"] ?? ""), 2, ""), 0, 2) as $n) {
			$ns_propuestos[] = ["nombre" => $n, "resuelve" => $n !== "" && gethostbyname($n) !== $n];
		}
	}
}

[$registros, $registros_ns, $zona] = dnsp_construir($v_domain, $data);

render_page($user, $TAB, "list_dns_provider");

// Back uri
$_SESSION["back"] = $_SERVER["REQUEST_URI"];

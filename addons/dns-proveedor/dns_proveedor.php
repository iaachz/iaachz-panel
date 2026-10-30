<?php
// iaachz-custom: convierte los registros de una zona de Hestia en filas listas
// para copiar en el panel del proveedor del dominio y en un archivo de zona BIND.

function dnsp_quitar_punto($v) {
	return rtrim($v, ".");
}

// Parte un TXT largo (DKIM) en trozos de 255 para el archivo de zona.
function dnsp_txt_zona($valor) {
	$plano = trim($valor, '"');
	if (strlen($plano) <= 255) {
		return '"' . $plano . '"';
	}
	$trozos = str_split($plano, 255);
	return "( \"" . implode("\"\n\t\t\t\t\t\"", $trozos) . "\" )";
}

// Registros recomendados para un sitio web que no tiene zona DNS en este servidor,
// en el mismo formato que v-list-dns-records (para pasarlos a dnsp_construir).
function dnsp_desde_web($dominio, $web, $mail, $dkim) {
	$ip = $web["IP"];
	$data = [];
	$add = function ($record, $type, $value, $priority = "") use (&$data) {
		$data[count($data) + 1] = [
			"RECORD" => $record,
			"TYPE" => $type,
			"VALUE" => $value,
			"PRIORITY" => $priority,
			"TTL" => "3600",
			"SUSPENDED" => "no",
		];
	};

	$add("@", "A", $ip);
	if (!empty($web["IP6"])) {
		$add("@", "AAAA", $web["IP6"]);
	}
	foreach (array_filter(explode(",", $web["ALIAS"] ?? "")) as $alias) {
		if ($alias === "www." . $dominio) {
			$add("www", "CNAME", $dominio . ".");
		} elseif (str_ends_with($alias, "." . $dominio)) {
			$add(substr($alias, 0, -strlen($dominio) - 1), "A", $ip);
		}
	}
	if ($mail) {
		$add("mail", "A", $ip);
		$add("@", "MX", "mail." . $dominio . ".", "10");
		$add("@", "TXT", '"v=spf1 a mx ip4:' . $ip . ' -all"');
		$add("_dmarc", "TXT", '"v=DMARC1; p=quarantine; pct=100"');
		if ($dkim !== "") {
			$add("mail._domainkey", "TXT", '"' . $dkim . '"');
		}
	}
	return $data;
}

// Alias que pertenecen a otro dominio: hay que crearlos en el proveedor de ese otro dominio.
function dnsp_alias_externos($dominio, $web) {
	return array_values(array_filter(explode(",", $web["ALIAS"] ?? ""), function ($a) use ($dominio) {
		return $a !== "" && $a !== $dominio && !str_ends_with($a, "." . $dominio);
	}));
}

function dnsp_construir($dominio, $data) {
	$orden = ["A" => 1, "AAAA" => 2, "CNAME" => 3, "MX" => 4, "TXT" => 5, "SRV" => 6, "CAA" => 7, "NS" => 8];
	uasort($data, function ($a, $b) use ($orden) {
		$oa = $orden[$a["TYPE"]] ?? 99;
		$ob = $orden[$b["TYPE"]] ?? 99;
		if ($oa != $ob) {
			return $oa - $ob;
		}
		return strcmp($a["RECORD"], $b["RECORD"]);
	});

	$registros = [];
	$ns = [];
	foreach ($data as $r) {
		if (($r["SUSPENDED"] ?? "no") == "yes") {
			continue;
		}
		$tipo = $r["TYPE"];
		$host = $r["RECORD"];
		$valor = $r["VALUE"];
		$item = [
			"host" => $host,
			"fqdn" => $host === "@" ? $dominio : $host . "." . $dominio,
			"tipo" => $tipo,
			"prioridad" => in_array($tipo, ["MX", "SRV"]) ? (string) $r["PRIORITY"] : "",
			"ttl" => $r["TTL"] ?: "3600",
			// Lo que se pega en el formulario del proveedor: sin comillas ni punto final.
			"valor" => $tipo === "TXT" ? trim($valor, '"') : dnsp_quitar_punto($valor),
			// Lo que va en el archivo de zona.
			"valor_zona" => $tipo === "TXT" ? dnsp_txt_zona($valor) : $valor,
		];
		if ($tipo === "NS") {
			$ns[] = $item;
		} else {
			$registros[] = $item;
		}
	}

	$zona = "; Zona de " . $dominio . " generada por Hestia (" . date("Y-m-d H:i") . ")\n";
	$zona .= "\$ORIGIN " . $dominio . ".\n\$TTL 3600\n";
	foreach ($registros as $r) {
		$zona .= sprintf(
			"%-28s %-6s IN %-6s %s%s\n",
			$r["host"],
			$r["ttl"],
			$r["tipo"],
			$r["prioridad"] !== "" ? $r["prioridad"] . " " : "",
			$r["valor_zona"],
		);
	}

	return [$registros, $ns, $zona];
}

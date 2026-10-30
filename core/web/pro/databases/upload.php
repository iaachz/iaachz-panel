<?php
// iaachz-panel: subida de volcados por partes (8 MB cada una), así no aplica el límite de subida de PHP.
// Las partes se guardan en /var/lib/iaachz-panel/uploads (solo hestiaweb); `v-hp db-import` mueve el archivo
// a una zona solo-root antes de leerlo.
include __DIR__ . "/../inc/pro.php";

header("Content-Type: application/json; charset=utf-8");
const HP_UPLOADS = "/var/lib/iaachz-panel/uploads";
const HP_MAX_UPLOAD = 8 * 1024 * 1024 * 1024; // 8 GB

function hp_up_fail(string $msg, int $code = 400): void {
	http_response_code($code);
	exit(json_encode(["error" => $msg], JSON_UNESCAPED_UNICODE));
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
	hp_up_fail(__('Método no permitido'), 405);
}
if (!hash_equals($_SESSION["token"] ?? "", (string) ($_SERVER["HTTP_X_TOKEN"] ?? ""))) {
	hp_up_fail(__('Sesión caducada: recarga la página'), 403);
}
$id = (string) ($_SERVER["HTTP_X_UPLOAD_ID"] ?? "");
$index = (int) ($_SERVER["HTTP_X_CHUNK_INDEX"] ?? -1);
$total = (int) ($_SERVER["HTTP_X_FILE_SIZE"] ?? 0);
$name = basename(rawurldecode((string) ($_SERVER["HTTP_X_FILE_NAME"] ?? "volcado.sql")));
if (!preg_match('/^[a-f0-9]{32}$/', $id) || $index < 0) {
	hp_up_fail(__('Parámetros de subida no válidos'));
}
if ($total <= 0 || $total > HP_MAX_UPLOAD) {
	hp_up_fail(__('El archivo supera el máximo de 8 GB; súbelo por FTP/SFTP e impórtalo desde el servidor'));
}
if (!preg_match('/\.(sql|gz|zip|bz2|xz|zst)$/i', $name)) {
	hp_up_fail(__('Formato no admitido: usa .sql, .sql.gz, .zip, .bz2, .xz o .zst'));
}

$part = HP_UPLOADS . "/$id.part";
$metaFile = HP_UPLOADS . "/$id.json";
$owner = $_SESSION["user"];

if ($index === 0) {
	if (@disk_free_space(HP_UPLOADS) < $total * 2) {
		hp_up_fail(__('No hay espacio libre suficiente en el servidor para este volcado'));
	}
	$meta = ["owner" => $owner, "name" => $name, "size" => $total, "next" => 0, "received" => 0];
	file_put_contents($part, "");
} else {
	$meta = json_decode((string) @file_get_contents($metaFile), true);
	if (!$meta || $meta["owner"] !== $owner || $meta["next"] !== $index) {
		hp_up_fail(__('Subida interrumpida: vuelve a empezar'));
	}
}

$in = fopen("php://input", "rb");
$out = fopen($part, "ab");
$copied = stream_copy_to_stream($in, $out, 16 * 1024 * 1024);
fclose($in);
fclose($out);
$meta["received"] += $copied;
$meta["next"] = $index + 1;
if ($meta["received"] > $meta["size"]) {
	@unlink($part);
	@unlink($metaFile);
	hp_up_fail(__('El archivo recibido es mayor de lo anunciado'));
}
file_put_contents($metaFile, json_encode($meta));
echo json_encode(["ok" => true, "received" => $meta["received"], "complete" => $meta["received"] === $meta["size"]]);

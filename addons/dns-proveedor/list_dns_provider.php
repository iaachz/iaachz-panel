<!-- Begin toolbar -->
<div class="toolbar">
	<div class="toolbar-inner">
		<div class="toolbar-buttons">
			<a class="button button-secondary button-back js-button-back" href="<?= tohtml($volver) ?>">
				<i class="fas fa-arrow-left icon-blue"></i><?= tohtml(_("Back")) ?>
			</a>
			<?php if (!empty($registros)) { ?>
			<button type="button" class="button button-secondary" data-copiar-todo>
				<i class="fas fa-copy icon-green"></i>Copiar todo (texto)
			</button>
			<?php } ?>
		</div>
	</div>
</div>
<!-- End toolbar -->

<div class="container">
	<h1 class="u-mt20 u-mb10">Registros DNS para tu proveedor: <?= tohtml($v_domain) ?></h1>
	<p class="u-mb20">
		Crea estos registros en el panel donde compraste el dominio (GoDaddy, Namecheap, Cloudflare, Punto.pe…).
		En <b>Host</b>, <code>@</code> es el dominio raíz; algunos proveedores lo piden vacío o con el dominio completo.
		Pulsa <i class="fas fa-copy"></i> para copiar cada valor.
	</p>

	<?php if ($origen === "web" && $sitio_puerto === "") { ?>
		<p class="u-mb20">
			<i class="fas fa-circle-info icon-blue"></i>
			Este dominio no tiene zona DNS en este servidor: estos registros se generaron a partir del sitio web
			(IP, alias<?= !empty($mail) ? " y correo" : "" ?>). Si agregas alias o correo, vuelve a esta página y se actualizan solos.
		</p>
	<?php } ?>
	<?php if (!empty($alias_externos)) { ?>
		<p class="u-mb20">
			<i class="fas fa-triangle-exclamation icon-orange"></i>
			Alias de otros dominios (créalos como registro <b>A</b> hacia <code><?= tohtml($web["IP"]) ?></code> en el proveedor de cada uno):
			<?php foreach ($alias_externos as $a) { ?><code><?= tohtml($a) ?></code> <?php } ?>
		</p>
	<?php } ?>

	<?php if ($sitio_puerto !== "") { ?>
		<p class="u-mb20">
			<i class="fas fa-circle-info icon-blue"></i>
			Este sitio se publica por IP y puerto, no por nombre: no necesita registros DNS.
			Se abre en <a href="<?= tohtml($sitio_puerto) ?>" target="_blank" rel="noopener"><code><?= tohtml($sitio_puerto) ?></code></a>.
		</p>
	<?php } elseif (empty($registros)) { ?>
		<p class="u-mb20">Esta zona no tiene registros activos.</p>
	<?php } else { ?>
	<div class="units-table u-mb20">
		<div class="units-table-header">
			<div class="units-table-cell">Tipo</div>
			<div class="units-table-cell">Host</div>
			<div class="units-table-cell">Valor</div>
			<div class="units-table-cell u-text-center">Prioridad</div>
			<div class="units-table-cell u-text-center">TTL</div>
		</div>
		<?php foreach ($registros as $r) { ?>
		<div class="units-table-row">
			<div class="units-table-cell u-text-bold"><?= tohtml($r["tipo"]) ?></div>
			<div class="units-table-cell">
				<code><?= tohtml($r["host"]) ?></code>
				<button type="button" class="dnsp-copiar" data-copiar="<?= tohtml($r["host"]) ?>" title="Copiar host"><i class="fas fa-copy"></i></button>
				<div class="u-text-small" style="opacity:.6"><?= tohtml($r["fqdn"]) ?></div>
			</div>
			<div class="units-table-cell" style="word-break:break-all">
				<code><?= tohtml($r["valor"]) ?></code>
				<button type="button" class="dnsp-copiar" data-copiar="<?= tohtml($r["valor"]) ?>" title="Copiar valor"><i class="fas fa-copy"></i></button>
			</div>
			<div class="units-table-cell u-text-center"><?= tohtml($r["prioridad"]) ?></div>
			<div class="units-table-cell u-text-center"><?= tohtml($r["ttl"]) ?></div>
		</div>
		<?php } ?>
	</div>
	<?php } ?>

	<?php if (!empty($registros_ns)) { ?>
	<h2 class="u-mb10">Opción alternativa: usar este servidor como DNS</h2>
	<p class="u-mb10">
		En vez de copiar los registros de arriba, puedes cambiar los <b>nameservers</b> del dominio por estos.
		Solo funciona si esos nombres resuelven a este servidor (glue records / child nameservers).
	</p>
	<ul class="u-mb20">
		<?php foreach ($registros_ns as $r) { ?>
			<li><code><?= tohtml($r["valor"]) ?></code>
				<button type="button" class="dnsp-copiar" data-copiar="<?= tohtml($r["valor"]) ?>" title="Copiar"><i class="fas fa-copy"></i></button>
			</li>
		<?php } ?>
	</ul>
	<?php } ?>

	<?php if ($origen === "web" && $sitio_puerto === "" && $read_only !== true) { ?>
	<div class="form-container u-mb20" style="max-width:none">
		<h2 class="u-mb10">Editar registros y nameservers</h2>
		<p class="u-mb10">
			Estos registros son de solo lectura porque el dominio no tiene zona DNS en este servidor.
			Crea la zona para poder editarlos (incluidos los NS) desde <b>DNS</b>. Se creará con la IP del sitio,
			sus alias<?= !empty($mail) ? ", el correo y su DKIM actual" : "" ?>.
		</p>
		<form method="post" action="/list/dns-proveedor/?<?= tohtml(http_build_query(["domain" => $v_domain])) ?>">
			<input type="hidden" name="token" value="<?= tohtml($_SESSION["token"]) ?>">
			<input type="hidden" name="crear_zona" value="1">
			<?php foreach ($ns_propuestos as $i => $n) { ?>
			<div class="u-mb10">
				<label class="form-label" for="ns<?= $i + 1 ?>">Servidor de nombres <?= $i + 1 ?></label>
				<input type="text" class="form-control" id="ns<?= $i + 1 ?>" name="ns<?= $i + 1 ?>" value="<?= tohtml($n["nombre"]) ?>" required>
				<?php if (!$n["resuelve"]) { ?>
					<small class="u-text-small" style="color:#d9534f">
						<i class="fas fa-triangle-exclamation"></i> «<?= tohtml($n["nombre"]) ?>» no existe en internet: cámbialo por un NS real (p. ej. ns1.tudominio.com con glue record hacia <?= tohtml($web["IP"]) ?>).
					</small>
				<?php } ?>
			</div>
			<?php } ?>
			<button type="submit" class="button"><i class="fas fa-circle-plus"></i>Crear zona DNS editable</button>
		</form>
	</div>
	<?php } ?>

	<?php if ($origen === "zona" && $read_only !== true) { ?>
	<p class="u-mb20">
		<a class="button button-secondary" href="/list/dns/?<?= tohtml(http_build_query(["domain" => $v_domain])) ?>"><i class="fas fa-pencil icon-orange"></i>Editar registros (incluidos los NS)</a>
	</p>
	<?php } ?>

	<?php if (!empty($registros)) { ?>
	<h2 class="u-mb10">Archivo de zona (BIND)</h2>
	<p class="u-mb10">Para proveedores que permiten importar una zona (Cloudflare, Route 53, etc.).</p>
	<textarea class="form-control u-console u-mb10" rows="<?= min(30, substr_count($zona, "\n") + 2) ?>" readonly id="dnsp-zona"><?= tohtml($zona) ?></textarea>
	<button type="button" class="button u-mb20" data-copiar-zona><i class="fas fa-copy"></i>Copiar zona</button>
	<?php } ?>
</div>

<style>
	.dnsp-copiar { background: none; border: 0; cursor: pointer; padding: 0 4px; color: var(--color-text-link, #2c54ac); }
	.dnsp-copiar.ok { color: #3c763d; }
</style>
<script>
(function () {
	function copiar(texto, boton) {
		var hecho = function () {
			if (!boton) return;
			boton.classList.add("ok");
			setTimeout(function () { boton.classList.remove("ok"); }, 1200);
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(texto).then(hecho);
		} else {
			var t = document.createElement("textarea");
			t.value = texto; document.body.appendChild(t); t.select();
			document.execCommand("copy"); t.remove(); hecho();
		}
	}
	document.querySelectorAll(".dnsp-copiar").forEach(function (b) {
		b.addEventListener("click", function () { copiar(b.dataset.copiar, b); });
	});
	var zona = document.getElementById("dnsp-zona");
	if (!zona) return;
	document.querySelector("[data-copiar-zona]").addEventListener("click", function (e) { copiar(zona.value, e.currentTarget); });
	document.querySelector("[data-copiar-todo]").addEventListener("click", function (e) {
		var filas = <?= json_encode(array_map(fn($r) => $r["tipo"] . "\t" . $r["host"] . "\t" . ($r["prioridad"] !== "" ? $r["prioridad"] . "\t" : "") . $r["valor"], $registros), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
		copiar(filas.join("\n"), e.currentTarget);
	});
})();
</script>

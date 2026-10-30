<?php
$actions = '<a class="hp-btn" href="/list/package/"><i class="fas fa-box-archive"></i>Planes de servicio</a>'
	. '<a class="hp-btn hp-btn-primary" href="/pro/new/?mode=subscription"><i class="fas fa-user-plus"></i>Nueva suscripción</a>';
hp_page_header("Suscripciones", "Cada suscripción es un cliente con su plan, sus dominios, correo, bases de datos y aplicaciones.", [], $actions);
$tok = $_SESSION["token"];
?>
<div x-data="{ q: '' }">
	<div class="hp-card hp-mb">
		<div class="hp-card-body">
			<div class="hp-search" style="max-width:420px"><i class="fas fa-magnifying-glass"></i><input type="search" x-model="q" placeholder="Buscar cliente, correo o dominio…"></div>
		</div>
	</div>
	<div class="hp-grid hp-grid-2">
		<?php foreach ($users as $name => $u) {
			$doms = $byUser[$name] ?? [];
			$main = $doms[0]["domain"] ?? "";
			$diskPct = get_percentage($u["U_DISK"], $u["DISK_QUOTA"]);
			$bwPct = get_percentage($u["U_BANDWIDTH"], $u["BANDWIDTH"]);
			$search = strtolower($name . " " . $u["NAME"] . " " . $u["CONTACT"] . " " . implode(" ", array_column($doms, "domain")));
			$suspended = $u["SUSPENDED"] === "yes";
			$isRoot = $name === $_SESSION["ROOT_USER"];
			?>
			<div class="hp-card" x-show="!q || <?= e(json_encode($search)) ?>.includes(q.toLowerCase())" style="<?= $suspended ? "opacity:.65" : "" ?>">
				<div class="hp-card-body">
					<div class="hp-between" style="align-items:flex-start">
						<div class="hp-flex" style="gap:12px;min-width:0">
							<span class="hp-avatar" style="width:44px;height:44px;font-size:1.1rem"><?= e(strtoupper(mb_substr($name, 0, 1))) ?></span>
							<div style="min-width:0">
								<div class="hp-flex hp-wrap"><b style="font-size:1.05rem;color:var(--hp-text)"><?= e($name) ?></b>
									<?= $u["ROLE"] === "admin" ? '<span class="hp-badge hp-badge-primary">Administrador</span>' : "" ?>
									<?= $suspended ? '<span class="hp-badge hp-badge-danger">Suspendida</span>' : '<span class="hp-badge hp-badge-ok"><i class="fas fa-circle"></i>Activa</span>' ?>
								</div>
								<div class="hp-muted hp-small"><?= e($u["NAME"]) ?> · <?= e($u["CONTACT"]) ?></div>
							</div>
						</div>
						<div class="hp-menu" x-data="{ open: false }">
							<button type="button" class="hp-btn hp-btn-sm hp-btn-ghost" x-on:click="open = !open"><i class="fas fa-ellipsis-vertical"></i></button>
							<div class="hp-menu-panel hp-menu-right" x-cloak x-show="open" x-on:click.outside="open = false">
								<?php if (!$isRoot) { ?><a href="/login/?<?= e(http_build_query(["loginas" => $name, "token" => $tok])) ?>"><i class="fas fa-right-to-bracket"></i>Entrar como <?= e($name) ?></a><?php } ?>
								<a href="/edit/user/?<?= e(http_build_query(["user" => $name, "token" => $tok])) ?>"><i class="fas fa-pen"></i>Editar cliente y plan</a>
								<a href="/pro/new/?<?= e(http_build_query(["user" => $name])) ?>"><i class="fas fa-plus"></i>Agregar dominio</a>
								<?php if (!$isRoot) { ?>
									<a class="js-confirm-action" href="/<?= $suspended ? "unsuspend" : "suspend" ?>/user/?<?= e(http_build_query(["user" => $name, "token" => $tok])) ?>" data-confirm-title="<?= $suspended ? "Reactivar" : "Suspender" ?>" data-confirm-message="¿<?= $suspended ? "Reactivar" : "Suspender" ?> la suscripción <?= e($name) ?>?"><i class="fas <?= $suspended ? "fa-play" : "fa-pause" ?>"></i><?= $suspended ? "Reactivar" : "Suspender" ?></a>
									<a class="hp-menu-danger js-confirm-action" href="/delete/user/?<?= e(http_build_query(["user" => $name, "token" => $tok])) ?>" data-confirm-title="Eliminar" data-confirm-message="¿Eliminar la suscripción <?= e($name) ?> con TODOS sus sitios, correos y bases de datos?"><i class="fas fa-trash"></i>Eliminar</a>
								<?php } ?>
							</div>
						</div>
					</div>

					<div class="hp-domain-meta hp-mt">
						<span class="hp-tag"><i class="fas fa-box-archive"></i>Plan <?= e($u["PACKAGE"]) ?></span>
						<span class="hp-tag"><i class="fas fa-earth-americas"></i><?= (int) $u["U_WEB_DOMAINS"] ?> sitios</span>
						<span class="hp-tag"><i class="fas fa-envelope"></i><?= (int) $u["U_MAIL_ACCOUNTS"] ?> buzones</span>
						<span class="hp-tag"><i class="fas fa-database"></i><?= (int) $u["U_DATABASES"] ?> BD</span>
						<span class="hp-tag"><i class="fas fa-cubes"></i><?= (int) ($appsByUser[$name] ?? 0) ?> apps</span>
					</div>

					<div class="hp-grid hp-grid-2 hp-mt" style="gap:14px">
						<div>
							<div class="hp-between hp-small"><span class="hp-muted">Disco</span><span><?= hp_mb($u["U_DISK"]) ?> / <?= hp_mb($u["DISK_QUOTA"]) ?></span></div>
							<div class="hp-progress is-<?= $diskPct >= 90 ? "danger" : ($diskPct >= 75 ? "warn" : "ok") ?>" style="margin-top:6px"><span style="width:<?= max(2, $diskPct) ?>%"></span></div>
						</div>
						<div>
							<div class="hp-between hp-small"><span class="hp-muted">Tráfico del mes</span><span><?= hp_mb($u["U_BANDWIDTH"]) ?> / <?= hp_mb($u["BANDWIDTH"]) ?></span></div>
							<div class="hp-progress is-<?= $bwPct >= 90 ? "danger" : ($bwPct >= 75 ? "warn" : "ok") ?>" style="margin-top:6px"><span style="width:<?= max(2, $bwPct) ?>%"></span></div>
						</div>
					</div>

					<?php if ($doms) { ?>
						<div class="hp-mt" style="border-top:1px solid var(--hp-border);padding-top:10px">
							<?php foreach (array_slice($doms, 0, 4) as $d) {
								$t = $d["app"]["type"] ?? ($d["cms"] === "WordPress" ? "wordpress" : $d["hosting"]); ?>
								<a class="hp-tool" style="padding:6px 8px" href="/pro/site/?<?= e(http_build_query(["user" => $name, "domain" => $d["domain"]])) ?>">
									<?= hp_type_chip($t, "sm") ?><span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis"><?= e($d["domain"]) ?></span>
									<?= $d["ssl"] ? '<i class="fas fa-lock" style="background:none;color:var(--hp-ok);width:auto;height:auto"></i>' : "" ?>
								</a>
							<?php } ?>
							<?php if (count($doms) > 4) { ?><div class="hp-muted hp-small" style="padding:4px 8px">y <?= count($doms) - 4 ?> más…</div><?php } ?>
						</div>
					<?php } ?>
				</div>
			</div>
		<?php } ?>
	</div>
</div>

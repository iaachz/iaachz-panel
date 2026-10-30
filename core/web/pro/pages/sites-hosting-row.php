<?php

			$t = $s["app"]["type"] ?? ($s["cms"] === "WordPress" ? "wordpress" : $s["hosting"]);
			$q = http_build_query(["user" => $s["user"], "domain" => $s["domain"]]);
			$search = strtolower($s["domain"] . " " . $s["user"] . " " . hp_hosting_meta($t)[0] . " " . ($s["cms"] ?? "") . " " . implode(" ", $s["aliases"] ?? []));
			$url = ($s["ssl"] ? "https://" : "http://") . $s["domain"] . "/";
			$own = $s["user"] === hp_me();
			?>
			<div class="hp-domain<?= $s["suspended"] ? " is-suspended" : "" ?>" x-show="(!q || <?= e(json_encode($search)) ?>.includes(q.toLowerCase())) && (!type || type === '<?= e($t) ?>')">
				<div class="hp-domain-row">
					<?= hp_type_chip($t) ?>
					<div>
						<a class="hp-domain-name" href="/pro/site/?<?= e($q) ?>"><?= e($s["domain"]) ?></a>
						<?php if ($s["suspended"]) { ?><span class="hp-badge hp-badge-danger"><?= __e('Suspendido') ?></span><?php } ?>
						<div class="hp-domain-meta">
							<?php if (hp_admin()) { ?><span class="hp-tag" title="<?= __e('Suscripción') ?>"><i class="fas fa-user"></i><?= e($s["user"]) ?></span><?php } ?>
							<?php if ($s["hosting"] === "none") { ?>
								<span class="hp-tag"><?= __e('Sin sitio web') ?></span>
							<?php } elseif ($s["app"]) { ?>
								<span class="hp-tag"><?= e(hp_hosting_meta($s["app"]["type"])[0] . (!empty($s["app"]["version"]) ? " " . $s["app"]["version"] : "")) ?><?= !empty($s["app"]["port"]) ? " · :" . (int) $s["app"]["port"] : "" ?></span>
								<?= hp_state_badge($s["app"]["state"]) ?>
							<?php } else { ?>
								<span class="hp-tag"><?= e($s["php"]) ?></span>
								<?php if (($s["webserver"] ?? "") === "apache") { ?><span class="hp-tag" title="<?= __e('nginx + Apache: respeta .htaccess') ?>"><i class="fas fa-feather"></i><?= __e('nginx + Apache') ?></span><?php } ?>
								<?php if ($s["cms"]) { ?><span class="hp-tag"><i class="fas fa-puzzle-piece"></i><?= e($s["cms"]) ?><?= !empty($s["wp_version"]) ? " " . e($s["wp_version"]) : "" ?></span><?php } ?>
							<?php } ?>
							<?php if ($s["hosting"] !== "none") {
								if ($s["ssl"] && !empty($s["cert"])) {
									$days = $s["cert"]["days"];
									echo '<span class="hp-badge hp-badge-' . ($days < 7 ? "danger" : ($days < 20 ? "warn" : "ok")) . '" title="' . e($s["cert"]["issuer"]) . ('">' . '<i class="fas fa-lock">' . '</i>' . __('SSL ·') . ' ') . $days . (" " . __('días') . "</span>");
								} elseif ($s["ssl"]) {
									echo '<span class="hp-badge hp-badge-ok"><i class="fas fa-lock"></i>SSL</span>';
								} else {
									echo ('<span class="hp-badge hp-badge-warn">' . '<i class="fas fa-lock-open">' . '</i>' . __('Sin SSL') . '</span>');
								}
							} ?>
							<?php if ($s["dns"]) { ?><span class="hp-tag"><i class="fas fa-sitemap"></i>DNS <?= (int) $s["dns_records"] ?></span><?php } ?>
							<?php if ($s["mail"]) { ?><span class="hp-tag"><i class="fas fa-envelope"></i><?= (int) $s["mail_accounts"] ?> buzones</span><?php } ?>
							<?php if (!empty($s["git"])) { ?><span class="hp-tag"><i class="fas fa-code-branch"></i>Git</span><?php } ?>
							<?php if ($s["hosting"] !== "none") { ?><span class="hp-tag"><i class="fas fa-hard-drive"></i><?= hp_mb($s["disk"]) ?></span><?php } ?>
						</div>
					</div>
					<div class="hp-btn-group">
						<?php if ($s["hosting"] !== "none") { ?>
							<a class="hp-btn hp-btn-sm" href="<?= e($url) ?>" target="_blank" rel="noopener" title="<?= __e('Abrir el sitio') ?>"><i class="fas fa-arrow-up-right-from-square"></i></a>
						<?php } ?>
						<button type="button" class="hp-btn hp-btn-sm" x-on:click="open[<?= $i ?>] = !open[<?= $i ?>]"><i class="fas" :class="open[<?= $i ?>] ? 'fa-chevron-up' : 'fa-chevron-down'"></i><span class="hp-hide-sm"><?= __e('Herramientas') ?></span></button>
						<a class="hp-btn hp-btn-sm hp-btn-primary" href="/pro/site/?<?= e($q) ?>"><i class="fas fa-gear"></i><span class="hp-hide-sm"><?= __e('Gestionar') ?></span></a>
					</div>
				</div>
				<div class="hp-domain-tools" x-show="open[<?= $i ?>]" x-collapse x-cloak>
					<div class="hp-tools">
						<?php if ($s["hosting"] !== "none") { ?>
							<a class="hp-tool" href="/pro/site/?<?= e($q) ?>#hosting"><i class="fas fa-sliders"></i><?= __e('Hosting y PHP') ?></a>
							<a class="hp-tool" href="/pro/site/?<?= e($q) ?>#app"><i class="fas fa-cubes"></i><?= __e('Node.js / Python / Docker') ?></a>
							<a class="hp-tool" href="/pro/site/?<?= e($q) ?>#ssl"><i class="fas fa-lock"></i>SSL/TLS</a>
							<a class="hp-tool" href="/pro/site/?<?= e($q) ?>#git"><i class="fas fa-code-branch"></i>Git</a>
							<?php if ($own) { ?>
								<a class="hp-tool" href="/fm/?path=<?= e(urlencode("/web/" . $s["domain"])) ?>"><i class="fas fa-folder-open"></i><?= __e('Archivos') ?></a>
								<a class="hp-tool" href="/list/web-log/?<?= e(http_build_query(["domain" => $s["domain"], "type" => "access"])) ?>"><i class="fas fa-file-lines"></i><?= __e('Registros') ?></a>
								<a class="hp-tool" href="/edit/web/?<?= e(http_build_query(["domain" => $s["domain"], "token" => $_SESSION["token"]])) ?>"><i class="fas fa-pen"></i><?= __e('Ajustes avanzados') ?></a>
							<?php } ?>
							<?php if ($s["cms"] === "WordPress") { ?>
								<a class="hp-tool" href="<?= e($url) ?>wp-admin/" target="_blank" rel="noopener"><i class="fas fa-pen-nib"></i><?= __e('Escritorio WP') ?></a>
							<?php } ?>
						<?php } ?>
						<?php if ($own) { ?>
							<a class="hp-tool" href="<?= $s["dns"] ? "/list/dns/?" . e(http_build_query(["domain" => $s["domain"], "token" => $_SESSION["token"]])) : "/list/dns-proveedor/?" . e(http_build_query(["domain" => $s["domain"]])) ?>"><i class="fas fa-sitemap"></i>DNS</a>
							<a class="hp-tool" href="<?= $s["mail"] ? "/list/mail/?" . e(http_build_query(["domain" => $s["domain"], "token" => $_SESSION["token"]])) : "/add/mail/" ?>"><i class="fas fa-envelope"></i><?= __e('Correo') ?></a>
							<a class="hp-tool" href="/pro/databases/"><i class="fas fa-database"></i><?= __e('Bases de datos') ?></a>
						<?php } elseif (hp_admin()) { ?>
							<a class="hp-tool" href="/login/?<?= e(http_build_query(["loginas" => $s["user"], "token" => $_SESSION["token"]])) ?>"><i class="fas fa-right-to-bracket"></i><?= __e("Entrar como %s", $s["user"]) ?></a>
						<?php } ?>
					</div>
				</div>
			</div>
		
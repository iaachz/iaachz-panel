<!-- Begin toolbar -->
<div class="toolbar">
	<div class="toolbar-inner">
		<div class="toolbar-buttons">
			<a class="button button-secondary button-back js-button-back" href="/list/web/">
				<i class="fas fa-arrow-left icon-blue"></i><?= tohtml( _("Back")) ?>
			</a>
		</div>
		<div class="toolbar-buttons">
			<button type="submit" class="button" form="main-form">
				<i class="fas fa-floppy-disk icon-purple"></i><?= tohtml( _("Save")) ?>
			</button>
		</div>
	</div>
</div>
<!-- End toolbar -->

<div class="container">

	<form id="main-form" name="v_add_subdomain" method="post" class="js-enable-inputs-on-submit">
		<input type="hidden" name="token" value="<?= tohtml($_SESSION["token"]) ?>">
		<input type="hidden" name="ok" value="Add">

		<div class="form-container">
			<h1 class="u-mb20"><?= tohtml(sd_t("Add Subdomain", "Agregar subdominio")) ?></h1>
			<?php show_alert_message($_SESSION); ?>
			<div class="u-mb10">
				<label for="v_sub" class="form-label"><?= tohtml(sd_t("Subdomain", "Subdominio")) ?></label>
				<input type="text" class="form-control" name="v_sub" id="v_sub" value="<?= tohtml($v_sub) ?>" placeholder="<?= tohtml(sd_t("e.g. test", "ej. prueba")) ?>" required>
			</div>
			<div class="u-mb10">
				<label for="v_parent" class="form-label"><?= tohtml(sd_t("Parent domain", "Dominio principal")) ?></label>
				<select class="form-select" name="v_parent" id="v_parent" required>
					<?php foreach ($parents as $p) { ?>
						<option value="<?= tohtml($p) ?>" <?= $p === $v_parent ? "selected" : "" ?>><?= tohtml($p) ?></option>
					<?php } ?>
				</select>
			</div>
			<div class="u-mb20">
				<label for="v_ip" class="form-label"><?= tohtml( _("IP Address")) ?></label>
				<select class="form-select" name="v_ip" id="v_ip">
					<?php
						foreach ($ips as $ip => $value) {
							$display_ip = htmlentities(empty($value['NAT']) ? $ip : "{$value['NAT']}");
							echo "\t\t\t\t<option value=\"" . tohtml($ip) . "\">{$display_ip}</option>\n";
						}
					?>
				</select>
			</div>
			<?php if (!empty($zones)) { ?>
				<div class="form-check u-mb10">
					<input class="form-check-input" type="checkbox" name="v_dns" id="v_dns" checked>
					<label for="v_dns"><?= tohtml(sd_t("Create DNS record in the parent zone", "Crear registro DNS en la zona del dominio principal")) ?></label>
				</div>
			<?php } ?>
			<div class="form-check">
				<input class="form-check-input" type="checkbox" name="v_ssl" id="v_ssl" checked>
				<label for="v_ssl"><?= tohtml(sd_t("Enable SSL (Let's Encrypt)", "Activar SSL (Let's Encrypt)")) ?></label>
			</div>
		</div>

	</form>

</div>

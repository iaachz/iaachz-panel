<div class="toolbar"><div class="toolbar-inner"><div class="toolbar-buttons">
<a class="button button-secondary button-back" href="/list/web/"><i class="fas fa-arrow-left icon-blue"></i>Volver a sitios web y dominios</a>
</div><div class="toolbar-buttons"><a class="button button-secondary" href="/add/tunnel/"><i class="fas fa-arrows-rotate icon-blue"></i>Actualizar estado</a></div></div></div>
<div class="container">
<form method="post" id="main-form" class="js-enable-inputs-on-submit" x-data="<?= tohtml(json_encode(['parent'=>$form['parent_domain']??'', 'sub'=>$form['subdomain']??'', 'full'=>$form['domain']])) ?>">
<input type="hidden" name="token" value="<?= tohtml($_SESSION['token']) ?>">
<input type="hidden" name="action" id="tunnel-action" value="save">
<div class="form-container">
<h1 class="u-mb10"><?= $editing ? 'Editar túnel' : 'Agregar túnel' ?></h1>
<p class="u-mb20">Publica una app de tu servidor VPN con un dominio y HTTPS. La app y sus archivos permanecen en el servidor de origen.</p>
<?php show_alert_message($_SESSION); ?>
<div class="u-mb20"><label class="form-label" for="parent_domain">Dominio principal (opcional)</label>
<select class="form-select" id="parent_domain" name="parent_domain" x-model="parent" <?= $editing?'disabled':'' ?>>
<option value="">Sin seleccionar · escribir dominio completo / DNS externo</option>
<?php foreach (($inventory['domains'] ?? array_column($inventory['zones'],'domain')) as $parent) { ?><option value="<?= tohtml($parent) ?>" <?= $parent===($form['parent_domain']??'')?'selected':'' ?>><?= tohtml($parent) ?></option><?php } ?>
</select><p class="u-mt5">Elige un dominio disponible o deja esta opción vacía si el DNS está en otro servidor.</p></div>
<div class="u-mb20" x-show="parent" x-cloak><label class="form-label" for="subdomain">Subdominio (opcional)</label>
<input class="form-control" id="subdomain" name="subdomain" x-model="sub" value="<?= tohtml($form['subdomain']??'') ?>" placeholder="miapp" maxlength="200">
<p class="u-mt5">Escribe solo el nombre, por ejemplo miapp. Déjalo vacío para usar el dominio principal.</p></div>
<div class="u-mb20" x-show="!parent"><label class="form-label" for="domain">Dominio completo o subdominio externo</label>
<input class="form-control" id="domain" name="domain" x-model="full" value="<?= tohtml($form['domain']) ?>" placeholder="app.otro-dominio.com" :required="!parent" maxlength="240" <?= $editing ? 'readonly' : '' ?>>
<p class="u-mt5">No necesita estar registrado como sitio web en Hestia. También puedes escribir aquí un dominio de este servidor.</p>
</div>
<div class="u-mb20" x-show="parent || full" x-cloak><strong>Dirección del túnel:</strong> <span x-text="parent ? (sub ? sub + '.' : '') + parent : full"></span></div>
<div class="u-mb20" x-show="!parent" style="border-left:3px solid #438fff;padding:12px 16px;background:rgba(67,143,255,.06)">
<strong>Si administras el DNS en otro servidor</strong>
<p>Crea allí un registro <strong>A</strong> del dominio o subdominio completo hacia <strong><?= tohtml($inventory['public_ip'] ?? (function_exists('hp_setting') ? hp_setting('public_ip', 'la IP pública del servidor') : 'la IP pública del servidor')) ?></strong>. Después crea el túnel aquí. No tienes que trasladar la zona DNS ni agregar antes un dominio web en Hestia.</p>
<p>El asistente esperará a que el DNS público resuelva correctamente y activará HTTPS automáticamente.</p>
</div>
<div class="u-mb20"><label class="form-label" for="target">Servidor conectado a la VPN</label>
<select class="form-select" id="target" name="target" required>
<?php foreach ($inventory['targets'] as $peer) { ?>
<option value="<?= tohtml($peer['ip']) ?>" <?= $peer['ip'] === $form['target'] ? 'selected' : '' ?>><?= tohtml($peer['name'].' · '.$peer['ip'].($peer['online'] ? ' · conectado' : ' · desconectado')) ?></option>
<?php } ?></select></div>
<div class="u-mb20"><label class="form-label" for="port">Puerto HTTP de la app</label>
<input class="form-control" id="port" name="port" type="number" min="1" max="65535" value="<?= tohtml($form['port']) ?>" required>
<p class="u-mt5">Ejemplo: 7504. La app debe ser accesible desde la VPN.</p></div>
<div class="u-mb20"><label class="form-label" for="profile">Tipo de uso</label>
<select class="form-select" id="profile" name="profile"><option value="standard" <?= $form['profile']==='standard'?'selected':'' ?>>Normal · hasta 10 GB por petición</option><option value="large" <?= $form['profile']==='large'?'selected':'' ?>>Archivos grandes · sin límite de tamaño en el proxy</option></select>
<p class="u-mt5">Ambos admiten WebSockets y transferencias continuas. Las cuotas de la app siguen aplicando.</p></div>
<div class="form-check u-mb10"><input class="form-check-input" type="checkbox" id="https" name="https" <?= $form['https']?'checked':'' ?>><label for="https">Activar HTTPS y renovar el certificado automáticamente</label></div>
<div class="form-check u-mb20" x-show="parent" x-cloak><input class="form-check-input" type="checkbox" id="auto_dns" name="auto_dns" :disabled="!parent" <?= $form['auto_dns']?'checked':'' ?>><label for="auto_dns">Crear el registro DNS si este servidor administra la zona</label></div>
<p class="u-mb20">Si el DNS aún no está listo, el túnel quedará pendiente y reintentará HTTPS cada 15 minutos. Los registros existentes no se sobrescriben. Usa este dominio como URL pública en la app cuando corresponda.</p>
<?php if ($read_only !== true) { ?><div class="toolbar-buttons"><button class="button button-secondary" type="submit" onclick="this.form.elements.action.value='probe'">Probar conexión</button><button class="button" type="submit" onclick="this.form.elements.action.value='save'"><?= $editing?'Guardar cambios':'Crear túnel' ?></button></div><?php } ?>
</div></form>
<section class="u-mt30 u-mb30"><h2 class="u-mb20">Túneles VPN</h2>
<?php if (!$inventory['items']) { ?><p>Aquí aparecerán los túneles creados con este asistente. Los proxies existentes se conservan sin cambios.</p><?php } ?>
<?php $labels=['active'=>'Activo','waiting_dns'=>'Esperando DNS','pending'=>'Configurando','error'=>'Revisar conexión']; foreach ($inventory['items'] as $item) { ?>
<div class="u-mb20" style="border:1px solid var(--border-color,#ddd);border-radius:6px;padding:18px">
<h3 class="u-mb10"><?= tohtml($item['domain']) ?> · <?= tohtml($labels[$item['status']] ?? $item['status']) ?></h3>
<p><?= tohtml($item['target'].':'.$item['port'].' · '.($item['profile']==='large'?'Archivos grandes':'Normal')) ?></p>
<p class="u-mb10"><?= tohtml($item['message'] ?? 'Preparando configuración…') ?></p>
<?php if (!empty($item['dns_note'])) { ?><p class="u-mb10"><?= tohtml($item['dns_note']) ?></p><?php } ?>
<div class="toolbar-buttons"><a class="button button-secondary" href="/add/tunnel/?domain=<?= rawurlencode($item['domain']) ?>">Configurar</a>
<?php if ($item['status']==='active') { ?><a class="button button-secondary" target="_blank" rel="noopener" href="<?= tohtml(($item['https']?'https':'http').'://'.$item['domain']) ?>">Abrir</a><?php } ?>
<?php if ($read_only !== true) { ?><form method="post"><input type="hidden" name="token" value="<?= tohtml($_SESSION['token']) ?>"><input type="hidden" name="domain" value="<?= tohtml($item['domain']) ?>"><input type="hidden" name="action" value="retry"><button class="button button-secondary" onclick="this.form.elements.action.value='retry'">Comprobar ahora</button><button class="button button-secondary" onclick="if(!confirm('¿Eliminar este proxy? La app, sus archivos y el DNS se conservarán.'))return false;this.form.elements.action.value='delete';">Eliminar túnel</button></form><?php } ?>
</div></div><?php } ?>
</section></div>

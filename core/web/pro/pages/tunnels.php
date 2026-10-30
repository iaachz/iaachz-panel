<?php hp_page_header($editing ? __('Editar túnel') : __('Agregar túnel'), __('Publica tus apps por VPN, sin mover sus archivos.'), [__('Sitios web y dominios')=>"/pro/sites/"], ('<a class="hp-btn" href="/pro/tunnels/">' . __('Actualizar estado') . '</a>')); ?>
<div class="hp-card hp-mb"><div class="hp-card-body">
<form method="post" id="main-form" class="js-enable-inputs-on-submit" x-data="<?= tohtml(json_encode(['parent'=>$form['parent_domain']??'', 'sub'=>$form['subdomain']??'', 'full'=>$form['domain']])) ?>">
<input type="hidden" name="token" value="<?= tohtml($_SESSION['token']) ?>">
<input type="hidden" name="action" id="tunnel-action" value="save">
<div class="hp-form">

<div class="u-mb20"><label class="form-label" for="parent_domain">Dominio principal (opcional)</label>
<select class="hp-select" id="parent_domain" name="parent_domain" x-model="parent" <?= $editing?'disabled':'' ?>>
<option value=""><?= __e('Sin seleccionar · escribir dominio completo / DNS externo') ?></option>
<?php foreach (($inventory['domains'] ?? array_column($inventory['zones'],'domain')) as $parent) { ?><option value="<?= tohtml($parent) ?>" <?= $parent===($form['parent_domain']??'')?'selected':'' ?>><?= tohtml($parent) ?></option><?php } ?>
</select><p class="u-mt5"><?= __e('Elige un dominio disponible o deja esta opción vacía si el DNS está en otro servidor.') ?></p></div>
<div class="u-mb20" x-show="parent" x-cloak><label class="form-label" for="subdomain">Subdominio (opcional)</label>
<input class="hp-input" id="subdomain" name="subdomain" x-model="sub" value="<?= tohtml($form['subdomain']??'') ?>" placeholder="miapp" maxlength="200">
<p class="u-mt5"><?= __e('Escribe solo el nombre, por ejemplo miapp. Déjalo vacío para usar el dominio principal.') ?></p></div>
<div class="u-mb20" x-show="!parent"><label class="form-label" for="domain"><?= __e('Dominio completo o subdominio externo') ?></label>
<input class="hp-input" id="domain" name="domain" x-model="full" value="<?= tohtml($form['domain']) ?>" placeholder="app.otro-dominio.com" :required="!parent" maxlength="240" <?= $editing ? 'readonly' : '' ?>>
<p class="u-mt5"><?= __e('No necesita estar registrado como sitio web en Hestia. También puedes escribir aquí un dominio de este servidor.') ?></p>
</div>
<div class="u-mb20" x-show="parent || full" x-cloak><strong><?= __e('Dirección del túnel:') ?></strong> <span x-text="parent ? (sub ? sub + '.' : '') + parent : full"></span></div>
<div class="u-mb20" x-show="!parent" style="border-left:3px solid #438fff;padding:12px 16px;background:rgba(67,143,255,.06)">
<strong><?= __e('Si administras el DNS en otro servidor') ?></strong>
<p><?= __e('Crea allí un registro') ?> <strong>A</strong> <?= __e('del dominio o subdominio completo hacia') ?> <strong><?= tohtml($inventory['public_ip'] ?? hp_setting('public_ip', __('la IP pública del servidor'))) ?></strong><?= __e('. Después crea el túnel aquí. No tienes que trasladar la zona DNS ni agregar antes un dominio web en Hestia.') ?></p>
<p><?= __e('El asistente esperará a que el DNS público resuelva correctamente y activará HTTPS automáticamente.') ?></p>
</div>
<div class="u-mb20"><label class="form-label" for="target"><?= __e('Servidor conectado a la VPN') ?></label>
<select class="hp-select" id="target" name="target" required>
<?php foreach ($inventory['targets'] as $peer) { ?>
<option value="<?= tohtml($peer['ip']) ?>" <?= $peer['ip'] === $form['target'] ? 'selected' : '' ?>><?= tohtml($peer['name'].' · '.$peer['ip'].($peer['online'] ? __(' · conectado') : __(' · desconectado'))) ?></option>
<?php } ?></select></div>
<div class="u-mb20"><label class="form-label" for="port"><?= __e('Puerto HTTP de la app') ?></label>
<input class="hp-input" id="port" name="port" type="number" min="1" max="65535" value="<?= tohtml($form['port']) ?>" required>
<p class="u-mt5"><?= __e('Ejemplo: 7504. La app debe ser accesible desde la VPN.') ?></p></div>
<div class="u-mb20"><label class="form-label" for="profile"><?= __e('Tipo de uso') ?></label>
<select class="hp-select" id="profile" name="profile"><option value="standard" <?= $form['profile']==='standard'?'selected':'' ?>><?= __e('Normal · hasta 10 GB por petición') ?></option><option value="large" <?= $form['profile']==='large'?'selected':'' ?>><?= __e('Archivos grandes · sin límite de tamaño en el proxy') ?></option></select>
<p class="u-mt5"><?= __e('Ambos admiten WebSockets y transferencias continuas. Las cuotas de la app siguen aplicando.') ?></p></div>
<div class="form-check u-mb10"><input class="form-check-input" type="checkbox" id="https" name="https" <?= $form['https']?'checked':'' ?>><label for="https"><?= __e('Activar HTTPS y renovar el certificado automáticamente') ?></label></div>
<div class="form-check u-mb20" x-show="parent" x-cloak><input class="form-check-input" type="checkbox" id="auto_dns" name="auto_dns" :disabled="!parent" <?= $form['auto_dns']?'checked':'' ?>><label for="auto_dns"><?= __e('Crear el registro DNS si este servidor administra la zona') ?></label></div>
<p class="u-mb20"><?= __e('Si el DNS aún no está listo, el túnel quedará pendiente y reintentará HTTPS cada 15 minutos. Los registros existentes no se sobrescriben. Usa este dominio como URL pública en la app cuando corresponda.') ?></p>
<?php if ($read_only !== true) { ?><div class="hp-btn-group"><button class="hp-btn" type="submit" onclick="this.form.elements.action.value='probe'"><?= __e('Probar conexión') ?></button><button class="hp-btn hp-btn-primary" type="submit" onclick="this.form.elements.action.value='save'"><?= $editing?__('Guardar cambios'):__('Crear túnel') ?></button></div><?php } ?>
</div></form></div></div>
<section class="u-mt30 u-mb30"><h2 class="u-mb20"><?= __e('Túneles VPN') ?></h2>
<?php if (!$inventory['items']) { ?><p><?= __e('Aquí aparecerán los túneles creados con este asistente. Los proxies existentes se conservan sin cambios.') ?></p><?php } ?>
<?php $labels=['active'=>__('Activo'),'waiting_dns'=>__('Esperando DNS'),'pending'=>__('Configurando'),'error'=>__('Revisar conexión')]; foreach ($inventory['items'] as $item) { ?>
<div class="u-mb20" style="border:1px solid var(--border-color,#ddd);border-radius:6px;padding:18px">
<h3 class="u-mb10"><?= tohtml($item['domain']) ?> · <?= tohtml($labels[$item['status']] ?? $item['status']) ?></h3>
<p><?= tohtml($item['target'].':'.$item['port'].' · '.($item['profile']==='large'?__('Archivos grandes'):'Normal')) ?></p>
<p class="u-mb10"><?= tohtml($item['message'] ?? __('Preparando configuración…')) ?></p>
<?php if (!empty($item['dns_note'])) { ?><p class="u-mb10"><?= tohtml($item['dns_note']) ?></p><?php } ?>
<div class="hp-btn-group"><a class="hp-btn" href="/pro/tunnels/?domain=<?= rawurlencode($item['domain']) ?>"><?= __e('Configurar') ?></a>
<?php if ($item['status']==='active') { ?><a class="hp-btn" target="_blank" rel="noopener" href="<?= tohtml(($item['https']?'https':'http').'://'.$item['domain']) ?>"><?= __e('Abrir') ?></a><?php } ?>
<?php if ($read_only !== true) { ?><form method="post"><input type="hidden" name="token" value="<?= tohtml($_SESSION['token']) ?>"><input type="hidden" name="domain" value="<?= tohtml($item['domain']) ?>"><input type="hidden" name="action" value="retry"><button class="hp-btn" onclick="this.form.elements.action.value='retry'"><?= __e('Comprobar ahora') ?></button><button class="hp-btn" onclick="if(!confirm('¿Eliminar este proxy? La app, sus archivos y el DNS se conservarán.'))return false;this.form.elements.action.value='delete';"><?= __e('Eliminar túnel') ?></button></form><?php } ?>
</div></div><?php } ?>
</section>
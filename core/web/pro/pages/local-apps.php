<?php hp_page_header(__('Webs y aplicaciones sin dominio'), __('Publica por IP y puerto, sin configurar DNS ni comprar un dominio.')); ?>
<div class="hp-card hp-mb"><div class="hp-card-body">
<form id="np-local-form" class="hp-form">
<div class="hp-field"><label><?= __e('Nombre') ?></label><input class="hp-input" name="name" required maxlength="80" placeholder="<?= __e('Mi aplicación') ?>"></div>
<div class="hp-field"><label><?= __e('Tipo') ?></label><select class="hp-select" name="type"><option value="php"><?= __e('Web PHP') ?></option><option value="static"><?= __e('Web estática') ?></option><option value="node">Node.js</option><option value="python">Python</option><option value="docker"><?= __e('Contenedor Docker') ?></option></select></div>
<div class="hp-field"><label><?= __e('Acceso') ?></label><select class="hp-select" name="scope"><option value="local"><?= __e('Solo en el servidor — 127.0.0.1') ?></option><option value="private"><?= __e('IP privada o VPN del servidor') ?></option></select></div>
<div class="hp-field"><label><?= __e('Puerto') ?></label><input class="hp-input" name="port" type="number" min="1024" max="65535" value="9000" required></div>
<div class="hp-field" id="np-node-version" hidden><label>Node.js</label><select class="hp-select" name="version"><option value="22">22</option><option value="24">24</option><option value="26">26</option><option value="20">20</option></select></div>
<div id="np-docker-fields" hidden><div class="hp-field"><label><?= __e('Imagen Docker') ?></label><input class="hp-input" name="image" placeholder="nginx:alpine"></div><div class="hp-field"><label><?= __e('Puerto del contenedor') ?></label><input class="hp-input" name="container_port" type="number" min="1" max="65535" value="80"></div></div>
<p class="hp-hint"><?= __e('Se crea un proyecto de ejemplo listo para editar. En modo local, la dirección pertenece al servidor; para abrirla desde otro equipo elige la IP privada/VPN. Docker debe estar instalado antes de crear un contenedor.') ?></p>
<button class="hp-btn hp-btn-primary" type="submit"><?= __e('Crear y levantar') ?></button>
</form><p id="np-local-message" role="status"></p></div></div>
<div class="hp-card"><div class="hp-card-body"><div id="np-local-list"><?= __e('Cargando…') ?></div></div></div>
<dialog id="np-local-logs" style="width:min(1000px,90vw);max-height:80vh"><button id="np-local-log-close" class="hp-btn"><?= __e('Cerrar') ?></button><pre id="np-local-log-text" style="white-space:pre-wrap;overflow-wrap:anywhere"></pre></dialog>
<script src="/pro/assets/local-apps.js"></script>

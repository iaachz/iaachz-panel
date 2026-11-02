<?php // Node.js toolkit: a compact overview with diagnostics before activation. ?>
<div class="hp-node-toolkit" x-show="form.type === 'node'">
    <div class="hp-node-heading">
        <div class="hp-flex"><span class="hp-chip hp-chip-node">JS</span><div><h2>Node.js</h2><p class="hp-muted"><?= __e('Tu aplicación, su entorno y sus registros en un solo lugar.') ?></p></div></div>
        <span class="hp-badge" :class="app?.type === 'node' ? stateClass(app.state) : 'hp-badge-muted'" x-text="app?.type === 'node' ? stateLabel(app.state) : HP.t('Sin activar')"></span>
    </div>
    <div class="hp-tabs" role="tablist" aria-label="Node.js">
        <button type="button" class="hp-tab" role="tab" :aria-selected="toolTab === 'panel'" :class="toolTab === 'panel' && 'active'" x-on:click="toolTab = 'panel'"><?= __e('Panel') ?></button>
        <button type="button" class="hp-tab" role="tab" :aria-selected="toolTab === 'environment'" :class="toolTab === 'environment' && 'active'" x-on:click="toolTab = 'environment'"><?= __e('Variables de entorno') ?> <span class="hp-badge hp-badge-muted" x-text="env.length"></span></button>
        <button type="button" class="hp-tab" role="tab" :aria-selected="toolTab === 'commands'" :class="toolTab === 'commands' && 'active'" x-on:click="toolTab = 'commands'"><?= __e('Comandos npm') ?></button>
    </div>
    <div class="hp-node-toolbar">
        <button type="button" class="hp-btn hp-btn-primary" x-show="!app?.type" :disabled="busy || inspecting || inspection?.blocking || !inspection?.found" x-on:click="activateNode()"><i class="fas" :class="busy ? 'fa-circle-notch fa-spin' : 'fa-play'"></i><?= __e('Activar Node.js') ?></button>
        <button type="button" class="hp-btn" :disabled="busy || inspecting" x-on:click="toolTab = 'panel'; inspectProject(true, inspectionRoot || '')"><i class="fas" :class="inspecting ? 'fa-circle-notch fa-spin' : 'fa-wand-magic-sparkles'"></i><?= __e('Configurar automáticamente') ?></button>
        <a class="hp-btn" href="<?= $native('/pro/transfers/?' . http_build_query(['open' => '/web/' . $cfg['domain']])) ?>"><i class="fas fa-folder-open"></i><?= __e('Archivos') ?></a>
        <button type="button" class="hp-btn" x-show="app?.type === 'node'" :disabled="busy" x-on:click="logs()"><i class="fas fa-file-lines"></i><?= __e('Registros') ?></button>
    </div>
    <div x-show="toolTab === 'panel'">
        <div class="hp-card hp-mb">
            <div class="hp-card-head hp-between hp-wrap"><h3 class="hp-card-title"><?= __e('Resumen de la aplicación') ?></h3><button type="button" class="hp-btn hp-btn-sm" x-on:click="showSettings = !showSettings" :aria-expanded="showSettings"><i class="fas fa-pen"></i><span x-text="showSettings ? HP.t('Ocultar configuración') : HP.t('Editar configuración')"></span></button></div>
            <dl class="hp-node-summary">
                <dt><?= __e('Tipo de aplicación') ?></dt><dd x-text="inspection?.framework || 'Node.js'"></dd>
                <dt><?= __e('Versión de Node.js') ?></dt><dd x-text="runtimes.node.installed[form.version] || HP.t('Selecciona una versión')"></dd>
                <dt><?= __e('Administrador de paquetes') ?></dt><dd>npm <small class="hp-muted" x-text="inspection?.lock ? ' · package-lock.json' : ''"></small></dd>
                <dt><?= __e('URL de la aplicación') ?></dt><dd><a :href="'https://' + domain" target="_blank" rel="noopener noreferrer" x-text="'https://' + domain"></a></dd>
                <dt><?= __e('Raíz de la aplicación') ?></dt><dd><code x-text="'~/web/' + domain + '/' + form.root"></code></dd>
                <dt><?= __e('Inicio de la aplicación') ?></dt><dd><code x-text="form.start_mode === 'npm' ? 'npm run ' + form.startup : form.start_mode === 'file' ? 'node ' + form.startup : form.startup"></code></dd>
                <dt><?= __e('Modo de aplicación') ?></dt><dd x-text="form.mode === 'production' ? HP.t('Producción') : HP.t('Desarrollo')"></dd>
                <dt><?= __e('Puerto y dominio') ?></dt><dd><?= __e('Configurados automáticamente al activar') ?><small class="hp-muted hp-node-note"><?= __e('El dominio se conectará a Node.js. No necesitas escribir un puerto ni cambiar la plantilla web.') ?></small></dd>
            </dl>
        </div>
        <div class="hp-card hp-mb" aria-live="polite">
            <div class="hp-card-head hp-between hp-wrap"><h3 class="hp-card-title"><i class="fas fa-list-check"></i><?= __e('Revisión antes de activar') ?></h3><button type="button" class="hp-btn hp-btn-sm" :disabled="inspecting || busy" x-on:click="inspectProject(false, form.root)"><i class="fas fa-arrows-rotate"></i><?= __e('Revisar') ?></button></div>
            <div class="hp-card-body hp-form">
                <p class="hp-muted" x-show="inspecting"><i class="fas fa-circle-notch fa-spin"></i> <?= __e('Revisando el proyecto…') ?></p>
                <p class="inline-alert inline-alert-danger" x-show="inspectError" x-text="inspectError"></p>
                <template x-if="inspection && !inspection.found"><p class="hp-muted"><?= __e('Sube tu proyecto o selecciona su carpeta para detectar la configuración. No se modifica ningún archivo durante la revisión.') ?></p></template>
                <template x-if="inspection && inspection.candidates.length > 1">
                    <div class="hp-field"><label><?= __e('Proyectos encontrados') ?></label><select class="hp-select" x-model="inspectionRoot" x-on:change="inspectProject(true, inspectionRoot)"><option value=""><?= __e('Elige la carpeta del proyecto') ?></option><template x-for="root in inspection.candidates" :key="root"><option :value="root" x-text="root"></option></template></select></div>
                </template>
                <div class="hp-node-checks"><template x-for="(check, i) in (inspection?.checks || [])" :key="i"><div class="hp-node-check"><span class="hp-badge" :class="{'ok':'hp-badge-ok','error':'hp-badge-danger','warning':'hp-badge-warn','pending':'hp-badge-info'}[check.state]" x-text="checkLabel(check.state)"></span><span x-text="check.text"></span></div></template></div>
                <p class="hp-hint" x-show="inspection?.blocking"><?= __e('La activación está pendiente hasta resolver los puntos marcados. Los archivos del proyecto se conservan.') ?></p>
            </div>
        </div>
    </div>
    <div class="hp-card hp-mb" x-show="toolTab === 'commands'">
        <div class="hp-card-head"><h3 class="hp-card-title"><?= __e('Comandos de package.json') ?></h3></div>
        <div class="hp-card-body hp-form">
            <p class="hp-hint"><?= __e('Los comandos se ejecutan como el usuario de la aplicación y muestran su progreso. Usa Iniciar o Reiniciar para mantener el servidor en marcha.') ?></p>
            <button type="button" class="hp-btn" :disabled="busy || !app?.type" x-on:click="deps()"><i class="fas fa-download"></i><?= __e('Instalar dependencias') ?></button>
            <template x-for="(cmd, name) in scripts" :key="name"><div class="hp-node-script"><div><b x-text="'npm run ' + name"></b><code class="hp-muted hp-node-note" x-text="cmd"></code></div><button type="button" class="hp-btn hp-btn-sm" :disabled="busy || !app?.type || ['start','dev','serve','preview','start:prod'].includes(name)" x-on:click="runScript(name)"><?= __e('Ejecutar') ?></button></div></template>
            <p class="hp-hint" x-show="!app?.type"><?= __e('Activa primero la aplicación para ejecutar sus comandos desde el panel.') ?></p>
        </div>
    </div>
</div>

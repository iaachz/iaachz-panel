<?php
if (!function_exists('hp_admin') || !hp_admin()) { return; }
$tunnelRows = [];
exec(HESTIA_CMD . __('v-tunnel list ') . \Hestiacp\quoteshellarg\quoteshellarg(hp_me()), $tunnelLines, $tunnelRc);
$tunnelData = json_decode(implode("\n",$tunnelLines),true);
if (!empty($tunnelData['ok'])) $tunnelRows = $tunnelData['result']['items'];
?>
<section class="hp-card hp-mt">
<div class="hp-card-head"><h2><?= __e('Túneles VPN') ?> <span class="hp-badge"><?= count($tunnelRows) ?></span></h2><a class="hp-btn" href="/pro/tunnels/"><i class="fas fa-network-wired"></i><?= __e('Gestionar túneles') ?></a></div>
<?php if (!$tunnelRows) { ?><div class="hp-card-body"><p><?= __e('Conecta una app de tu servidor VPN a un dominio sin mover sus archivos.') ?></p></div><?php } ?>
<?php foreach($tunnelRows as $tunnel) { ?>
<div class="hp-domain"><div class="hp-domain-row"><span class="hp-type-chip"><i class="fas fa-network-wired"></i></span><div><a class="hp-domain-name" href="/pro/tunnels/?domain=<?= rawurlencode($tunnel['domain']) ?>"><?= e($tunnel['domain']) ?></a><div class="hp-domain-meta"><span class="hp-tag"><?= e($tunnel['target'].':'.$tunnel['port']) ?></span><span class="hp-badge hp-badge-<?= $tunnel['status']==='active'?'ok':'warn' ?>"><?= e(['active'=>__('Activo'),'waiting_dns'=>__('Esperando DNS'),'pending'=>__('Configurando'),'error'=>__('Revisar conexión')][$tunnel['status']] ?? $tunnel['status']) ?></span></div></div><a class="hp-btn hp-btn-sm" href="/pro/tunnels/?domain=<?= rawurlencode($tunnel['domain']) ?>"><?= __e('Gestionar') ?></a></div></div>
<?php } ?>
</section>

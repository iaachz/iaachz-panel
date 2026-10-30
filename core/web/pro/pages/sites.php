<?php
// domain-groups:v1 — presentation only; proxy and hosting configuration are not modified.
if (!function_exists('hp_admin')) { http_response_code(403); exit; }
$sites = array_values(array_filter($sites, function($site) {
    foreach (hp_setting_list('hidden_domains') as $hidden) {
        if ($site['domain']===$hidden || str_ends_with($site['domain'],'.'.$hidden)) return false;
    }
    return true;
}));
$actions = ('<a class="hp-btn" href="/add/subdomain/">' . '<i class="fas fa-diagram-project">' . '</i>' . __('Agregar subdominio') . '</a>')
    . ('<a class="hp-btn hp-btn-primary" href="/pro/new/">' . '<i class="fas fa-plus">' . '</i>' . __('Agregar dominio') . '</a>');
// tunnel-pro:inicio
if (hp_admin()) $actions .= '<a class="hp-btn" href="/pro/tunnels/"><i class="fas fa-network-wired"></i>'.__('Agregar túnel').'</a>';
// tunnel-pro:fin
$inventory = ['items'=>[], 'existing'=>[], 'zones'=>[]];
if (hp_admin()) {
    exec(HESTIA_CMD . __('v-tunnel list ') . \Hestiacp\quoteshellarg\quoteshellarg(hp_me()), $lines, $rc);
    $result = json_decode(implode("\n",$lines),true);
    if (!empty($result['ok'])) $inventory = $result['result'];
}
$entries = [];
foreach ($sites as $site) $entries[$site['domain'].'|'.$site['user']] = $site + ['_kind'=>'hosting'];
foreach (array_merge(array_map(fn($x)=>$x+['_kind'=>'existing'],$inventory['existing'] ?? []),array_map(fn($x)=>$x+['_kind'=>'tunnel'],$inventory['items'] ?? [])) as $tunnel) {
    $owner = $tunnel['owner'] ?? hp_me();
    $key = $tunnel['domain'].'|'.$owner;
    if (isset($entries[$key]) && $entries[$key]['hosting'] !== 'none') continue;
    $mail = $entries[$key] ?? [];
    $entries[$key] = $mail + ['user'=>$owner,'mail'=>false,'mail_accounts'=>0,'dns'=>false,'disk'=>0,'suspended'=>false];
    $entries[$key] = array_merge($entries[$key],$tunnel,['hosting'=>'vpn','ssl'=>$tunnel['https'] && ($tunnel['_kind']==='existing' || $tunnel['status']==='active'),'_kind'=>$tunnel['_kind']]);
}
$roots = array_column($inventory['zones'] ?? [],'domain');
foreach ($sites as $site) if (!empty($site['dns']) || !empty($site['mail'])) $roots[]=$site['domain'];
usort($roots,fn($a,$b)=>strlen($a)<=>strlen($b));
$rootOf = function($domain) use ($roots) {
    foreach ($roots as $root) if ($domain===$root || str_ends_with($domain,'.'.$root)) return $root;
    if (str_ends_with($domain,'.local')) return __('Dominios locales');
    foreach (hp_setting_list('standalone_suffixes') as $suffix) if (str_ends_with($domain,$suffix)) return $domain;
    $parts=explode('.',$domain);$suffix=implode('.',array_slice($parts,-2));
    $compound=['com.pe','org.pe','net.pe','edu.pe','gob.pe','co.uk','org.uk','com.mx','com.ar','com.co','com.br','com.es','com.au','co.nz'];
    return implode('.',array_slice($parts,in_array($suffix,$compound,true)?-3:-2));
};
$groups=[];$counts=[];
foreach ($entries as $site) {
    $type=$site['hosting']==='vpn'?'vpn':($site['app']['type'] ?? (($site['cms'] ?? '')==='WordPress'?'wordpress':$site['hosting']));
    $site['_type']=$type;
    $vpnSearch=$type==='vpn' ? __('vpn túnel ').(hp_target_label((string)$site['target'])!==''?hp_target_label((string)$site['target']).' ':'').$site['target'].':'.$site['port'] : '';
    $site['_search']=strtolower($site['domain'].' '.$site['user'].' '.($type==='vpn'?$vpnSearch:hp_hosting_meta($type)[0]).' '.($site['cms']??'').' '.implode(' ',$site['aliases']??[]));
    $groups[$rootOf($site['domain'])][]=$site;$counts[$type]=($counts[$type]??0)+1;
}
uksort($groups,'strnatcasecmp');
hp_page_header(__('Sitios web y dominios'),__('%s grupos de dominios · %s sitios y servicios',count($groups),count($entries)),[],$actions);
?>
<style>
.hp-domain-groups{display:grid;gap:12px}.hp-domain-group{overflow:hidden}.hp-domain-group>summary{list-style:none;display:flex;align-items:center;gap:16px;padding:20px 22px;cursor:pointer;user-select:none}.hp-domain-group>summary::-webkit-details-marker{display:none}.hp-domain-group>summary:hover{background:rgba(127,143,170,.06)}.hp-domain-group>summary:focus-visible{outline:2px solid #438fff;outline-offset:-3px}.hp-group-icon{width:40px;height:40px;flex:none;border-radius:11px;display:grid;place-items:center;background:rgba(55,133,255,.12);color:#75adff;font-size:18px}.hp-group-name{font-size:17px;font-weight:650;line-height:1.5;overflow-wrap:anywhere}.hp-group-subtitle{font-size:12px;color:var(--hp-muted,#9aa8ba);margin-top:3px;line-height:1.6}.hp-group-main{flex:1;min-width:0}.hp-group-status{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}.hp-group-chevron{color:#8f9aad;transition:transform .15s;font-size:12px}.hp-domain-group[open]>summary .hp-group-chevron{transform:rotate(90deg)}.hp-group-body{border-top:1px solid rgba(127,143,170,.17);background:rgba(0,0,0,.07)}.hp-group-body>.hp-domain:last-child{border-bottom:0}.hp-group-body .hp-domain-row{padding-left:26px}.hp-group-body .hp-domain-name{font-size:14px}.hp-group-body .hp-domain-tools{padding-left:26px}.hp-group-foot{padding:10px 22px;font-size:12px;color:var(--hp-muted,#9aa8ba);border-top:1px solid rgba(127,143,170,.12)}.hp-group-empty{padding:24px;text-align:center;color:#9aa8ba}
@media(max-width:720px){.hp-domain-group>summary{padding:15px 14px;gap:10px;flex-wrap:wrap}.hp-group-icon{width:32px;height:32px}.hp-group-status{margin-left:42px;justify-content:flex-start}.hp-group-main{min-width:calc(100% - 64px)}.hp-group-body .hp-domain-row{padding-left:14px}.hp-group-chevron{margin-left:auto}}
</style>
<div x-data="{q:'',type:'',open:{}}">
<div class="hp-card hp-mb"><div class="hp-card-body hp-between hp-wrap">
<div class="hp-search" style="max-width:420px"><i class="fas fa-magnifying-glass"></i><input type="search" x-model="q" placeholder="<?= __e('Buscar dominio, sitio o tecnología…') ?>" aria-label="<?= __e('Buscar sitios y dominios') ?>"></div>
<div class="hp-segmented"><button type="button" :class="type==='' && 'active'" x-on:click="type=''"><?= __e('Todos %s', count($entries)) ?></button>
<?php foreach($counts as $type=>$count) { ?><button type="button" :class="type==='<?= e($type) ?>' && 'active'" x-on:click="type='<?= e($type) ?>'"><?= e($type==='vpn'?__('Túneles'):hp_hosting_meta($type)[0]) ?> <?= $count ?></button><?php } ?></div>
</div></div>
<div class="hp-domain-groups">
<?php $i=0;foreach($groups as $root=>$children) {
    usort($children,fn($a,$b)=>[$a['domain']!==$root,$a['domain'],$a['user']]<=>[$b['domain']!==$root,$b['domain'],$b['user']]);
    $web=0;$vpn=0;$tls=0;$mail=0;$disk=0;$attention=0;$search=[];
    foreach($children as $child) {
        $web+=($child['hosting']!=='none' && $child['hosting']!=='vpn');$vpn+=($child['hosting']==='vpn');$tls+=($child['hosting']!=='none' && $child['ssl']);$mail+=(int)($child['mail_accounts']??0);$disk+=(int)($child['disk']??0);
        $attention+=!empty($child['suspended']) || (($child['status']??'')==='error');
        $search[]=['q'=>$child['_search'],'t'=>$child['_type']];
    }
    $summary=[];if($web)$summary[]=sprintf($web===1?__('%s sitio'):__('%s sitios'),$web);if($vpn)$summary[]=sprintf($vpn===1?__('%s túnel'):__('%s túneles'),$vpn);if($mail)$summary[]=sprintf($mail===1?__('%s buzón'):__('%s buzones'),$mail);if(!$web&&!$vpn)$summary[]=__('DNS y correo');if($disk)$summary[]=hp_mb($disk);
?>
<details class="hp-card hp-domain-group" :open="q!=='' || type!=='' ? true : null" x-show="<?= e(json_encode($search)) ?>.some(s=>(!q || s.q.includes(q.toLowerCase())) && (!type || s.t===type))">
<summary><span class="hp-group-icon"><i class="fas fa-globe"></i></span><div class="hp-group-main"><div class="hp-group-name"><?= e($root) ?></div><div class="hp-group-subtitle"><?= e(implode(' · ',$summary)) ?></div></div>
<div class="hp-group-status"><?php if($tls){ ?><span class="hp-badge hp-badge-ok"><i class="fas fa-lock"></i><?= $tls ?> HTTPS</span><?php } ?><?php if($attention){ ?><span class="hp-badge hp-badge-warn"><?= __e('%s por revisar', $attention) ?></span><?php } ?></div><i class="fas fa-chevron-right hp-group-chevron"></i></summary>
<div class="hp-group-body">
<?php foreach($children as $s) { if($s['hosting']==='vpn') { ?>
<div class="hp-domain" x-show="(!q || <?= e(json_encode($s['_search'])) ?>.includes(q.toLowerCase())) && (!type || type==='vpn')">
<div class="hp-domain-row"><span class="hp-type-chip"><i class="fas fa-network-wired"></i></span><div><span class="hp-domain-name"><?= e($s['domain']) ?></span><div class="hp-domain-meta"><span class="hp-tag"><?= __e('Túnel VPN') ?></span><span class="hp-tag"><?= e($s['user']) ?></span><span class="hp-tag"><?= e($s['target'].':'.$s['port']) ?></span><span class="hp-badge hp-badge-<?= $s['ssl']?'ok':'warn' ?>"><?= $s['ssl']?'HTTPS':'HTTP' ?></span><?php if(!empty($s['mail'])){ ?><span class="hp-tag"><?= (int)$s['mail_accounts'] ?> <?= (int)$s['mail_accounts']===1?__('buzón'):'buzones' ?></span><?php } ?></div></div>
<div class="hp-btn-group"><a class="hp-btn hp-btn-sm" href="<?= e(($s['ssl']?'https':'http').'://'.$s['domain'].'/') ?>" target="_blank" rel="noopener" title="Abrir <?= e($s['domain']) ?>"><i class="fas fa-arrow-up-right-from-square"></i></a><button type="button" class="hp-btn hp-btn-sm" x-on:click="open[<?= $i ?>]=!open[<?= $i ?>]" :aria-expanded="!!open[<?= $i ?>]"><?= __e('Detalles') ?></button><?php if($s['_kind']==='tunnel'){ ?><a class="hp-btn hp-btn-sm hp-btn-primary" href="/pro/tunnels/?domain=<?= rawurlencode($s['domain']) ?>"><?= __e('Gestionar') ?></a><?php } ?></div></div>
<div class="hp-domain-tools" x-show="open[<?= $i ?>]" x-collapse x-cloak><p><strong><?= __e('Destino:') ?></strong> <?= __e('%s por VPN.', $s['target'].':'.$s['port']) ?></p><p><?= e($s['_kind']==='existing'?__('Proxy existente. La aplicación permanece en su servidor de origen.'):($s['message']??__('Túnel administrado con el asistente.'))) ?></p><?php if($s['_kind']==='tunnel'){ ?><p><strong><?= __e('Perfil:') ?></strong> <?= $s['profile']==='large'?__('Archivos grandes'):__('Normal') ?></p><?php } ?></div>
</div>
<?php } else { include __DIR__.'/sites-hosting-row.php'; } ++$i; } ?>
</div></details>
<?php } ?>
<?php if(!$groups){ ?><div class="hp-card hp-group-empty"><?= __e('Todavía no hay sitios o dominios. Usa Agregar dominio o Agregar túnel para empezar.') ?></div><?php } ?>
</div></div>

'use strict';
(() => {
  const form=document.getElementById('np-local-form'), list=document.getElementById('np-local-list'), message=document.getElementById('np-local-message');
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  async function api(action,params={}) {
    const r=await fetch('/pro/local-apps/api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,params,token:window.HP_TOKEN})});
    const data=await r.json();if(!r.ok||data.error)throw Error(data.error||HP.t('No se pudo completar la acción'));return data;
  }
  async function refresh(){
    try{const data=await api('list');list.innerHTML=data.items.length?data.items.map(x=>`<section class="hp-mb" style="padding:16px;border-bottom:1px solid #dce3eb"><strong>${esc(x.name)}</strong> <span class="hp-chip">${esc(x.type)}</span><p>${x.local_only?`<code>${esc(x.url)}</code> · ${HP.t('local al servidor')}`:`<a href="${esc(x.url)}" target="_blank" rel="noopener">${esc(x.url)}</a>`}</p><p>${esc(x.message)}${x.path?` · <code>${esc(x.path)}</code>`:''}</p><div class="hp-btn-group">${x.files_url?`<a class="hp-btn hp-btn-xs" href="${esc(x.files_url)}">${HP.t('Archivos')}</a>`:''}${['start','stop','restart','logs','delete'].map((a,i)=>`<button class="hp-btn hp-btn-xs" data-id="${x.id}" data-action="${a}" ${x.status==='preparing'?'disabled':''}>${[HP.t('Iniciar'),HP.t('Detener'),HP.t('Reiniciar'),HP.t('Registros'),HP.t('Eliminar')][i]}</button>`).join('')}</div></section>`).join(''):HP.t('No hay webs o aplicaciones por puerto.');}
    catch(error){message.textContent=error.message;}
  }
  form.elements.type.onchange=()=>{document.getElementById('np-node-version').hidden=form.elements.type.value!=='node';document.getElementById('np-docker-fields').hidden=form.elements.type.value!=='docker';};
  form.onsubmit=async event=>{event.preventDefault();const button=form.querySelector('button[type=submit]');button.disabled=true;try{const f=Object.fromEntries(new FormData(form));f.port=Number(f.port);f.container_port=Number(f.container_port);if(f.type==='python')f.version='system';await api('create',f);message.textContent=HP.t('Preparando el proyecto. El estado se actualizará aquí.');await refresh();}catch(error){message.textContent=error.message;}finally{button.disabled=false;}};
  list.onclick=async event=>{const button=event.target.closest('button[data-action]');if(!button)return;const {action,id}=button.dataset;if(action==='delete'&&!confirm(HP.t('¿Eliminar esta aplicación y sus archivos?')))return;button.disabled=true;try{const data=await api(action,{id});if(action==='logs'){document.getElementById('np-local-log-text').textContent=data.log;document.getElementById('np-local-logs').showModal();}await refresh();}catch(error){message.textContent=error.message;}finally{button.disabled=false;}};
  document.getElementById('np-local-log-close').onclick=()=>document.getElementById('np-local-logs').close();
  refresh();setInterval(()=>{if(!document.hidden)refresh();},3000);
})();

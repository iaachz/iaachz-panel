'use strict';
const API_PREFIX=document.body.dataset.apiPrefix||'/api/';
const INTEGRATED=document.body.dataset.integrated==='1';
const HESTIA_CSRF=document.body.dataset.hestiaCsrf||'';
const $ = (s, root = document) => root.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const newPane = connection => ({connection, path:'/', entries:[], selected:new Set(), anchor:null, loading:false, version:0, filter:'', sort:{key:'name', dir:1}, error:''});
const panes = {left:newPane('local'), right:newPane('')};
let connections = [], currentJobs = [], toastTimer, loggedIn = false, activeSide = 'left', clipboard = null, uploadSide = 'left', menuSide = null;
let localRootLabel = 'Carpeta de archivos del servicio';
const autoDownload = new Set();
panes.left.path=new URL(location.href).searchParams.get('open')||'/';
const other = side => side === 'left' ? 'right' : 'left';
const sideName = side => side === 'left' ? 'izquierdo' : 'derecho';
const sizes = n => {if(n == null) return '—'; if(n < 1024) return n+' B'; const i=Math.min(Math.floor(Math.log(n)/Math.log(1024)),4); return (n/1024**i).toFixed(i>1?1:0)+' '+['B','KB','MB','GB','TB'][i];};
const join = (path,name) => (path==='/'?'':path.replace(/\/$/,''))+'/'+name;
const parent = path => path.replace(/\/$/,'').split('/').slice(0,-1).join('/') || '/';
const baseName = path => path.replace(/\/$/,'').split('/').pop() || '/';
const isZip = e => e.kind==='file' && /\.zip$/i.test(e.name);
const plural = (n, one, many) => n===1 ? one : n+' '+many;
const glyph = path => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${path}</svg>`;
const folderGlyph = glyph('<path d="M3 7a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v10H3z" fill="currentColor" fill-opacity=".13"/>');
const fileGlyph = glyph('<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v5h5M9 12h6M9 16h5"/>');
const icon = e => e.kind==='directory' ? folderGlyph : isZip(e) ? glyph('<path d="M6 3h12v18H6zM12 3v10m-2 2h4v3h-4z"/>') : e.kind!=='file' ? '↗' : ({image:'▨',video:'▶',audio:'♪'})[mediaKind(e)] || fileGlyph;

function notify(text,error=false,ms){clearTimeout(toastTimer);const el=$('#toast');el.textContent=text;el.classList.toggle('error',error);el.hidden=false;toastTimer=setTimeout(()=>el.hidden=true,ms||(error?8000:4000));}
function ask(message,{title='Confirmar',input=false,value='',accept='Continuar',alt=null,danger=false}={}){
  return new Promise(resolve=>{
    const dialog=$('#action-dialog'),field=$('#action-input'),altButton=$('#action-alt'),acceptButton=$('#action-accept');
    $('#action-title').textContent=title;$('#action-message').textContent=message;
    $('#action-input-label').hidden=!input;field.required=input;field.value=value;
    acceptButton.textContent=accept;acceptButton.classList.toggle('danger',danger);
    altButton.hidden=!alt;altButton.textContent=alt||'';
    let result=null;
    $('#action-form').onsubmit=e=>{e.preventDefault();result=input?field.value.trim():true;dialog.close();};
    altButton.onclick=()=>{result='alt';dialog.close();};
    $('#action-cancel').onclick=()=>dialog.close();dialog.onclose=()=>resolve(result);dialog.showModal();
    if(input){field.focus();const dot=value.lastIndexOf('.');field.setSelectionRange(0,dot>0?dot:value.length);}
  });
}
async function api(path,method='GET',body){
  const options={method,headers:{'X-App-Request':'lftp-web','X-Hestia-CSRF':HESTIA_CSRF}};
  if(body instanceof FormData) options.body=body;
  else if(body!==undefined){options.headers['Content-Type']='application/json';options.body=JSON.stringify(body);}
  const r=await fetch(API_PREFIX+''+path,options);
  let data; try{data=await r.json();}catch{throw Error('No se pudo leer la respuesta del servicio.');}
  if(!r.ok){if(INTEGRATED&&(r.status===401||r.status===403)){window.top.location.href='/login/';throw Error('La sesión de Hestia ha caducado.');}if(r.status===401&&path!=='login'){loggedIn=false;if(!$('#login-dialog').open)$('#login-dialog').showModal();}throw Error(data.error||'No se pudo completar la operación.');}
  return data;
}
function title(cid){return cid==='local'?'Archivos de Hestia':connections.find(c=>c.id===cid)?.name||'Servidor remoto';}
function view(p){
  const f=p.filter.toLowerCase(),{key,dir}=p.sort;
  const compare=(a,b)=>key==='size'?(a.size||0)-(b.size||0):key==='modified'?(a.modified||0)-(b.modified||0):a.name.localeCompare(b.name,'es',{numeric:true,sensitivity:'base'});
  return p.entries.filter(e=>!f||e.name.toLowerCase().includes(f))
    .sort((a,b)=>(a.kind==='directory')!==(b.kind==='directory')?(a.kind==='directory'?-1:1):dir*compare(a,b));
}
const selectedEntries = side => view(panes[side]).filter(e=>panes[side].selected.has(e.name));
const isCut = (side,name) => clipboard?.mode==='cut' && clipboard.connection===panes[side].connection && clipboard.path===panes[side].path && clipboard.names.includes(name);

function renderPane(side){
  const p=panes[side],el=$('#pane-'+side),c=connections.find(c=>c.id===p.connection),local=p.connection==='local',rows=view(p),sel=selectedEntries(side);
  const focused=el.contains(document.activeElement)?document.activeElement:null,filterFocused=focused?.classList.contains('filter-input');
  const scroll=$('.file-area',el)?.scrollTop||0,none=!p.connection?'disabled':'';
  const sortMark=k=>p.sort.key===k?(p.sort.dir>0?' ▲':' ▼'):'';
  el.classList.toggle('active',activeSide===side);
  el.innerHTML=`<div class="pane-head"><div class="location-icon">${local?'▣':'⌁'}</div><div><select class="location-select" aria-label="Ubicación del panel ${sideName(side)}"><option value="" ${!p.connection?'selected':''}>Selecciona una conexión</option><option value="local" ${local?'selected':''}>Archivos de Hestia</option>${connections.map(c=>`<option value="${esc(c.id)}" ${c.id===p.connection?'selected':''}>${esc(c.name)}</option>`).join('')}</select><div class="subtitle">${local?esc(localRootLabel):c?esc(c.username+'@'+c.host):'Conecta un servidor para explorar sus archivos'}</div></div><span class="badge">${local?'LOCAL':c?esc(c.protocol.toUpperCase()):'REMOTO'}</span></div>
  <form class="path-bar"><button type="button" data-action="up" title="Carpeta superior (Retroceso)" aria-label="Carpeta superior" ${none}>↑</button><input class="path-input" aria-label="Ruta del panel ${sideName(side)}" value="${esc(p.path)}" ${none}><button type="submit" title="Ir a la ruta" aria-label="Ir a la ruta" ${none}>↵</button><button type="button" data-action="refresh" title="Actualizar (F5)" aria-label="Actualizar" ${none}>↻</button><input class="filter-input" type="search" placeholder="Filtrar…" aria-label="Filtrar archivos" value="${esc(p.filter)}" ${none}></form>
  <div class="file-area" data-drop="pane">${p.loading?'<div class="empty-pane"><strong>Cargando archivos…</strong></div>':!p.connection?'<div class="empty-pane">⌁<strong>Tu siguiente destino empieza aquí</strong><span>Añade una conexión SFTP, FTPS o FTP, o elige otra ubicación en el selector.</span><button class="primary" data-action="connect">＋ Conectar servidor</button></div>':p.error?`<div class="empty-pane"><strong>No se pudo abrir esta carpeta</strong><span>${esc(p.error)}</span><button data-action="refresh">Reintentar</button></div>`:`<table class="file-table"><thead><tr><th data-sort="name">NOMBRE${sortMark('name')}</th><th data-sort="size">TAMAÑO${sortMark('size')}</th><th data-sort="modified">MODIFICADO${sortMark('modified')}</th><th>PERMISOS</th></tr></thead><tbody>${rows.map(e=>`<tr tabindex="-1" draggable="true" data-name="${esc(e.name)}" class="${p.selected.has(e.name)?'selected':''} ${p.anchor===e.name?'anchor':''} ${isCut(side,e.name)?'cut':''} ${e.kind==='directory'?'folder-row':''}" aria-selected="${p.selected.has(e.name)}"><td title="${esc(e.name)}"><span class="file-symbol ${e.kind==='directory'?'folder':isZip(e)?'zip':''}">${icon(e)}</span>${esc(e.name)}</td><td>${e.kind==='directory'?'Carpeta':sizes(e.size)}</td><td>${e.modified?new Date(e.modified*1000).toLocaleString('es-PE',{day:'2-digit',month:'short',year:'2-digit',hour:'2-digit',minute:'2-digit'}):'—'}</td><td>${esc(e.perm||'—')}</td></tr>`).join('')}</tbody></table>${!rows.length?`<div class="empty-pane"><strong>${p.filter?'Ningún elemento coincide con el filtro':'Esta carpeta está vacía'}</strong><span>${p.filter?'Prueba con otro texto en el filtro.':'Arrastra aquí archivos o carpetas desde tu equipo, o usa clic derecho.'}</span></div>`:''}`}</div>
  <div class="pane-foot"><span>${rows.length} elementos${sel.length?` · ${sel.length} seleccionado${sel.length>1?'s':''}`:''}</span><span>${sel.length===1?esc(sel[0].name):sel.length?sizes(sel.reduce((n,e)=>n+(e.kind==='file'?e.size:0),0))+' en archivos':'Clic derecho para más opciones'}</span></div>
  <div class="pane-tools"><button data-action="mkdir" ${none}>＋ Carpeta</button><button data-action="newfile" ${none}>＋ Archivo</button><button data-action="upload" ${none}>↑ Subir archivos</button><button data-action="upload-folder" ${none}>↑ Subir carpeta</button><button class="download-button" data-action="download" ${!sel.length?'disabled':''}>↓ Descargar</button><button data-action="rename" ${sel.length!==1?'disabled':''}>Renombrar</button>${local?`<button data-action="zip" ${!sel.length?'disabled':''}>Comprimir</button>`:''}<button class="danger-text" data-action="delete" ${!sel.length?'disabled':''}>Eliminar</button><button data-action="menu" title="Más opciones" aria-label="Más opciones" ${none}>⋯</button></div>`;
  $('.file-area',el).scrollTop=scroll;
  if(filterFocused){const f=$('.filter-input',el);f.focus();f.setSelectionRange(f.value.length,f.value.length);}
  else if(focused)$('.file-area',el).focus();
  updateArrows();
}
function setActive(side){if(activeSide===side)return;activeSide=side;for(const s of ['left','right'])$('#pane-'+s).classList.toggle('active',s===side);}
function updateArrows(){
  for(const [id,src,dst] of [['copy-right','left','right'],['copy-left','right','left']])
    $('#'+id).disabled=!panes[dst].connection||!panes[src].selected.size||panes[src].loading||panes[dst].loading;
}
async function loadPane(side,path){
  const p=panes[side],version=++p.version,samePath=path===undefined||path===p.path,keep=samePath?new Set(p.selected):new Set();
  if(path!==undefined)p.path=path;
  if(!samePath){p.filter='';p.anchor=null;}
  p.selected=new Set();p.error='';p.loading=Boolean(p.connection);renderPane(side);
  if(!p.connection){p.entries=[];renderPane(side);return;}
  try{const data=await api('files?'+new URLSearchParams({connection:p.connection,path:p.path}));if(version!==p.version)return;p.entries=data.entries;p.path=data.path;p.selected=new Set(data.entries.map(e=>e.name).filter(n=>keep.has(n)));}
  catch(e){if(version!==p.version)return;p.entries=[];p.error=e.message;notify(e.message,true);}
  finally{if(version===p.version){p.loading=false;renderPane(side);}}
}
function reloadWhere(connection,path){for(const s of ['left','right']){const p=panes[s];if(p.connection===connection&&(path===undefined||p.path===path)&&!p.loading)loadPane(s);}}
function selectRow(side,name,e){
  const p=panes[side],names=view(p).map(x=>x.name),mod=e.ctrlKey||e.metaKey;
  if(e.shiftKey&&p.anchor!==null&&names.includes(p.anchor)){
    const a=names.indexOf(p.anchor),b=names.indexOf(name);if(!mod)p.selected.clear();
    names.slice(Math.min(a,b),Math.max(a,b)+1).forEach(n=>p.selected.add(n));
  }else if(mod){p.selected.has(name)?p.selected.delete(name):p.selected.add(name);p.anchor=name;}
  else{
    // A click that changes nothing must not rebuild the rows, or the second click of a double click is lost.
    if(p.selected.size===1&&p.selected.has(name)&&p.anchor===name)return;
    p.selected=new Set([name]);p.anchor=name;
  }
  renderPane(side);
}
function openEntry(side,name){
  const p=panes[side],e=p.entries.find(x=>x.name===name);if(!e)return;
  if(e.kind==='directory')return loadPane(side,join(p.path,e.name));
  if(mediaKind(e))return preview(side,e);
  if(isZip(e)&&p.connection==='local')return extract(side,e);
  if(CodeEditor.isText(e.name)||(p.connection==='local'&&!isZip(e)&&e.size<=256*1024))return editFile(side,e);
  downloadSelection(side,[e]);
}

// ---------- Acciones ----------
function uniqueName(name,existing){
  if(!existing.has(name))return name;
  const dot=name.lastIndexOf('.'),stem=dot>0?name.slice(0,dot):name,ext=dot>0?name.slice(dot):'';
  for(let i=1;;i++){const candidate=`${stem} (copia${i>1?' '+i:''})${ext}`;if(!existing.has(candidate))return candidate;}
}
async function transferItems(src,names,mode,destSide,destPath){
  const d=panes[destSide];destPath=destPath??d.path;
  if(!d.connection||!names.length)return;
  const sameConnection=src.connection===d.connection,sameDir=sameConnection&&src.path===destPath;
  if(mode==='cut'&&sameDir)return notify('Los elementos ya están en esta carpeta.');
  const inside=sameConnection?names.filter(n=>(destPath+'/').startsWith(join(src.path,n)+'/')):[];
  const note=inside.length?` Se omitió ${inside.map(n=>`«${n}»`).join(', ')}: no se puede copiar ni mover una carpeta dentro de sí misma.`:'';
  names=names.filter(n=>!inside.includes(n));
  if(!names.length)return notify(note.trim(),true);
  const existing=new Set(destPath===d.path?d.entries.map(e=>e.name):[]);
  const plan=names.map(name=>({name,target:sameDir?uniqueName(name,existing):name}));
  const conflicts=plan.filter(x=>!sameDir&&existing.has(x.target)),skip=new Set();
  let overwrite=false;
  if(conflicts.length){
    const answer=await ask(`${conflicts.length===1?`«${conflicts[0].target}» ya existe`:`${conflicts.length} elementos ya existen`} en el destino. Si reemplazas, las carpetas se combinan y los archivos con el mismo nombre se sustituyen.`,{title:'Elementos existentes',accept:'Reemplazar',alt:'Omitir existentes'});
    if(!answer)return;
    if(answer==='alt')conflicts.forEach(x=>skip.add(x.name));else overwrite=true;
  }
  const todo=plan.filter(x=>!skip.has(x.name));let queued=0,moved=0;const errors=[];
  for(const x of todo){
    const from=join(src.path,x.name),to=join(destPath,x.target),replace=overwrite&&existing.has(x.target);
    try{
      if(mode==='cut'&&sameConnection&&!replace){await api('move','POST',{connection:src.connection,source:from,target:to});moved++;}
      else{await api('jobs','POST',{source:{connection:src.connection,path:from},destination:{connection:d.connection,path:to},overwrite:replace,move:mode==='cut'});queued++;}
    }catch(e){errors.push(`${x.name}: ${e.message}`);}
  }
  if(errors.length)notify(errors.join('\n')+note,true);
  else if(queued)notify(`${plural(queued,'Una transferencia','transferencias')} en cola.${note}`,!!note);
  else if(moved)notify(`${plural(moved,'Elemento movido','elementos movidos')}.${note}`,!!note);
  if(moved){reloadWhere(src.connection,src.path);reloadWhere(d.connection,destPath);}
  if(queued)await refreshJobs();
}
function sendTo(side,mode){const p=panes[side];transferItems({connection:p.connection,path:p.path},selectedEntries(side).map(e=>e.name),mode,other(side));}
function setClipboard(side,mode){
  const p=panes[side],names=selectedEntries(side).map(e=>e.name);if(!names.length)return;
  clipboard={mode,connection:p.connection,path:p.path,names};
  notify(`${plural(names.length,'Un elemento','elementos')} ${mode==='cut'?'cortado'+(names.length>1?'s':''):'copiado'+(names.length>1?'s':'')}. Pégalo con Ctrl+V o clic derecho → Pegar.`);
  for(const s of ['left','right'])renderPane(s);
}
async function paste(side){
  if(!clipboard)return;const clip=clipboard;
  if(clip.mode==='cut')clipboard=null;
  await transferItems(clip,clip.names,clip.mode,side);
  for(const s of ['left','right'])renderPane(s);
}
function triggerDownload(url){const a=document.createElement('a');a.href=url;a.download='';document.body.append(a);a.click();a.remove();}
const zipUrl = paths => API_PREFIX+'download-zip?'+new URLSearchParams(paths.map(path=>['path',path]));
async function downloadSelection(side,entries){
  const p=panes[side];entries=entries||selectedEntries(side);if(!entries.length)return;
  if(p.connection==='local'){
    if(entries.length===1&&entries[0].kind==='file')return triggerDownload(API_PREFIX+'download?'+new URLSearchParams({path:join(p.path,entries[0].name)}));
    notify('Preparando ZIP… la descarga empezará en unos segundos.');return triggerDownload(zipUrl(entries.map(e=>join(p.path,e.name))));
  }
  if(!await ask(`${entries.length===1?`«${entries[0].name}»`:`${entries.length} elementos`} se copiará${entries.length>1?'n':''} primero a la carpeta /Descargas del servidor de la aplicación y después se descargará${entries.length>1?'n':''} en tu equipo.`,{title:'Descargar a mi equipo',accept:'Descargar'}))return;
  try{await api('folders','POST',{connection:'local',path:'/',name:'Descargas'});}catch{/* Ya existe. */}
  for(const e of entries){
    try{const {id}=await api('jobs','POST',{source:{connection:p.connection,path:join(p.path,e.name)},destination:{connection:'local',path:'/Descargas/'+e.name},overwrite:true});autoDownload.add(id);}
    catch(err){notify(`${e.name}: ${err.message}`,true);}
  }
  notify('Descarga en cola. Se abrirá automáticamente al terminar.');await refreshJobs();
}
async function rename(side){
  const p=panes[side],[e]=selectedEntries(side);if(!e)return;
  const name=await ask('Escribe el nuevo nombre.',{title:'Renombrar',input:true,value:e.name,accept:'Renombrar'});
  if(!name||name===e.name)return;
  try{await api('move','POST',{connection:p.connection,source:join(p.path,e.name),target:join(p.path,name)});p.selected=new Set([name]);p.anchor=name;notify('Renombrado.');reloadWhere(p.connection,p.path);}
  catch(err){notify(err.message,true);}
}
async function removeSelection(side){
  const p=panes[side],entries=selectedEntries(side);if(!entries.length)return;
  const folders=entries.filter(e=>e.kind==='directory').length;
  const local=p.connection==='local';
  const message=local?`Se moverá${entries.length>1?'n':''} ${entries.length===1?`«${entries[0].name}»`:`${entries.length} elementos`} a .trash en la carpeta de tu cuenta. Puedes recuperarlos desde allí con Cortar y Pegar.`:`Se eliminará${entries.length>1?'n':''} ${entries.length===1?`«${entries[0].name}»`:`${entries.length} elementos`}${folders?' con todo su contenido':''} de ${title(p.connection)}. No se puede deshacer.`;
  if(!await ask(message,{title:local?'Mover a .trash':'Eliminar',accept:local?'Mover a .trash':'Eliminar',danger:true}))return;
  try{
    const r=await api('delete','POST',{connection:p.connection,paths:entries.map(e=>join(p.path,e.name))});
    if(r.id){notify('Eliminación en cola.');await refreshJobs();}else{notify(local?'Movido a .trash.':'Eliminado.');reloadWhere(p.connection,p.path);}
  }catch(err){notify(err.message,true);reloadWhere(p.connection,p.path);}
}
async function zipSelection(side){
  const p=panes[side],entries=selectedEntries(side);if(!entries.length||p.connection!=='local')return;
  const suggested=entries.length===1?entries[0].name.replace(/\.zip$/i,'')+'.zip':(p.path==='/'?'archivos':baseName(p.path))+'.zip';
  let name=await ask(`Se comprimirá${entries.length>1?'n':''} ${entries.length===1?`«${entries[0].name}»`:`${entries.length} elementos`} en esta carpeta.`,{title:'Comprimir en ZIP',input:true,value:suggested,accept:'Comprimir'});
  if(!name)return;if(!/\.zip$/i.test(name))name+='.zip';
  let overwrite=false;
  if(p.entries.some(e=>e.name===name)){if(!await ask(`«${name}» ya existe. ¿Reemplazarlo?`,{title:'ZIP existente',accept:'Reemplazar'}))return;overwrite=true;}
  try{await api('archive','POST',{path:p.path,items:entries.map(e=>e.name),name,overwrite});notify('Compresión en cola.');await refreshJobs();}catch(err){notify(err.message,true);}
}
async function extract(side,e){
  const p=panes[side];
  const name=await ask(`Se extraerá «${e.name}» en una carpeta nueva dentro de esta ubicación.`,{title:'Extraer ZIP',input:true,value:e.name.replace(/\.zip$/i,''),accept:'Extraer'});
  if(!name)return;
  let overwrite=false;
  if(p.entries.some(x=>x.name===name)){if(!await ask(`«${name}» ya existe. Se combinará con el contenido del ZIP y se reemplazarán los archivos con el mismo nombre.`,{title:'Carpeta existente',accept:'Combinar'}))return;overwrite=true;}
  try{await api('extract','POST',{path:join(p.path,e.name),name,overwrite});notify('Extracción en cola.');await refreshJobs();}catch(err){notify(err.message,true);}
}
async function create(side,kind){
  const p=panes[side];if(!p.connection)return;
  const name=await ask('Se creará dentro de la carpeta abierta.',{title:kind==='folder'?'Nueva carpeta':'Nuevo archivo',input:true,value:kind==='folder'?'Nueva carpeta':'nuevo.txt',accept:'Crear'});
  if(!name)return;
  try{await api(kind==='folder'?'folders':'newfile','POST',{connection:p.connection,path:p.path,name});p.selected=new Set([name]);p.anchor=name;reloadWhere(p.connection,p.path);}catch(err){notify(err.message,true);}
}
async function chmod(side){
  const p=panes[side],[e]=selectedEntries(side);if(!e)return;
  const mode=await ask(`Permisos de «${e.name}» en formato octal (por ejemplo 644 para archivos o 755 para carpetas).`,{title:'Permisos',input:true,value:e.perm||(e.kind==='directory'?'755':'644'),accept:'Aplicar'});
  if(!mode)return;
  try{await api('chmod','POST',{connection:p.connection,path:join(p.path,e.name),mode});notify('Permisos actualizados.');reloadWhere(p.connection,p.path);}catch(err){notify(err.message,true);}
}
async function copyPath(side,path){
  try{await navigator.clipboard.writeText(path);notify('Ruta copiada: '+path);}
  catch{await ask(path,{title:'Ruta',accept:'Cerrar'});}
}
async function properties(side){
  const p=panes[side],entries=selectedEntries(side),dialog=$('#props-dialog');
  const target=entries.length===1?entries[0]:entries.length?null:{name:baseName(p.path),kind:'directory',current:true};
  const rows=[];
  if(target){
    const path=target.current?p.path:join(p.path,target.name);
    rows.push(['Nombre',esc(target.name)],['Tipo',target.kind==='directory'?'Carpeta':isZip(target)?'Archivo ZIP':target.kind==='file'?'Archivo':'Otro'],['Ubicación',esc(title(p.connection))],['Ruta',`<code>${esc(path)}</code>`]);
    rows.push(['Tamaño',target.kind==='directory'?`<span id="props-size">—</span> <button type="button" id="props-calc" class="small-button">Calcular</button>`:`${sizes(target.size)} (${(target.size||0).toLocaleString('es-PE')} bytes)`]);
    if(target.modified)rows.push(['Modificado',new Date(target.modified*1000).toLocaleString('es-PE')]);
    if(target.perm)rows.push(['Permisos',esc(target.perm)]);
    $('#props-body').innerHTML=rows.map(([k,v])=>`<tr><th>${k}</th><td>${v}</td></tr>`).join('');
    const calc=$('#props-calc');
    if(calc)calc.onclick=async()=>{calc.disabled=true;$('#props-size').textContent='Calculando…';try{const r=await api('size?'+new URLSearchParams({connection:p.connection,path}));$('#props-size').textContent=`${sizes(r.size)} (${r.size.toLocaleString('es-PE')} bytes)${r.files!=null?` · ${r.files.toLocaleString('es-PE')} archivos`:''}`;}catch(err){$('#props-size').textContent=err.message;}finally{calc.remove();}};
  }else{
    const files=entries.filter(e=>e.kind==='file'),folders=entries.length-files.length;
    rows.push(['Selección',`${entries.length} elementos (${files.length} archivos, ${folders} carpetas)`],['Ubicación',esc(title(p.connection))],['Carpeta',`<code>${esc(p.path)}</code>`],['Tamaño de archivos',sizes(files.reduce((n,e)=>n+e.size,0))]);
    $('#props-body').innerHTML=rows.map(([k,v])=>`<tr><th>${k}</th><td>${v}</td></tr>`).join('');
  }
  dialog.showModal();
}
async function editFile(side,e){
  const p=panes[side],connection=p.connection,folder=p.path,path=join(folder,e.name);
  if(e.size>2*1024*1024)return notify('El archivo supera 2 MB; descárgalo para verlo.',true);
  let content;
  notify('Abriendo '+e.name+'…');
  try{content=(await api('text?'+new URLSearchParams({connection,path}))).content;}
  catch(err){return notify(err.message+' Usa «Descargar» para abrirlo en tu equipo.',true);}
  $('#toast').hidden=true;
  CodeEditor.open({name:e.name,path,where:title(connection),content,ask,
    save:text=>api('text','POST',{connection,path,content:text}),
    onClose:()=>reloadWhere(connection,folder)});
}
// ---------- Vista previa de imágenes, vídeo y audio ----------
const MEDIA={image:/\.(jpe?g|png|gif|webp|avif|bmp|ico|svg)$/i,video:/\.(mp4|m4v|webm|ogv|mov)$/i,audio:/\.(mp3|m4a|ogg|oga|wav|flac|aac|opus)$/i};
const mediaKind=e=>e.kind==='file'?Object.keys(MEDIA).find(k=>MEDIA[k].test(e.name)):undefined;
let previewState=null;
function preview(side,entry){
  const p=panes[side],items=view(p).filter(mediaKind);
  previewState={side,connection:p.connection,path:p.path,items,index:Math.max(0,items.findIndex(x=>x.name===entry.name))};
  showPreview();$('#preview-dialog').showModal();
}
function showPreview(){
  const s=previewState,e=s.items[s.index],kind=mediaKind(e),stage=$('#preview-stage');
  const url=API_PREFIX+'preview?'+new URLSearchParams({connection:s.connection,path:join(s.path,e.name)});
  $('#preview-title').textContent=e.name;
  $('#preview-info').textContent=`${sizes(e.size)} · ${s.index+1} de ${s.items.length} en ${title(s.connection)}${s.connection!=='local'&&kind==='video'?' · en remoto, el vídeo se reproduce mientras llega':''}`;
  $('#preview-prev').disabled=$('#preview-next').disabled=s.items.length<2;
  stage.classList.remove('zoom');
  stage.innerHTML=kind==='image'?`<img src="${esc(url)}" alt="${esc(e.name)}">`:kind==='video'?`<video src="${esc(url)}" controls autoplay playsinline></video>`:`<audio src="${esc(url)}" controls autoplay></audio>`;
  const media=stage.firstElementChild;
  media.onerror=()=>{stage.innerHTML='<div class="preview-error">No se pudo mostrar este archivo. Puede que el navegador no admita su formato; usa «Descargar».</div>';};
  if(kind==='image')media.onclick=()=>stage.classList.toggle('zoom');
}
function stepPreview(delta){const s=previewState;if(!s||s.items.length<2)return;s.index=(s.index+delta+s.items.length)%s.items.length;showPreview();}
$('#preview-prev').onclick=()=>stepPreview(-1);$('#preview-next').onclick=()=>stepPreview(1);
$('#preview-close').onclick=()=>$('#preview-dialog').close();
$('#preview-dialog').addEventListener('close',()=>{$('#preview-stage').innerHTML='';previewState=null;});
$('#preview-dialog').addEventListener('keydown',ev=>{if(ev.key==='ArrowLeft'){ev.preventDefault();stepPreview(-1);}else if(ev.key==='ArrowRight'){ev.preventDefault();stepPreview(1);}});
$('#preview-download').onclick=()=>{
  const s=previewState,e=s.items[s.index];
  if(s.connection==='local')return triggerDownload(API_PREFIX+'download?'+new URLSearchParams({path:join(s.path,e.name)}));
  $('#preview-dialog').close();
  const side=['left','right'].find(x=>panes[x].connection===s.connection&&panes[x].path===s.path);
  if(side)downloadSelection(side,[e]);
};
async function closeConnection(side){
  const p=panes[side],cid=p.connection;if(!cid||cid==='local')return;
  if(!await ask(`Se cerrará la conexión «${title(cid)}». Las credenciales se borran de la memoria del servicio.`,{title:'Cerrar conexión',accept:'Cerrar conexión'}))return;
  try{await api('connections/'+cid,'DELETE');}catch(err){return notify(err.message,true);}
  connections=connections.filter(c=>c.id!==cid);
  for(const s of ['left','right'])if(panes[s].connection===cid){panes[s]=newPane('');}
  for(const s of ['left','right'])renderPane(s);
}

// ---------- Subidas ----------
function uploadOne(target,file,relative,overwrite,onprogress){
  return new Promise((resolve,reject)=>{
    const data=new FormData();data.append(target.batch?'batch':'path',target.batch||target.path);data.append('file',file);
    if(relative)data.append('relative',relative);if(overwrite)data.append('overwrite','1');
    const x=new XMLHttpRequest();x.open('POST',API_PREFIX+'upload');x.setRequestHeader('X-App-Request','lftp-web');x.setRequestHeader('X-Hestia-CSRF',HESTIA_CSRF);
    x.upload.onprogress=e=>onprogress(e.loaded);
    x.onload=()=>{let d={};try{d=JSON.parse(x.responseText);}catch{}if(x.status===401){if(INTEGRATED){window.top.location.href='/login/';}else{loggedIn=false;$('#login-dialog').showModal();}}x.status<300?resolve(d):reject(Error(d.error||'No se pudo subir el archivo.'));};
    x.onerror=()=>reject(Error('Error de red al subir.'));x.send(data);
  });
}
const randomBatch=()=>[...crypto.getRandomValues(new Uint8Array(16))].map(b=>b.toString(16).padStart(2,'0')).join('');
async function uploadItems(side,items){
  const p=panes[side],remote=p.connection!=='local',connection=p.connection,path=p.path;
  if(!items.length||!connection)return;
  const existing=new Set(p.entries.map(e=>e.name)),tops=[...new Set(items.map(i=>i.relative.split('/')[0]))],conflicts=tops.filter(t=>existing.has(t));
  let overwrite=false,skip=new Set();
  if(conflicts.length){
    const answer=await ask(`${conflicts.length===1?`«${conflicts[0]}» ya existe`:`${conflicts.length} elementos ya existen`} en esta carpeta.`,{title:'Elementos existentes',accept:'Reemplazar',alt:'Omitir existentes'});
    if(!answer)return;if(answer==='alt')skip=new Set(conflicts);else overwrite=true;
  }
  items=items.filter(i=>!skip.has(i.relative.split('/')[0]));
  if(!items.length)return;
  // A remote destination receives the upload in a hidden staging area first; a queued job then sends it on with lftp.
  const target=remote?{batch:randomBatch()}:{path},total=items.reduce((n,i)=>n+i.file.size,0)||1,errors=[];let done=0;
  for(const [n,i] of items.entries()){
    const show=loaded=>notify(`Subiendo ${n+1}/${items.length} · ${Math.floor((done+loaded)/total*100)}% · ${i.relative}`,false,60000);
    show(0);
    try{await uploadOne(target,i.file,i.relative,overwrite,show);}catch(e){errors.push(`${i.relative}: ${e.message}`);}
    done+=i.file.size;
  }
  if(errors.length)notify(`${errors.length} archivo(s) no se subieron:\n${errors.slice(0,5).join('\n')}`,true);
  if(!remote){if(!errors.length)notify(`${plural(items.length,'Archivo subido','archivos subidos')}.`);return reloadWhere('local',path);}
  if(errors.length===items.length)return;
  try{
    const names=[...new Set(items.map(i=>i.relative.split('/')[0]))];
    await api('upload-commit','POST',{batch:target.batch,connection,path,names,overwrite});
    if(!errors.length)notify(`Recibido. Enviando ${plural(names.length,'1 elemento','elementos')} a ${title(connection)}; sigue el avance en la cola.`);
    await refreshJobs();
  }catch(e){notify(e.message,true);}
}
async function filesFromDrop(dataTransfer){
  // Entries must be taken synchronously: the DataTransfer is emptied after the first await.
  const entries=[...dataTransfer.items].map(i=>i.webkitGetAsEntry?.()).filter(Boolean),files=[...dataTransfer.files];
  if(!entries.length)return files.map(file=>({file,relative:file.name}));
  const out=[];
  async function walk(entry,prefix){
    if(entry.isFile){out.push({file:await new Promise((ok,fail)=>entry.file(ok,fail)),relative:prefix+entry.name});return;}
    if(!entry.isDirectory)return;
    const reader=entry.createReader();let batch;
    do{batch=await new Promise((ok,fail)=>reader.readEntries(ok,fail));for(const child of batch)await walk(child,prefix+entry.name+'/');}while(batch.length);
  }
  for(const entry of entries)await walk(entry,'');
  return out;
}

// ---------- Menú contextual ----------
function menuItems(side){
  const p=panes[side],sel=selectedEntries(side),one=sel.length===1?sel[0]:null,local=p.connection==='local',dst=panes[other(side)],ready=!!dst.connection&&!dst.loading,arrow=side==='left'?'→':'←';
  if(!p.connection)return [{label:'Conectar servidor…',icon:'＋',run:()=>$('#connection-dialog').showModal()}];
  const items=[];
  if(sel.length){
    if(one?.kind==='directory')items.push({label:'Abrir',icon:'▰',key:'Intro',run:()=>openEntry(side,one.name)});
    if(one&&mediaKind(one))items.push({label:'Vista previa',icon:'▶',key:'Intro',run:()=>preview(side,one)});
    if(one?.kind==='file'&&!mediaKind(one))items.push({label:'Abrir en el editor',icon:'✎',key:CodeEditor.isText(one.name)?'Intro':'',disabled:one.size>2*1024*1024,run:()=>editFile(side,one)});
    if(one&&isZip(one)&&local)items.push({label:'Extraer aquí…',icon:'⇲',run:()=>extract(side,one)});
    items.push('-',
      {label:`Copiar al panel ${sideName(other(side))}`,icon:arrow,disabled:!ready,run:()=>sendTo(side,'copy')},
      {label:`Mover al panel ${sideName(other(side))}`,icon:'⇥',disabled:!ready,run:()=>sendTo(side,'cut')},
      {label:sel.length>1||one?.kind==='directory'?(local?'Descargar como ZIP':'Descargar a mi equipo'):'Descargar a mi equipo',icon:'↓',run:()=>downloadSelection(side)},
      '-',
      {label:'Copiar',icon:'⧉',key:'Ctrl+C',run:()=>setClipboard(side,'copy')},
      {label:'Cortar',icon:'✂',key:'Ctrl+X',run:()=>setClipboard(side,'cut')});
  }
  items.push({label:clipboard?`Pegar ${plural(clipboard.names.length,'1 elemento','elementos')}${clipboard.mode==='cut'?' (mover)':''}`:'Pegar',icon:'⎙',key:'Ctrl+V',disabled:!clipboard,run:()=>paste(side)});
  if(sel.length){
    items.push('-',
      {label:'Renombrar…',icon:'✎',key:'F2',disabled:!one,run:()=>rename(side)},
      {label:'Duplicar',icon:'⧉',run:()=>transferItems({connection:p.connection,path:p.path},sel.map(e=>e.name),'copy',side)});
    if(local)items.push({label:'Comprimir en ZIP…',icon:'▣',run:()=>zipSelection(side)});
    items.push({label:'Permisos…',icon:'⚿',disabled:!one,run:()=>chmod(side)},
      {label:'Copiar ruta',icon:'⎘',disabled:!one,run:()=>copyPath(side,join(p.path,one.name))},
      {label:'Propiedades',icon:'ⓘ',run:()=>properties(side)},
      '-',
      {label:'Eliminar…',icon:'✕',key:'Supr',danger:true,run:()=>removeSelection(side)});
  }else{
    items.push('-',
      {label:'Nueva carpeta…',icon:'＋',run:()=>create(side,'folder')},
      {label:'Nuevo archivo…',icon:'＋',run:()=>create(side,'file')});
    items.push({label:'Subir archivos…',icon:'↑',run:()=>pickUpload(side,false)},{label:'Subir carpeta…',icon:'↑',run:()=>pickUpload(side,true)});
    items.push('-',
      {label:'Seleccionar todo',icon:'☑',key:'Ctrl+A',disabled:!p.entries.length,run:()=>{p.selected=new Set(view(p).map(e=>e.name));renderPane(side);}},
      {label:'Actualizar',icon:'↻',key:'F5',run:()=>loadPane(side)},
      {label:'Carpeta superior',icon:'↑',key:'Retroceso',disabled:p.path==='/',run:()=>loadPane(side,parent(p.path))},
      {label:'Copiar ruta de la carpeta',icon:'⎘',run:()=>copyPath(side,p.path)},
      {label:'Propiedades de la carpeta',icon:'ⓘ',run:()=>properties(side)});
    if(local&&p.entries.length)items.push({label:'Descargar carpeta como ZIP',icon:'↓',run:()=>{notify('Preparando ZIP…');triggerDownload(zipUrl([p.path]));}});
    if(!local)items.push('-',{label:'Cerrar conexión',icon:'⏏',run:()=>closeConnection(side)});
  }
  return items;
}
function openMenu(side,x,y){
  const menu=$('#context-menu'),items=menuItems(side);menuSide=side;
  menu.innerHTML=items.map((item,i)=>item==='-'?'<div class="menu-sep" role="separator"></div>':`<button type="button" role="menuitem" data-item="${i}" class="${item.danger?'danger-text':''}" ${item.disabled?'disabled':''}><span class="menu-icon">${item.icon||''}</span><span class="menu-label">${esc(item.label)}</span><span class="menu-key">${esc(item.key||'')}</span></button>`).join('');
  menu.hidden=false;
  const r=menu.getBoundingClientRect();
  menu.style.left=Math.max(4,Math.min(x,innerWidth-r.width-4))+'px';
  menu.style.top=Math.max(4,y+r.height>innerHeight-4?y-r.height:y)+'px';
  menu.querySelectorAll('[data-item]').forEach(b=>b.onclick=()=>{closeMenu();items[Number(b.dataset.item)].run();});
  menu.querySelector('button:not(:disabled)')?.focus();
}
function closeMenu(){const menu=$('#context-menu');if(menu.hidden)return;menu.hidden=true;const side=menuSide;menuSide=null;if(side)$('#pane-'+side+' .file-area')?.focus();}
document.addEventListener('mousedown',e=>{if(!e.target.closest('#context-menu'))closeMenu();});
window.addEventListener('blur',closeMenu);window.addEventListener('resize',closeMenu);
document.addEventListener('scroll',closeMenu,true);
$('#context-menu').addEventListener('keydown',e=>{
  const buttons=[...$('#context-menu').querySelectorAll('button:not(:disabled)')],i=buttons.indexOf(document.activeElement);
  if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();buttons[(i+(e.key==='ArrowDown'?1:-1)+buttons.length)%buttons.length]?.focus();}
  else if(e.key==='Escape'){e.preventDefault();closeMenu();}
});

// ---------- Eventos de cada panel ----------
function pickUpload(side,folder){uploadSide=side;$(folder?'#upload-folder-input':'#upload-input').click();}
async function paneAction(side,name,target){
  const p=panes[side];
  if(name==='connect')return $('#connection-dialog').showModal();
  if(name==='refresh')return loadPane(side);
  if(name==='up')return loadPane(side,parent(p.path));
  if(name==='upload')return pickUpload(side,false);
  if(name==='upload-folder')return pickUpload(side,true);
  if(name==='mkdir')return create(side,'folder');
  if(name==='newfile')return create(side,'file');
  if(name==='download')return downloadSelection(side);
  if(name==='rename')return rename(side);
  if(name==='delete')return removeSelection(side);
  if(name==='zip')return zipSelection(side);
  if(name==='menu'){const r=target.getBoundingClientRect();return openMenu(side,r.left,r.bottom+4);}
}
for(const side of ['left','right']){
  const el=$('#pane-'+side),p=()=>panes[side];
  el.addEventListener('mousedown',()=>setActive(side));
  el.addEventListener('focusin',()=>setActive(side));
  el.addEventListener('click',e=>{
    const action=e.target.closest('[data-action]');if(action&&!action.disabled)return paneAction(side,action.dataset.action,action);
    const th=e.target.closest('th[data-sort]');if(th){const s=p().sort;s.dir=s.key===th.dataset.sort?-s.dir:1;s.key=th.dataset.sort;return renderPane(side);}
    const row=e.target.closest('tr[data-name]');if(row)return selectRow(side,row.dataset.name,e);
    if(e.target.closest('.file-area')&&p().selected.size){p().selected.clear();renderPane(side);}
  });
  el.addEventListener('dblclick',e=>{const row=e.target.closest('tr[data-name]');if(row)openEntry(side,row.dataset.name);});
  el.addEventListener('contextmenu',e=>{
    if(!e.target.closest('.file-area'))return;
    e.preventDefault();setActive(side);
    const row=e.target.closest('tr[data-name]');
    if(row&&!p().selected.has(row.dataset.name)){p().selected=new Set([row.dataset.name]);p().anchor=row.dataset.name;renderPane(side);}
    if(!row&&p().selected.size){p().selected.clear();renderPane(side);}
    openMenu(side,e.clientX,e.clientY);
  });
  el.addEventListener('submit',e=>{e.preventDefault();loadPane(side,$('.path-input',el).value.trim()||'/');});
  el.addEventListener('change',async e=>{if(!e.target.classList.contains('location-select'))return;panes[side]=Object.assign(newPane(e.target.value),{});await loadPane(side);});
  el.addEventListener('input',e=>{if(!e.target.classList.contains('filter-input'))return;p().filter=e.target.value;renderPane(side);});
  // Arrastrar filas: entre paneles copia (Mayús mueve); sobre una carpeta del mismo panel mueve dentro.
  el.addEventListener('dragstart',e=>{
    const row=e.target.closest('tr[data-name]');if(!row)return;
    if(!p().selected.has(row.dataset.name)){p().selected=new Set([row.dataset.name]);p().anchor=row.dataset.name;}
    e.dataTransfer.setData('application/x-lftp-web',side);e.dataTransfer.effectAllowed='copyMove';
  });
  el.addEventListener('dragover',e=>{
    const internal=e.dataTransfer.types.includes('application/x-lftp-web'),files=e.dataTransfer.types.includes('Files');
    if(!(internal||files)||!p().connection||!e.target.closest('.file-area'))return;
    e.preventDefault();e.dataTransfer.dropEffect=internal&&e.shiftKey?'move':'copy';
    el.querySelectorAll('.drop-target').forEach(r=>r.classList.remove('drop-target'));
    const row=e.target.closest('tr.folder-row');(row||$('.file-area',el)).classList.add('drop-target');
  });
  el.addEventListener('dragleave',e=>{if(!el.contains(e.relatedTarget))el.querySelectorAll('.drop-target').forEach(r=>r.classList.remove('drop-target'));});
  el.addEventListener('drop',async e=>{
    el.querySelectorAll('.drop-target').forEach(r=>r.classList.remove('drop-target'));
    if(!e.target.closest('.file-area')||!p().connection)return;
    e.preventDefault();
    const fromSide=e.dataTransfer.getData('application/x-lftp-web'),row=e.target.closest('tr.folder-row');
    if(fromSide){
      const src=panes[fromSide],names=selectedEntries(fromSide).map(x=>x.name),destPath=row?join(p().path,row.dataset.name):undefined;
      if(fromSide===side){if(!row||names.includes(row.dataset.name))return;return transferItems({connection:src.connection,path:src.path},names,'cut',side,destPath);}
      return transferItems({connection:src.connection,path:src.path},names,e.shiftKey?'cut':'copy',side,destPath);
    }
    uploadItems(side,await filesFromDrop(e.dataTransfer));
  });
}
document.addEventListener('keydown',e=>{
  if(!$('#context-menu').hidden||document.querySelector('dialog[open]')||e.target.closest('input,textarea,select'))return;
  const side=activeSide,p=panes[side];if(!p.connection||p.loading)return;
  const mod=e.ctrlKey||e.metaKey,key=e.key.toLowerCase(),sel=selectedEntries(side),names=view(p).map(x=>x.name);
  if(mod&&key==='a'){e.preventDefault();p.selected=new Set(names);renderPane(side);}
  else if(mod&&key==='c'&&sel.length&&!getSelection().toString()){e.preventDefault();setClipboard(side,'copy');}
  else if(mod&&key==='x'&&sel.length){e.preventDefault();setClipboard(side,'cut');}
  else if(mod&&key==='v'&&clipboard){e.preventDefault();paste(side);}
  else if(e.key==='Delete'&&sel.length){e.preventDefault();removeSelection(side);}
  else if(e.key==='F2'&&sel.length===1){e.preventDefault();rename(side);}
  else if(e.key==='F5'){e.preventDefault();loadPane(side);}
  else if(e.key==='Enter'&&sel.length===1){e.preventDefault();openEntry(side,sel[0].name);}
  else if(e.key==='Backspace'){e.preventDefault();if(p.path!=='/')loadPane(side,parent(p.path));}
  else if(e.key==='Escape'&&p.selected.size){p.selected.clear();renderPane(side);}
  else if((e.key==='ArrowDown'||e.key==='ArrowUp')&&names.length){
    e.preventDefault();const current=names.indexOf(p.anchor),next=Math.max(0,Math.min(names.length-1,current<0?0:current+(e.key==='ArrowDown'?1:-1)));
    if(e.shiftKey)p.selected.add(names[next]);else p.selected=new Set([names[next]]);
    p.anchor=names[next];renderPane(side);$('#pane-'+side+' tr.anchor')?.scrollIntoView({block:'nearest'});
  }
});

// ---------- Cola ----------
const stateLabels={queued:'En cola',running:'En curso',completed:'Completada',failed:'Error',cancelled:'Cancelada',interrupted:'Interrumpida'};
const emptyQueue=$('#jobs').innerHTML;
function jobInfo(j){
  const p=j.payload,kind=p.kind||'transfer',name=baseName(p.source.path);
  if(kind==='zip')return {icon:'▣',name:baseName(p.destination.path),route:`Comprimir ${plural(p.items.length,'1 elemento','elementos')} · ${p.destination.path}`};
  if(kind==='unzip')return {icon:'⇲',name:'Extraer '+name,route:`→ ${p.destination.path}`};
  if(kind==='delete')return {icon:'✕',name:`Eliminar ${plural(p.items.length,'1 elemento','elementos')}`,route:`${title(p.source.connection)} · ${p.destination.path}`,count:true};
  if(p.upload)return {icon:'↑',name,route:`Tu equipo → ${title(p.destination.connection)} · ${p.destination.path}`};
  return {icon:p.move?'⇥':'▤',name,route:`${title(p.source.connection)} → ${title(p.destination.connection)} · ${p.destination.path}${p.move?' · mover':''}`};
}
function downloadUrl(path){return path.endsWith('/')?zipUrl([path.slice(0,-1)]):API_PREFIX+'download?'+new URLSearchParams({path});}
function renderJobs(){
  $('#job-count').textContent=currentJobs.length;
  $('#clear-jobs').hidden=!currentJobs.some(j=>!['queued','running'].includes(j.status));
  if(!currentJobs.length){$('#jobs').innerHTML=emptyQueue;return;}
  $('#jobs').innerHTML=currentJobs.map(j=>{
    const info=jobInfo(j),active=['queued','running'].includes(j.status),percent=j.total?Math.min(j.bytes/j.total*100,99):0;
    const amount=info.count?`${j.bytes||0} / ${j.total||0} elementos`:`${sizes(j.bytes)}${j.total?' / '+sizes(j.total):''}`;
    return `<div class="job"><div><div class="job-name">${info.icon} ${esc(info.name)}</div><div class="job-route">${esc(info.route)}</div></div><div><span class="job-state ${esc(j.status)}">${esc(stateLabels[j.status]||j.status)}</span><div class="job-message">${esc(j.message)}</div></div><div class="muted small">${amount}<div class="progress"><i style="width:${j.status==='completed'?100:percent}%"></i></div></div><div>${active?`<button data-cancel="${j.id}">Cancelar</button>`:j.download&&j.status==='completed'?`<a href="${esc(downloadUrl(j.download))}" download>↓ Descargar</a>`:''}</div></div>`;
  }).join('');
  $('#jobs').querySelectorAll('[data-cancel]').forEach(b=>b.onclick=async()=>{try{await api('jobs/'+b.dataset.cancel+'/cancel','POST',{});await refreshJobs();}catch(e){notify(e.message,true);}});
}
let polling=false;
async function refreshJobs(){
  if(!loggedIn||polling)return;polling=true;
  try{
    const {jobs}=await api('jobs'),finished=jobs.filter(j=>!['queued','running'].includes(j.status)&&['queued','running',undefined].includes(currentJobs.find(o=>o.id===j.id)?.status)&&currentJobs.length);
    currentJobs=jobs;renderJobs();
    for(const j of finished){
      for(const end of [j.payload.source,j.payload.destination])reloadWhere(end.connection,j.payload.kind==='delete'?j.payload.destination.path:parent(end.path));
      if(autoDownload.delete(j.id)&&j.status==='completed'&&j.download)triggerDownload(downloadUrl(j.download));
    }
  }catch(e){if(loggedIn)notify(e.message,true);}finally{polling=false;}
}

// ---------- Arranque ----------
$('#add-connection').onclick=()=>$('#connection-dialog').showModal();
$('#close-dialog').onclick=$('#cancel-dialog').onclick=()=>$('#connection-dialog').close();
$('#copy-right').onclick=()=>sendTo('left','copy');$('#copy-left').onclick=()=>sendTo('right','copy');
$('#clear-jobs').onclick=async()=>{try{await api('jobs/clear','POST',{});await refreshJobs();}catch(e){notify(e.message,true);}};
$('#props-close').onclick=()=>$('#props-dialog').close();
$('#connection-form [name=protocol]').onchange=e=>{$('#connection-form [name=port]').value=e.target.value==='sftp'?22:21;};
$('#connection-form').onsubmit=async e=>{
  e.preventDefault();const form=e.target,button=$('[type=submit]',form),error=$('#connection-error');button.disabled=true;button.textContent='Conectando…';error.hidden=true;
  try{const data=await api('connections','POST',Object.fromEntries(new FormData(form)));connections.push(data.connection);const side=!panes.right.connection?'right':!panes.left.connection?'left':'right';panes[side]=newPane(data.connection.id);panes[side].path=data.path;$('#connection-dialog').close();form.reset();for(const s of ['left','right'])renderPane(s);await loadPane(side);notify('Conexión establecida.');}
  catch(e){error.textContent=e.message;error.hidden=false;}finally{button.disabled=false;button.textContent='Conectar servidor';$('[name=password]',form).value='';}
};
for(const id of ['#upload-input','#upload-folder-input'])$(id).onchange=async e=>{
  const items=[...e.target.files].map(file=>({file,relative:file.webkitRelativePath||file.name}));e.target.value='';
  await uploadItems(uploadSide,items);
};
if(!INTEGRATED){
$('#login-dialog').addEventListener('cancel',e=>{if(!loggedIn)e.preventDefault();});
$('#login-form').onsubmit=async e=>{e.preventDefault();const input=$('[name=password]',e.target),username=$('[name=username]',e.target),button=$('button',e.target);button.disabled=true;$('#login-error').hidden=true;try{await api('login','POST',{username:username.value,password:input.value});$('#login-dialog').close();loggedIn=true;await initialize();}catch(e){$('#login-error').textContent=e.message;$('#login-error').hidden=false;}finally{input.value='';button.disabled=false;}};
}
$('#logout').onclick=async()=>{if(INTEGRATED){window.top.location.href='/pro/';return;}await api('logout','POST',{});location.reload();};
async function initialize(){
  try{const status=await api('status');localRootLabel=status.root_label||localRootLabel;$('#engine').textContent=status.lftp?'Motor lftp disponible':'Vista local · lftp pendiente';$('#engine-warning').hidden=status.lftp;$('#logout').hidden=INTEGRATED||!status.authenticated;if(!INTEGRATED){$('#login-config').hidden=status.auth_configured;$('#login-form button').disabled=!status.auth_configured;}
    loggedIn=status.authenticated;if(!loggedIn){if(INTEGRATED){window.top.location.href='/login/';return;}if(!$('#login-dialog').open)$('#login-dialog').showModal();return;}
    connections=(await api('connections')).connections;
    for(const side of ['left','right']){if(panes[side].connection!=='local'&&!connections.some(c=>c.id===panes[side].connection))panes[side].connection='';await loadPane(side);}
    await refreshJobs();
  }catch(e){notify(e.message,true);}
}
renderPane('left');renderPane('right');initialize();setInterval(refreshJobs,2000);

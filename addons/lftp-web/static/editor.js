'use strict';
// Editor de texto y código sin dependencias: un <textarea> real (deshacer, IME, portapapeles nativos)
// sobre un <pre> con el resaltado, más números de línea, búsqueda, reemplazo e indentación.
const CodeEditor = (() => {
  const $ = s => document.querySelector(s);
  const escapeHtml = s => s.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
  const words = s => new Set(s.split(/\s+/).filter(Boolean));
  const C_LIKE = {line:['//'], block:['/*','*/'], strings:['"',"'"]};
  const LANGS = {
    plain:{label:'Texto'},
    js:{label:'JavaScript', ...C_LIKE, strings:['"',"'",'`'], kw:words('async await break case catch class const continue debugger default delete do else export extends finally for from function if import in instanceof let new of return static super switch this throw try typeof var void while with yield true false null undefined NaN')},
    ts:{label:'TypeScript', ...C_LIKE, strings:['"',"'",'`'], kw:words('abstract any as async await boolean break case catch class const constructor continue declare default delete do else enum export extends false finally for from function get if implements import in infer instanceof interface keyof let module namespace never new null number object of private protected public readonly return set static string super switch symbol this throw true try type typeof undefined unknown var void while yield')},
    json:{label:'JSON', strings:['"'], kw:words('true false null')},
    py:{label:'Python', line:['#'], strings:['"""',"'''",'"',"'"], kw:words('and as assert async await break class continue def del elif else except False finally for from global if import in is lambda None nonlocal not or pass raise return self True try while with yield print')},
    php:{label:'PHP', line:['//','#'], block:['/*','*/'], strings:['"',"'"], kw:words('abstract and array as break callable case catch class clone const continue declare default do echo else elseif empty enddeclare endfor endforeach endif endswitch endwhile extends false final finally fn for foreach function global goto if implements include include_once instanceof insteadof interface isset list match namespace new null or print private protected public readonly require require_once return static switch this throw trait true try unset use var while yield')},
    sh:{label:'Shell', line:['#'], strings:['"',"'"], kw:words('if then else elif fi for while until do done case esac in function return export local readonly declare echo exit set unset source alias cd shift trap sudo true false')},
    css:{label:'CSS', block:['/*','*/'], strings:['"',"'"], css:true, kw:words('important inherit initial unset none auto')},
    sql:{label:'SQL', line:['--'], block:['/*','*/'], strings:["'",'"'], nocase:true, kw:words('select from where and or not insert into values update set delete create table alter drop index view primary key foreign references join left right inner outer on group by order having limit offset as distinct union all null is like in between exists case when then else end default auto_increment int integer varchar text date datetime timestamp boolean if begin commit rollback grant')},
    yaml:{label:'YAML', line:['#'], strings:['"',"'"], keys:true, kw:words('true false null yes no on off')},
    ini:{label:'INI / config', line:['#',';'], strings:['"',"'"], keys:true, kw:words('true false on off yes no')},
    html:{label:'HTML', markup:true},
    xml:{label:'XML', markup:true},
    md:{label:'Markdown', markdown:true},
  };
  const EXT = {js:'js',mjs:'js',cjs:'js',jsx:'js',ts:'ts',tsx:'ts',json:'json',py:'py',php:'php',phtml:'php',sh:'sh',bash:'sh',zsh:'sh',css:'css',scss:'css',less:'css',sql:'sql',yml:'yaml',yaml:'yaml',ini:'ini',conf:'ini',cfg:'ini',env:'ini',toml:'ini',properties:'ini',htaccess:'ini',html:'html',htm:'html',vue:'html',svg:'xml',xml:'xml',md:'md',markdown:'md',txt:'plain',log:'plain',csv:'plain'};
  const TEXT_NAMES = /^(dockerfile|makefile|readme|license|changelog|\.?[\w.-]*rc|\.env.*|\.htaccess|\.gitignore|\.editorconfig|crontab|hosts)$/i;
  const HIGHLIGHT_LIMIT = 300000;  // Por encima, solo texto plano: el resaltado completo por tecla sería lento.

  function languageFor(name){
    const lower=name.toLowerCase(),dot=lower.lastIndexOf('.'),ext=dot>=0?lower.slice(dot+1):'';
    if(EXT[ext])return EXT[ext];
    if(/^dockerfile$|^makefile$|\.sh$/.test(lower))return 'sh';
    if(/^\.env/.test(lower)||lower==='.htaccess')return 'ini';
    return 'plain';
  }
  const isText = name => languageFor(name)!=='plain' || /\.(txt|log|csv)$/i.test(name) || TEXT_NAMES.test(name);
  const reEsc = s => s.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');

  function stringPattern(q){
    if(q.length===3)return reEsc(q)+'[\\s\\S]*?(?:'+reEsc(q)+'|$)';
    const multi=q==='`';
    return reEsc(q)+'(?:\\\\[\\s\\S]|[^\\\\'+reEsc(q)+(multi?'':'\\n')+'])*(?:'+reEsc(q)+'|$)';
  }
  const compiled = {};
  function tokenizer(lang){
    if(compiled[lang])return compiled[lang];
    const L=LANGS[lang],parts=[];
    if(L.block)parts.push('(?<c1>'+reEsc(L.block[0])+'[\\s\\S]*?(?:'+reEsc(L.block[1])+'|$))');
    if(L.line)parts.push('(?<c2>(?:'+L.line.map(reEsc).join('|')+')[^\\n]*)');
    if(L.strings)parts.push('(?<s>'+L.strings.map(stringPattern).join('|')+')');
    if(L.keys)parts.push('(?<k>^[ \\t-]*[\\w.$@"\'-]+(?=[ \\t]*[:=]))');
    if(L.css)parts.push('(?<p>[\\w-]+(?=\\s*:[^;{]*[;}\\n]))|(?<sel>[.#][\\w-]+|@[\\w-]+)');
    parts.push('(?<n>\\b(?:0x[\\da-fA-F]+|\\d[\\d_]*(?:\\.\\d+)?(?:[eE][+-]?\\d+)?)(?:px|em|rem|%|s|ms|vh|vw|deg)?\\b)');
    parts.push('(?<w>[A-Za-z_$][\\w$]*)');
    return compiled[lang]=new RegExp(parts.join('|'),'gm');
  }
  function highlightCode(text,lang){
    const L=LANGS[lang],re=tokenizer(lang);let out='',last=0;
    for(const m of text.matchAll(re)){
      const g=m.groups,value=m[0];let cls=null;
      if(g.c1||g.c2)cls='tok-comment';else if(g.s)cls='tok-string';else if(g.k)cls='tok-key';
      else if(g.p)cls='tok-key';else if(g.sel)cls='tok-tag';else if(g.n)cls='tok-number';
      else if(g.w&&L.kw&&L.kw.has(L.nocase?value.toLowerCase():value))cls='tok-keyword';
      else if(g.w&&text[m.index+value.length]==='(')cls='tok-func';
      if(!cls)continue;
      out+=escapeHtml(text.slice(last,m.index))+`<span class="${cls}">${escapeHtml(value)}</span>`;last=m.index+value.length;
    }
    return out+escapeHtml(text.slice(last));
  }
  function highlightMarkup(text){
    const re=/(?<c><!--[\s\S]*?(?:-->|$))|(?<t><\/?[\w:.-]+|\/?>|<!DOCTYPE[^>]*>|<\?[\w]*|\?>)|(?<a>[\w:.-]+)(?==)|(?<s>"[^"]*"|'[^']*')/gi;
    let out='',last=0,inTag=false;
    for(const m of text.matchAll(re)){
      const g=m.groups,value=m[0];let cls=null;
      if(g.c)cls='tok-comment';
      else if(g.t){cls='tok-tag';inTag=!/>$/.test(value);}
      else if(inTag&&g.a)cls='tok-attr';
      else if(inTag&&g.s)cls='tok-string';
      if(!cls)continue;
      out+=escapeHtml(text.slice(last,m.index))+`<span class="${cls}">${escapeHtml(value)}</span>`;last=m.index+value.length;
    }
    return out+escapeHtml(text.slice(last));
  }
  function highlightMarkdown(text){
    return text.split('\n').map(line=>{
      if(/^\s{0,3}#{1,6}\s/.test(line))return `<span class="tok-keyword">${escapeHtml(line)}</span>`;
      if(/^\s*(```|~~~)/.test(line))return `<span class="tok-comment">${escapeHtml(line)}</span>`;
      return escapeHtml(line).replace(/(`[^`]+`)/g,'<span class="tok-string">$1</span>').replace(/(\*\*[^*]+\*\*)/g,'<span class="tok-func">$1</span>').replace(/^(\s*[-*+]|\s*\d+\.)\s/,'<span class="tok-number">$1</span> ');
    }).join('\n');
  }
  function highlight(text,lang){
    const L=LANGS[lang];
    if(!L||lang==='plain')return escapeHtml(text);
    if(L.markup)return highlightMarkup(text);
    if(L.markdown)return highlightMarkdown(text);
    return highlightCode(text,lang);
  }
  function detectIndent(text){
    const lines=text.split('\n',2000);let tabs=0,two=0,four=0;
    for(const line of lines){if(line.startsWith('\t'))tabs++;else{const n=/^ +/.exec(line)?.[0].length||0;if(n&&n%4===0)four++;else if(n%2===0&&n)two++;}}
    return tabs>two+four?'\t':two>four?'  ':'    ';
  }

  let state=null;
  function els(){return {dialog:$('#editor-dialog'),area:$('#editor-text'),pre:$('#code-highlight'),gutter:$('#code-gutter'),status:$('#editor-status'),pos:$('#editor-pos'),lang:$('#editor-lang'),wrap:$('#editor-wrap'),find:$('#editor-find'),findInput:$('#find-input'),replaceInput:$('#replace-input'),findCount:$('#find-count'),findCase:$('#find-case'),title:$('#editor-title'),path:$('#editor-path'),save:$('#editor-save')};}

  function render(){
    const e=els(),text=e.area.value,plain=text.length>HIGHLIGHT_LIMIT;
    e.area.classList.toggle('plain',plain);
    e.pre.innerHTML=plain?'':highlight(text,state.lang)+'\n ';
    const lines=text.split('\n').length;
    if(lines!==state.lines){state.lines=lines;e.gutter.textContent=Array.from({length:lines},(_,i)=>i+1).join('\n')+'\n ';}
    e.pre.style.width=e.area.clientWidth+'px';
    sync();updateStatus();
  }
  let pending=0;
  const schedule=()=>{cancelAnimationFrame(pending);pending=requestAnimationFrame(render);};
  function sync(){const e=els();e.pre.scrollTop=e.area.scrollTop;e.pre.scrollLeft=e.area.scrollLeft;e.gutter.scrollTop=e.area.scrollTop;}
  function updateStatus(){
    const e=els(),before=e.area.value.slice(0,e.area.selectionStart),line=before.split('\n').length,col=before.length-before.lastIndexOf('\n'),sel=e.area.selectionEnd-e.area.selectionStart;
    const dirty=e.area.value!==state.saved;
    e.pos.textContent=`Línea ${line}, col ${col}${sel?` · ${sel} seleccionados`:''} · ${state.crlf?'CRLF':'LF'} · UTF-8 · ${state.indent==='\t'?'Tabulaciones':state.indent.length+' espacios'}`;
    e.title.textContent=(dirty?'● ':'')+state.name;
  }
  function insert(text){
    // execCommand conserva el historial de deshacer del navegador; setRangeText es el respaldo.
    const area=els().area;
    if(!document.execCommand('insertText',false,text))area.setRangeText(text,area.selectionStart,area.selectionEnd,'end');
  }
  function lineBounds(value,start,end){
    const from=value.lastIndexOf('\n',start-1)+1;let to=value.indexOf('\n',end>start&&value[end-1]==='\n'?end-1:end);if(to<0)to=value.length;
    return [from,to];
  }
  function indentSelection(outdent){
    const area=els().area,value=area.value,unit=state.indent,[from,to]=lineBounds(value,area.selectionStart,area.selectionEnd);
    const block=value.slice(from,to),lines=block.split('\n');
    const changed=lines.map(l=>outdent?(l.startsWith(unit)?l.slice(unit.length):l.replace(/^[ \t]{1,4}/,'')):(l?unit+l:l)).join('\n');
    area.setSelectionRange(from,to);insert(changed);area.setSelectionRange(from,from+changed.length);
  }
  function onDialogKey(ev){
    // Atajos de todo el editor, también con el foco en la barra de búsqueda.
    const mod=ev.ctrlKey||ev.metaKey,key=ev.key.toLowerCase();
    if(mod&&key==='s'){ev.preventDefault();return ev.shiftKey?saveAndClose():save();}
    if(mod&&key==='f'){ev.preventDefault();return openFind(false);}
    if(mod&&key==='h'){ev.preventDefault();return openFind(true);}
    if(mod&&key==='g'){ev.preventDefault();return goToLine();}
    if(ev.key==='F3'){ev.preventDefault();return findNext(!ev.shiftKey);}
  }
  function onKey(ev){
    const area=els().area,mod=ev.ctrlKey||ev.metaKey;
    if(ev.key==='Tab'){
      ev.preventDefault();
      if(ev.shiftKey||area.value.slice(area.selectionStart,area.selectionEnd).includes('\n'))return indentSelection(ev.shiftKey);
      return insert(state.indent);
    }
    if(ev.key==='Enter'&&!mod&&!ev.altKey){
      // Autoindentado: conserva la sangría y añade un nivel tras { [ ( : o una etiqueta abierta.
      ev.preventDefault();
      const value=area.value,start=area.selectionStart,lineStart=value.lastIndexOf('\n',start-1)+1,line=value.slice(lineStart,start);
      let indent=/^[ \t]*/.exec(line)[0];
      if(/[{[(:]\s*$/.test(line)||/<(?![\/!?])[^>]*[^\/]>\s*$/.test(line))indent+=state.indent;
      return insert('\n'+indent);
    }
    if(ev.key==='Escape'&&!els().find.hidden){ev.preventDefault();ev.stopPropagation();els().find.hidden=true;area.focus();}
  }
  function matches(){
    const e=els(),needle=e.findInput.value;if(!needle)return [];
    const hay=e.findCase.checked?e.area.value:e.area.value.toLowerCase(),n=e.findCase.checked?needle:needle.toLowerCase(),out=[];
    for(let i=hay.indexOf(n);i>=0&&out.length<100000;i=hay.indexOf(n,i+Math.max(n.length,1)))out.push(i);
    return out;
  }
  function select(start,length){
    const area=els().area;area.focus();area.setSelectionRange(start,start+length);
    const line=area.value.slice(0,start).split('\n').length,lineHeight=parseFloat(getComputedStyle(area).lineHeight)||18;
    area.scrollTop=Math.max(0,(line-5)*lineHeight);sync();updateStatus();
  }
  function findNext(forward=true){
    const e=els(),found=matches(),len=e.findInput.value.length;
    e.findCount.textContent=e.findInput.value?(found.length?`${found.length} coincidencia${found.length>1?'s':''}`:'Sin resultados'):'';
    if(!found.length)return;
    const pos=forward?e.area.selectionEnd:e.area.selectionStart;
    const next=forward?(found.find(i=>i>=pos)??found[0]):([...found].reverse().find(i=>i+len<=pos)??found[found.length-1]);
    select(next,len);e.findCount.textContent=`${found.indexOf(next)+1} de ${found.length}`;
  }
  function replaceOne(){
    const e=els(),needle=e.findInput.value,area=e.area,current=area.value.slice(area.selectionStart,area.selectionEnd);
    if(needle&&(e.findCase.checked?current===needle:current.toLowerCase()===needle.toLowerCase()))insert(e.replaceInput.value);
    findNext(true);
  }
  function replaceAll(){
    const e=els(),found=matches(),len=e.findInput.value.length;if(!found.length)return;
    let value=e.area.value;for(const i of [...found].reverse())value=value.slice(0,i)+e.replaceInput.value+value.slice(i+len);
    e.area.focus();e.area.select();insert(value);e.area.setSelectionRange(0,0);
    e.findCount.textContent=`${found.length} reemplazada${found.length>1?'s':''}`;
  }
  function openFind(withReplace){
    const e=els(),selected=e.area.value.slice(e.area.selectionStart,e.area.selectionEnd);
    e.find.hidden=false;e.find.classList.toggle('with-replace',withReplace);
    if(selected&&!selected.includes('\n'))e.findInput.value=selected;
    (withReplace&&e.findInput.value?e.replaceInput:e.findInput).focus();e.findInput.select();
  }
  async function goToLine(){
    const e=els(),answer=await state.ask(`Escribe un número entre 1 y ${state.lines}.`,{title:'Ir a la línea',input:true,value:'',accept:'Ir'});
    const n=parseInt(answer,10);if(!n)return e.area.focus();
    const lines=e.area.value.split('\n'),target=Math.min(Math.max(n,1),lines.length);
    select(lines.slice(0,target-1).reduce((s,l)=>s+l.length+1,0),0);
  }
  let saving=false;
  const saveButtons=()=>[$('#editor-save'),$('#editor-save-close')];
  async function save(){
    // Devuelve si quedó guardado, para que «Guardar y cerrar» solo cierre tras guardar.
    const e=els();if(saving)return false;
    saving=true;saveButtons().forEach(b=>b.disabled=true);e.status.textContent='Guardando…';
    const text=e.area.value,payload=state.crlf?text.replace(/\n/g,'\r\n'):text;
    try{await state.save(payload);state.saved=text;e.status.textContent='Guardado ✓ '+new Date().toLocaleTimeString('es-PE');return true;}
    catch(err){e.status.textContent='No se guardó: '+err.message;return false;}
    finally{saving=false;saveButtons().forEach(b=>b.disabled=false);updateStatus();}
  }
  async function saveAndClose(){if(await save())finish();}
  function finish(){els().dialog.close();state.onClose?.();}
  function setWrap(on){
    const e=els();state.wrap=on;e.wrap.setAttribute('aria-pressed',String(on));
    e.area.wrap=on?'soft':'off';e.dialog.classList.toggle('wrap',on);
    try{localStorage.setItem('lftp-web-wrap',on?'1':'0');}catch{}
    schedule();
  }

  function bind(){
    if(bind.done)return;bind.done=true;
    const e=els();
    e.area.addEventListener('input',schedule);
    e.area.addEventListener('scroll',sync);
    e.area.addEventListener('keydown',onKey);
    e.dialog.addEventListener('keydown',onDialogKey);
    for(const ev of ['keyup','click','select'])e.area.addEventListener(ev,updateStatus);
    new ResizeObserver(schedule).observe(e.area);
    e.lang.addEventListener('change',()=>{state.lang=e.lang.value;schedule();e.area.focus();});
    e.wrap.addEventListener('click',()=>setWrap(!state.wrap));
    e.save.addEventListener('click',save);
    $('#editor-find-btn').addEventListener('click',()=>openFind(false));
    $('#editor-replace-btn').addEventListener('click',()=>openFind(true));
    $('#editor-goto-btn').addEventListener('click',goToLine);
    $('#find-next').addEventListener('click',()=>findNext(true));
    $('#find-prev').addEventListener('click',()=>findNext(false));
    $('#replace-one').addEventListener('click',replaceOne);
    $('#replace-all').addEventListener('click',replaceAll);
    $('#find-close').addEventListener('click',()=>{e.find.hidden=true;e.area.focus();});
    e.findInput.addEventListener('input',()=>{const found=matches();e.findCount.textContent=e.findInput.value?`${found.length} coincidencia${found.length===1?'':'s'}`:'';});
    for(const input of [e.findInput,e.replaceInput])input.addEventListener('keydown',ev=>{
      if(ev.key==='Enter'){ev.preventDefault();input===e.replaceInput?replaceOne():findNext(!ev.shiftKey);}
      else if(ev.key==='Escape'){ev.preventDefault();ev.stopPropagation();e.find.hidden=true;e.area.focus();}
    });
    e.findCase.addEventListener('change',()=>findNext(true));
    $('#editor-close').addEventListener('click',close);
    $('#editor-cancel').addEventListener('click',close);
    $('#editor-save-close').addEventListener('click',saveAndClose);
    e.dialog.addEventListener('cancel',ev=>{ev.preventDefault();if(e.find.hidden)close();});
    e.lang.innerHTML=Object.entries(LANGS).map(([k,v])=>`<option value="${k}">${v.label}</option>`).join('');
  }
  async function close(){
    const e=els();
    if(e.area.value===state.saved)return finish();
    const answer=await state.ask(`«${state.name}» tiene cambios sin guardar.`,{title:'Cambios sin guardar',accept:'Guardar y cerrar',alt:'Cerrar sin guardar'});
    if(answer==='alt')return finish();
    if(answer)return saveAndClose();
    e.area.focus();
  }

  function open({name,path,where,content,save,ask,onClose}){
    bind();
    const e=els(),crlf=content.includes('\r\n'),text=crlf?content.replace(/\r\n/g,'\n'):content;
    state={name,lang:languageFor(name),saved:text,crlf,indent:detectIndent(text),lines:0,wrap:false,save,ask,onClose};
    e.path.textContent=where+' · '+path;e.status.textContent='';e.find.hidden=true;
    e.lang.value=state.lang;e.area.value=text;
    let wrap=false;try{wrap=localStorage.getItem('lftp-web-wrap')==='1';}catch{}
    e.dialog.showModal();setWrap(wrap);render();
    e.area.focus();e.area.setSelectionRange(0,0);e.area.scrollTop=0;sync();
  }
  return {open,isText,languageFor};
})();

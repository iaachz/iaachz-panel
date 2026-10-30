/* iaachz-panel — utilidades y componentes Alpine de las pantallas estilo Plesk.
 * Se carga de forma síncrona en <head> (antes que Alpine) para registrar los componentes a tiempo. */
(function () {
	'use strict';

	const HP = (window.HP = {});

	// Traducción: el texto en español es la clave (ver web/pro/inc/i18n.php). HP.t('Hay %s sitios', n)
	HP.lang = window.HP_LANG || 'es';
	HP.t = function (s, ...args) {
		let i = 0;
		return ((window.HP_I18N && window.HP_I18N[s]) || s).replace(/%[sd]/g, (m) => (i < args.length ? args[i++] : m));
	};

	// ------------------------------------------------------------------ utilidades

	HP.api = async function (action, data = {}) {
		const res = await fetch('/pro/api/', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(Object.assign({ action, token: window.HP_TOKEN }, data)),
			credentials: 'same-origin',
		});
		let json;
		try {
			json = await res.json();
		} catch (e) {
			throw new Error(HP.t('Respuesta no válida del servidor (%s)', res.status));
		}
		if (!res.ok || (json && json.error)) {
			throw new Error((json && json.error) || HP.t('Error %s', res.status));
		}
		return json;
	};

	HP.bytes = function (n, dec = 1) {
		n = Number(n) || 0;
		const u = ['B', 'KB', 'MB', 'GB', 'TB'];
		let i = 0;
		while (n >= 1024 && i < u.length - 1) {
			n /= 1024;
			i++;
		}
		return n.toFixed(i ? dec : 0).replace('.', HP.lang === 'en' ? '.' : ',') + ' ' + u[i];
	};

	HP.rate = (n) => HP.bytes(n) + '/s';

	HP.duration = function (s) {
		s = Math.floor(Number(s) || 0);
		const d = Math.floor(s / 86400),
			h = Math.floor((s % 86400) / 3600),
			m = Math.floor((s % 3600) / 60);
		if (d) return d + ' d ' + h + ' h';
		if (h) return h + ' h ' + m + ' min';
		return m + ' min';
	};

	HP.esc = (s) =>
		String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

	HP.level = (p, warn = 75, danger = 90) => (p >= danger ? 'danger' : p >= warn ? 'warn' : 'ok');

	HP.color = (p, warn = 75, danger = 90) => (p >= danger ? '#d23f3f' : p >= warn ? '#e08a00' : '#1f6fd6');

	HP.toast = function (msg, type = 'ok') {
		let box = document.querySelector('.hp-toasts');
		if (!box) {
			box = document.createElement('div');
			box.className = 'hp-toasts';
			document.body.appendChild(box);
		}
		const t = document.createElement('div');
		t.className = 'hp-toast ' + type;
		t.innerHTML =
			'<i class="fas ' + (type === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation') + '"></i><span></span>';
		t.querySelector('span').textContent = msg;
		box.appendChild(t);
		setTimeout(() => t.remove(), type === 'ok' ? 3500 : 7000);
	};

	HP.copy = function (text) {
		navigator.clipboard?.writeText(text).then(() => HP.toast(HP.t('Copiado al portapapeles')));
	};

	HP.password = function (n = 16) {
		const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
		const arr = new Uint32Array(n);
		crypto.getRandomValues(arr);
		let p = Array.from(arr, (x) => chars[x % chars.length]).join('');
		return 'A' + p.slice(1, -2) + 'a7';
	};

	// Diálogo genérico (confirmaciones y tareas)
	function dialog(title, bodyHtml, footHtml) {
		const d = document.createElement('dialog');
		d.className = 'hp-modal';
		d.innerHTML =
			'<div class="hp-modal-head"><h3></h3><button type="button" class="hp-icon-btn" data-close><i class="fas fa-xmark"></i></button></div>' +
			'<div class="hp-modal-body">' + bodyHtml + '</div>' +
			(footHtml ? '<div class="hp-modal-foot">' + footHtml + '</div>' : '');
		d.querySelector('h3').textContent = title;
		document.body.appendChild(d);
		d.addEventListener('close', () => setTimeout(() => d.remove(), 50));
		d.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => d.close()));
		d.showModal();
		return d;
	}
	HP.dialog = dialog;

	HP.confirm = function (message, { title = HP.t('Confirmar'), ok = HP.t('Continuar'), danger = false } = {}) {
		return new Promise((resolve) => {
			const d = dialog(
				title,
				'<p style="margin:0"></p>',
				'<button type="button" class="hp-btn" data-close>' + HP.t('Cancelar') + '</button>' +
					'<button type="button" class="hp-btn ' + (danger ? 'hp-btn-danger' : 'hp-btn-primary') + '" data-ok></button>',
			);
			d.querySelector('p').textContent = message;
			d.querySelector('[data-ok]').textContent = ok;
			let answered = false;
			d.querySelector('[data-ok]').addEventListener('click', () => {
				answered = true;
				d.close();
				resolve(true);
			});
			d.addEventListener('close', () => answered || resolve(false));
		});
	};

	HP.logs = async function (title, loader) {
		const d = dialog(title, '<pre class="hp-log">' + HP.t('Cargando…') + '</pre>', '<button type="button" class="hp-btn" data-reload><i class="fas fa-rotate"></i>' + HP.t('Actualizar') + '</button><button type="button" class="hp-btn hp-btn-primary" data-close>' + HP.t('Cerrar') + '</button>');
		d.style.width = 'min(1000px, calc(100vw - 24px))';
		const pre = d.querySelector('pre');
		const load = async () => {
			try {
				const r = await loader();
				pre.textContent = (r.log || '').trim() || '(sin registros)';
				pre.scrollTop = pre.scrollHeight;
			} catch (e) {
				pre.textContent = HP.t('Error: %s', e.message);
			}
		};
		d.querySelector('[data-reload]').addEventListener('click', load);
		load();
	};

	// Sigue una tarea en segundo plano mostrando pasos y registro. Devuelve el resultado final.
	HP.job = function (jobId, title = HP.t('Trabajando…'), { autoClose = false } = {}) {
		return new Promise((resolve, reject) => {
			const d = dialog(
				title,
				'<ul class="hp-steps"><li class="is-running"><i class="fas fa-circle-notch fa-spin"></i><span>' + HP.t('Iniciando…') + '</span></li></ul>' +
					'<details class="hp-mt"><summary class="hp-muted hp-small" style="cursor:pointer">' + HP.t('Ver registro detallado') + '</summary><pre class="hp-log hp-mt"></pre></details>',
				'<span class="hp-muted hp-small" data-status style="margin-right:auto"></span><button type="button" class="hp-btn" data-close>' + HP.t('Cerrar') + '</button>',
			);
			const steps = d.querySelector('.hp-steps');
			const pre = d.querySelector('pre');
			const status = d.querySelector('[data-status]');
			let done = false;
			const icon = { ok: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation', running: 'fa-circle-notch fa-spin' };
			const tick = async () => {
				let j;
				try {
					j = await HP.api('job-status', { id: jobId });
				} catch (e) {
					status.textContent = e.message;
					return setTimeout(tick, 2500);
				}
				const list = j.steps || [];
				steps.innerHTML = list
					.map((s, i) => {
						// un paso "running" ya superado se muestra como completado
						let st = s.status;
						if (st === 'running' && (i < list.length - 1 || j.status !== 'running')) st = j.status === 'error' && i === list.length - 1 ? 'error' : 'ok';
						return '<li class="is-' + st + '"><i class="fas ' + (icon[st] || 'fa-circle') + '"></i><span>' + HP.esc(s.name) + (s.detail ? '<small>' + HP.esc(s.detail) + '</small>' : '') + '</span></li>';
					})
					.join('') || '<li class="is-running"><i class="fas fa-circle-notch fa-spin"></i><span>' + HP.t('Iniciando…') + '</span></li>';
				const log = (j.log || '').split('\n').filter((l) => !l.startsWith('::result::')).join('\n');
				pre.textContent = log || '(sin salida)';
				if (j.status === 'running') {
					status.textContent = HP.t('En curso…');
					return setTimeout(tick, 1200);
				}
				done = true;
				let result = null;
				const m = (j.log || '').match(/::result::(.*)$/m);
				if (m) {
					try {
						result = JSON.parse(m[1]);
					} catch (e) {}
				}
				if (j.status === 'ok') {
					status.innerHTML = '<span style="color:var(--hp-ok)"><i class="fas fa-circle-check"></i> Completado</span>';
					if (autoClose) setTimeout(() => d.close(), 900);
					resolve({ result, dialog: d, job: j });
				} else {
					status.innerHTML = '<span style="color:var(--hp-danger)"><i class="fas fa-circle-xmark"></i> ' + HP.esc(j.error || HP.t('Falló')) + '</span>';
					d.querySelector('details').open = true;
					reject(Object.assign(new Error(j.error || HP.t('La tarea falló')), { dialog: d }));
				}
			};
			tick();
			d.addEventListener('close', () => {
				if (!done) HP.toast(HP.t('La tarea sigue en segundo plano. Puedes verla en Herramientas → Tareas.'), 'ok');
			});
		});
	};

	// Ejecuta una acción que devuelve {job} y sigue su progreso
	HP.runJob = async function (action, data, title, opts) {
		const r = await HP.api(action, data);
		if (!r.job) return r;
		return HP.job(r.job, title, opts);
	};

	let chartPromise = null;
	HP.Chart = function () {
		if (!chartPromise) {
			const path = '/js/dist/chart.js-auto.min.js';
			chartPromise = import(path).then((m) => m.Chart || m.default);
		}
		return chartPromise;
	};

	HP.css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

	// opts.static: gráfico para exportar (tamaño fijo, sin animación, colores claros)
	HP.lineChart = async function (canvas, labels, datasets, opts = {}) {
		const Chart = await HP.Chart();
		const grid = opts.static ? '#e5e7eb' : HP.css('--chart-grid-color') || '#e9eef4';
		const label = opts.static ? '#475569' : HP.css('--chart-label-color') || '#64748b';
		if (opts.static) opts = Object.assign({}, opts, { animation: false });
		return new Chart(canvas, {
			type: 'line',
			data: {
				labels,
				datasets: datasets.map((ds) =>
					Object.assign(
						{
							tension: 0.35,
							pointRadius: 0,
							pointHoverRadius: 3,
							borderWidth: 2,
							fill: ds.fill ?? true,
							backgroundColor: (ds.borderColor || '#1f6fd6') + '1f',
						},
						ds,
					),
				),
			},
			options: {
				responsive: !opts.static,
				maintainAspectRatio: false,
				devicePixelRatio: opts.static ? 2.5 : undefined,
				animation: opts.animation ?? { duration: 300 },
				interaction: { mode: 'index', intersect: false },
				plugins: {
					legend: { display: datasets.length > 1, position: 'bottom', labels: { color: label, boxWidth: 10, boxHeight: 10, usePointStyle: true, font: opts.static ? { size: 13 } : undefined } },
					tooltip: { callbacks: { label: (c) => ' ' + c.dataset.label + ': ' + (opts.fmt ? opts.fmt(c.parsed.y) : c.parsed.y) } },
				},
				scales: {
					x: { ticks: { color: label, maxTicksLimit: 8, maxRotation: 0, font: opts.static ? { size: 12 } : undefined }, grid: { display: false } },
					y: {
						beginAtZero: true,
						suggestedMax: opts.max,
						max: opts.hardMax,
						ticks: { color: label, maxTicksLimit: 5, callback: (v) => (opts.fmt ? opts.fmt(v) : v), font: opts.static ? { size: 12 } : undefined },
						grid: { color: grid },
					},
				},
			},
		});
	};

	/* Dibujador de gráficos SVG que viaja DENTRO del informe exportado (se incrusta con toString()).
	 * Sin dependencias ni internet: lee window.HP_CHARTS y dibuja líneas con cursor, tooltip y leyenda
	 * que oculta/muestra series. No usar backticks ni cerrar etiquetas script aquí dentro. */
	HP.reportCharts = function () {
		var NS = 'http://www.w3.org/2000/svg';
		var data = window.HP_CHARTS || {};
		function bytes(n) {
			var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
			n = Number(n) || 0;
			while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
			return n.toFixed(i ? 1 : 0).replace('.', ',') + ' ' + u[i];
		}
		var FMT = {
			pct: function (v) { return Math.round(v) + '%'; },
			fixed2: function (v) { return Number(v).toFixed(2); },
			num1: function (v) { return (Math.round(v * 10) / 10).toLocaleString(); },
			int: function (v) { return Math.round(v).toLocaleString(); },
			rate: function (v) { return bytes(v) + '/s'; },
		};
		function nice(v) {
			if (!(v > 0)) return 1;
			var e = Math.pow(10, Math.floor(Math.log10(v))), f = v / e;
			return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * e;
		}
		function el(name, attrs, parent) {
			var n = document.createElementNS(NS, name);
			for (var k in attrs) n.setAttribute(k, attrs[k]);
			if (parent) parent.appendChild(n);
			return n;
		}
		function draw(box) {
			var c = data[box.getAttribute('data-chart')];
			if (!c) return;
			var off = box._off || (box._off = {});
			var fmt = FMT[c.ftype] || FMT.num1;
			box.innerHTML = '';
			var lg = document.createElement('div');
			lg.className = 'lg';
			c.series.forEach(function (s, i) {
				var b = document.createElement('button');
				b.type = 'button';
				b.className = off[i] ? 'off' : '';
				b.innerHTML = '<i style="background:' + s.color + '"></i>';
				b.appendChild(document.createTextNode(s.name));
				b.title = HP.t('Mostrar / ocultar');
				b.onclick = function () { off[i] = !off[i]; draw(box); };
				lg.appendChild(b);
			});
			box.appendChild(lg);
			var n = c.labels.length;
			var vis = c.series.map(function (s, i) { return { s: s, i: i }; }).filter(function (o) {
				return !off[o.i] && o.s.values.some(function (v) { return v !== null && v !== undefined; });
			});
			if (n < 2 || !vis.length) {
				var e = document.createElement('div');
				e.className = 'empty';
				e.textContent = n < 2 ? HP.t('Sin datos en este periodo') : HP.t('Todas las series están ocultas');
				box.appendChild(e);
				return;
			}
			var W = Math.max(280, box.clientWidth || 500), H = 210, m = { l: 58, r: 12, t: 8, b: 24 };
			var iw = W - m.l - m.r, ih = H - m.t - m.b;
			var ymax = 0;
			vis.forEach(function (o) { o.s.values.forEach(function (v) { if (v > ymax) ymax = v; }); });
			ymax = c.max || nice(ymax * 1.08);
			var sx = function (i) { return m.l + (i / (n - 1)) * iw; };
			var sy = function (v) { return m.t + ih - (Math.min(v, ymax) / ymax) * ih; };
			var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, height: H });
			for (var g = 0; g <= 4; g++) {
				var gv = (ymax / 4) * g, gy = sy(gv);
				el('line', { x1: m.l, x2: m.l + iw, y1: gy, y2: gy, class: g ? 'gl' : 'base' }, svg);
				el('text', { x: m.l - 6, y: gy + 4, 'text-anchor': 'end', class: 'ax' }, svg).textContent = fmt(gv);
			}
			var ticks = Math.max(2, Math.min(6, n, Math.floor(iw / 95)));
			for (var t = 0; t < ticks; t++) {
				var ti = Math.round(((n - 1) / (ticks - 1)) * t);
				el('text', { x: sx(ti), y: H - 6, 'text-anchor': t === 0 ? 'start' : t === ticks - 1 ? 'end' : 'middle', class: 'ax' }, svg).textContent = c.labels[ti];
			}
			vis.forEach(function (o, k) {
				var d = '', pen = false, first = null, lastI = null;
				o.s.values.forEach(function (v, i) {
					if (v === null || v === undefined) { pen = false; return; }
					d += (pen ? 'L' : 'M') + sx(i).toFixed(1) + ',' + sy(v).toFixed(1);
					pen = true;
					if (first === null) first = i;
					lastI = i;
				});
				if (o.s.fill && k === 0 && first !== null) {
					el('path', { d: d + 'L' + sx(lastI).toFixed(1) + ',' + sy(0) + 'L' + sx(first).toFixed(1) + ',' + sy(0) + 'Z', fill: o.s.color, class: 'area' }, svg);
				}
				el('path', { d: d, stroke: o.s.color, class: 'ln' + (o.s.dash ? ' dash' : '') }, svg);
			});
			var cur = el('line', { y1: m.t, y2: m.t + ih, class: 'cur', visibility: 'hidden' }, svg);
			var dots = vis.map(function (o) { return el('circle', { r: 4, fill: o.s.color, class: 'dot', visibility: 'hidden' }, svg); });
			var hit = el('rect', { x: m.l, y: m.t, width: iw, height: ih, fill: 'transparent' }, svg);
			box.appendChild(svg);
			var tip = document.createElement('div');
			tip.className = 'tip';
			box.appendChild(tip);
			hit.addEventListener('mouseleave', function () {
				cur.setAttribute('visibility', 'hidden');
				dots.forEach(function (d) { d.setAttribute('visibility', 'hidden'); });
				tip.style.display = 'none';
			});
			hit.addEventListener('mousemove', function (ev) {
				var r = svg.getBoundingClientRect();
				var px = ((ev.clientX - r.left) / r.width) * W;
				var i = Math.max(0, Math.min(n - 1, Math.round(((px - m.l) / iw) * (n - 1))));
				var cx = sx(i);
				cur.setAttribute('x1', cx);
				cur.setAttribute('x2', cx);
				cur.setAttribute('visibility', 'visible');
				var rows = '<b>' + (c.tips ? c.tips[i] : c.labels[i]) + '</b>';
				vis.forEach(function (o, k) {
					var v = o.s.values[i];
					if (v === null || v === undefined) { dots[k].setAttribute('visibility', 'hidden'); return; }
					dots[k].setAttribute('cx', cx);
					dots[k].setAttribute('cy', sy(v));
					dots[k].setAttribute('visibility', 'visible');
					rows += '<br><span class="sw" style="background:' + o.s.color + '"></span>' + o.s.name + ': <b>' + fmt(v) + '</b>';
				});
				tip.innerHTML = rows;
				tip.style.display = 'block';
				var left = (cx / W) * r.width, tw = tip.offsetWidth;
				tip.style.left = (left + 14 + tw > r.width ? left - tw - 14 : left + 14) + 'px';
				tip.style.top = lg.offsetHeight + 6 + 'px';
			});
		}
		function all() {
			var boxes = document.querySelectorAll('[data-chart]');
			for (var i = 0; i < boxes.length; i++) draw(boxes[i]);
		}
		all();
		var timer;
		window.addEventListener('resize', function () { clearTimeout(timer); timer = setTimeout(all, 150); });
		window.addEventListener('beforeprint', all);
		window.addEventListener('afterprint', all);
	};

	HP.timeLabel = function (ts, range) {
		const d = new Date(ts * 1000);
		if (['1h', '6h', '24h', 'live'].includes(range)) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
		if (range === '7d') return d.toLocaleDateString([], { weekday: 'short' }) + ' ' + d.toLocaleTimeString([], { hour: '2-digit' }) + 'h';
		return d.toLocaleDateString([], { day: 'numeric', month: 'short' });
	};

	HP.ring = function (pct, color) {
		const r = 46,
			c = 2 * Math.PI * r,
			off = c * (1 - Math.min(Math.max(pct, 0), 100) / 100);
		return (
			'<svg width="112" height="112" viewBox="0 0 112 112"><circle class="hp-gauge-track" cx="56" cy="56" r="' + r + '"/>' +
			'<circle class="hp-gauge-bar" cx="56" cy="56" r="' + r + '" stroke="' + color + '" stroke-dasharray="' + c.toFixed(1) + '" stroke-dashoffset="' + off.toFixed(1) + '"/></svg>'
		);
	};

	// ------------------------------------------------------------------ países y mapa del mundo

	const REGION_NAMES = (() => {
		try {
			return new Intl.DisplayNames(['es'], { type: 'region' });
		} catch (e) {
			return null;
		}
	})();
	HP.countryName = function (cc) {
		if (!cc || cc === '--') return HP.t('Desconocido');
		if (cc === 'LO') return HP.t('Red local / privada');
		if (cc === 'IP') return HP.t('IPs bloqueadas');
		try {
			return (REGION_NAMES && REGION_NAMES.of(cc)) || cc;
		} catch (e) {
			return cc;
		}
	};
	let worldPromise = null;
	HP.world = function () {
		if (!worldPromise) worldPromise = fetch('/pro/assets/world.json?v=2', { credentials: 'same-origin' }).then((r) => r.json());
		return worldPromise;
	};

	// Mapa SVG con zoom (rueda o botones), arrastre y aviso al pasar el ratón. Sin librerías.
	// opts: { onClick(cc), tip(cc) -> html, compact }
	HP.worldMap = async function (el, opts = {}) {
		const w = await HP.world();
		const NS = 'http://www.w3.org/2000/svg';
		el.classList.add('hp-map');
		el.innerHTML =
			'<svg class="hp-map-svg" viewBox="0 0 ' + w.w + ' ' + w.h + '" role="img" aria-label="Mapa del mundo">' +
			'<defs><pattern id="hp-hatch" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="6" height="6" class="hp-map-hatch-bg"/><line x1="0" y1="0" x2="0" y2="6" class="hp-map-hatch"/></pattern></defs>' +
			'<g class="hp-map-countries"></g><g class="hp-map-dots"></g></svg>' +
			'<div class="hp-map-tip" hidden></div>' +
			'<div class="hp-map-zoom"><button type="button" data-z="1.6" title="Acercar"><i class="fas fa-plus"></i></button><button type="button" data-z="0.625" title="Alejar"><i class="fas fa-minus"></i></button><button type="button" data-z="0" title="Ver todo"><i class="fas fa-expand"></i></button></div>';
		const svg = el.querySelector('svg');
		const g = svg.querySelector('.hp-map-countries');
		const dots = svg.querySelector('.hp-map-dots');
		const tip = el.querySelector('.hp-map-tip');
		const paths = {};
		Object.keys(w.c).forEach((cc) => {
			const p = document.createElementNS(NS, 'path');
			p.setAttribute('d', w.c[cc]);
			p.dataset.cc = cc;
			g.appendChild(p);
			paths[cc] = p;
		});
		// zoom y arrastre sobre el viewBox
		const full = [0, 0, w.w, w.h];
		let vb = full.slice();
		const setVb = () => svg.setAttribute('viewBox', vb.map((n) => n.toFixed(1)).join(' '));
		const zoom = (k, cx, cy) => {
			if (!k) {
				vb = full.slice();
				return setVb();
			}
			const nw = Math.min(full[2], Math.max(full[2] / 12, vb[2] / k));
			const nh = (nw * full[3]) / full[2];
			cx = cx == null ? vb[0] + vb[2] / 2 : cx;
			cy = cy == null ? vb[1] + vb[3] / 2 : cy;
			const fx = (cx - vb[0]) / vb[2], fy = (cy - vb[1]) / vb[3];
			vb = [cx - fx * nw, cy - fy * nh, nw, nh];
			clamp();
			setVb();
		};
		const clamp = () => {
			vb[0] = Math.max(0, Math.min(full[2] - vb[2], vb[0]));
			vb[1] = Math.max(0, Math.min(full[3] - vb[3], vb[1]));
		};
		const toSvg = (e) => {
			const r = svg.getBoundingClientRect();
			return [vb[0] + ((e.clientX - r.left) / r.width) * vb[2], vb[1] + ((e.clientY - r.top) / r.height) * vb[3]];
		};
		el.querySelectorAll('.hp-map-zoom button').forEach((b) => b.addEventListener('click', () => zoom(Number(b.dataset.z))));
		svg.addEventListener('wheel', (e) => {
			if (!e.ctrlKey && vb[2] === full[2]) return; // sin Ctrl solo se acerca si ya hay zoom: deja hacer scroll a la página
			e.preventDefault();
			const [x, y] = toSvg(e);
			zoom(e.deltaY < 0 ? 1.25 : 0.8, x, y);
		}, { passive: false });
		let drag = null, moved = false;
		svg.addEventListener('pointerdown', (e) => {
			drag = { x: e.clientX, y: e.clientY, vb: vb.slice() };
			moved = false;
		});
		window.addEventListener('pointerup', () => (drag = null));
		svg.addEventListener('pointermove', (e) => {
			if (drag) {
				const r = svg.getBoundingClientRect();
				const dx = ((e.clientX - drag.x) / r.width) * vb[2], dy = ((e.clientY - drag.y) / r.height) * vb[3];
				if (Math.abs(e.clientX - drag.x) + Math.abs(e.clientY - drag.y) > 4) moved = true;
				if (moved && vb[2] < full[2]) {
					vb[0] = drag.vb[0] - dx;
					vb[1] = drag.vb[1] - dy;
					clamp();
					setVb();
				}
			}
			const cc = e.target.dataset && e.target.dataset.cc;
			if (!cc || !opts.tip) {
				tip.hidden = true;
				return;
			}
			tip.innerHTML = opts.tip(cc);
			tip.hidden = false;
			const r = el.getBoundingClientRect();
			let x = e.clientX - r.left + 14, y = e.clientY - r.top + 14;
			if (x + tip.offsetWidth > r.width) x = e.clientX - r.left - tip.offsetWidth - 14;
			if (y + tip.offsetHeight > r.height) y = e.clientY - r.top - tip.offsetHeight - 14;
			tip.style.left = Math.max(0, x) + 'px';
			tip.style.top = Math.max(0, y) + 'px';
		});
		svg.addEventListener('pointerleave', () => (tip.hidden = true));
		svg.addEventListener('click', (e) => {
			const cc = e.target.dataset && e.target.dataset.cc;
			if (!moved && cc && opts.onClick) opts.onClick(cc);
		});
		let selected = null;
		return {
			// values: {cc: número}; blocked: [cc]; live: {cc: número} para puntos que laten
			update(values = {}, { blocked = [], live = {}, color = 'var(--hp-primary)' } = {}) {
				const max = Math.max(1, ...Object.values(values));
				const bl = new Set(blocked);
				Object.entries(paths).forEach(([cc, p]) => {
					const v = values[cc] || 0;
					p.classList.toggle('has', v > 0);
					p.classList.toggle('blocked', bl.has(cc));
					p.style.fill = bl.has(cc) ? 'url(#hp-hatch)' : v > 0 ? 'color-mix(in srgb, ' + color + ' ' + Math.round(18 + (82 * Math.log(v + 1)) / Math.log(max + 1)) + '%, var(--hp-map-land))' : '';
				});
				dots.innerHTML = '';
				const lmax = Math.max(1, ...Object.values(live));
				Object.entries(live).forEach(([cc, n]) => {
					const pt = w.p[cc];
					if (!pt || !n) return;
					const c = document.createElementNS(NS, 'circle');
					c.setAttribute('cx', pt[0]);
					c.setAttribute('cy', pt[1]);
					c.setAttribute('r', (3 + 9 * Math.sqrt(n / lmax)).toFixed(1));
					c.setAttribute('class', 'hp-map-dot');
					dots.appendChild(c);
				});
			},
			select(cc) {
				if (selected && paths[selected]) paths[selected].classList.remove('sel');
				selected = cc;
				if (cc && paths[cc]) {
					paths[cc].classList.add('sel');
					g.appendChild(paths[cc]); // al frente para que se vea el borde
				}
			},
			has: (cc) => !!paths[cc],
			codes: w.codes,
		};
	};

	// ------------------------------------------------------------------ componentes

	document.addEventListener('alpine:init', () => {
		const Alpine = window.Alpine;

		// Inicio: medidores en vivo, gráfico en tiempo real e historial
		// Los gráficos y sus series viven fuera del estado reactivo de Alpine (Chart.js y los proxies no se llevan bien)
		Alpine.data('hpDashboard', () => {
			let liveChart = null, histChart = null;
			const series = { t: [], cpu: [], mem: [] };
			return {
			live: null,
			services: null,
			range: '24h',
			error: '',
			timer: null,
			lastTs: 0,
			async init() {
				// los últimos ~2 minutos ya están en el servidor (servicio de muestreo cada 2 s)
				try {
					(await HP.api('metrics-live-history')).slice(-60).forEach((p) => this.add(p.time, p.cpu, typeof p.mem === 'object' ? p.mem.percent : p.mem));
				} catch (e) {}
				await this.poll();
				this.timer = setInterval(() => document.hidden || this.poll(), 2000);
				this.loadServices();
				this.loadHistory();
			},
			add(time, cpu, mem) {
				if (!time || time <= this.lastTs) return false;
				this.lastTs = time;
				series.t.push(new Date(time * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
				series.cpu.push(cpu);
				series.mem.push(mem);
				while (series.t.length > 60) ['t', 'cpu', 'mem'].forEach((k) => series[k].shift());
				return true;
			},
			async poll() {
				try {
					const l = await HP.api('metrics-live');
					this.live = l;
					this.error = '';
					const s = series;
					this.add(l.time, l.cpu, l.mem.percent);
					await this.$nextTick();
					if (!liveChart && this.$refs.live) {
						liveChart = await HP.lineChart(this.$refs.live, s.t, [
							{ label: 'CPU %', data: s.cpu, borderColor: '#1f6fd6' },
							{ label: 'RAM %', data: s.mem, borderColor: '#e0569b' },
						], { hardMax: 100, animation: false, fmt: (v) => Math.round(v) + '%' });
					} else if (liveChart) {
						liveChart.update('none');
					}
				} catch (e) {
					this.error = e.message;
				}
			},
			async loadHistory() {
				try {
					const h = await HP.api('metrics-history', { range: this.range });
					const s = h.series;
					const labels = s.ts.map((t) => HP.timeLabel(t, this.range));
					if (histChart) histChart.destroy();
					histChart = await HP.lineChart(this.$refs.hist, labels, [
						{ label: 'CPU %', data: s.cpu, borderColor: '#1f6fd6' },
						{ label: 'RAM %', data: s.mem, borderColor: '#e0569b' },
						{ label: HP.t('Disco %'), data: s.disk, borderColor: '#14945a', fill: false },
					], { hardMax: 100, fmt: (v) => Math.round(v) + '%' });
				} catch (e) {
					this.error = e.message;
				}
			},
			async loadServices() {
				try {
					this.services = await HP.api('services');
				} catch (e) {
					this.services = {};
				}
			},
			async restart(name) {
				if (!(await HP.confirm(HP.t('¿Reiniciar el servicio %s?', name), { ok: HP.t('Reiniciar') }))) return;
				try {
					await HP.api('service-restart', { name });
					HP.toast(HP.t('Servicio %s reiniciado', name));
					this.loadServices();
				} catch (e) {
					HP.toast(e.message, 'error');
				}
			},
			gauge(pct, warn = 75, danger = 90) {
				return HP.ring(pct, HP.color(pct, warn, danger));
			},
			rootDisk() {
				return this.live ? this.live.disks.find((d) => d.mount === '/') || this.live.disks[0] || { percent: 0, used: 0, total: 0 } : null;
			},
			loadPct() {
				return this.live ? Math.min(100, Math.round((this.live.load[0] / this.live.cores) * 100)) : 0;
			},
			fmt: HP,
			};
		});

		// Monitor: historial completo
		Alpine.data('hpMonitor', () => {
			const charts = {};
			// "En vivo": ventana deslizante con una muestra cada ~2 s (fuera del estado reactivo de Alpine)
			const LIVE_POINTS = 150;
			const LIVE_KEYS = ['cpu', 'cpu_max', 'mem', 'swap', 'load1', 'load5', 'load15', 'rx', 'tx', 'rd', 'wr', 'disk', 'req', 'conn', 'active', 'tcp_est', 'tcp_new', 'db_qps', 'db_conn'];
			const buf = { labels: [] };
			LIVE_KEYS.forEach((k) => (buf[k] = []));
			let polling = false;
			let last = null; // datos del último gráfico dibujado (para exportar)
			let lastTs = 0; // hora de la última muestra en vivo agregada
			const saved = (() => {
				try {
					return localStorage.getItem('hp-monitor-range');
				} catch (e) {
					return null;
				}
			})();
			return {
			range: saved || '24h',
			tab: (location.hash || '#resources').slice(1),
			live: null,
			tr: null,
			error: '',
			ranges: [['live', HP.t('En vivo')], ['1h', HP.t('1 hora')], ['6h', HP.t('6 horas')], ['24h', HP.t('24 horas')], ['7d', HP.t('7 días')], ['30d', HP.t('30 días')], ['365d', HP.t('1 año')]],
			async init() {
				this.load();
				this.loop();
			},
			// Una sola petición a la vez: cada ~2 s en vivo, cada 5 s en el resto
			async loop() {
				if (!document.hidden && !polling) {
					polling = true;
					await this.pollTop();
					polling = false;
				}
				setTimeout(() => this.loop(), this.range === 'live' ? 1000 : 5000);
			},
			setRange(r) {
				this.range = r;
				try {
					localStorage.setItem('hp-monitor-range', r);
				} catch (e) {}
				this.load();
			},
			// Acepta tanto la respuesta de metrics-live como un punto del historial del servicio de muestreo
			pushLive(l, redraw = true) {
				if (!l || !l.time || l.time <= lastTs) return; // el servidor muestrea cada 2 s: sin duplicados
				lastTs = l.time;
				const root = (l.disks || []).find((d) => d.mount === '/') || (l.disks || [])[0] || {};
				const w = l.web || {};
				const mem = typeof l.mem === 'object' ? l.mem.percent : l.mem;
				const swap = typeof l.mem === 'object' ? l.mem.swap_percent : l.swap;
				const v = {
					cpu: l.cpu, cpu_max: l.cpu_cores && l.cpu_cores.length ? Math.max(...l.cpu_cores) : l.cpu, mem, swap,
					load1: l.load[0], load5: l.load[1], load15: l.load[2], rx: l.net.rx, tx: l.net.tx, rd: l.io.read, wr: l.io.write,
					disk: l.disk ?? root.percent,
					req: w.req, conn: w.conn, active: w.active, tcp_est: w.tcp_est, tcp_new: w.tcp_new, db_qps: w.db_qps, db_conn: w.db_conn,
				};
				buf.labels.push(new Date(l.time * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
				LIVE_KEYS.forEach((k) => buf[k].push(v[k] ?? null));
				while (buf.labels.length > LIVE_POINTS) {
					buf.labels.shift();
					LIVE_KEYS.forEach((k) => buf[k].shift());
				}
				if (redraw) Object.entries(charts).forEach(([k, c]) => k !== 'codes' && c.update('none'));
			},
			// Carga los últimos 5 minutos que el servidor ya tiene guardados
			async fillLive() {
				try {
					const pts = await HP.api('metrics-live-history');
					buf.labels.length = 0;
					LIVE_KEYS.forEach((k) => (buf[k].length = 0));
					lastTs = 0;
					(pts || []).forEach((p) => this.pushLive(p, false));
				} catch (e) {}
			},
			go(t) {
				history.replaceState(null, '', '#' + t);
				this.tab = t;
			},
			async loadTraffic() {
				try {
					this.tr = await HP.api('traffic-top', { range: this.range === 'live' ? '1h' : this.range });
				} catch (e) {
					this.tr = null;
				}
			},
			pct(a, b) {
				if (!b) return '—';
				const p = (a * 100) / b;
				return a > 0 && p < 1 ? '<1%' : Math.round(p) + '%';
			},
			// Abre el explorador de registros con el filtro de la fila pulsada y el mismo periodo del Monitor
			logs(f) {
				const range = { live: '1h', '365d': '30d' }[this.range] || this.range;
				const domains = this.tr ? this.tr.domains.map((d) => d.domain) : [];
				window.dispatchEvent(new CustomEvent('hp-logs', { detail: Object.assign({ domain: '*', range, domains }, f) }));
			},
			num(n, d = 0) {
				return n == null ? '—' : Number(n).toLocaleString(undefined, { maximumFractionDigits: d });
			},
			peakLabel(p) {
				if (!p) return '—';
				const d = new Date(p.ts * 1000);
				return d.toLocaleString([], { weekday: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) + ' · ' + p.req.toLocaleString() + (p.per === 60 ? ' pet./min' : ' pet./hora');
			},
			async pollTop() {
				try {
					this.live = await HP.api('metrics-live');
					if (this.range === 'live') this.pushLive(this.live);
				} catch (e) {}
			},
			async load() {
				try {
					const isLive = this.range === 'live';
					if (isLive) await this.fillLive();
					// en vivo los códigos HTTP salen de los registros (por minuto): se muestra la última hora
					const h = await HP.api('metrics-history', { range: isLive ? '1h' : this.range });
					const hs = h.series;
					const hlabels = hs.ts.map((t) => HP.timeLabel(t, isLive ? '1h' : this.range));
					const s = isLive ? buf : hs;
					const labels = isLive ? buf.labels : hlabels;
					const fixed = isLive ? { animation: false } : {};
					const defs = {
						cpu: [[{ label: HP.t('CPU promedio %'), data: s.cpu, borderColor: '#1f6fd6' }, { label: isLive ? HP.t('Núcleo más cargado %') : 'Pico de CPU % (muestras de 2 s)', data: s.cpu_max, borderColor: '#93c5fd', fill: false, borderDash: [4, 3] }], { hardMax: 100, fmt: (v) => Math.round(v) + '%' }],
						mem: [[{ label: 'RAM %', data: s.mem, borderColor: '#e0569b' }, { label: HP.t('Swap %'), data: s.swap, borderColor: '#f59e0b', fill: false }], { hardMax: 100, fmt: (v) => Math.round(v) + '%' }],
						load: [[{ label: HP.t('1 min'), data: s.load1, borderColor: '#7c3aed' }, { label: HP.t('5 min'), data: s.load5, borderColor: '#a78bfa', fill: false }, { label: HP.t('15 min'), data: s.load15, borderColor: '#c4b5fd', fill: false }], { fmt: (v) => Number(v).toFixed(2) }],
						net: [[{ label: HP.t('Entrada'), data: s.rx, borderColor: '#0ea5e9' }, { label: HP.t('Salida'), data: s.tx, borderColor: '#14945a' }], { fmt: HP.rate }],
						io: [[{ label: HP.t('Lectura'), data: s.rd, borderColor: '#f97316' }, { label: HP.t('Escritura'), data: s.wr, borderColor: '#ef4444' }], { fmt: HP.rate }],
						disk: [[{ label: HP.t('Disco / %'), data: s.disk, borderColor: '#14945a' }], { hardMax: 100, fmt: (v) => Math.round(v) + '%' }],
						webreq: [[{ label: isLive ? HP.t('Peticiones/s') : 'Peticiones/s (promedio)', data: s.req, borderColor: '#1f6fd6' }, { label: HP.t('Conexiones nuevas/s'), data: s.conn, borderColor: '#14945a', fill: false }].concat(isLive ? [] : [{ label: HP.t('Pico de peticiones/s'), data: s.req_max, borderColor: '#93c5fd', fill: false, borderDash: [4, 3] }]), { fmt: (v) => Number(v).toFixed(1) }],
						codes: [[{ label: HP.t('2xx correctas'), data: hs.s2, borderColor: '#14945a' }, { label: HP.t('3xx redirecciones'), data: hs.s3, borderColor: '#0ea5e9', fill: false }, { label: HP.t('4xx no encontradas/denegadas'), data: hs.s4, borderColor: '#e08a00', fill: false }, { label: HP.t('5xx errores del servidor'), data: hs.s5, borderColor: '#d23f3f', fill: false }], { fmt: (v) => Math.round(v) }],
						conns: [[{ label: HP.t('Abiertas en nginx'), data: s.active, borderColor: '#7c3aed' }, { label: 'TCP establecidas (todo el servidor)', data: s.tcp_est, borderColor: '#94a3b8', fill: false }, { label: HP.t('TCP nuevas/s'), data: s.tcp_new, borderColor: '#f97316', fill: false }], { fmt: (v) => Number(v).toFixed(1) }],
						mysql: [[{ label: HP.t('Consultas/s'), data: s.db_qps, borderColor: '#0f8c8c' }, { label: HP.t('Conexiones abiertas'), data: s.db_conn, borderColor: '#e0569b', fill: false }], { fmt: (v) => Math.round(v) }],
					};
					last = { defs, labels, hlabels, hs, isLive };
					for (const [k, [ds, opts]] of Object.entries(defs)) {
						if (charts[k]) charts[k].destroy();
						delete charts[k];
						const own = k === 'codes' ? hlabels : labels;
						if (this.$refs[k]) charts[k] = await HP.lineChart(this.$refs[k], own, ds, Object.assign({}, opts, k === 'codes' ? {} : fixed));
					}
					this.error = isLive || hs.ts.length ? '' : HP.t('Aún no hay datos para este periodo: las métricas se recogen cada minuto.');
					this.loadTraffic();
				} catch (e) {
					this.error = e.message;
				}
			},
			exporting: false,
			rangeName() {
				return (this.ranges.find((r) => r[0] === this.range) || [0, this.range])[1] + (this.range === 'live' ? ' ' + HP.t('(últimos 5 minutos)') : '');
			},
			fileName(ext) {
				const d = new Date();
				const p = (n) => String(n).padStart(2, '0');
				const host = (this.live && this.live.hostname) || 'servidor';
				return `informe-${host}-${this.range}-${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}.${ext}`;
			},
			download(name, content, type) {
				const a = document.createElement('a');
				a.href = URL.createObjectURL(new Blob([content], { type }));
				a.download = name;
				document.body.appendChild(a);
				a.click();
				setTimeout(() => (URL.revokeObjectURL(a.href), a.remove()), 1000);
			},
			// Informe autocontenido: resumen, gráficos interactivos (datos + dibujador SVG incrustado) y tablas
			async buildReport() {
				if (!last) throw new Error(HP.t('Los gráficos aún se están cargando'));
				const esc = HP.esc;
				const titles = { cpu: 'CPU', mem: HP.t('Memoria'), load: HP.t('Carga media'), net: HP.t('Red'), io: HP.t('Lectura / escritura de disco'), disk: HP.t('Ocupación del disco'),
					webreq: HP.t('Peticiones y conexiones por segundo'), codes: HP.t('Respuestas por minuto según código'), conns: HP.t('Conexiones abiertas'), mysql: HP.t('MySQL / MariaDB') };
				const ftypes = { cpu: 'pct', mem: 'pct', disk: 'pct', load: 'fixed2', net: 'rate', io: 'rate', webreq: 'num1', conns: 'num1', codes: 'int', mysql: 'int' };
				const full = (t) => new Date(t * 1000).toLocaleString([], { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
				const histTips = (last.hs.ts || []).map(full);
				const charts = {};
				for (const [k, [ds, opts]] of Object.entries(last.defs)) {
					const fromHist = k === 'codes' || !last.isLive;
					charts[k] = {
						labels: (fromHist ? last.hlabels : last.labels).slice(),
						tips: fromHist ? histTips.slice() : null,
						ftype: ftypes[k],
						max: opts.hardMax || null,
						series: ds.map((d) => ({ name: d.label, color: d.borderColor, values: (d.data || []).slice(), dash: !!d.borderDash, fill: d.fill !== false })),
					};
				}
				const stat = (arr, f = (v) => v) => {
					const v = (arr || []).filter((x) => x != null);
					if (!v.length) return '—';
					return HP.t('prom. %s · máx. %s', f(v.reduce((a, b) => a + b, 0) / v.length), f(Math.max(...v)));
				};
				const src = last.isLive ? buf : last.hs;
				const l = this.live || {};
				const root = (l.disks || []).find((d) => d.mount === '/') || {};
				const pct = (v) => Math.round(v) + '%';
				const n1 = (v) => Number(v).toFixed(1);
				const rows = [
					[HP.t('Servidor'), esc(l.hostname || '')], [HP.t('Sistema'), esc(l.os || '') + ' · kernel ' + esc(l.kernel || '')],
					[HP.t('Procesador'), esc(l.cpu_model || '') + ' · ' + HP.t('%s núcleos', l.cores || '?')], ['Hestia', 'v' + esc(l.hestia_version || '') + HP.t(' + Pro')],
					[HP.t('Encendido'), HP.duration(l.uptime || 0)],
					[HP.t('Memoria ahora'), l.mem ? HP.t('%s de %s', HP.bytes(l.mem.used), HP.bytes(l.mem.total)) + ' (' + l.mem.percent + '%)' : '—'],
					[HP.t('Disco / ahora'), root.total ? HP.t('%s de %s', HP.bytes(root.used), HP.bytes(root.total)) + ' (' + root.percent + '%)' : '—'],
				];
				if (src) {
					rows.push([HP.t('CPU en el periodo'), stat(src.cpu, pct)], [HP.t('RAM en el periodo'), stat(src.mem, pct)], ['Carga (1 min)', stat(src.load1, (v) => v.toFixed(2))],
						[HP.t('Peticiones/s'), stat(src.req, n1)], [HP.t('Consultas MySQL/s'), stat(src.db_qps, Math.round)]);
				}
				const table = (head, body) => '<table><thead><tr>' + head.map((h, i) => `<th${i ? ' class="r"' : ''}>${h}</th>`).join('') + '</tr></thead><tbody>' +
					(body.length ? body.map((r) => '<tr>' + r.map((c, i) => `<td${i ? ' class="r"' : ''}>${c}</td>`).join('') + '</tr>').join('') : `<tr><td colspan="${head.length}" class="muted">${HP.t('Sin datos en este periodo')}</td></tr>`) + '</tbody></table>';
				const t = this.tr;
				const pc = (a, b) => (b ? Math.round((a * 100) / b) + '%' : '—');
				const traffic = t ? `
					<div class="kpis">
						<div><b>${t.total.req.toLocaleString()}</b><span>peticiones</span></div>
						<div><b>${pc(t.total.s4 + t.total.s5, t.total.req)}</b><span>con error (${t.total.s4} 4xx · ${t.total.s5} 5xx)</span></div>
						<div><b>${pc(t.total.bots, t.total.req)}</b><span>bots y scripts</span></div>
						<div><b>${HP.bytes(t.total.bytes)}</b><span>enviados</span></div>
					</div>
					<h3>Dominios con más tráfico</h3>${table(['Dominio', 'Visitantes', 'IPs (con bots)', 'Peticiones', 'Errores', 'Bots', 'Enviado'], t.domains.map((d) => [esc(d.domain), (d.visitors || 0).toLocaleString(), (d.ips || 0).toLocaleString(), d.req.toLocaleString(), pc(d.s4 + d.s5, d.req), pc(d.bots, d.req), HP.bytes(d.bytes)]))}
					<h3>Páginas más visitadas</h3>${table(['URL', 'Peticiones'], t.urls.map((u) => [esc(u.domain) + '<code>' + esc(u.path) + '</code>', u.req.toLocaleString()]))}
					<h3>URLs con errores</h3>${table(['URL', '404', '5xx'], t.errors.map((u) => [esc(u.domain) + '<code>' + esc(u.path) + '</code>', u.e404, u.e5]))}
					<h3>IPs con más peticiones</h3>${table(['IP', 'Peticiones', 'Con error'], t.ips.map((i) => [esc(i.ip), i.req.toLocaleString(), pc(i.err, i.req)]))}
					<h3>IPs sospechosas</h3>${table(['IP', 'Peticiones', 'Con error'], t.suspects.map((i) => [esc(i.ip), i.req.toLocaleString(), pc(i.err, i.req)]))}` : '<p class="muted">' + HP.t('Sin datos de tráfico.') + '</p>';
				const chartBlock = (keys) => keys.filter((k) => charts[k]).map((k) => `<figure><figcaption>${titles[k]}${k === 'codes' && last.isLive ? ' (última hora)' : ''}</figcaption><div class="chart" data-chart="${k}"></div></figure>`).join('');
				const json = JSON.stringify(charts).replace(/</g, '\\u003c');
				const script = '<scr' + 'ipt>window.HP_CHARTS=' + json + ';(' + HP.reportCharts.toString() + ')();</scr' + 'ipt>';
				const now = new Date().toLocaleString();
				return `<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Informe del servidor ${esc(l.hostname || '')} · ${esc(this.rangeName())}</title>
<style>
	:root{color-scheme:light;--bg:#f2f5f9;--page:#fff;--text:#1e293b;--muted:#64748b;--line:#e2e8f0;--head:#f8fafc;--grid:#edf1f5;--base:#cbd5e1;--accent:#1f6fd6;--code:#334155}
	@media (prefers-color-scheme:dark){:root{color-scheme:dark;--bg:#0f141c;--page:#171e2a;--text:#e2e8f0;--muted:#94a3b8;--line:#263042;--head:#1c2433;--grid:#222b3a;--base:#334155;--accent:#5aa9ff;--code:#cbd5e1}}
	*{box-sizing:border-box} body{font-family:"Segoe UI",Roboto,system-ui,sans-serif;color:var(--text);background:var(--bg);margin:0;padding:28px;font-size:14px}
	.page{max-width:1080px;margin:0 auto;background:var(--page);border-radius:14px;padding:36px 40px;box-shadow:0 4px 20px #0f172a14}
	header{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:3px solid var(--accent);padding-bottom:14px;margin-bottom:22px;gap:20px}
	h1{margin:0;font-size:24px} h2{font-size:18px;margin:30px 0 12px;color:var(--accent)} h3{font-size:14px;margin:22px 0 8px}
	.muted{color:var(--muted)} dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 22px;margin:0} dt{color:var(--muted)} dd{margin:0}
	.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px} figure{margin:0;border:1px solid var(--line);border-radius:10px;padding:12px;break-inside:avoid;min-width:0}
	figcaption{font-weight:600;margin-bottom:6px}
	.chart{position:relative;min-height:240px} .chart svg{display:block;width:100%;overflow:visible}
	.chart .gl{stroke:var(--grid);stroke-width:1} .chart .base{stroke:var(--base);stroke-width:1}
	.chart .ax{fill:var(--muted);font-size:11px;font-variant-numeric:tabular-nums} .chart .ln{fill:none;stroke-width:2;stroke-linejoin:round;stroke-linecap:round}
	.chart .ln.dash{stroke-dasharray:5 4;stroke-width:1.5} .chart .area{opacity:.12} .chart .cur{stroke:var(--muted);stroke-width:1}
	.chart .dot{stroke:var(--page);stroke-width:2} .chart .empty{color:var(--muted);text-align:center;padding:60px 0;font-size:13px}
	.chart .lg{display:flex;flex-wrap:wrap;gap:4px 10px;margin-bottom:4px}
	.chart .lg button{border:0;background:none;color:var(--muted);font:inherit;font-size:12px;cursor:pointer;padding:2px 4px;border-radius:5px}
	.chart .lg button:hover{background:var(--head)} .chart .lg button.off{opacity:.4;text-decoration:line-through}
	.chart .lg i{display:inline-block;width:10px;height:3px;border-radius:2px;margin-right:6px;vertical-align:middle}
	.chart .tip{position:absolute;display:none;pointer-events:none;background:var(--page);color:var(--text);border:1px solid var(--line);border-radius:8px;padding:6px 9px;font-size:12px;line-height:1.5;box-shadow:0 6px 18px #0002;white-space:nowrap;z-index:5}
	.chart .tip .sw{display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:6px}
	table{width:100%;border-collapse:collapse;font-size:13px;break-inside:avoid} th,td{text-align:left;padding:7px 10px;border-bottom:1px solid var(--line)}
	th{background:var(--head);color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.4px} .r{text-align:right}
	code{display:block;font-family:Consolas,monospace;font-size:12px;color:var(--code);word-break:break-all}
	.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px} .kpis div{border:1px solid var(--line);border-radius:10px;padding:12px}
	.kpis b{display:block;font-size:20px} .kpis span{color:var(--muted);font-size:12px}
	.bar{position:sticky;top:0;text-align:right;max-width:1080px;margin:0 auto 12px;z-index:9}
	.bar button{background:var(--accent);color:#fff;border:0;border-radius:8px;padding:9px 16px;font-size:14px;cursor:pointer}
	.hint{font-size:12px;color:var(--muted);margin:-4px 0 10px}
	footer{margin-top:30px;color:var(--muted);font-size:12px;text-align:center}
	@media print{:root{color-scheme:light;--bg:#fff;--page:#fff;--text:#1e293b;--muted:#64748b;--line:#e2e8f0;--head:#f8fafc;--grid:#edf1f5;--base:#cbd5e1;--accent:#1f6fd6;--code:#334155}
		body{padding:0}.page{box-shadow:none;padding:0;max-width:none}.bar,.hint{display:none}h2{break-after:avoid}.chart .lg button{color:var(--muted)}@page{margin:14mm}}
	@media(max-width:760px){.grid,.kpis{grid-template-columns:1fr}.page{padding:20px}}
</style></head><body>
<div class="bar"><button onclick="print()">Imprimir / Guardar como PDF</button></div>
<div class="page">
	<header><div><h1>Informe del servidor</h1><div class="muted">${esc(l.hostname || '')}</div></div><div class="muted" style="text-align:right">Periodo: <b>${esc(this.rangeName())}</b><br>Generado: ${esc(now)}</div></header>
	<h2>Resumen</h2><dl>${rows.map(([a, b]) => `<dt>${a}</dt><dd>${b}</dd>`).join('')}</dl>
	<h2>Recursos</h2><p class="hint">Pasa el ratón por los gráficos para ver los valores; pulsa una serie de la leyenda para ocultarla o mostrarla.</p>
	<div class="grid">${chartBlock(['cpu', 'mem', 'load', 'net', 'io', 'disk'])}</div>
	<h2>Tráfico y conexiones</h2><div class="grid">${chartBlock(['webreq', 'codes', 'conns', 'mysql'])}</div>
	${traffic}
	<footer>iaachz-panel · informe generado desde el Monitor del panel</footer>
</div>${script}</body></html>`;
			},
			async exportHtml() {
				this.exporting = true;
				try {
					this.download(this.fileName('html'), await this.buildReport(), 'text/html;charset=utf-8');
					HP.toast(HP.t('Informe HTML descargado'));
				} catch (e) {
					HP.toast(e.message, 'error');
				}
				this.exporting = false;
			},
			async exportPdf() {
				// la ventana se abre ya (dentro del clic) para que el navegador no la bloquee
				const w = window.open('', '_blank');
				if (!w) return HP.toast(HP.t('El navegador bloqueó la ventana: permite ventanas emergentes para este panel'), 'error');
				w.document.write('<p style="font-family:sans-serif;padding:30px">' + HP.t('Generando informe…') + '</p>');
				this.exporting = true;
				try {
					const html = await this.buildReport();
					w.document.open();
					w.document.write(html);
					w.document.close();
					setTimeout(() => w.print(), 600);
				} catch (e) {
					w.close();
					HP.toast(e.message, 'error');
				}
				this.exporting = false;
			},
			exportCsv() {
				if (!last) return;
				const src = last.isLive ? buf : last.hs;
				const keys = last.isLive ? LIVE_KEYS : Object.keys(src).filter((k) => k !== 'ts');
				const names = { cpu: 'cpu_%', cpu_max: 'cpu_max_%', mem: 'ram_%', swap: 'swap_%', disk: 'disco_%', rx: 'red_entrada_B/s', tx: 'red_salida_B/s', rd: 'disco_lectura_B/s', wr: 'disco_escritura_B/s',
					req: 'peticiones/s', conn: 'conexiones_nuevas/s', active: 'conexiones_abiertas', tcp_est: 'tcp_establecidas', tcp_new: 'tcp_nuevas/s', db_qps: 'mysql_consultas/s', db_conn: 'mysql_conexiones',
					s2: '2xx/min', s3: '3xx/min', s4: '4xx/min', s5: '5xx/min', mem_used: 'ram_usada_B', mem_total: 'ram_total_B', procs: 'procesos' };
				const p2 = (n) => String(n).padStart(2, '0');
				const local = (t) => { const d = new Date(t * 1000); return `${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())} ${p2(d.getHours())}:${p2(d.getMinutes())}`; };
				const times = last.isLive ? buf.labels : src.ts.map(local);
				const lines = [['fecha'].concat(keys.map((k) => names[k] || k)).join(';')];
				times.forEach((t, i) => lines.push([t].concat(keys.map((k) => (src[k] && src[k][i] != null ? String(src[k][i]).replace('.', ',') : ''))).join(';')));
				this.download(this.fileName('csv'), '﻿' + lines.join('\r\n'), 'text/csv;charset=utf-8');
				HP.toast(HP.t('Datos CSV descargados'));
			},
			fmt: HP,
			};
		});

		// Formulario de aplicación (Node.js / Python / Docker / proxy) de un dominio
		// Selector de países con búsqueda por nombre en español (x-modelable="value")
		const norm = (s) => String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
		Alpine.data('hpCountries', () => ({
			value: [],
			q: '',
			open: false,
			hi: 0,
			all: [],
			init() {
				HP.world().then((w) => {
					this.all = w.codes.map((cc) => ({ cc, name: HP.countryName(cc), n: norm(HP.countryName(cc)) })).sort((a, b) => a.name.localeCompare(b.name, 'es'));
				});
			},
			get options() {
				const q = norm(this.q.trim());
				return this.all.filter((c) => !this.value.includes(c.cc) && (!q || c.n.includes(q) || c.cc.toLowerCase() === q)).slice(0, 8);
			},
			add(cc) {
				if (!this.value.includes(cc)) this.value = this.value.concat(cc);
				this.q = '';
				this.hi = 0;
			},
			remove(cc) {
				this.value = this.value.filter((c) => c !== cc);
			},
			key(e) {
				const o = this.options;
				if (e.key === 'ArrowDown') this.hi = Math.min(o.length - 1, this.hi + 1);
				else if (e.key === 'ArrowUp') this.hi = Math.max(0, this.hi - 1);
				else if (e.key === 'Enter') {
					if (o[this.hi]) this.add(o[this.hi].cc);
				} else if (e.key === 'Backspace' && !this.q && this.value.length) this.remove(this.value[this.value.length - 1]);
				else if (e.key === 'Escape') this.open = false;
				else return;
				e.preventDefault();
			},
		}));

		// Tarjeta del Monitor: de dónde llegan las visitas (sigue el periodo elegido en el Monitor)
		Alpine.data('hpGeoCard', () => {
			let map = null;
			let data = null;
			return {
				rng: '',
				top: [],
				ready: true,
				total: 0,
				async setRange(r) {
					const rng = { live: '1h', '365d': '30d' }[r] || r;
					if (rng === this.rng) return;
					this.rng = rng;
					if (!map) map = await HP.worldMap(this.$refs.map, { tip: (cc) => this.tip(cc), onClick: (cc) => this.logs(cc) });
					this.load();
				},
				async load() {
					try {
						data = await HP.api('geo-map', { range: this.rng });
					} catch (e) {
						return;
					}
					this.ready = data.db;
					const vals = {};
					Object.entries(data.countries).forEach(([cc, c]) => (vals[cc] = c.req));
					const live = {};
					Object.entries(data.live).forEach(([cc, c]) => (live[cc] = c.conns));
					map.update(vals, { blocked: data.blocked.server, live });
					this.total = Object.values(data.countries).reduce((a, c) => a + c.req, 0);
					this.top = Object.entries(data.countries).map(([cc, c]) => Object.assign({ cc }, c)).sort((a, b) => b.req - a.req).slice(0, 8);
				},
				tip(cc) {
					const c = (data && data.countries[cc]) || null;
					const l = data && data.live[cc];
					return '<b>' + HP.esc(HP.countryName(cc)) + '</b>' +
						(c ? '<div>' + HP.t('%s peticiones · %s IPs', c.req.toLocaleString(), c.ips) + '</div>' + (c.err ? '<div>' + HP.t('%s% con error', Math.round((c.err * 100) / c.req)) + '</div>' : '') : '<div class="hp-muted">' + HP.t('Sin visitas en el periodo') + '</div>') +
						(l ? '<div>' + HP.t('Ahora: %s conexiones', l.conns) + '</div>' : '') +
						(data && data.blocked.server.includes(cc) ? '<div class="hp-map-tip-bad">' + HP.t('Bloqueado en todo el servidor') + '</div>' : '');
				},
				share(n) {
					return this.total ? Math.max(1, Math.round((n * 100) / this.total)) : 0;
				},
				shareLabel(n) {
					const p = this.total ? (n * 100) / this.total : 0;
					return n > 0 && p < 1 ? '<1%' : Math.round(p) + '%';
				},
				logs(cc) {
					window.dispatchEvent(new CustomEvent('hp-logs', { detail: { domain: '*', cc, range: this.rng } }));
				},
			};
		});

		// Explorador de registros de acceso y de errores (ventana de pages/logs_modal.php)
		Alpine.data('hpLogs', (isAdmin = false) => {
			const CODES = {
				200: HP.t('Correcta'), 201: HP.t('Creado'), 204: HP.t('Correcta, sin contenido'), 206: HP.t('Contenido parcial (descargas, vídeo)'),
				301: HP.t('Redirección permanente'), 302: HP.t('Redirección temporal'), 303: HP.t('Redirección tras enviar un formulario'), 304: HP.t('Sin cambios (el navegador usa su caché)'),
				307: HP.t('Redirección temporal'), 308: HP.t('Redirección permanente'),
				400: HP.t('Petición mal formada'), 401: HP.t('Requiere iniciar sesión'), 403: 'Prohibido (permisos o regla de seguridad)', 404: HP.t('No existe esa página o archivo'),
				405: HP.t('Método no permitido'), 408: HP.t('El visitante tardó demasiado'), 413: HP.t('Archivo o envío demasiado grande'), 414: HP.t('URL demasiado larga'),
				429: HP.t('Demasiadas peticiones (límite)'), 444: HP.t('Conexión cerrada por nginx'), 499: HP.t('El visitante cerró antes de recibir la respuesta'),
				500: HP.t('Error interno de la aplicación'), 502: HP.t('La aplicación (PHP/Node/Python) no respondió'), 503: HP.t('Servicio no disponible'),
				504: HP.t('La aplicación tardó demasiado en responder'),
			};
			const CLASS = { 2: HP.t('Correcta'), 3: HP.t('Redirección'), 4: HP.t('Error del visitante'), 5: HP.t('Fallo del servidor') };
			const TIPS = {
				404: HP.t('<b>404</b>: piden algo que no existe. Si son rutas como <span class="hp-mono">/.env</span>, <span class="hp-mono">/.git/config</span> o <span class="hp-mono">/wp-login.php</span> en un sitio sin WordPress, son <b>escáneres automáticos</b> buscando fallos: no hacen daño si esos archivos no existen. Si es una página real (o <span class="hp-mono">/favicon.ico</span>), revisa el enlace o sube el archivo.'),
				403: HP.t('<b>403</b>: el servidor negó el acceso (permisos de archivos, carpetas sin índice o una regla de seguridad).'),
				499: HP.t('<b>499</b>: el visitante cerró o cambió de página antes de recibir la respuesta. Muchos seguidos indican páginas lentas.'),
				500: HP.t('<b>500</b>: la aplicación falló. Mira la pestaña <b>Errores del servidor</b> a la misma hora para ver el mensaje de PHP.'),
				502: HP.t('<b>502</b>: nginx no pudo hablar con PHP-FPM o con la aplicación (caída o reiniciándose). Revisa <b>Errores del servidor</b>.'),
				504: HP.t('<b>504</b>: la aplicación tardó demasiado. Suele ser una consulta lenta a la base de datos o un proceso pesado.'),
				'5xx': HP.t('<b>5xx</b>: fallos del servidor o de la aplicación. Compara la hora con la pestaña <b>Errores del servidor</b>.'),
				err: HP.t('Pulsa un código para ver solo ese tipo de error, o una fila para ver todos sus detalles (de dónde viene, navegador, etc.).'),
			};
			let seq = 0; // descarta respuestas viejas si se cambia el filtro rápido
			return {
				isAdmin,
				kind: 'access',
				f: { domain: '*', range: '24h', status: 'all', ip: '', q: '', bots: '', cc: '' },
				domains: [],
				res: null,
				eres: null,
				rows: [],
				sel: -1,
				busy: false,
				err: '',
				open(d = {}) {
					this.kind = d.kind || 'access';
					this.f = { domain: d.domain || '*', range: d.range || '24h', status: d.status || 'all', ip: d.ip || '', q: d.q || '', bots: d.bots || '', cc: d.cc || '' };
					this.domains = (d.domains || []).slice().sort();
					if (this.f.domain !== '*' && this.domains.length && !this.domains.includes(this.f.domain)) this.domains.push(this.f.domain);
					this.res = this.eres = null;
					this.rows = [];
					this.$refs.dlg.showModal();
					this.load();
				},
				setKind(k) {
					this.kind = k;
					this.load();
				},
				filtered() {
					return this.f.status !== 'all' || this.f.ip || this.f.q || this.f.bots || this.f.cc;
				},
				reset() {
					Object.assign(this.f, { status: 'all', ip: '', q: '', bots: '', cc: '' });
					this.load();
				},
				async load() {
					const my = ++seq;
					this.busy = true;
					this.err = '';
					this.sel = -1;
					try {
						const r = await HP.api('weblog', Object.assign({ kind: this.kind, limit: 400 }, this.f));
						if (my !== seq) return;
						if (this.kind === 'error') this.eres = r;
						else {
							this.res = r;
							this.rows = r.rows;
						}
					} catch (e) {
						if (my === seq) this.err = e.message;
					} finally {
						if (my === seq) this.busy = false;
					}
				},
				num(n) {
					return n == null ? '—' : Number(n).toLocaleString();
				},
				when(t) {
					if (!t) return '—';
					const d = new Date(t * 1000);
					const today = new Date().toDateString() === d.toDateString();
					return (today ? '' : d.toLocaleDateString([], { day: '2-digit', month: 'short' }) + ' ') + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
				},
				codeHelp(c) {
					return CODES[c] || CLASS[String(c)[0]] || '';
				},
				// Explicación del código elegido y qué hacer con él
				codeNote() {
					return TIPS[this.f.status] || '';
				},
				agent(ua) {
					if (!ua || ua === '-') return '(sin identificar)';
					const m = ua.match(/(Googlebot|bingbot|YandexBot|AhrefsBot|SemrushBot|GPTBot|ClaudeBot|facebookexternalhit|Applebot|DuckDuckBot|curl|Wget|python-requests|Go-http-client|okhttp|MoodleBot|WordPress)/i);
					if (m) return m[1];
					const c = ua.match(/compatible; ([^;/)]+)/);
					if (c && !/MSIE/.test(c[1])) return c[1].trim();
					const os = /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS' : /Windows/.test(ua) ? 'Windows' : /Mac OS X/.test(ua) ? 'macOS' : /Linux/.test(ua) ? 'Linux' : '';
					const br = /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : '';
					return br || os ? [br, os].filter(Boolean).join(' · ') : ua.slice(0, 40);
				},
				// Bloqueo rápido de una IP (en este sitio o en todo el servidor)
				async block(ip, scope) {
					const where = scope === 'site' ? this.f.domain : HP.t('todo el servidor');
					if (!(await HP.confirm(HP.t('¿Bloquear %s en %s?', ip, where), { ok: HP.t('Bloquear'), danger: true }))) return;
					try {
						await HP.api('geo-block', { kind: 'ip', value: ip, scope, domain: this.f.domain });
						HP.toast(HP.t('%s bloqueada en %s', ip, where));
					} catch (e) {
						HP.toast(e.message, 'error');
					}
				},
				line(r) {
					return r.ip + ' [' + new Date(r.t * 1000).toISOString() + '] "' + r.method + ' ' + r.url + ' ' + r.proto + '" ' + r.status + ' ' + r.bytes + ' "' + r.ref + '" "' + r.ua + '"';
				},
				errLevel(l) {
					const m = l.match(/\[(emerg|alert|crit|error|warn|notice|info)\]/) || l.match(/\[[a-z_]+:(emerg|alert|crit|error|warn|notice|info)\]/);
					return m ? m[1] : 'error';
				},
				// Quita la fecha y el pid del principio para que se lea el mensaje
				errText(l) {
					return l.replace(/^\d{4}\/\d\d\/\d\d \d\d:\d\d:\d\d \[\w+\] \d+#\d+: (\*\d+ )?/, '').replace(/^\[[^\]]+\] /, '');
				},
			};
		});

		Alpine.data('hpAppForm', (cfg) => ({
			user: cfg.user,
			domain: cfg.domain,
			isAdmin: cfg.isAdmin,
			app: null,
			form: Object.assign({ type: 'node', version: '', root: 'app', start_mode: 'file', startup: 'app.js', mode: 'production', server: 'gunicorn', module: 'app:app', workers: 2, command: '', image: '', container_port: 80, target: 'http://127.0.0.1:8080', memory_mb: 0, enabled: true }, cfg.preset || {}),
			env: [],
			volumes: [],
			runtimes: null,
			scripts: {},
			busy: false,
			loaded: false,
			async init() {
				try {
					this.runtimes = await HP.api('runtimes');
				} catch (e) {
					this.runtimes = { node: { installed: {} }, python: { installed: {} }, docker: {} };
				}
				await this.reload();
				this.loaded = true;
			},
			async reload() {
				if (!this.domain) return;
				try {
					this.app = await HP.api('app-get', { user: this.user, domain: this.domain });
				} catch (e) {
					this.app = null;
				}
				if (this.app && this.app.type) {
					Object.assign(this.form, this.app);
					this.env = Object.entries(this.app.env || {}).map(([k, v]) => ({ k, v }));
					this.volumes = (this.app.volumes || []).slice();
					if (this.app.type === 'node') this.loadScripts();
				} else {
					const nodes = Object.keys(this.runtimes?.node?.installed || {});
					if (!this.form.version) this.form.version = this.form.type === 'python' ? 'system' : nodes[nodes.length - 1] || '';
				}
			},
			setType(t) {
				this.form.type = t;
				if (t === 'node') {
					const nodes = Object.keys(this.runtimes?.node?.installed || {});
					if (!nodes.includes(String(this.form.version))) this.form.version = nodes[nodes.length - 1] || '';
					if (!this.form.root || this.form.root === 'pyapp') this.form.root = 'app';
				}
				if (t === 'python' && !Object.keys(this.runtimes?.python?.installed || {}).includes(String(this.form.version))) this.form.version = 'system';
			},
			async loadScripts() {
				try {
					this.scripts = (await HP.api('app-scripts', { user: this.user, domain: this.domain })).scripts || {};
				} catch (e) {}
			},
			payload() {
				const env = {};
				this.env.forEach((r) => r.k && (env[r.k.trim()] = r.v));
				return Object.assign({}, this.form, { env, volumes: this.volumes });
			},
			async save() {
				this.busy = true;
				try {
					const r = await HP.api('app-save', { user: this.user, domain: this.domain, app: this.payload() });
					if (r.job) {
						await HP.job(r.job, HP.t('Aplicando la aplicación de %s', this.domain), { autoClose: true }).catch(() => {});
					}
					HP.toast(r.sample_created ? HP.t('Aplicación creada con un ejemplo funcionando') : HP.t('Aplicación guardada'));
					await this.reload();
				} catch (e) {
					HP.toast(e.message, 'error');
				}
				this.busy = false;
			},
			async act(what) {
				try {
					await HP.api('app-action', { user: this.user, domain: this.domain, what });
					HP.toast({ start: HP.t('Iniciada'), stop: HP.t('Detenida'), restart: HP.t('Reiniciada'), enable: HP.t('Habilitada'), disable: HP.t('Deshabilitada') }[what] || HP.t('Hecho'));
					await this.reload();
				} catch (e) {
					HP.toast(e.message, 'error');
				}
			},
			async remove() {
				if (!(await HP.confirm(HP.t('Se quitará la aplicación de %s y el dominio volverá a su plantilla anterior. Los archivos NO se borran.', this.domain), { title: HP.t('Quitar aplicación'), ok: HP.t('Quitar'), danger: true }))) return;
				try {
					await HP.api('app-delete', { user: this.user, domain: this.domain });
					HP.toast(HP.t('Aplicación quitada'));
					this.app = null;
					location.reload();
				} catch (e) {
					HP.toast(e.message, 'error');
				}
			},
			async deps() {
				try {
					await HP.runJob('app-deps', { user: this.user, domain: this.domain }, this.form.type === 'node' ? 'npm install' : 'pip install');
					await this.act('restart');
				} catch (e) {
					HP.toast(e.message, 'error');
				}
			},
			async runScript(name) {
				try {
					await HP.runJob('app-run-script', { user: this.user, domain: this.domain, script: name }, 'npm run ' + name);
				} catch (e) {
					HP.toast(e.message, 'error');
				}
			},
			logs() {
				HP.logs(HP.t('Registros de %s', this.domain), () => HP.api('app-logs', { user: this.user, domain: this.domain, lines: 400 }));
			},
			mem(b) {
				return b ? HP.bytes(b) : '—';
			},
			stateLabel(s) {
				return { running: HP.t('En marcha'), starting: HP.t('Iniciando'), stopped: HP.t('Detenida'), disabled: HP.t('Deshabilitada'), failed: HP.t('Con error'), missing: HP.t('Sin contenedor') }[s] || s || '—';
			},
			stateClass(s) {
				return { running: 'hp-badge-ok', starting: 'hp-badge-warn', failed: 'hp-badge-danger', missing: 'hp-badge-danger' }[s] || 'hp-badge-muted';
			},
		}));

		// Git por dominio
		Alpine.data('hpGit', (cfg) => ({
			user: cfg.user,
			domain: cfg.domain,
			git: null,
			form: { repo: '', branch: 'main', path: 'public_html', post_deploy: '', auto: false, restart_app: true },
			key: '',
			loaded: false,
			async init() {
				try {
					this.git = await HP.api('git-get', { user: this.user, domain: this.domain });
					if (this.git) Object.assign(this.form, this.git);
				} catch (e) {}
				this.loaded = true;
			},
			webhook() {
				return this.git ? location.origin + '/pro/hook/?u=' + encodeURIComponent(this.user) + '&d=' + encodeURIComponent(this.domain) + '&k=' + this.git.secret : '';
			},
			async save() {
				try {
					this.git = await HP.api('git-save', { user: this.user, domain: this.domain, git: this.form });
					HP.toast(HP.t('Repositorio guardado'));
				} catch (e) {
					HP.toast(e.message, 'error');
				}
			},
			async deploy() {
				try {
					await HP.runJob('git-deploy', { user: this.user, domain: this.domain }, HP.t('Desplegando %s', this.domain));
					this.git = await HP.api('git-get', { user: this.user, domain: this.domain });
				} catch (e) {
					HP.toast(e.message, 'error');
				}
			},
			async remove() {
				if (!(await HP.confirm(HP.t('¿Desvincular el repositorio? Los archivos desplegados se conservan.'), { danger: true, ok: HP.t('Desvincular') }))) return;
				await HP.api('git-delete', { user: this.user, domain: this.domain });
				this.git = null;
				HP.toast(HP.t('Repositorio desvinculado'));
			},
			async showKey() {
				try {
					this.key = (await HP.api('git-key', { user: this.user })).public_key;
				} catch (e) {
					HP.toast(e.message, 'error');
				}
			},
			copy: HP.copy,
		}));
	});
})();

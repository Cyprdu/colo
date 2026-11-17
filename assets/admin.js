/* Back-office ColoMap : aucune donnée n'est injectée en HTML, tout passe par textContent (pas de XSS possible). */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var csrf = ($('meta[name="csrf"]') || {}).content || '';
  var DATA = {};
  try { DATA = JSON.parse(($('#adm-data') || {}).textContent || '{}'); } catch (e) { DATA = {}; }

  function el(tag, attrs) {
    var n = document.createElement(tag);
    for (var k in (attrs || {})) {
      if (k === 'text') n.textContent = attrs[k];
      else if (k === 'class') n.className = attrs[k];
      else if (k.slice(0, 2) === 'on') n.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] !== false && attrs[k] != null) n.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
    }
    (function add(list) {
      for (var i = 0; i < list.length; i++) {
        var c = list[i];
        if (c == null || c === false) continue;
        if (Array.isArray(c)) { add(c); continue; }   // dialog() passe un tableau de nœuds : appendChild(tableau) levait une TypeError
        n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
      }
    })([].slice.call(arguments, 2));
    return n;
  }
  function icon(name) {
    var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('class', 'ic'); s.setAttribute('aria-hidden', 'true');
    var u = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    u.setAttribute('href', '#i-' + name); s.appendChild(u); return s;
  }

  /* ---------- toast ---------- */
  var toastT;
  function toast(msg, bad) {
    var t = $('#toast'); if (!t) return;
    t.textContent = msg; t.className = 'toast show' + (bad ? ' bad' : '');
    clearTimeout(toastT); toastT = setTimeout(function () { t.className = 'toast'; }, 4200);
  }

  /* ---------- modales ---------- */
  function open(m) { m.hidden = false; var f = m.querySelector('input:not(.sr),textarea,button.btn:not(.ghost)'); if (f) setTimeout(function () { f.focus(); }, 30); }
  function close(m) { m.hidden = true; }
  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('[data-close]')) { var m = e.target.closest('.modal'); if (m) close(m); }
    else if (e.target.classList && e.target.classList.contains('modal')) close(e.target);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.querySelectorAll('.modal:not([hidden])').forEach(close);
  });

  /* Fenêtre générique : renvoie {root, close}. */
  function dialog(title, bodyNodes, buttons, wide) {
    var row = el('div', { class: 'row' });
    var root = el('div', { class: 'modal' }, el('div', { class: 'dlg' + (wide ? ' wide' : '') }, el('h3', { text: title }), el('div', {}, bodyNodes), row));
    (buttons || []).forEach(function (b) { row.appendChild(el('button', { class: 'btn' + (b.ghost ? ' ghost' : ''), type: 'button', text: b.label, onclick: function () { b.fn ? b.fn(root) : root.remove(); } })); });
    document.body.appendChild(root); return root;
  }

  /* Confirmation : Promise<boolean>. opts.word = mot à taper. */
  function confirmBox(title, text, word) {
    return new Promise(function (res) {
      var m = $('#m-confirm'), go = $('#cf-go'), inp = $('#cf-in');
      $('#cf-title').textContent = title; $('#cf-text').textContent = text;
      $('#cf-typed').hidden = !word; $('#cf-word').textContent = word || ''; inp.value = '';
      var done = function (v) { go.onclick = null; m.removeEventListener('click', onClose); close(m); res(v); };
      var onClose = function (e) { if ((e.target.closest && e.target.closest('[data-close]')) || e.target === m) done(false); };
      m.addEventListener('click', onClose);
      go.onclick = function () { if (word && inp.value.trim() !== word) { inp.focus(); return; } done(true); };
      open(m);
    });
  }

  /* ---------- ré-authentification ---------- */
  var unlockedUntil = DATA.reauth ? Date.now() + DATA.reauth * 1000 : 0;
  function refreshLock() {
    var s = $('#lockst'), t = $('#locktxt'); if (!s) return;
    var left = Math.floor((unlockedUntil - Date.now()) / 1000);
    if (left > 0) { s.classList.add('open'); t.textContent = 'Déverrouillé ' + Math.floor(left / 60) + ':' + ('0' + (left % 60)).slice(-2); }
    else { s.classList.remove('open'); t.textContent = 'Verrouillé'; }
  }
  setInterval(refreshLock, 1000); refreshLock();
  function unlocked() { return Date.now() < unlockedUntil; }

  function askReauth() {
    return new Promise(function (res) {
      var m = $('#m-reauth'), inp = $('#re-pass'), err = $('#re-err'), go = $('#re-go');
      inp.value = ''; err.textContent = '';
      var finish = function (v) { $('#re-form').onsubmit = null; m.removeEventListener('click', onClose); close(m); res(v); };
      var onClose = function (e) { if ((e.target.closest && e.target.closest('[data-close]')) || e.target === m) finish(false); };
      m.addEventListener('click', onClose);
      var submit = function () {
        if (!inp.value) return;
        go.disabled = true; err.textContent = '';
        post({ a: 'reauth', password: inp.value }, true).then(function () {
          unlockedUntil = Date.now() + 590 * 1000; refreshLock(); inp.value = ''; go.disabled = false; finish(true);
        }).catch(function (e) { go.disabled = false; err.textContent = e.message; inp.select(); });
      };
      var form = $('#re-form'); form.onsubmit = function (e) { e.preventDefault(); submit(); };
      open(m);
    });
  }

  /* ---------- API ---------- */
  function post(body, noRetry) {
    return fetch('admin_api.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().catch(function () { return { error: 'Réponse invalide du serveur.' }; }).then(function (j) { return { r: r, j: j }; }); })
      .then(function (o) {
        if (o.r.ok && o.j.ok !== false && !o.j.error) return o.j;
        if (o.r.status === 401 && o.j.reauth && !noRetry) {
          unlockedUntil = 0; refreshLock();
          return askReauth().then(function (ok) { if (!ok) throw new Error('Action annulée.'); return post(body, true); });
        }
        if (o.j.expired) { toast('Session expirée : rechargez la page.', true); }
        var e = new Error(o.j.error || 'Erreur.'); e.status = o.r.status; throw e;
      });
  }
  // Les actions sensibles ouvrent la fenêtre de mot de passe d'elles-mêmes (401 reauth:true)
  function api(body) { return post(body, false); }

  /* ---------- menu mobile ---------- */
  var menu = $('#menu'); if (menu) menu.addEventListener('click', function () { $('#side').classList.toggle('open'); });

  /* ---------- pages de liste : actions ---------- */
  function listActions() {
    document.querySelectorAll('button[data-act]').forEach(function (b) {
      b.addEventListener('click', function () {
        var act = b.dataset.act, id = +b.dataset.id, e = b.dataset.e;
        var run = function (extra) {
          b.disabled = true;
          return api(Object.assign({ a: 'act', act: act, id: id, e: e }, extra || {})).then(function (j) { b.disabled = false; return j; }, function (err) { b.disabled = false; throw err; });
        };
        var fail = function (err) { toast(err.message, true); };
        if (act === 'contact.reply') return replyDialog(b, run, fail);
        if (act === 'virement.iban') return run().then(function (j) { showIban(j.iban); }).catch(fail);
        var go = function () { run().then(function () { toast('Fait.'); setTimeout(function () { location.reload(); }, 500); }).catch(fail); };
        if (b.dataset.confirm) confirmBox('Confirmer', b.dataset.confirm).then(function (ok) { if (ok) go(); }); else go();
      });
    });
  }
  function replyDialog(b, run, fail) {
    var ta = el('textarea', { class: 'in', rows: '7', maxlength: '5000', placeholder: 'Votre réponse…' });
    var d = dialog('Répondre à ' + (b.dataset.from || ''), [
      el('p', { class: 'quote', text: b.dataset.msg || '' }), ta,
      el('p', { class: 'hint', text: 'Le contact reçoit un e-mail avec un lien vers la réponse.' })
    ], [
      { label: 'Annuler', ghost: true },
      { label: 'Envoyer la réponse', fn: function () {
        if (!ta.value.trim()) { ta.focus(); return; }
        run({ text: ta.value }).then(function (j) { d.remove(); toast(j.mail ? 'Réponse envoyée.' : 'Réponse enregistrée (e-mail non envoyé).', !j.mail); setTimeout(function () { location.reload(); }, 900); }).catch(fail);
      } }
    ], true);
  }
  function showIban(v) {
    var line = function (k, val) { return el('div', { class: 'kv' }, el('span', { text: k }), el('b', { class: 'mono', text: val || '—' })); };
    dialog('Coordonnées bancaires', [
      line('Organisme', v.nom_organisme), line('IBAN', v.iban), line('BIC', v.bic_swift), line('Net à virer', (+v.montant_apres_commission).toFixed(2).replace('.', ',') + ' €'),
      el('p', { class: 'hint', text: 'Cet affichage est enregistré dans le journal d\'audit.' })
    ], [{ label: 'Fermer' }]);
  }

  /* ---------- éditeur de base de données ---------- */
  function dbApp() {
    var cur = { t: null, page: 1, q: '', sort: null, dir: 'desc' }, tables = [], qT;
    var list = $('#db-list');
    function show(id, on) { $(id).hidden = !on; }
    function start() {
      show('#db-lock', false);
      api({ a: 'db.tables' }).then(function (j) {
        tables = j.tables; renderTables();
        var p = new URLSearchParams(location.search), t = p.get('t');
        if (t) load(t, p.get('pk'));
      }).catch(function (e) { show('#db-lock', true); if (e.message !== 'Action annulée.') toast(e.message, true); });
    }
    function renderTables() {
      var f = ($('#db-filter').value || '').toLowerCase(); list.textContent = '';
      tables.filter(function (t) { return t.name.toLowerCase().indexOf(f) > -1; }).forEach(function (t) {
        list.appendChild(el('li', {}, el('button', { type: 'button', class: 'tl' + (t.name === cur.t ? ' on' : ''), onclick: function () { cur.page = 1; cur.q = ''; cur.sort = null; $('#db-q').value = ''; load(t.name); } },
          icon(t.protected ? 'lock' : 'database'), el('span', { text: t.name }), el('small', { text: t.rows == null ? '' : String(t.rows) }))));
      });
    }
    function load(t, openPk) {
      cur.t = t; renderTables(); show('#db-empty', false);
      return api({ a: 'db.browse', t: t, page: cur.page, q: cur.q, sort: cur.sort, dir: cur.dir }).then(function (j) {
        show('#db-view', true); $('#db-title').textContent = t; $('#db-ro').hidden = !j.protected; $('#db-new').hidden = j.protected;
        $('#db-count').textContent = j.total + ' ligne' + (j.total > 1 ? 's' : ''); cur.sort = j.sort; cur.dir = j.dir;
        renderRows(j); renderPager(j);
        if (openPk) editRow(t, openPk);
      }).catch(function (e) { toast(e.message, true); });
    }
    function cellText(v) { if (v === null) return el('i', { class: 'nul', text: 'NULL' }); var s = String(v); return el('span', { title: s.length > 60 ? s : false, text: s.length > 60 ? s.slice(0, 60) + '…' : s }); }
    function renderRows(j) {
      var tb = $('#db-table'); tb.textContent = '';
      var hr = el('tr', {}, el('th', { class: 'act-h', text: '' }));
      j.cols.forEach(function (c) {
        hr.appendChild(el('th', { class: 'sortable', title: c.type, onclick: function () { cur.dir = (cur.sort === c.name && cur.dir === 'desc') ? 'asc' : 'desc'; cur.sort = c.name; load(cur.t); } },
          c.name + (c.key === 'PRI' ? ' ★' : '') + (cur.sort === c.name ? (cur.dir === 'asc' ? ' ▲' : ' ▼') : '')));
      });
      tb.appendChild(el('thead', {}, hr));
      var body = el('tbody');
      if (!j.rows.length) body.appendChild(el('tr', {}, el('td', { colspan: String(j.cols.length + 1), class: 'empty', text: 'Aucune ligne.' })));
      j.rows.forEach(function (r) {
        var pkv = j.pk ? r[j.pk] : null, tr = el('tr'), acts = el('td', { class: 'acts' });
        if (j.pk && !j.protected) {
          acts.appendChild(el('button', { class: 'ib', type: 'button', title: 'Éditer', 'aria-label': 'Éditer', onclick: function () { editRow(cur.t, pkv); } }, icon('edit')));
          acts.appendChild(el('button', { class: 'ib danger', type: 'button', title: 'Supprimer', 'aria-label': 'Supprimer', onclick: function () { delRow(cur.t, pkv); } }, icon('trash')));
        }
        tr.appendChild(acts);
        j.cols.forEach(function (c) { tr.appendChild(el('td', {}, cellText(r[c.name]))); });
        body.appendChild(tr);
      });
      tb.appendChild(body);
    }
    function renderPager(j) {
      var p = $('#db-pager'), pages = Math.max(1, Math.ceil(j.total / j.per)); p.textContent = '';
      if (pages < 2) return;
      if (j.page > 1) p.appendChild(el('button', { class: 'btn ghost', type: 'button', onclick: function () { cur.page = j.page - 1; load(cur.t); } }, icon('back'), ' Précédent'));
      p.appendChild(el('span', { text: 'Page ' + j.page + ' / ' + pages }));
      if (j.page < pages) p.appendChild(el('button', { class: 'btn ghost', type: 'button', onclick: function () { cur.page = j.page + 1; load(cur.t); } }, 'Suivant ', icon('next')));
    }
    function delRow(t, pk) {
      confirmBox('Supprimer la ligne', 'Supprimer définitivement la ligne ' + t + ' #' + pk + ' ? Cette action est irréversible.', 'SUPPRIMER').then(function (ok) {
        if (!ok) return;
        api({ a: 'db.delete', t: t, pk: pk }).then(function () { toast('Ligne supprimée.'); load(t); }).catch(function (e) { toast(e.message, true); });
      });
    }
    function editRow(t, pk) {
      var isNew = pk == null;
      (isNew ? api({ a: 'db.browse', t: t, page: 1, q: '', sort: null }) : api({ a: 'db.get', t: t, pk: pk })).then(function (j) {
        var row = isNew ? {} : j.row, cols = j.cols, inputs = {}, form = $('#row-form'); form.textContent = '';
        $('#row-title').textContent = isNew ? 'Nouvelle ligne : ' + t : 'Modifier ' + t + ' #' + pk; $('#row-err').textContent = '';
        cols.forEach(function (c) {
          var long = /text|blob|json/i.test(c.type), val = row[c.name];
          var inp = el(long ? 'textarea' : 'input', { class: 'in' + (long ? ' mono' : ''), rows: long ? '4' : false, type: long ? false : 'text', autocomplete: 'off', 'data-lpignore': 'true', 'data-1p-ignore': 'true', spellcheck: 'false' });
          if (c.sensitive) inp.classList.add('secret');   // pas de type=password : le navigateur ne propose ni ne remplit rien ici
          var isPk = !isNew && c.name === j.pk;
          if (c.sensitive) { inp.placeholder = isNew ? '' : '(inchangé : tapez une nouvelle valeur pour remplacer)'; }
          else inp.value = val == null ? '' : String(val);
          if (isPk || (isNew && c.auto)) { inp.disabled = true; if (isNew) inp.placeholder = 'automatique'; }
          var nul = null;
          if (c.null && !c.sensitive && !isPk) {
            nul = el('input', { type: 'checkbox' }); nul.checked = !isNew && val === null; inp.disabled = nul.checked;
            nul.addEventListener('change', function () { inp.disabled = nul.checked; });
          }
          inputs[c.name] = { inp: inp, nul: nul, orig: val, c: c, skip: isPk || (isNew && c.auto) };
          form.appendChild(el('label', { class: 'fld' }, el('span', { class: 'fl' }, c.name, el('small', { text: ' ' + c.type + (c.key === 'PRI' ? ' · clé' : '') })), inp,
            nul ? el('span', { class: 'nulchk' }, nul, ' NULL') : false));
        });
        $('#row-save').onclick = function () {
          var values = {}, n = 0;
          Object.keys(inputs).forEach(function (k) {
            var o = inputs[k]; if (o.skip) return;
            if (o.c.sensitive) { if (o.inp.value) { values[k] = o.inp.value; n++; } return; }
            var v = (o.nul && o.nul.checked) ? null : o.inp.value;
            if (isNew) { if (v !== '' || o.nul) { if (v === '' && !o.nul) return; values[k] = v; n++; } }
            else if (v !== (o.orig == null ? null : String(o.orig))) { values[k] = v; n++; }
          });
          if (!n) { $('#row-err').textContent = 'Aucune modification.'; return; }
          confirmBox(isNew ? 'Créer la ligne' : 'Enregistrer', (isNew ? 'Ajouter une ligne à ' : 'Modifier ' + n + ' champ(s) de ') + t + (isNew ? '' : ' #' + pk) + ' ?').then(function (ok) {
            if (!ok) return;
            api({ a: isNew ? 'db.insert' : 'db.update', t: t, pk: pk, values: values }).then(function () { close($('#m-row')); toast('Enregistré.'); load(t); })
              .catch(function (e) { $('#row-err').textContent = e.message; });
          });
        };
        open($('#m-row'));
      }).catch(function (e) { toast(e.message, true); });
    }
    $('#db-filter').addEventListener('input', renderTables);
    $('#db-q').addEventListener('input', function () { clearTimeout(qT); qT = setTimeout(function () { cur.q = $('#db-q').value; cur.page = 1; if (cur.t) load(cur.t); }, 300); });
    $('#db-new').addEventListener('click', function () { editRow(cur.t, null); });
    $('#db-unlock').addEventListener('click', function () { askReauth().then(function (ok) { if (ok) start(); }); });
    if (unlocked()) start(); else show('#db-lock', true);
  }

  /* ---------- console SQL ---------- */
  function sqlApp() {
    var inp = $('#sql-in'), out = $('#sql-out'), info = $('#sql-info');
    function lock(on) { $('#sql-lock').hidden = !on; $('#sql-box').hidden = on; }
    function params() { var v = $('#sql-params').value; return v.trim() === '' ? [] : v.split(/\r?\n/); }
    function render(j) {
      out.textContent = '';
      if (j.kind !== 'read') { out.appendChild(el('div', { class: 'note ok', text: (j.kind === 'ddl' ? 'Structure modifiée.' : j.affected + ' ligne(s) affectée(s).') })); return; }
      if (!j.cols.length) { out.appendChild(el('div', { class: 'note', text: 'Aucun résultat.' })); return; }
      var t = el('table', { class: 'tbl' }), hr = el('tr');
      j.cols.forEach(function (c) { hr.appendChild(el('th', { text: c })); });
      t.appendChild(el('thead', {}, hr)); var b = el('tbody');
      j.rows.forEach(function (r) { var tr = el('tr'); j.cols.forEach(function (c) { var v = r[c]; tr.appendChild(el('td', {}, v === null ? el('i', { class: 'nul', text: 'NULL' }) : el('span', { text: String(v) }))); }); b.appendChild(tr); });
      t.appendChild(b); out.appendChild(el('div', { class: 'tbl-wrap' }, t));
      if (j.truncated) out.appendChild(el('p', { class: 'hint', text: 'Résultat tronqué à 500 lignes.' }));
    }
    function run(extra) {
      var btn = $('#sql-run'); btn.disabled = true; info.textContent = '';
      return api(Object.assign({ a: 'sql.run', sql: inp.value, params: params() }, extra || {})).then(function (j) {
        btn.disabled = false;
        if (j.needs_confirm) {
          return confirmBox(j.risky ? 'Opération à risque' : 'Confirmer l\'écriture', j.message, j.risky ? 'CONFIRMER' : '').then(function (ok) {
            if (ok) return run({ confirm: j.token, typed: j.risky ? 'CONFIRMER' : '' });
            info.textContent = 'Annulé.';
          });
        }
        info.textContent = j.ms + ' ms'; render(j);
      }).catch(function (e) { btn.disabled = false; out.textContent = ''; out.appendChild(el('div', { class: 'note err', text: e.message })); });
    }
    $('#sql-run').addEventListener('click', function () { run(); });
    inp.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); run(); } });
    $('#sql-tables').addEventListener('click', function () { inp.value = 'SHOW TABLES'; $('#sql-params').value = ''; run(); });
    $('#sql-unlock').addEventListener('click', function () { askReauth().then(function (ok) { if (ok) lock(false); }); });
    lock(!unlocked());
  }

  if (DATA.page === 'list') listActions();
  if (DATA.page === 'db') dbApp();
  if (DATA.page === 'sql') sqlApp();
})();

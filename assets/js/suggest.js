/* moncamp.fr : autocomplétion de la barre de recherche de l'en-tête (api/search_suggest.php).
   Légère : 2 lettres minimum, 180 ms de pause avant l'appel, réponses gardées en mémoire, un seul appel à la fois. Sans JavaScript, la recherche marche comme avant. */
(function () {
    'use strict';
    var URL_API = '/api/search_suggest.php';
    var KINDS = { 'alias': 'Organisme', 'organisme': 'Organisme', 'mot-clé': 'Mot-clé', 'catégorie': 'Catégorie', 'séjour': 'Séjour', 'ville': 'Ville', 'précision': 'Précision' };

    var st = document.createElement('style');
    st.textContent = '.sg-box{position:absolute;left:0;right:0;top:100%;margin-top:6px;z-index:60;background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 16px 40px rgba(18,55,42,.16);overflow:hidden}' +
        '.sg-item{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;padding:10px 16px;text-align:left;font-size:14px;color:#26343b;background:none;border:0;cursor:pointer;font-family:inherit}' +
        '.sg-item:hover,.sg-item[aria-selected="true"]{background:#f1f3f5}.sg-kind{flex:none;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9aa3ab}' +
        '.sg-dym{padding:10px 16px;font-size:13.5px;color:#5b4a1c;background:#f8f5ea;border-bottom:1px solid #ebe3c8}.sg-dym button{font-weight:800;text-decoration:underline;background:none;border:0;cursor:pointer;font-family:inherit;color:inherit}';
    document.head.appendChild(st);

    function attach(input) {
        if (!input || !input.form) return;
        var host = input.parentElement, box = document.createElement('div'), items = [], idx = -1, timer, ctl, cache = {};
        box.className = 'sg-box'; box.id = 'sg-' + Math.random().toString(36).slice(2, 7); box.hidden = true; box.setAttribute('role', 'listbox');
        host.appendChild(box);
        input.setAttribute('role', 'combobox'); input.setAttribute('aria-autocomplete', 'list'); input.setAttribute('aria-expanded', 'false'); input.setAttribute('aria-controls', box.id);

        function close() { box.hidden = true; input.setAttribute('aria-expanded', 'false'); idx = -1; }
        function mark() { items.forEach(function (b, i) { b.setAttribute('aria-selected', i === idx ? 'true' : 'false'); }); }
        function go(it) { if (it.href) { location.href = it.href; } else { input.value = it.label; input.form.submit(); } }
        function draw(d) {
            box.textContent = ''; items = []; idx = -1;
            if (d.dym) {
                var p = document.createElement('div'); p.className = 'sg-dym'; p.appendChild(document.createTextNode('Vouliez-vous dire '));
                var b = document.createElement('button'); b.type = 'button'; b.textContent = '« ' + d.dym + ' »';
                b.addEventListener('mousedown', function (e) { e.preventDefault(); input.value = d.dym; input.form.submit(); });
                p.appendChild(b); p.appendChild(document.createTextNode(' ?')); box.appendChild(p);
            }
            (d.items || []).forEach(function (it) {
                var b = document.createElement('button'); b.type = 'button'; b.className = 'sg-item'; b.setAttribute('role', 'option');
                var l = document.createElement('span'); l.textContent = it.label;
                var k = document.createElement('span'); k.className = 'sg-kind'; k.textContent = KINDS[it.kind] || it.kind;
                b.appendChild(l); b.appendChild(k);
                b.addEventListener('mousedown', function (e) { e.preventDefault(); go(it); });
                box.appendChild(b); items.push(b); b._it = it;
            });
            var show = items.length || d.dym;
            box.hidden = !show; input.setAttribute('aria-expanded', show ? 'true' : 'false');
        }
        function ask(q) {
            if (cache[q]) { draw(cache[q]); return; }
            if (ctl) ctl.abort();
            ctl = window.AbortController ? new AbortController() : null;
            fetch(URL_API + '?q=' + encodeURIComponent(q), ctl ? { signal: ctl.signal } : {})
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) { if (!d || input.value.trim() !== q) return; cache[q] = d; draw(d); })
                .catch(function () {});
        }
        input.addEventListener('input', function () {
            clearTimeout(timer);
            var q = input.value.trim();
            if (q.length < 2) { close(); return; }
            timer = setTimeout(function () { ask(q); }, 180);
        });
        input.addEventListener('keydown', function (e) {
            if (box.hidden || !items.length) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); idx = (idx + 1) % items.length; mark(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); idx = (idx - 1 + items.length) % items.length; mark(); }
            else if (e.key === 'Enter' && idx >= 0) { e.preventDefault(); go(items[idx]._it); }
            else if (e.key === 'Escape') close();
        });
        input.addEventListener('blur', function () { setTimeout(close, 150); });
    }

    attach(document.getElementById('desktop-search-input'));
    attach(document.getElementById('mobile-search-input'));
})();

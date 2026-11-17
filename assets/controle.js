// assets/controle.js : exécute les tests du tableau de contrôle quatre par quatre et affiche leur statut.
(function () {
    var body = document.getElementById('ctl-body'), sum = document.getElementById('ctl-sum'), todo = document.getElementById('ctl-todo'), busy = false;
    if (!body) return;
    var LBL = { ok: 'OK', warn: 'À vérifier', ko: 'À corriger', info: 'Info', run: 'Test…' };
    function el(t, c, x) { var e = document.createElement(t); if (c) e.className = c; if (x != null) e.textContent = x; return e; }
    function count() {
        var n = { ok: 0, warn: 0, ko: 0, info: 0, run: 0 };
        body.querySelectorAll('.ctl-row').forEach(function (r) { n[r.dataset.s] = (n[r.dataset.s] || 0) + 1; r.hidden = todo.checked && (r.dataset.s === 'ok' || r.dataset.s === 'info'); });
        body.querySelectorAll('.ctl-g').forEach(function (g) { g.hidden = !g.querySelector('.ctl-row:not([hidden])'); });
        sum.className = 'note ' + (n.ko ? 'err' : (n.warn || n.run ? 'warn' : 'ok'));
        sum.textContent = n.run ? 'Tests en cours… (' + n.run + ' restants)' : n.ko + ' à corriger · ' + n.warn + ' à vérifier · ' + n.ok + ' OK · ' + n.info + ' informations';
        return n;
    }
    function set(row, r) {
        row.dataset.s = r.s; var p = row.querySelector('.pill'); p.className = 'pill ' + (r.s === 'ko' ? 'bad' : r.s); p.textContent = LBL[r.s] || r.s;
        row.querySelector('.d').textContent = r.d || ''; row.querySelector('.ms').textContent = r.ms != null ? r.ms + ' ms' : '';
        var t = row.querySelector('.t'); if (r.t && r.s !== 'ok') { t.hidden = false; t.textContent = 'À faire : ' + r.t; } else t.hidden = true;
        if (count().run === 0) busy = false;
    }
    function row(test) {
        var r = el('div', 'ctl-row'), l = el('div', 'l', test.l); r.dataset.s = 'run'; l.appendChild(el('span', 'ms'));
        var t = el('div', 't'); t.hidden = true; r.append(el('span', 'pill run', LBL.run), l, el('div', 'd'), t); return r;
    }
    function go() {
        if (busy) return; busy = true;
        fetch('tableau_de_controle.php?list=1', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            body.textContent = ''; var groups = {}, rows = [], i = 0;
            d.tests.forEach(function (t) { if (!groups[t.g]) { var g = el('section', 'ctl-g'); g.appendChild(el('h2', '', t.g)); body.appendChild(g); groups[t.g] = g; } var r = row(t); groups[t.g].appendChild(r); rows.push([t, r]); });
            count();
            function next() {
                if (i >= rows.length) return;
                var x = rows[i++];
                fetch('tableau_de_controle.php?run=' + encodeURIComponent(x[0].id), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) { set(x[1], r.error ? { s: 'warn', d: r.error } : r); })
                    .catch(function () { set(x[1], { s: 'warn', d: 'Pas de réponse (délai dépassé ou erreur serveur).' }); }).then(next);
            }
            for (var k = 0; k < 4; k++) next();
        }).catch(function () { sum.className = 'note err'; sum.textContent = 'Impossible de charger la liste des tests (session expirée ?).'; busy = false; });
    }
    document.getElementById('ctl-run').addEventListener('click', go); todo.addEventListener('change', count); go();
})();

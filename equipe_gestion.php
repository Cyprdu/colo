<?php
// equipe_gestion.php?t=<jeton du séjour> : « Infos pour mon équipe » (directeur). Blocs libres, message d'équipe, documents PDF, date de versement.
// Les données sont lues et écrites par api/equipe_infos.php (réservé au directeur du séjour).
$body_class = 'bg-white';
require_once 'partials/header.php';
$t = (string)($_GET['t'] ?? '');
if (empty($_SESSION['user']['id'])) { header('Location: login.php?redirect=' . urlencode('equipe_gestion.php?t=' . $t)); exit; }
?>
<title>Infos pour mon équipe - ColoMap</title>
<meta name="robots" content="noindex, nofollow">
<main class="ds-page" style="max-width:820px">
    <a href="mes_camps.php" class="text-[14px] text-[#6b7780]">← Mes séjours</a>
    <p class="ds-eyebrow mt-3">Équipe d'animation</p>
    <h1 class="ds-h1 mt-1" id="titre">Infos pour mon équipe</h1>
    <p class="ds-lead">Ce que vos animateurs inscrits voient dans leur espace : lieu, horaires, programme, consignes, documents…</p>
    <div id="box" class="mt-6 space-y-6"><p class="text-[#6b7780]">Chargement…</p></div>
</main>
<script>
(function () {
    var T = <?= json_encode($t) ?>, box = document.getElementById('box'), D;
    var csrf = (document.querySelector('meta[name=csrf]') || {}).content || '';
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var SUGGEST = ['Lieu exact et accès', 'Arrivée et horaires', 'Programme', 'Contacts utiles', 'Ce qu’il faut apporter', 'Consignes', 'Planning', 'Informations pratiques'];
    var blocs = [];
    async function call(url, opt) {
        var r = await fetch(url, Object.assign({ credentials: 'same-origin' }, opt)), d = {};
        try { d = await r.json(); } catch (x) { }
        if (!r.ok) throw new Error(d.error || 'Une erreur est survenue.');
        return d;
    }
    function docs() {
        return D.docs.length ? '<ul class="space-y-2">' + D.docs.map(function (d) { return '<li class="flex items-center justify-between gap-3 text-[14px]"><span class="font-bold text-[#26343b] truncate">' + esc(d.nom) + '.pdf</span><button type="button" class="ds-btn ds-btn-light ds-btn-sm" data-deldoc="' + d.id + '">Supprimer</button></li>'; }).join('') + '</ul>' : '<p class="ds-hint">Aucun document.</p>';
    }
    function blocsHtml() {
        return blocs.map(function (b, i) {
            return '<div class="rounded-2xl border-2 border-[#e5e7eb] p-4 space-y-2" data-i="' + i + '"><div class="flex gap-2"><input class="ds-input" data-f="titre" maxlength="120" placeholder="Titre du bloc" value="' + esc(b.titre) + '"><button type="button" class="ds-btn ds-btn-light ds-btn-sm" data-delb="' + i + '" aria-label="Supprimer ce bloc">&times;</button></div>' +
                '<textarea class="ds-textarea" data-f="texte" rows="4" maxlength="4000" placeholder="Texte">' + esc(b.texte) + '</textarea></div>';
        }).join('');
    }
    function sync() { [].forEach.call(document.querySelectorAll('#blocs [data-i]'), function (row) { var b = blocs[row.dataset.i]; [].forEach.call(row.querySelectorAll('[data-f]'), function (el) { b[el.dataset.f] = el.value; }); }); }
    function draw() {
        box.innerHTML =
            '<section class="ds-card p-6 space-y-3"><h2 class="text-[18px] font-extrabold text-[#26343b]">Message à l’équipe</h2><p class="ds-hint">Un nouveau message prévient chaque animateur inscrit.</p><textarea id="msg" class="ds-textarea" rows="3" maxlength="2000">' + esc(D.message) + '</textarea></section>' +
            '<section class="ds-card p-6 space-y-3"><h2 class="text-[18px] font-extrabold text-[#26343b]">Informations</h2><div id="blocs" class="space-y-3">' + blocsHtml() + '</div>' +
            '<div class="flex flex-wrap gap-2"><button type="button" class="ds-btn ds-btn-light" id="addb">Ajouter un bloc</button>' + SUGGEST.filter(function (s) { return !blocs.some(function (b) { return b.titre === s; }); }).slice(0, 4).map(function (s) { return '<button type="button" class="ds-btn ds-btn-light ds-btn-sm" data-sug="' + esc(s) + '">+ ' + esc(s) + '</button>'; }).join('') + '</div></section>' +
            '<section class="ds-card p-6 space-y-3"><h2 class="text-[18px] font-extrabold text-[#26343b]">Documents (PDF)</h2><div id="docs">' + docs() + '</div><p class="ds-hint">PDF de 5 Mo maximum, 10 documents. Visibles uniquement par vos animateurs inscrits.</p><input type="file" id="file" accept="application/pdf" class="text-[14px]"></section>' +
            '<section class="ds-card p-6 space-y-4"><h2 class="text-[18px] font-extrabold text-[#26343b]">Rémunération et équipe</h2>' +
            '<div class="max-w-xs"><label class="ds-label" for="dv">Date de versement prévue</label><input id="dv" type="date" class="ds-input" value="' + esc(D.date_versement || '') + '"><p class="ds-hint">Le montant de la rémunération se règle dans les réglages « Recruter des animateurs ».</p></div>' +
            '<label class="flex items-center gap-3 text-[15px]"><input type="checkbox" id="mq" ' + (+D.montre_equipe ? 'checked' : '') + ' style="accent-color:#26343b"> Les animateurs voient la liste des autres membres de l’équipe</label></section>' +
            '<div id="err" class="ds-alert-err hidden"></div><div id="ok" class="ds-alert-ok hidden">Enregistré.</div><button type="button" class="ds-btn" id="save">Enregistrer</button>';
    }
    box.addEventListener('click', function (e) {
        var t = e.target;
        if (t.id === 'addb') { sync(); if (blocs.length < 12) blocs.push({ titre: '', texte: '' }); draw(); }
        else if (t.dataset.sug) { sync(); blocs.push({ titre: t.dataset.sug, texte: '' }); draw(); }
        else if (t.dataset.delb !== undefined) { sync(); blocs.splice(+t.dataset.delb, 1); draw(); }
        else if (t.dataset.deldoc) { call('api/equipe_infos.php?a=doc_del', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ camp: T, id: +t.dataset.deldoc }) }).then(function (d) { D.docs = d.docs; document.getElementById('docs').innerHTML = docs(); }).catch(show); }
        else if (t.id === 'save') save();
    });
    box.addEventListener('change', function (e) {
        if (e.target.id !== 'file' || !e.target.files[0]) return;
        var fd = new FormData(); fd.append('camp', T); fd.append('fichier', e.target.files[0]);
        call('api/equipe_infos.php?a=doc_add', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: fd }).then(function (d) { D.docs = d.docs; document.getElementById('docs').innerHTML = docs(); e.target.value = ''; }).catch(show);
    });
    function show(x) { var er = document.getElementById('err'); er.textContent = x.message; er.classList.remove('hidden'); }
    function save() {
        sync();
        var er = document.getElementById('err'), ok = document.getElementById('ok'); er.classList.add('hidden'); ok.classList.add('hidden');
        call('api/equipe_infos.php?a=save', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ camp: T, blocs: blocs, message: document.getElementById('msg').value, date_versement: document.getElementById('dv').value, montre_equipe: document.getElementById('mq').checked }) })
            .then(function () { D.message = document.getElementById('msg').value; ok.classList.remove('hidden'); }).catch(show);
    }
    call('api/equipe_infos.php?a=get&camp=' + encodeURIComponent(T)).then(function (d) {
        D = d; blocs = d.blocs.slice(); document.getElementById('titre').textContent = 'Infos pour mon équipe · ' + d.camp.nom;
        if (!blocs.length) blocs = SUGGEST.slice(0, 3).map(function (s) { return { titre: s, texte: '' }; });
        draw();
    }).catch(function (x) { box.innerHTML = '<p class="ds-alert-err">' + esc(x.message) + '</p>'; });
})();
</script>
<?php require_once 'partials/footer.php'; ?>

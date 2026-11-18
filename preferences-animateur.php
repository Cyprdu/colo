<?php
// preferences-animateur.php : préférences de l'animateur (qualification, zone, disponibilités, thèmes, rémunération, alertes e-mail).
// Elles alimentent le moteur de suggestions (api/anim_match.php). Données : api/anim_prefs.php.
$body_class = 'bg-white';
require_once 'partials/header.php';
if (empty($_SESSION['user']['is_animateur'])) { header('Location: login.php?redirect=' . urlencode('preferences-animateur.php')); exit; }
?>
<title>Mes préférences - Espace animateur - moncamp.fr</title>
<main class="ds-page" style="max-width:820px">
    <p class="ds-eyebrow">Espace animateur</p>
    <h1 class="ds-h1">Mes préférences</h1>
    <p class="ds-lead">Dites-nous ce que vous cherchez : vos suggestions de missions et vos alertes s'adaptent.</p>

    <form id="f" class="mt-8 space-y-6" novalidate>
        <fieldset class="ds-card p-6"><legend class="sr-only">Qualification</legend>
            <h2 class="font-extrabold text-[18px] text-[#26343b]">Ma qualification</h2><p class="ds-hint mb-3">Plusieurs choix possibles. Les séjours qui exigent un BAFA ne vous sont proposés que si vous en avez un.</p>
            <div id="qualifs" class="flex flex-wrap gap-2"></div></fieldset>

        <fieldset class="ds-card p-6"><legend class="sr-only">Localisation</legend>
            <h2 class="font-extrabold text-[18px] text-[#26343b]">Où je veux travailler</h2>
            <div class="mt-3 grid sm:grid-cols-3 gap-4">
                <div><label class="ds-label" for="cp">Code postal</label><input id="cp" class="ds-input" maxlength="6" inputmode="numeric" autocomplete="postal-code"></div>
                <div><label class="ds-label" for="ville">Ville</label><input id="ville" class="ds-input" maxlength="120" autocomplete="address-level2"></div>
                <div><label class="ds-label" for="rayon">Zone</label><select id="rayon" class="ds-select"></select></div>
            </div></fieldset>

        <fieldset class="ds-card p-6"><legend class="sr-only">Disponibilités</legend>
            <h2 class="font-extrabold text-[18px] text-[#26343b]">Mes disponibilités</h2><p class="ds-hint mb-3">Vacances scolaires et/ou dates précises.</p>
            <div id="periodes" class="flex flex-wrap gap-2"></div>
            <div id="dates" class="mt-4 space-y-2"></div>
            <button type="button" class="ds-btn ds-btn-light mt-2" id="add-date">Ajouter une période précise</button></fieldset>

        <fieldset class="ds-card p-6"><legend class="sr-only">Types de séjours</legend>
            <h2 class="font-extrabold text-[18px] text-[#26343b]">Les séjours qui me plaisent</h2>
            <p class="ds-label mt-3">Type de séjour</p><div id="types" class="flex flex-wrap gap-2"></div>
            <p class="ds-label mt-4">Thématiques</p><div id="themes" class="flex flex-wrap gap-2"></div>
            <div class="mt-4 grid grid-cols-2 gap-4 max-w-sm"><div><label class="ds-label" for="amin">Enfants de (ans)</label><input id="amin" type="number" min="0" max="30" class="ds-input"></div>
                <div><label class="ds-label" for="amax">à (ans)</label><input id="amax" type="number" min="0" max="30" class="ds-input"></div></div></fieldset>

        <fieldset class="ds-card p-6"><legend class="sr-only">Rémunération</legend>
            <h2 class="font-extrabold text-[18px] text-[#26343b]">Rémunération</h2>
            <div class="mt-3 max-w-xs"><label class="ds-label" for="remu">Rémunération minimale (€)</label><input id="remu" type="number" min="0" step="1" class="ds-input" placeholder="Aucun minimum"></div>
            <label class="mt-4 flex items-center gap-3 text-[15px]"><input type="checkbox" id="benevole" style="accent-color:#26343b"> J'accepte aussi les séjours bénévoles</label></fieldset>

        <fieldset class="ds-card p-6"><legend class="sr-only">Alertes</legend>
            <h2 class="font-extrabold text-[18px] text-[#26343b]">Alertes e-mail</h2>
            <label class="mt-3 flex items-center gap-3 text-[15px]"><input type="checkbox" id="alerte" style="accent-color:#26343b"> Me prévenir par e-mail quand une nouvelle mission me correspond</label>
            <div class="mt-3 max-w-xs"><label class="ds-label" for="freq">Fréquence</label><select id="freq" class="ds-select"></select></div></fieldset>

        <div id="msg" class="hidden" role="status"></div>
        <button type="submit" class="ds-btn" id="save">Enregistrer mes préférences</button>
    </form>
</main>
<script src="assets/js/anim.js?v=3"></script>
<script>
(function () {
    var A = window.ANIM, E = A.esc, $ = function (i) { return document.getElementById(i); }, P, C, dates = [];
    function chips(id, map, list, single) {
        var box = $(id); box.innerHTML = '';
        (Array.isArray(map) ? map.map(function (x) { return [x, x]; }) : Object.keys(map).map(function (k) { return [k, map[k]]; })).forEach(function (kv) {
            var b = document.createElement('button'); b.type = 'button'; b.className = 'ds-chip'; b.dataset.v = kv[0]; b.textContent = kv[1];
            var paint = function () { var on = list.indexOf(kv[0]) !== -1; b.style.background = on ? '#26343b' : '#fff'; b.style.color = on ? '#fff' : '#3b4a54'; b.setAttribute('aria-pressed', on); };
            b.addEventListener('click', function () { var i = list.indexOf(kv[0]); if (i === -1) list.push(kv[0]); else list.splice(i, 1); paint(); });
            paint(); box.appendChild(b);
        });
        if (Array.isArray(map) && !map.length) box.innerHTML = '<span class="text-[13px] text-[#9aa3ab]">Aucune thématique disponible.</span>';
    }
    function drawDates() {
        $('dates').innerHTML = dates.map(function (d, i) {
            return '<div class="flex flex-wrap items-center gap-2" data-i="' + i + '"><label class="text-[13px]">Du <input type="date" class="ds-input" style="width:auto;padding:6px 10px" data-f="from" value="' + E(d.from) + '"></label>' +
                '<label class="text-[13px]">au <input type="date" class="ds-input" style="width:auto;padding:6px 10px" data-f="to" value="' + E(d.to) + '"></label>' +
                '<button type="button" class="ds-btn ds-btn-light ds-btn-sm" data-del="' + i + '" aria-label="Supprimer cette période">&times;</button></div>';
        }).join('');
    }
    function syncDates() { [].forEach.call($('dates').children, function (row) { [].forEach.call(row.querySelectorAll('[data-f]'), function (el) { dates[row.dataset.i][el.dataset.f] = el.value; }); }); }
    $('add-date').addEventListener('click', function () { syncDates(); if (dates.length < 12) dates.push({ from: '', to: '' }); drawDates(); });
    $('dates').addEventListener('click', function (e) { var d = e.target.closest('[data-del]'); if (d) { syncDates(); dates.splice(+d.dataset.del, 1); drawDates(); } });

    A.api('api/anim_prefs.php').then(function (d) {
        P = d.prefs; C = d.choix;
        chips('qualifs', C.qualifs, P.qualifs); chips('periodes', C.periodes, P.periodes); chips('types', C.types, P.types); chips('themes', C.themes, P.themes);
        $('rayon').innerHTML = Object.keys(C.rayons).map(function (k) { return '<option value="' + k + '"' + (P.rayon === k ? ' selected' : '') + '>' + E(C.rayons[k]) + '</option>'; }).join('');
        $('freq').innerHTML = Object.keys(C.freqs).map(function (k) { return '<option value="' + k + '"' + (P.alerte_freq === k ? ' selected' : '') + '>' + E(C.freqs[k]) + '</option>'; }).join('');
        $('cp').value = P.code_postal; $('ville').value = P.ville; $('amin').value = P.age_min == null ? '' : P.age_min; $('amax').value = P.age_max == null ? '' : P.age_max;
        $('remu').value = P.remu_min == null ? '' : P.remu_min; $('benevole').checked = !!P.benevole; $('alerte').checked = !!P.alerte_email;
        dates = P.dates_dispo.slice(); drawDates();
    }).catch(function (e) { A.toast(e.message, true); });

    $('f').addEventListener('submit', async function (ev) {
        ev.preventDefault(); syncDates();
        var m = $('msg'); $('save').disabled = true;
        try {
            await A.api('api/anim_prefs.php', { json: { qualifs: P.qualifs, code_postal: $('cp').value, ville: $('ville').value, rayon: $('rayon').value, periodes: P.periodes, dates_dispo: dates, themes: P.themes, types: P.types,
                age_min: $('amin').value, age_max: $('amax').value, remu_min: $('remu').value, benevole: $('benevole').checked, alerte_email: $('alerte').checked, alerte_freq: $('freq').value } });
            m.className = 'ds-alert-ok'; m.innerHTML = 'Préférences enregistrées. <a class="underline" href="animateur">Voir mes suggestions</a>';
        } catch (e) { m.className = 'ds-alert-err'; m.textContent = e.message; }
        $('save').disabled = false;
    });
})();
</script>
<?php require_once 'partials/footer.php'; ?>

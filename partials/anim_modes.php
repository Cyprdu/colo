<?php
// partials/anim_modes.php : réglages « animateurs » d'un séjour, en DEUX blocs distincts.
//   (A) Recruter des animateurs via ColoMap       -> catalogue des animateurs, candidatures à valider, rémunération éventuelle
//   (B) Inscription de mon équipe d'animation     -> formulaire réservé à SES animateurs, par lien privé ou public (jamais listé), participation éventuelle
// Utilisé par partials/camp_form.php (dans le formulaire du séjour) et par demandes-animation.php (onglet « Réglages »).
// Variables attendues : $animLegacy (bool : afficher les quotas / exigences historiques du bloc A, postés avec le formulaire du séjour),
//                       $camp (array|null : séjour en cours de modification), $val / $chk / $tick (helpers de camp_form, si $animLegacy).
// JS exposé : window.AnimModes = { load(cfg), collect(), save(campToken) -> Promise }.
$animLegacy = !empty($animLegacy);
$animCfg = ['recrute' => false, 'remuneration' => null, 'equipe' => ['active' => false, 'age_min' => null, 'quota' => null, 'tarifs' => [], 'champs' => []]];
if (!empty($camp) && is_array($camp)) {
    require_once __DIR__ . '/../api/animateurs.php';
    require_once __DIR__ . '/../api/equipe.php';
    global $pdo;
    if (isset($pdo)) {
        anim_ensure($pdo);
        $c2 = $pdo->prepare("SELECT * FROM camps WHERE id = ?"); $c2->execute([(int)$camp['id']]); $fresh = $c2->fetch(PDO::FETCH_ASSOC) ?: $camp;
        $animCfg = ['recrute' => !empty($fresh['recrute_animateurs']), 'remuneration' => $fresh['anim_remuneration'] !== null ? (float)$fresh['anim_remuneration'] : null, 'equipe' => equipe_config($pdo, $fresh)];
    }
}
$tk = $tick ?? '';
?>
<div id="am-root" class="space-y-5">
    <!-- (A) -->
    <div class="rounded-2xl border-2 border-[#e5e7eb] p-5" id="am-a">
        <div class="ds-row">
            <div>
                <p class="ds-opt-title">A. Recruter des animateurs via ColoMap</p>
                <p class="ds-opt-sub">Vous cherchez des animateurs : le séjour apparaît dans le catalogue des animateurs inscrits sur ColoMap (avec ou sans BAFA), vous validez leurs candidatures.<br><em>Exemple : un séjour sportif d'été qui a besoin de 6 animateurs qu'il ne connaît pas encore.</em></p>
            </div>
            <label class="ds-switch"><input type="checkbox" id="am-recrute" <?= $animLegacy ? 'name="gestion_animateur"' : '' ?> <?= $animCfg['recrute'] || ($animLegacy && !empty($camp['gestion_animateur'])) ? 'checked' : '' ?> aria-label="Recruter des animateurs via ColoMap"><span></span></label>
        </div>
        <div id="am-a-body" class="mt-4 space-y-4 hidden">
            <div class="max-w-xs"><label class="ds-label" for="am-rem">Rémunération prévue par animateur (€)</label><input id="am-rem" type="number" min="0" step="0.01" class="ds-input" placeholder="Vide = non rémunéré"></div>
            <?php if ($animLegacy): ?>
            <div class="grid grid-cols-3 gap-4">
                <div><label class="ds-label" for="a-tot">Animateurs recherchés</label><input id="a-tot" type="number" min="0" name="quota_max_anim" class="ds-input" value="<?= $val('quota_max_anim') ?>"></div>
                <div><label class="ds-label" for="a-f">Dont femmes</label><input id="a-f" type="number" min="0" name="quota_anim_fille" class="ds-input" value="<?= $val('quota_anim_fille') ?>"></div>
                <div><label class="ds-label" for="a-g">Dont hommes</label><input id="a-g" type="number" min="0" name="quota_anim_garcon" class="ds-input" value="<?= $val('quota_anim_garcon') ?>"></div>
            </div>
            <div class="grid sm:grid-cols-2 gap-3">
                <?php foreach (['anim_plus_18' => 'Majeurs uniquement', 'bafa_obligatoire' => 'BAFA obligatoire', 'remuneration_anim' => 'Animateurs rémunérés', 'anim_doit_payer' => "L'animateur paie une participation"] as $k => $t): ?>
                    <label class="ds-opt items-center"><input type="checkbox" name="<?= $k ?>" <?= $chk($k) ?>><span class="ds-tick"><?= $tk ?></span><span class="ds-opt-title"><?= $t ?></span></label>
                <?php endforeach; ?>
            </div>
            <div class="max-w-xs"><label class="ds-label" for="a-prix">Participation de l'animateur (€)</label><input id="a-prix" type="number" min="0" step="0.01" name="prix_anim" class="ds-input" value="<?= $val('prix_anim') ?>"></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- (B) -->
    <div class="rounded-2xl border-2 border-[#e5e7eb] p-5" id="am-b">
        <div class="ds-row">
            <div>
                <p class="ds-opt-title">B. Inscription de mon équipe d'animation</p>
                <p class="ds-opt-sub">Vos animateurs sont déjà choisis (vous ne recrutez pas via ColoMap) mais doivent s'inscrire, souvent en payant une participation. Vous partagez un lien ; le séjour n'apparaît jamais dans le catalogue des animateurs, et l'âge des enfants n'est pas vérifié.<br><em>Exemple : une pastorale des jeunes organise un week-end pour des collégiens ; ses animateurs de 20 ans s'inscrivent par le lien et règlent 25 €.</em></p>
            </div>
            <label class="ds-switch"><input type="checkbox" id="am-equipe" aria-label="Ouvrir l'inscription de mon équipe d'animation"><span></span></label>
        </div>
        <div id="am-b-body" class="mt-4 space-y-5 hidden">
            <div class="grid sm:grid-cols-2 gap-4">
                <div><label class="ds-label" for="am-age">Âge minimum de l'animateur (facultatif)</label><input id="am-age" type="number" min="0" max="99" class="ds-input" placeholder="Ex : 17"></div>
                <div><label class="ds-label" for="am-quota">Places de l'équipe (facultatif)</label><input id="am-quota" type="number" min="0" class="ds-input" placeholder="Illimité"><p class="ds-hint">Séparées des places enfants : elles ne les consomment pas.</p></div>
            </div>
            <div>
                <p class="ds-label">Participation financière</p>
                <p class="ds-hint mb-2">Aucune participation = inscription gratuite. Sinon, ajoutez un ou plusieurs tarifs (prix fixe, ou montant libre avec un minimum) ; l'animateur paie par carte.</p>
                <div id="am-tarifs" class="space-y-2"></div>
                <button type="button" class="ds-btn ds-btn-light mt-2" id="am-add-tarif">Ajouter un tarif</button>
            </div>
            <div>
                <p class="ds-label">Questions propres à l'équipe</p>
                <p class="ds-hint mb-2">Posées à chaque animateur en plus de son identité, ses coordonnées, son diplôme, ses allergies, son régime et son contact d'urgence.</p>
                <div id="am-champs" class="space-y-2"></div>
                <button type="button" class="ds-btn ds-btn-light mt-2" id="am-add-champ">Ajouter une question</button>
            </div>
            <p class="ds-hint">Les liens d'inscription (privés ou publics, révocables) se créent dans « Gestion des animateurs » après l'enregistrement du séjour.</p>
        </div>
    </div>
</div>
<script>
(function () {
    var $ = function (i) { return document.getElementById(i); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var TYPES = { texte: 'Texte court', paragraphe: 'Texte long', case: 'Case à cocher', liste: 'Liste de choix', nombre: 'Nombre', date: 'Date' };
    var tarifs = [], champs = [];
    function toggles() { $('am-a-body').classList.toggle('hidden', !$('am-recrute').checked); $('am-b-body').classList.toggle('hidden', !$('am-equipe').checked); }
    function drawTarifs() {
        $('am-tarifs').innerHTML = tarifs.map(function (t, i) {
            return '<div class="grid gap-2 sm:grid-cols-[1fr_110px_auto_110px_auto] items-center" data-i="' + i + '"><input class="ds-input" data-f="nom" placeholder="Nom (ex. Animateur)" maxlength="120" value="' + esc(t.nom) + '">' +
                '<input class="ds-input" data-f="prix" type="number" min="0" step="0.01" placeholder="Prix €" value="' + esc(t.prix) + '">' +
                '<label class="text-[13px] flex items-center gap-2"><input type="checkbox" data-f="libre" ' + (t.libre ? 'checked' : '') + ' style="accent-color:#26343b"> Montant libre</label>' +
                '<input class="ds-input" data-f="min" type="number" min="0" step="0.01" placeholder="Minimum €" ' + (t.libre ? '' : 'disabled') + ' value="' + esc(t.min == null ? '' : t.min) + '">' +
                '<button type="button" class="ds-btn ds-btn-light ds-btn-sm" data-del="' + i + '" aria-label="Supprimer ce tarif">&times;</button></div>';
        }).join('');
    }
    function drawChamps() {
        $('am-champs').innerHTML = champs.map(function (c, i) {
            return '<div class="grid gap-2 sm:grid-cols-[1fr_150px_auto_auto] items-center" data-i="' + i + '"><input class="ds-input" data-f="libelle" placeholder="Question" maxlength="255" value="' + esc(c.libelle) + '">' +
                '<select class="ds-select" data-f="type">' + Object.keys(TYPES).map(function (k) { return '<option value="' + k + '"' + (c.type === k ? ' selected' : '') + '>' + TYPES[k] + '</option>'; }).join('') + '</select>' +
                '<label class="text-[13px] flex items-center gap-2"><input type="checkbox" data-f="obligatoire" ' + (c.obligatoire ? 'checked' : '') + ' style="accent-color:#26343b"> Obligatoire</label>' +
                '<button type="button" class="ds-btn ds-btn-light ds-btn-sm" data-delc="' + i + '" aria-label="Supprimer cette question">&times;</button>' +
                (c.type === 'liste' ? '<textarea class="ds-textarea sm:col-span-4" data-f="options" rows="3" placeholder="Un choix par ligne (2 minimum)">' + esc(c.options || '') + '</textarea>' : '') + '</div>';
        }).join('');
    }
    function sync() {
        [].forEach.call($('am-tarifs').children, function (row) { var t = tarifs[row.dataset.i]; [].forEach.call(row.querySelectorAll('[data-f]'), function (el) { t[el.dataset.f] = el.type === 'checkbox' ? el.checked : el.value; }); });
        [].forEach.call($('am-champs').children, function (row) { var c = champs[row.dataset.i]; [].forEach.call(row.querySelectorAll('[data-f]'), function (el) { c[el.dataset.f] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value; }); });
    }
    $('am-recrute').addEventListener('change', toggles); $('am-equipe').addEventListener('change', toggles);
    $('am-add-tarif').addEventListener('click', function () { sync(); if (tarifs.length < 10) tarifs.push({ nom: '', prix: '', libre: false, min: '' }); drawTarifs(); });
    $('am-add-champ').addEventListener('click', function () { sync(); if (champs.length < 20) champs.push({ id: 0, libelle: '', type: 'texte', options: '', obligatoire: 0 }); drawChamps(); });
    $('am-tarifs').addEventListener('click', function (e) { var d = e.target.closest('[data-del]'); if (d) { sync(); tarifs.splice(+d.dataset.del, 1); drawTarifs(); } });
    $('am-champs').addEventListener('click', function (e) { var d = e.target.closest('[data-delc]'); if (d) { sync(); champs.splice(+d.dataset.delc, 1); drawChamps(); } });
    $('am-tarifs').addEventListener('change', function (e) { if (e.target.dataset.f === 'libre') { sync(); drawTarifs(); } });
    $('am-champs').addEventListener('change', function (e) { if (e.target.dataset.f === 'type') { sync(); drawChamps(); } });

    function load(cfg) {
        $('am-recrute').checked = $('am-recrute').checked || !!cfg.recrute;
        $('am-rem').value = cfg.remuneration != null ? cfg.remuneration : '';
        var e = cfg.equipe || {};
        $('am-equipe').checked = !!e.active; $('am-age').value = e.age_min || ''; $('am-quota').value = e.quota || '';
        tarifs = (e.tarifs || []).map(function (t) { return { nom: t.nom, prix: t.prix, libre: !!t.libre, min: t.min }; });
        champs = (e.champs || []).map(function (c) { return { id: c.id, libelle: c.libelle, type: c.type, options: c.options, obligatoire: c.obligatoire }; });
        drawTarifs(); drawChamps(); toggles();
    }
    function collect() {
        sync();
        return { recrute: $('am-recrute').checked, remuneration: $('am-rem').value,
            equipe: { active: $('am-equipe').checked, age_min: $('am-age').value, quota: $('am-quota').value, tarifs: tarifs, champs: champs.filter(function (c) { return String(c.libelle).trim() !== ''; }) } };
    }
    async function save(campToken) {
        var body = collect(); body.camp = campToken;
        var r = await fetch('api/anim_directeur.php?a=config', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': (document.querySelector('meta[name=csrf]') || {}).content || '' }, body: JSON.stringify(body) });
        var d = {}; try { d = await r.json(); } catch (x) { }
        if (!r.ok) throw new Error(d.error || 'Réglages des animateurs non enregistrés.');
        return d;
    }
    window.AnimModes = { load: load, collect: collect, save: save };
    load(<?= json_encode($animCfg, JSON_UNESCAPED_UNICODE) ?>);
})();
</script>

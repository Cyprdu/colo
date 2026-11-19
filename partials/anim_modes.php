<?php
// partials/anim_modes.php : réglages « animateurs » d'un séjour, en DEUX blocs distincts.
//   (A) Recruter des animateurs via moncamp.fr       -> catalogue des animateurs, candidatures à valider, rémunération éventuelle
//   (B) Inscription de mon équipe d'animation     -> formulaire réservé à SES animateurs, par lien privé ou public (jamais listé), participation éventuelle
// Utilisé par partials/camp_form.php (dans le formulaire du séjour) et par demandes-animation.php (onglet « Réglages »).
// Variables attendues : $animLegacy (bool : afficher les quotas / exigences historiques du bloc A, postés avec le formulaire du séjour),
//                       $camp (array|null : séjour en cours de modification), $val / $chk / $tick (helpers de camp_form, si $animLegacy).
// JS exposé : window.AnimModes = { load(cfg), collect(), save(campToken) -> Promise }.
$animLegacy = !empty($animLegacy);
$animCfg = ['recrute' => false, 'remuneration' => null, 'equipe' => ['active' => false, 'visibilite' => 'prive', 'lien' => null, 'age_min' => null, 'quota' => null, 'tarifs' => [], 'champs' => []]];
if (!empty($camp) && is_array($camp)) {
    require_once __DIR__ . '/../api/animateurs.php';
    require_once __DIR__ . '/../api/equipe.php';
    global $pdo;
    if (isset($pdo)) {
        anim_ensure($pdo);
        $c2 = $pdo->prepare("SELECT * FROM camps WHERE id = ?"); $c2->execute([(int)$camp['id']]); $fresh = $c2->fetch(PDO::FETCH_ASSOC) ?: $camp;
        $animCfg = ['recrute' => !empty($fresh['recrute_animateurs']), 'remuneration' => $fresh['anim_remuneration'] !== null ? (float)$fresh['anim_remuneration'] : null, 'equipe' => equipe_config($pdo, $fresh), 'campToken' => $fresh['token']];
    }
}
$tk = $tick ?? '';
?>
<div id="am-root" class="space-y-5">
    <!-- (A) -->
    <div class="rounded-2xl border-2 border-[#e5e7eb] p-5" id="am-a">
        <div class="ds-row">
            <div>
                <div class="ds-opt-title">A. Recruter via moncamp.fr <details class="ds-help"><summary>?</summary><div>Le séjour apparaît dans le catalogue des animateurs inscrits sur moncamp.fr (avec ou sans BAFA) et vous validez leurs candidatures.<br>Exemple : un séjour sportif d'été qui cherche 6 animateurs qu'il ne connaît pas encore.</div></details></div>
                <p class="ds-opt-sub">Les animateurs de moncamp.fr postulent, vous validez.</p>
            </div>
            <label class="ds-switch"><input type="checkbox" id="am-recrute" <?= $animLegacy ? 'name="gestion_animateur"' : '' ?> <?= $animCfg['recrute'] || ($animLegacy && !empty($camp['gestion_animateur'])) ? 'checked' : '' ?> aria-label="Recruter des animateurs via moncamp.fr"><span></span></label>
        </div>
        <div id="am-a-body" class="mt-4 space-y-4 hidden">
            <div class="max-w-xs"><label class="ds-label" for="am-rem">Rémunération par animateur (€)</label><input id="am-rem" type="number" min="0" step="0.01" class="ds-input" placeholder="Vide = non rémunéré"></div>
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
                <div class="ds-opt-title">B. Inscrire mon équipe <details class="ds-help"><summary>?</summary><div>Vos animateurs sont déjà choisis mais doivent s'inscrire, souvent en payant une participation. Vous partagez un lien ; le séjour n'apparaît jamais dans le catalogue des animateurs.<br>Exemple : une pastorale des jeunes organise un week-end ; ses animateurs de 20 ans s'inscrivent par le lien et règlent 25 €.</div></details></div>
                <p class="ds-opt-sub">Vos animateurs s'inscrivent avec un lien.</p>
            </div>
            <label class="ds-switch"><input type="checkbox" id="am-equipe" aria-label="Ouvrir l'inscription de mon équipe d'animation"><span></span></label>
        </div>
        <div id="am-b-body" class="mt-4 space-y-5 hidden">
            <div class="grid sm:grid-cols-2 gap-4">
                <div><label class="ds-label" for="am-age">Âge minimum de l'animateur (facultatif)</label><input id="am-age" type="number" min="0" max="99" class="ds-input" placeholder="Ex : 17"></div>
                <div><label class="ds-label" for="am-quota">Places de l'équipe (facultatif)</label><input id="am-quota" type="number" min="0" class="ds-input" placeholder="Illimité"></div>
            </div>
            <details id="am-cats-box" class="rounded-2xl border border-[#e5e7eb] p-4">
                <summary class="cursor-pointer font-semibold text-[#26343b]">Options avancées : plusieurs catégories d'encadrants</summary>
                <p class="ds-hint">Animation, cuisine, logistique… Chaque catégorie a ses places (dont femmes / hommes) ; un tarif ou une question peut lui être réservé. L'encadrant choisit sa catégorie en s'inscrivant. Sans catégorie, tout le monde s'inscrit de la même façon.</p>
                <div id="am-cats" class="space-y-2 mt-3"></div>
                <button type="button" class="ds-btn ds-btn-light mt-2" id="am-add-cat">Ajouter une catégorie</button>
            </details>
            <div>
                <p class="ds-label">Qui peut s'inscrire ?</p>
                <div class="grid sm:grid-cols-2 gap-3">
                    <label class="ds-opt items-center"><input type="radio" name="am-visib" value="prive"><span class="ds-tick"><?= $tk ?></span><span><span class="ds-opt-title block">Lien privé</span><span class="ds-opt-sub">Uniquement via le lien envoyé.</span></span></label>
                    <label class="ds-opt items-center"><input type="radio" name="am-visib" value="public"><span class="ds-tick"><?= $tk ?></span><span><span class="ds-opt-title block">Public</span><span class="ds-opt-sub">Tarif « Animateur » sur la page du séjour.</span></span></label>
                </div>
                <div id="am-lien-box" class="mt-3 hidden">
                    <label class="ds-label" for="am-lien">Lien d'inscription des animateurs</label>
                    <div class="flex gap-2"><input id="am-lien" class="ds-input" readonly><button type="button" class="ds-btn ds-btn-light" id="am-copy">Copier</button></div>
                    <label class="mt-2 flex items-center gap-2 text-[13px]"><input type="checkbox" id="am-renew" style="accent-color:#26343b"> Générer un nouveau lien à l'enregistrement (l'ancien cessera de fonctionner)</label>
                </div>
                <p id="am-lien-hint" class="ds-hint mt-2 hidden">Le lien sera disponible ici après l'enregistrement du séjour.</p>
            </div>
            <div>
                <p class="ds-label">Participation financière <details class="ds-help"><summary>?</summary><div>Aucune participation = inscription gratuite. Sinon, ajoutez un ou plusieurs tarifs (prix fixe, ou montant libre avec un minimum) ; l'animateur paie par carte. Les places de l'équipe sont séparées des places enfants.</div></details></p>
                <div id="am-tarifs" class="space-y-2"></div>
                <button type="button" class="ds-btn ds-btn-light mt-2" id="am-add-tarif">Ajouter un tarif</button>
            </div>
            <div>
                <p class="ds-label">Informations demandées aux animateurs <details class="ds-help"><summary>?</summary><div><strong>Demander</strong> : le champ apparaît dans le formulaire, facultatif. <strong>Obligatoire</strong> : l'inscription est impossible sans. Identité, e-mail et date de naissance sont toujours demandés.</div></details></p>
                <div class="space-y-2 mb-5" id="am-infos">
                    <?php foreach (['diplome' => "Diplôme d'animation", 'allergies' => 'Allergies', 'regime' => 'Régime alimentaire', 'urgence' => "Personne à prévenir"] as $k => $lib): ?>
                        <div class="ds-row" data-k="<?= $k ?>">
                            <span class="ds-opt-title"><?= $lib ?></span>
                            <span class="flex items-center gap-5 flex-none text-[13px] font-semibold text-[#3b4a54]">
                                <label class="flex items-center gap-2 cursor-pointer">Demander <span class="ds-switch"><input type="checkbox" data-role="ask" aria-label="Demander : <?= $lib ?>"><span></span></span></label>
                                <label class="flex items-center gap-2 cursor-pointer">Obligatoire <span class="ds-switch"><input type="checkbox" data-role="req" aria-label="Obligatoire : <?= $lib ?>"><span></span></span></label>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="ds-label">Vos propres questions</p>
                <div id="am-champs" class="space-y-2"></div>
                <button type="button" class="ds-btn ds-btn-light mt-2" id="am-add-champ">Ajouter une question</button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var $ = function (i) { return document.getElementById(i); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var TYPES = { texte: 'Texte court', paragraphe: 'Texte long', case: 'Case à cocher', liste: 'Liste de choix', nombre: 'Nombre', date: 'Date' };
    var tarifs = [], champs = [], cats = [], lienTok = null, campTok = null;
    var cle = function () { return Array.prototype.map.call(crypto.getRandomValues(new Uint8Array(6)), function (b) { return (b + 256).toString(16).slice(1); }).join(''); };
    function catSel(v, span) {
        var l = cats.filter(function (k) { return String(k.nom).trim(); });
        return l.length ? '<select class="ds-select ' + span + '" data-f="cat" aria-label="Catégorie concernée"><option value="">Toutes les catégories</option>' + l.map(function (k) { return '<option value="' + esc(k.cle) + '"' + (v === k.cle ? ' selected' : '') + '>' + esc(k.nom) + '</option>'; }).join('') + '</select>' : '';
    }
    function drawCats() {
        $('am-cats').innerHTML = cats.map(function (k, i) {
            var n = function (f, ph) { return '<input class="ds-input" data-f="' + f + '" type="number" min="0" placeholder="' + ph + '" value="' + esc(k[f] == null ? '' : k[f]) + '">'; };
            return '<div class="grid gap-2 sm:grid-cols-[1fr_100px_100px_100px_auto] items-center" data-i="' + i + '"><input class="ds-input" data-f="nom" maxlength="80" placeholder="Ex. Cuisine" value="' + esc(k.nom) + '">' + n('quota', 'Places') + n('quota_f', 'Dont femmes') + n('quota_g', 'Dont hommes') +
                '<button type="button" class="ds-btn ds-btn-light ds-btn-sm" data-delk="' + i + '" aria-label="Supprimer cette catégorie">&times;</button></div>';
        }).join('');
    }
    var DEF_INFOS = { diplome: 1, allergies: 1, regime: 1, urgence: 2 };
    var visib = function () { var r = document.querySelector('[name=am-visib]:checked'); return r ? r.value : 'prive'; };
    function drawLien() {
        var ok = !!(lienTok && campTok); $('am-lien-box').classList.toggle('hidden', !ok); $('am-lien-hint').classList.toggle('hidden', ok);
        if (lienTok && campTok) $('am-lien').value = location.origin + '/camp_details.php?t=' + campTok + '&l=' + lienTok;
    }
    function toggles() { $('am-a-body').classList.toggle('hidden', !$('am-recrute').checked); $('am-b-body').classList.toggle('hidden', !$('am-equipe').checked); }
    function drawTarifs() {
        $('am-tarifs').innerHTML = tarifs.map(function (t, i) {
            return '<div class="grid gap-2 sm:grid-cols-[1fr_110px_auto_110px_auto] items-center" data-i="' + i + '"><input class="ds-input" data-f="nom" placeholder="Nom (ex. Animateur)" maxlength="120" value="' + esc(t.nom) + '">' +
                '<input class="ds-input" data-f="prix" type="number" min="0" step="0.01" placeholder="Prix €" value="' + esc(t.prix) + '">' +
                '<label class="text-[13px] flex items-center gap-2"><input type="checkbox" data-f="libre" ' + (t.libre ? 'checked' : '') + ' style="accent-color:#26343b"> Montant libre</label>' +
                '<input class="ds-input" data-f="min" type="number" min="0" step="0.01" placeholder="Minimum €" ' + (t.libre ? '' : 'disabled') + ' value="' + esc(t.min == null ? '' : t.min) + '">' +
                '<button type="button" class="ds-btn ds-btn-light ds-btn-sm" data-del="' + i + '" aria-label="Supprimer ce tarif">&times;</button>' + catSel(t.cat, 'sm:col-span-5') + '</div>';
        }).join('');
    }
    function drawChamps() {
        $('am-champs').innerHTML = champs.map(function (c, i) {
            return '<div class="grid gap-2 sm:grid-cols-[1fr_150px_auto_auto] items-center" data-i="' + i + '"><input class="ds-input" data-f="libelle" placeholder="Question" maxlength="255" value="' + esc(c.libelle) + '">' +
                '<select class="ds-select" data-f="type">' + Object.keys(TYPES).map(function (k) { return '<option value="' + k + '"' + (c.type === k ? ' selected' : '') + '>' + TYPES[k] + '</option>'; }).join('') + '</select>' +
                '<label class="text-[13px] flex items-center gap-2"><input type="checkbox" data-f="obligatoire" ' + (c.obligatoire ? 'checked' : '') + ' style="accent-color:#26343b"> Obligatoire</label>' +
                '<button type="button" class="ds-btn ds-btn-light ds-btn-sm" data-delc="' + i + '" aria-label="Supprimer cette question">&times;</button>' +
                (c.type === 'liste' ? '<textarea class="ds-textarea sm:col-span-4" data-f="options" rows="3" placeholder="Un choix par ligne (2 minimum)">' + esc(c.options || '') + '</textarea>' : '') + catSel(c.cat, 'sm:col-span-4') + '</div>';
        }).join('');
    }
    function sync() {
        [].forEach.call($('am-cats').children, function (row) { var k = cats[row.dataset.i]; [].forEach.call(row.querySelectorAll('[data-f]'), function (el) { k[el.dataset.f] = el.value; }); });
        [].forEach.call($('am-tarifs').children, function (row) { var t = tarifs[row.dataset.i]; [].forEach.call(row.querySelectorAll('[data-f]'), function (el) { t[el.dataset.f] = el.type === 'checkbox' ? el.checked : el.value; }); });
        [].forEach.call($('am-champs').children, function (row) { var c = champs[row.dataset.i]; [].forEach.call(row.querySelectorAll('[data-f]'), function (el) { c[el.dataset.f] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value; }); });
    }
    $('am-copy').addEventListener('click', function () {
        $('am-lien').select();
        (navigator.clipboard ? navigator.clipboard.writeText($('am-lien').value) : Promise.reject()).catch(function () { document.execCommand('copy'); });
        $('am-copy').textContent = 'Copié ✓'; setTimeout(function () { $('am-copy').textContent = 'Copier'; }, 2000);
    });
    $('am-infos').addEventListener('change', function (e) {   // Obligatoire implique Demander ; retirer Demander retire Obligatoire
        var row = e.target.closest('[data-k]'); if (!row) return;
        var ask = row.querySelector('[data-role=ask]'), req = row.querySelector('[data-role=req]');
        if (e.target === req && req.checked) ask.checked = true;
        if (e.target === ask && !ask.checked) req.checked = false;
    });
    function drawInfos(n) { [].forEach.call($('am-infos').children, function (row) { var v = (n || DEF_INFOS)[row.dataset.k]; row.querySelector('[data-role=ask]').checked = v >= 1; row.querySelector('[data-role=req]').checked = v === 2; }); }
    function collectInfos() { var o = {}; [].forEach.call($('am-infos').children, function (row) { o[row.dataset.k] = row.querySelector('[data-role=req]').checked ? 2 : (row.querySelector('[data-role=ask]').checked ? 1 : 0); }); return o; }
    $('am-recrute').addEventListener('change', toggles); $('am-equipe').addEventListener('change', toggles);
    $('am-add-cat').addEventListener('click', function () { sync(); if (cats.length < 10) cats.push({ cle: cle(), nom: '', quota: '', quota_f: '', quota_g: '' }); drawCats(); });
    $('am-cats').addEventListener('click', function (e) { var d = e.target.closest('[data-delk]'); if (d) { sync(); cats.splice(+d.dataset.delk, 1); drawCats(); drawTarifs(); drawChamps(); } });
    $('am-cats').addEventListener('change', function () { sync(); drawTarifs(); drawChamps(); });
    $('am-add-tarif').addEventListener('click', function () { sync(); if (tarifs.length < 10) tarifs.push({ nom: '', prix: '', libre: false, min: '' }); drawTarifs(); });
    $('am-add-champ').addEventListener('click', function () { sync(); if (champs.length < 20) champs.push({ id: 0, libelle: '', type: 'texte', options: '', obligatoire: 0 }); drawChamps(); });
    $('am-tarifs').addEventListener('click', function (e) { var d = e.target.closest('[data-del]'); if (d) { sync(); tarifs.splice(+d.dataset.del, 1); drawTarifs(); } });
    $('am-champs').addEventListener('click', function (e) { var d = e.target.closest('[data-delc]'); if (d) { sync(); champs.splice(+d.dataset.delc, 1); drawChamps(); } });
    $('am-tarifs').addEventListener('change', function (e) { if (e.target.dataset.f === 'libre') { sync(); drawTarifs(); } });
    $('am-champs').addEventListener('change', function (e) { if (e.target.dataset.f === 'type') { sync(); drawChamps(); } });

    function load(cfg) {
        $('am-recrute').checked = $('am-recrute').checked || !!cfg.recrute;
        $('am-rem').value = cfg.remuneration != null ? cfg.remuneration : '';
        var e = cfg.equipe || {}; if (cfg.campToken) campTok = cfg.campToken;
        $('am-equipe').checked = !!e.active; $('am-age').value = e.age_min || ''; $('am-quota').value = e.quota || '';
        cats = (e.categories || []).map(function (k) { return { cle: k.cle, nom: k.nom, quota: k.quota, quota_f: k.quota_f, quota_g: k.quota_g }; });
        tarifs = (e.tarifs || []).map(function (t) { return { nom: t.nom, prix: t.prix, libre: !!t.libre, min: t.min, cat: t.cat || '' }; });
        champs = (e.champs || []).map(function (c) { return { id: c.id, libelle: c.libelle, type: c.type, options: c.options, obligatoire: c.obligatoire, cat: c.cat || '' }; });
        $('am-cats-box').open = cats.length > 0; drawCats();
        lienTok = e.lien || null; $('am-renew').checked = false;
        var v = document.querySelector('[name=am-visib][value=' + (e.visibilite === 'public' ? 'public' : 'prive') + ']'); if (v) v.checked = true;
        drawInfos(e.infos); drawTarifs(); drawChamps(); drawLien(); toggles();
    }
    function collect() {
        sync();
        return { recrute: $('am-recrute').checked, remuneration: $('am-rem').value,
            equipe: { active: $('am-equipe').checked, visibilite: visib(), renouveler_lien: $('am-renew').checked, age_min: $('am-age').value, quota: $('am-quota').value, infos: collectInfos(), categories: cats.filter(function (k) { return String(k.nom).trim() !== ''; }), tarifs: tarifs, champs: champs.filter(function (c) { return String(c.libelle).trim() !== ''; }) } };
    }
    async function save(campToken) {
        var body = collect(); body.camp = campToken; campTok = campToken;
        var r = await fetch('api/anim_directeur.php?a=config', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': (document.querySelector('meta[name=csrf]') || {}).content || '' }, body: JSON.stringify(body) });
        var d = {}; try { d = await r.json(); } catch (x) { }
        if (!r.ok) throw new Error(d.error || 'Réglages des animateurs non enregistrés.');
        if (d.lien !== undefined) { lienTok = d.lien; $('am-renew').checked = false; drawLien(); }
        return d;
    }
    window.AnimModes = { load: load, collect: collect, save: save };
    load(<?= json_encode($animCfg, JSON_UNESCAPED_UNICODE) ?>);
})();
</script>

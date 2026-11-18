<?php
// equipe-inscription.php?l=<jeton> : inscription d'un membre de l'équipe d'animation d'un séjour (lien du directeur).
// Réservé aux comptes connectés (adulte avec son compte, pas de fiche enfant). Indépendant de la tranche d'âge des enfants.
$body_class = 'bg-white';
require_once 'partials/header.php';
$l = (string)($_GET['l'] ?? '');
if (!isset($_SESSION['user'])) { header('Location: login.php?redirect=' . urlencode('equipe-inscription.php?l=' . $l)); exit; }
?>
<title>Inscription de l'équipe d'animation - moncamp.fr</title>
<meta name="robots" content="noindex, nofollow">
<main class="ds-page" style="max-width:760px">
    <p class="ds-eyebrow">Équipe d'animation</p>
    <div id="box" class="mt-2"><p class="text-[#6b7780]">Chargement…</p></div>
</main>
<script src="assets/js/anim.js?v=3"></script>
<script>
(function () {
    var A = window.ANIM, E = A.esc, L = <?= json_encode($l) ?>, box = document.getElementById('box'), I;
    var inp = function (id, label, type, val, extra) { return '<div><label class="ds-label" for="' + id + '">' + label + '</label><input id="' + id + '" type="' + (type || 'text') + '" class="ds-input" value="' + E(val || '') + '" ' + (extra || '') + '></div>'; };
    function champ(c) {
        var id = 'q' + c.id, req = c.obligatoire ? ' *' : '';
        if (c.type === 'paragraphe') return '<div><label class="ds-label" for="' + id + '">' + E(c.libelle) + req + '</label><textarea id="' + id + '" data-q="' + c.id + '" rows="3" class="ds-textarea"></textarea></div>';
        if (c.type === 'liste') return '<div><label class="ds-label" for="' + id + '">' + E(c.libelle) + req + '</label><select id="' + id + '" data-q="' + c.id + '" class="ds-select"><option value="">Choisir…</option>' + c.choix.map(function (o) { return '<option>' + E(o) + '</option>'; }).join('') + '</select></div>';
        if (c.type === 'case') return '<label class="flex items-center gap-3 text-[15px]"><input type="checkbox" id="' + id + '" data-q="' + c.id + '" style="accent-color:#26343b"> ' + E(c.libelle) + req + '</label>';
        return '<div><label class="ds-label" for="' + id + '">' + E(c.libelle) + req + '</label><input id="' + id + '" data-q="' + c.id + '" type="' + (c.type === 'nombre' ? 'number' : c.type === 'date' ? 'date' : 'text') + '" class="ds-input"></div>';
    }
    function catBlock(d) {
        if (!d.categories.length) return '';
        var rest = function (k) { var r = k.restant || {}; return r.total === 0 ? 'complet' : (r.total != null ? r.total + ' place(s)' : ''); };
        return '<div class="border-t border-[#e5e7eb] pt-5"><p class="ds-label">Votre rôle dans l’équipe *</p><div class="grid sm:grid-cols-2 gap-2">' + d.categories.map(function (k) {
            return '<label class="ds-opt items-center"><input type="radio" name="cat" value="' + E(k.cle) + '" ' + (k.restant && k.restant.total === 0 ? 'disabled' : '') + '><span class="ds-tick"></span><span><span class="ds-opt-title block">' + E(k.nom) + '</span><span class="ds-opt-sub block">' + E(rest(k)) + '</span></span></label>';
        }).join('') + '</div><div id="sexeBox" class="mt-4 hidden" style="max-width:260px"><label class="ds-label" for="sexe">Vous êtes *</label><select id="sexe" class="ds-select"><option value="">Choisir…</option><option value="Femme">Une femme</option><option value="Homme">Un homme</option></select></div></div>';
    }
    // Seuls les tarifs et questions communs à tous ou réservés à la catégorie choisie sont affichés (et envoyés)
    function filtreCat() {
        var c = document.querySelector('[name=cat]:checked'), cle = c ? c.value : '', k = I.categories.find(function (x) { return x.cle === cle; });
        [].forEach.call(document.querySelectorAll('[data-cat]'), function (el) { el.hidden = !!el.dataset.cat && el.dataset.cat !== cle; });
        var vis = [].filter.call(document.querySelectorAll('[name=tarif]'), function (r) { return !r.closest('[data-cat]').hidden; });
        if (vis.length && !vis.some(function (r) { return r.checked; })) vis[0].checked = true;
        document.getElementById('free').hidden = vis.length > 0;
        if (document.getElementById('sexeBox')) document.getElementById('sexeBox').classList.toggle('hidden', !(k && k.sexe));
    }
    function tarifBlock(d) {
        return '<p id="free" class="ds-alert-ok">Participation gratuite.</p><div class="space-y-2">' + d.tarifs.map(function (t, i) {
            return '<label data-cat="' + E(t.cat || '') + '" class="block rounded-2xl border-2 p-4 cursor-pointer" style="border-color:#e5e7eb"><span class="flex items-center gap-3"><input type="radio" name="tarif" value="' + t.id + '" ' + (i === 0 ? 'checked' : '') + ' style="accent-color:#26343b"><span class="font-extrabold text-[#26343b]">' + E(t.nom) + '</span><span class="ml-auto font-bold">' + (t.libre ? 'dès ' + A.money(t.min) : (t.prix > 0 ? A.money(t.prix) : 'Gratuit')) + '</span></span>' +
                (t.libre ? '<span class="block mt-3 ml-7"><label class="ds-label" for="m' + t.id + '">Votre montant (€, minimum ' + A.money(t.min) + ')</label><input id="m' + t.id + '" type="number" min="' + t.min + '" step="0.01" value="' + t.min + '" class="ds-input" style="max-width:200px"></span>' : '') + '</label>';
        }).join('') + '</div>';
    }
    async function init() {
        try {
            var d = await A.api('api/equipe_inscrire.php?a=info&l=' + encodeURIComponent(L)); I = d;
            if (d.deja) { box.innerHTML = '<h1 class="ds-h1">Vous êtes déjà inscrit</h1><p class="ds-lead">Votre inscription à « ' + E(d.camp.nom) + ' » est enregistrée.</p><a class="ds-btn mt-5" href="reservations">Mes réservations</a>'; return; }
            if (d.places_restantes === 0) { box.innerHTML = '<h1 class="ds-h1">Équipe complète</h1><p class="ds-lead">Il n’y a plus de place dans l’équipe d’animation de « ' + E(d.camp.nom) + ' ». Contactez le directeur.</p>'; return; }
            var m = d.moi || {}, NV = d.infos || { diplome: 1, allergies: 1, regime: 1, urgence: 2 }, opt = function (k) { return NV[k] === 2 ? ' *' : ' (facultatif)'; };
            box.innerHTML = '<h1 class="ds-h1">' + E(d.camp.nom) + '</h1><p class="ds-lead">' + E(d.camp.organisateur) + ' · ' + E(d.camp.ville) + ' · ' + E(A.range(d.camp.date_debut, d.camp.date_fin)) + '</p>' +
                '<p class="mt-3 text-[14px] text-[#6b7780]">Inscription réservée à l’équipe d’animation' + (d.age_min ? ' (' + d.age_min + ' ans minimum)' : '') + (d.places_restantes != null ? ' · ' + d.places_restantes + ' place(s) restante(s)' : '') + '.</p>' +
                '<form id="f" class="ds-card mt-6 p-6 sm:p-8 space-y-6" novalidate>' +
                '<div class="grid sm:grid-cols-2 gap-4">' + inp('prenom', 'Prénom *', 'text', m.prenom, 'maxlength="80"') + inp('nom', 'Nom *', 'text', m.nom, 'maxlength="80"') +
                inp('email', 'E-mail *', 'email', m.email) + inp('tel', 'Téléphone', 'tel', m.tel) + inp('dn', 'Date de naissance' + (d.age_min ? ' *' : ''), 'date', m.date_naissance) + '</div>' +
                (NV.diplome ? '<div class="grid sm:grid-cols-2 gap-4"><div><label class="ds-label" for="bafa">Diplôme d’animation' + opt('diplome') + '</label><select id="bafa" class="ds-select">' +
                Object.keys(A.BAFA).map(function (k) { return '<option value="' + k + '"' + (k === 'aucun' ? ' selected' : '') + '>' + E(A.BAFA[k]) + '</option>'; }).join('') + '</select></div>' + inp('diplome', 'Numéro / intitulé' + opt('diplome'), 'text', '', 'maxlength="255"') + '</div>' : '') +
                (NV.allergies || NV.regime ? '<div class="grid sm:grid-cols-2 gap-4">' + (NV.allergies ? '<div><label class="ds-label" for="allergies">Allergies' + opt('allergies') + '</label><textarea id="allergies" rows="2" maxlength="1000" class="ds-textarea"></textarea></div>' : '') + (NV.regime ? '<div><label class="ds-label" for="regime">Régime alimentaire' + opt('regime') + '</label><input id="regime" class="ds-input" maxlength="255"></div>' : '') + '</div>' : '') +
                (NV.urgence ? '<div class="grid sm:grid-cols-2 gap-4">' + inp('un', 'Personne à prévenir en cas d’urgence' + opt('urgence'), 'text', '', 'maxlength="120"') + inp('ut', 'Téléphone de cette personne' + opt('urgence'), 'tel', '') + '</div>' : '') +
                catBlock(d) +
                (d.champs.length ? '<div class="space-y-4 border-t border-[#e5e7eb] pt-5">' + d.champs.map(function (c) { return '<div data-cat="' + E(c.cat || '') + '">' + champ(c) + '</div>'; }).join('') + '</div>' : '') +
                '<div class="border-t border-[#e5e7eb] pt-5"><p class="ds-label">Participation</p>' + tarifBlock(d) + '</div>' +
                '<div id="err" class="ds-alert-err hidden"></div><button class="ds-btn" id="go" type="submit">' + (d.tarifs.length ? 'Continuer vers le paiement' : 'Valider mon inscription') + '</button></form>';
            document.getElementById('f').addEventListener('submit', send);
            document.getElementById('f').addEventListener('change', function (e) { if (e.target.name === 'cat') filtreCat(); });
            filtreCat();
        } catch (e) { box.innerHTML = '<h1 class="ds-h1">Lien indisponible</h1><p class="ds-lead">' + E(e.message) + '</p>'; }
    }
    async function send(ev) {
        ev.preventDefault();
        var $ = function (i) { return document.getElementById(i); }, er = $('err'), go = $('go'), v = function (i) { return $(i) ? $(i).value : ''; };
        var rep = {}; [].forEach.call(document.querySelectorAll('[data-q]'), function (el) { if (el.closest('[data-cat]').hidden) return; rep[el.dataset.q] = el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value; });
        var t = document.querySelector('[name=tarif]:checked'), tid = t && !t.closest('[data-cat]').hidden ? t.value : 0, k = document.querySelector('[name=cat]:checked');
        er.classList.add('hidden'); go.disabled = true;
        try {
            var r = await A.api('api/equipe_inscrire.php?a=inscrire', { json: { lien: L, prenom: $('prenom').value, nom: $('nom').value, email: $('email').value, tel: $('tel').value, date_naissance: $('dn').value,
                bafa_statut: v('bafa') || 'aucun', diplome: v('diplome'), allergies: v('allergies'), regime: v('regime'), urgence_nom: v('un'), urgence_tel: v('ut'),
                categorie: k ? k.value : '', sexe: v('sexe'), tarif: tid, montant: tid && $('m' + tid) ? $('m' + tid).value : '', reponses: rep } });
            location.href = r.url || 'reservations?equipe=ok';
        } catch (e) { er.textContent = e.message; er.classList.remove('hidden'); er.scrollIntoView({ block: 'center' }); go.disabled = false; }
    }
    init();
})();
</script>
<?php require_once 'partials/footer.php'; ?>

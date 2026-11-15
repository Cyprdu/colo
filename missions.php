<?php
// missions.php : catalogue des séjours qui recrutent des animateurs (réservé aux comptes animateurs).
// Les données viennent de api/anim_catalogue.php (même moteur de filtres que la recherche).
$body_class = 'bg-white';
require_once 'partials/header.php';
if (empty($_SESSION['user']['is_animateur'])) { header('Location: login.php?redirect=' . urlencode('missions.php')); exit; }
?>
<title>Missions - Espace animateur - ColoMap</title>
<main class="ds-page">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="ds-eyebrow">Espace animateur</p>
            <h1 class="ds-h1">Missions</h1>
            <p class="ds-lead">Postulez auprès des directeurs qui cherchent des animateurs, avec ou sans BAFA.</p>
        </div>
        <a href="preferences-animateur" class="ds-btn ds-btn-light">Mes préférences</a>
    </div>

    <form id="f" class="ds-card mt-8 p-5 grid gap-4 md:grid-cols-[1.4fr_1fr_auto] items-end">
        <div><label class="ds-label" for="q">Séjour, thème, organisateur</label><input id="q" class="ds-input" placeholder="Rechercher…" autocomplete="off"></div>
        <div><label class="ds-label" for="city">Ville ou code postal</label><input id="city" class="ds-input" autocomplete="off"></div>
        <button class="ds-btn" type="submit">Rechercher</button>
        <div class="md:col-span-3 flex flex-wrap gap-5 text-[14px] text-[#3b4a54]">
            <label class="flex items-center gap-2"><input type="checkbox" id="remunere" style="accent-color:#26343b"> Rémunéré</label>
            <label class="flex items-center gap-2"><input type="checkbox" id="sans_bafa" style="accent-color:#26343b"> Ouvert sans BAFA</label>
            <label class="flex items-center gap-2">Période
                <input type="date" id="from" class="ds-input" style="width:auto;padding:6px 10px"> <span>au</span> <input type="date" id="to" class="ds-input" style="width:auto;padding:6px 10px"></label>
        </div>
    </form>

    <p id="count" class="mt-6 text-[14px] text-[#6b7780]" aria-live="polite"></p>
    <div id="grid" class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3"></div>
    <div id="more" class="mt-8 text-center hidden"><button type="button" class="ds-btn ds-btn-light" id="more-b">Voir plus de séjours</button></div>
</main>
<script src="assets/js/anim.js?v=3"></script>
<script>
(function () {
    var A = window.ANIM, $ = function (i) { return document.getElementById(i); }, page = 1;
    function card(c) {
        var tags = [];
        tags.push(c.remuneration ? 'Rémunéré · ' + A.money(c.remuneration) : 'Bénévole / non rémunéré');
        tags.push(c.bafa_obligatoire ? 'BAFA requis' : 'Sans BAFA accepté');
        if (c.majeur) tags.push('Majeurs');
        var img = c.image_url ? '<img src="' + A.esc(c.image_url) + '" alt="" loading="lazy" class="w-full h-full object-cover">' : '';
        return '<a href="info-camp-animateur?t=' + encodeURIComponent(c.token) + '" class="ds-tile ds-fade" style="flex-direction:column;align-items:stretch;padding:0;overflow:hidden">' +
            '<div style="height:150px;background:#e5eaef">' + img + '</div><div style="padding:18px">' +
            '<p class="ds-eyebrow">' + A.esc(c.organisateur_nom || '') + '</p><h3 class="font-extrabold text-[17px] text-[#26343b] mt-1">' + A.esc(c.nom) + '</h3>' +
            '<p class="mt-2 text-[13px] text-[#6b7780] flex items-center gap-2">' + A.icon('pin') + A.esc(c.ville) + '</p>' +
            '<p class="mt-1 text-[13px] text-[#6b7780] flex items-center gap-2">' + A.icon('cal') + A.esc(A.range(c.date_debut, c.date_fin)) + '</p>' +
            '<div class="mt-3 flex flex-wrap gap-2">' + tags.map(function (t) { return '<span class="ds-badge">' + A.esc(t) + '</span>'; }).join('') + '</div>' +
            (c.ma_candidature ? '<div class="mt-3">' + A.statut(c.ma_candidature) + '</div>' : '') + '</div></a>';
    }
    async function load(reset) {
        if (reset) { page = 1; $('grid').innerHTML = ''; }
        var p = new URLSearchParams({ page: page, limit: 12 });
        if ($('q').value.trim()) p.set('name', $('q').value.trim());
        if ($('city').value.trim()) p.set('city', $('city').value.trim());
        if ($('from').value) p.set('date_from', $('from').value);
        if ($('to').value) p.set('date_to', $('to').value);
        if ($('remunere').checked) p.set('remunere', '1');
        if ($('sans_bafa').checked) p.set('sans_bafa', '1');
        $('count').textContent = 'Recherche en cours…';
        try {
            var d = await A.api('api/anim_catalogue.php?' + p);
            $('grid').insertAdjacentHTML('beforeend', d.camps.map(card).join(''));
            $('count').textContent = d.total ? d.total + ' séjour' + (d.total > 1 ? 's' : '') + ' qui recrute' + (d.total > 1 ? 'nt' : '') : '';
            if (!d.total && reset) $('grid').innerHTML = '<div class="col-span-full ds-card p-10 text-center"><p class="font-extrabold text-[18px] text-[#26343b]">Aucun séjour ne recrute avec ces critères</p><p class="mt-2 text-[#6b7780]">Élargissez vos dates ou votre zone, de nouveaux séjours sont ajoutés régulièrement.</p></div>';
            $('more').classList.toggle('hidden', !d.has_more);
        } catch (e) { $('count').textContent = ''; $('grid').innerHTML = '<div class="col-span-full ds-alert-err">' + A.esc(e.message) + '</div>'; }
    }
    $('f').addEventListener('submit', function (e) { e.preventDefault(); load(true); });
    ['remunere', 'sans_bafa', 'from', 'to'].forEach(function (i) { $(i).addEventListener('change', function () { load(true); }); });
    $('more-b').addEventListener('click', function () { page++; load(false); });
    load(true);
})();
</script>
<?php require_once 'partials/footer.php'; ?>

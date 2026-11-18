<?php
// animateur.php : accueil du compte animateur : tâches utiles + suggestions de missions classées par correspondance avec le profil et les préférences.
// Données : api/anim_accueil.php. Le catalogue complet (avec filtres) est dans missions.php.
$body_class = 'bg-white';
require_once 'partials/header.php';
if (empty($_SESSION['user']['is_animateur'])) { header('Location: login.php?redirect=' . urlencode('animateur.php')); exit; }
?>
<title>Accueil animateur - moncamp.fr</title>
<main class="ds-page">
    <header>
        <p class="ds-eyebrow">Espace animateur</p>
        <h1 class="ds-h1" id="hello">Bonjour <?= htmlspecialchars((string)($_SESSION['user']['prenom'] ?? '')) ?></h1>
        <p class="ds-lead">Vos prochaines missions d'animation, choisies pour vous.</p>
    </header>

    <section id="taches" class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="À faire"></section>
    <div id="sections" class="mt-10 space-y-12"><div class="ds-card p-10 text-center text-[#6b7780]">Chargement…</div></div>
</main>
<script src="assets/js/anim.js?v=3"></script>
<script>
(function () {
    var A = window.ANIM, E = A.esc, $ = function (i) { return document.getElementById(i); };
    function tache(href, ico, titre, sub, strong) {
        return '<a href="' + href + '" class="ds-tile" style="' + (strong ? 'border-color:#26343b' : '') + '"><span class="ds-tile-ico">' + A.icon(ico, 'w-5 h-5') + '</span><span class="min-w-0"><span class="block font-extrabold text-[15px] text-[#26343b]">' + titre + '</span><span class="block text-[13px] text-[#6b7780]">' + sub + '</span></span></a>';
    }
    function section(titre, sous, list, more) {
        if (!list.length) return '';
        return '<section><div class="flex flex-wrap items-end justify-between gap-2 mb-4"><div><h2 class="font-extrabold text-[22px] text-[#26343b]">' + titre + '</h2><p class="text-[14px] text-[#6b7780]">' + sous + '</p></div>' +
            (more ? '<a href="missions" class="text-[14px] font-semibold text-[#3d6a8e] underline-offset-4 hover:underline">Toutes les missions</a>' : '') + '</div>' +
            '<div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">' + list.map(A.card).join('') + '</div></section>';
    }
    A.api('api/anim_accueil.php').then(function (d) {
        var t = d.taches, p = d.profil, h = '';
        h += tache('profil-animateur', 'user', 'Profil complété à ' + p.pct + ' %', p.manque.length ? E(p.manque[0].label) : 'Votre dossier est complet', p.pct < 100);
        h += tache('espace-animation', 'file', t.candidatures_attente + ' candidature' + (t.candidatures_attente > 1 ? 's' : '') + ' en attente', t.acceptees ? t.acceptees + ' acceptée' + (t.acceptees > 1 ? 's' : '') : 'Suivre mes candidatures', t.candidatures_attente > 0);
        h += tache('messagerie', 'bell', t.messages ? t.messages + ' nouveau' + (t.messages > 1 ? 'x' : '') + ' message' + (t.messages > 1 ? 's' : '') : 'Aucun nouveau message', 'Échanger avec les directeurs', t.messages > 0);
        h += tache('preferences-animateur', 'check', t.prefs_configurees ? 'Mes préférences' : 'Régler mes préférences', t.prefs_configurees ? 'Qualification, zone, disponibilités, alertes' : 'Pour des suggestions vraiment adaptées', !t.prefs_configurees);
        $('taches').innerHTML = h;

        var s = section('Pour vous', 'Les missions qui correspondent le mieux à votre profil et à vos préférences.', d.pour_vous, true) +
            section('Près de chez vous', 'Dans votre département ou votre région.', d.pres, false) +
            section('Dernières missions publiées', 'Les séjours qui viennent de lancer leur recrutement.', d.recents, false) +
            section('Séjours qui paient', 'Missions rémunérées, de la mieux payée à la moins bien payée.', d.paient, false);
        if (!s) {
            s = d.ouverts ? '<div class="ds-card p-10 text-center"><p class="font-extrabold text-[20px] text-[#26343b]">Aucune mission ne correspond à vos critères</p><p class="mt-2 text-[#6b7780]">Élargissez votre zone, vos périodes ou votre rémunération minimale pour voir davantage de séjours.</p><div class="mt-5 flex justify-center gap-3"><a class="ds-btn" href="preferences-animateur">Modifier mes préférences</a><a class="ds-btn ds-btn-light" href="missions">Voir toutes les missions</a></div></div>' :
                '<div class="ds-card p-10 text-center"><p class="font-extrabold text-[20px] text-[#26343b]">Aucun séjour ne recrute pour le moment</p><p class="mt-2 text-[#6b7780]">Complétez votre profil : dès qu’un directeur publie une mission, vous la retrouverez ici. Vous pouvez aussi activer une alerte e-mail.</p><div class="mt-5 flex justify-center gap-3"><a class="ds-btn" href="profil-animateur">Compléter mon profil</a><a class="ds-btn ds-btn-light" href="preferences-animateur">Activer une alerte</a></div></div>';
        }
        $('sections').innerHTML = s;
    }).catch(function (e) { $('sections').innerHTML = '<div class="ds-alert-err">' + E(e.message) + '</div>'; });
})();
</script>
<?php require_once 'partials/footer.php'; ?>

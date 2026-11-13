<?php
// a_propos.php : qui sommes-nous, comment ça marche (page de contenu, publicité autorisée)
$ads_enabled = true;
require_once 'partials/header3.php';
?>
<title>À propos de ColoMap - Colonies de vacances et séjours pour enfants</title>
<meta name="description" content="ColoMap aide les familles à trouver et réserver une colonie de vacances en toute confiance, et les organisateurs à gérer leurs inscriptions.">

<main class="flex-1 w-full max-w-3xl mx-auto px-6 py-14">
    <p class="text-[13px] font-bold uppercase tracking-widest text-[#3d6a8e]">À propos</p>
    <h1 class="mt-2 text-[34px] md:text-[42px] font-extrabold text-[#26343b] leading-tight">Trouver la bonne colonie, sans prise de tête</h1>
    <p class="mt-4 text-[18px] text-[#4b5964] leading-relaxed">ColoMap rassemble les séjours de vacances pour enfants et adolescents (colonies, camps, stages) et met les familles directement en relation avec les organisateurs. Notre idée : que choisir, réserver et suivre un séjour soit aussi simple et rassurant que possible, pour les parents comme pour les équipes qui l'organisent.</p>

    <h2 class="mt-12 text-[24px] font-extrabold text-[#26343b]">Comment ça marche</h2>
    <ol class="mt-5 space-y-5">
        <li class="flex gap-4"><span class="flex-none w-9 h-9 rounded-full bg-[#2f3d45] text-white font-bold flex items-center justify-center">1</span><p class="text-[#3b4a54] leading-relaxed"><b>Je cherche.</b> Par destination, âge, dates, thème ou budget. Chaque fiche présente le programme, le lieu, les tarifs, les conditions et l'organisme.</p></li>
        <li class="flex gap-4"><span class="flex-none w-9 h-9 rounded-full bg-[#2f3d45] text-white font-bold flex items-center justify-center">2</span><p class="text-[#3b4a54] leading-relaxed"><b>Je pose mes questions.</b> La messagerie permet d'échanger directement avec le directeur du séjour avant de s'engager.</p></li>
        <li class="flex gap-4"><span class="flex-none w-9 h-9 rounded-full bg-[#2f3d45] text-white font-bold flex items-center justify-center">3</span><p class="text-[#3b4a54] leading-relaxed"><b>Je réserve et je règle en ligne.</b> La fiche de l'enfant est renseignée une seule fois, le paiement par carte est sécurisé, et un reçu est disponible à tout moment dans « Mes réservations ».</p></li>
    </ol>

    <h2 class="mt-12 text-[24px] font-extrabold text-[#26343b]">Ce que nous garantissons</h2>
    <ul class="mt-5 grid sm:grid-cols-2 gap-4">
        <li class="rounded-2xl bg-[#f8f5ea] p-5"><b class="block text-[#26343b]">Des séjours vérifiés</b><span class="text-[14.5px] text-[#4b5964]">Chaque séjour est examiné avant sa publication et peut être signalé ou retiré en cas de manquement.</span></li>
        <li class="rounded-2xl bg-[#f8f5ea] p-5"><b class="block text-[#26343b]">Des paiements sécurisés</b><span class="text-[14.5px] text-[#4b5964]">Les cartes sont traitées par Stripe ; ColoMap ne conserve aucune donnée bancaire.</span></li>
        <li class="rounded-2xl bg-[#f8f5ea] p-5"><b class="block text-[#26343b]">Des données protégées</b><span class="text-[14.5px] text-[#4b5964]">Les informations de l'enfant ne sont transmises qu'à l'organisateur du séjour réservé. <a class="underline" href="legal#confidentialite">En savoir plus</a>.</span></li>
        <li class="rounded-2xl bg-[#f8f5ea] p-5"><b class="block text-[#26343b]">Une équipe joignable</b><span class="text-[14.5px] text-[#4b5964]">Une question, un problème ? <a class="underline" href="contact">Écrivez-nous</a>, nous répondons.</span></li>
    </ul>

    <?php ad_slot('apropos-1', ADS_SLOTS['article'], ['format' => 'fluid', 'layout' => 'in-article', 'minh' => 280]); ?>

    <h2 class="mt-12 text-[24px] font-extrabold text-[#26343b]">Pour les organisateurs et les animateurs</h2>
    <p class="mt-4 text-[#3b4a54] leading-relaxed">Les organismes publient leurs séjours, définissent leurs tarifs, leurs sessions et leurs propres questions d'inscription, suivent les dossiers des enfants et échangent avec les familles depuis un seul tableau de bord. Les animateurs (BAFA ou non) peuvent consulter les missions ouvertes et se porter candidats. Voir l'<a class="text-[#3d6a8e] underline" href="aide#organisateurs">aide pour les organisateurs</a>.</p>

    <h2 class="mt-12 text-[24px] font-extrabold text-[#26343b]">Financement du site</h2>
    <p class="mt-4 text-[#3b4a54] leading-relaxed">ColoMap est gratuit pour les familles. Le service est financé par la publicité affichée sur les pages d'information, avec votre consentement pour les annonces personnalisées, et par les services proposés aux organisateurs. Les annonces sont toujours signalées comme telles et n'influencent pas le classement des séjours.</p>

    <div class="mt-12 rounded-3xl bg-[#2f3d45] text-white p-8 text-center">
        <p class="text-[22px] font-extrabold">Prêt à trouver le séjour idéal ?</p>
        <a href="recherche" class="mt-5 inline-block bg-white text-[#26343b] font-bold px-8 py-3 rounded-xl hover:bg-[#f1f5f9] transition">Rechercher une colonie</a>
    </div>
</main>
<?php require_once 'partials/footer3.php'; ?>

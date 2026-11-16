<?php
// classement.php : « Comment fonctionne le classement » : la transparence sur l'ordre des séjours, des critères aux séjours sponsorisés.
// Les chiffres affichés sont lus dans le code du moteur (api/search_lib.php, api/marketing_lib.php) : la page ne peut pas dire autre chose que ce que le site fait.
require_once 'api/search_lib.php';

$ads_enabled = false;
$body_class = 'bg-white';
$page_title = 'Comment sont classés les séjours - ColoMap';
require_once 'partials/header.php';

$champs = [
    'kw_camp' => ["Mots-clés du séjour", "Les mots que l'organisme a choisis pour ce séjour (« EPJ »)."],
    'kw_orga' => ["Alias de l'organisme", "Les noms sous lesquels l'organisme est connu (« PAJE »)."],
    'nom' => ["Nom du séjour", ''],
    'nom_orga' => ["Nom de l'organisme", ''],
    'theme' => ["Thème indiqué par l'organisme", "Les anciens thèmes et mots-libres des fiches."],
    'cat' => ["Catégories", "Spirituel, Montagne, Équitation…"],
    'facet' => ["Précisions", "Niveau, confession, discipline… choisies par l'organisme."],
    'ville' => ["Ville et code postal", ''],
    'desc' => ["Description", "Seulement le début du texte."],
];
$criteres = [
    'rel' => ['Correspondance avec votre recherche', "À quel point les mots tapés se retrouvent dans le séjour (voir ci-dessus)."],
    'prox' => ['Départ proche', "Les séjours qui partent bientôt passent un peu devant ; au-delà de quelques mois, tous se valent."],
    'avail' => ['Places disponibles', "Un séjour complet passe très loin derrière : inutile de vous le montrer en premier."],
    'quality' => ['Qualité constatée', "La part des familles qui ouvrent le séjour quand il est affiché, et qui finissent par s'inscrire. Un séjour nouveau part avec une valeur moyenne, jamais zéro."],
    'fresh' => ['Nouveauté', "Un petit coup de pouce pendant les 3 premières semaines."],
    'perso' => ['Votre profil', "Si vous êtes connecté : l'âge de vos enfants, les catégories et organismes de vos favoris, réservations et séjours déjà consultés. Rien n'est utilisé sans compte."],
];
$boosts = mk_boosts();
?>
<link rel="stylesheet" href="/assets/css/colomap-search.css">
<main class="ds-page" style="max-width:860px">
    <p class="ds-eyebrow">Transparence</p>
    <h1 class="ds-h1">Comment sont classés les séjours</h1>
    <p class="ds-lead">Notre objectif : vous montrer d'abord les séjours qui correspondent à ce que vous cherchez, ni plus, ni moins. Voici exactement comment l'ordre est calculé.</p>

    <section class="ds-card ds-sec mt-8">
        <h2 class="ds-sec-title">1. Ce que vous tapez est compris</h2>
        <ul class="mt-3 space-y-2 text-[15px] text-[#3b4a54] list-disc pl-5">
            <li>Les <b>accents, majuscules et pluriels</b> ne comptent pas : « chevaux », « Cheval » et « cheval » sont le même mot.</li>
            <li>Les <b>mots proches</b> sont reconnus : « poney » trouve l'équitation, « neige » le ski.</li>
            <li>Une <b>faute de frappe légère</b> est tolérée (« equitaiton »), et nous vous proposons « Vouliez-vous dire … ? ». Les mots très courts (« ski », « mer ») ne sont pas corrigés.</li>
            <li>Vous pouvez écrire <b>une phrase</b> : « cheval 12 ans moins de 500 € été 2027 ». L'âge, le budget et la période sont reconnus et appliqués comme des filtres ; nous vous disons ce qui a été compris, et vous pouvez demander à ne pas interpréter votre phrase.</li>
            <li>Un <b>nom d'organisme ou un de ses alias</b> (« PAJE ») ramène ses séjours, sans les disperser parmi les autres.</li>
        </ul>
        <h3 class="mt-6 text-[15px] font-extrabold text-[#26343b]">Où vos mots sont cherchés, du plus au moins important</h3>
        <div class="mt-3 space-y-3">
            <?php foreach ($champs as $k => [$nom, $aide]): $w = SQ_FIELD_W[$k]; ?>
                <div><div class="flex justify-between gap-3 text-[14px]"><span class="font-bold text-[#26343b]"><?= htmlspecialchars($nom) ?><?php if ($aide): ?> <span class="font-normal text-[#6b7780]">: <?= htmlspecialchars($aide) ?></span><?php endif; ?></span><span class="text-[#6b7780]"><?= (int)$w ?> / <?= SQ_MAXW ?></span></div>
                    <div class="cl-bar mt-1" role="img" aria-label="Poids : <?= (int)$w ?> sur <?= SQ_MAXW ?>"><span style="width:<?= min(100, round($w / SQ_MAXW * 100)) ?>%"></span></div></div>
            <?php endforeach; ?>
        </div>
        <p class="mt-4 text-[14px] text-[#6b7780]">Pour chaque mot tapé, c'est le <b>meilleur</b> champ qui compte, jamais la somme : répéter un mot dans plusieurs champs, ou en empiler beaucoup, n'apporte rien de plus. Un mot-clé sans rapport avec le séjour vaut moitié moins, et un alias d'organisme ne peut pas être un thème ni le nom d'un autre organisme.</p>
    </section>

    <section class="ds-card ds-sec mt-6">
        <h2 class="ds-sec-title">2. Ce qui fait la note d'un séjour</h2>
        <p class="mt-2 text-[15px] text-[#3b4a54]">Chaque séjour reçoit une note de 0 à 100 pour chacun de ces critères, combinés ainsi quand vous tapez une recherche :</p>
        <div class="mt-4 space-y-4">
            <?php foreach ($criteres as $k => [$nom, $aide]): $pc = round(SQ_W_QUERY[$k] * 100); ?>
                <div><div class="flex justify-between gap-3 text-[14px]"><span class="font-bold text-[#26343b]"><?= htmlspecialchars($nom) ?></span><span class="text-[#6b7780]"><?= $pc ?> %</span></div>
                    <div class="cl-bar mt-1"><span style="width:<?= $pc * 2 ?>%"></span></div>
                    <p class="mt-1 text-[13.5px] text-[#6b7780]"><?= htmlspecialchars($aide) ?></p></div>
            <?php endforeach; ?>
        </div>
        <p class="mt-4 text-[14px] text-[#6b7780]">Sans recherche (en parcourant tout le catalogue), la correspondance n'existe pas : ses <?= round(SQ_W_QUERY['rel'] * 100) ?> % sont répartis sur la qualité, le profil, la proximité, les places et la nouveauté.</p>
        <p class="mt-3 text-[14px] text-[#6b7780]">Quand vous choisissez un tri (prix, date de départ), il est respecté tel quel.</p>
    </section>

    <section class="ds-card ds-sec mt-6">
        <h2 class="ds-sec-title">3. Pas dix séjours du même organisme d'affilée</h2>
        <p class="mt-2 text-[15px] text-[#3b4a54]">Une fois les notes calculées, l'ordre est rééquilibré : chaque séjour déjà placé d'un organisme réduit de <?= round((1 - SQ_DIVERSITE) * 100) ?> % la note de ses séjours suivants. Un organisme très pertinent garde donc plusieurs places en tête, mais ne les occupe pas toutes. Quand vous cherchez un organisme par son nom ou son alias, ses séjours restent groupés.</p>
    </section>

    <section class="ds-card ds-sec mt-6">
        <h2 class="ds-sec-title">4. Les séjours sponsorisés</h2>
        <p class="mt-2 text-[15px] text-[#3b4a54]">Les organismes peuvent acheter de la visibilité. C'est toujours <b>signalé</b> : le séjour porte l'étiquette « Sponsorisé » (ou « Dernière minute »). Voici ce que cela change, et ce que cela ne change pas :</p>
        <ul class="mt-3 space-y-2 text-[15px] text-[#3b4a54] list-disc pl-5">
            <?php foreach ($boosts as $k => $b): ?>
                <li><b><?= htmlspecialchars($b['label']) ?></b> : <?php
                    if ($k === 'vedette') echo 'multiplie la note par 1,55 pendant ' . (int)$b['days'] . ' jours.';
                    elseif ($k === 'urgence') echo 'multiplie la note par 1,20 pendant ' . (int)$b['days'] . ' jours, si le départ a lieu dans les 45 jours.';
                    else echo 'multiplie la note par 1,5 au moment de l\'achat, effet qui s\'estompe en 3 jours environ.';
                ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="mt-3 text-[15px] text-[#3b4a54]">Le boost <b>amplifie la pertinence, il ne la remplace pas</b> : il compte pleinement pour un séjour qui correspond parfaitement à votre recherche, et beaucoup moins pour un séjour peu pertinent. Un séjour sponsorisé qui ne correspond pas à votre recherche n'apparaît jamais. Les filtres (âge, dates, budget, catégories) s'appliquent toujours.</p>
        <p class="mt-3 text-[14px] text-[#6b7780]">Pour qu'aucun séjour ne s'installe en tête pour toujours, un léger tirage (± 3 %) change toutes les 3 heures.</p>
    </section>

    <section class="ds-card ds-sec mt-6">
        <h2 class="ds-sec-title">5. Vos données</h2>
        <p class="mt-2 text-[15px] text-[#3b4a54]">Pour améliorer la recherche, nous gardons un <b>journal anonyme</b> : le mot tapé, le jour, et le nombre de recherches. Jamais d'adresse IP, de compte ni d'identifiant. Les mots qui ressemblent à un e-mail ou à un numéro de téléphone ne sont jamais enregistrés, et un organisme ne voit un terme que s'il a été tapé par au moins <?= (int)SQ_TERME_MIN ?> recherches. Tout est effacé au bout de 400 jours.</p>
    </section>

    <section class="ds-card ds-sec mt-6">
        <h2 class="ds-sec-title">Vous organisez des séjours ?</h2>
        <ul class="mt-3 space-y-2 text-[15px] text-[#3b4a54] list-disc pl-5">
            <li>Choisissez <b>toutes les catégories</b> de votre séjour (jusqu'à <?= (int)CAT_MAX_PAR_SEJOUR ?>), puis répondez aux <b>précisions</b> : ce sont elles que les familles utilisent comme filtres.</li>
            <li>Ajoutez les <b>mots-clés</b> que les familles tapent vraiment, et les <b>alias</b> de votre organisme.</li>
            <li>Soignez la photo et le titre : la part des familles qui ouvrent votre séjour compte pour <?= round(SQ_W_QUERY['quality'] * 100) ?> % de sa note.</li>
            <li>Consultez dans « Marketing » les termes qui amènent des familles vers vos séjours.</li>
        </ul>
    </section>
</main>
<?php require_once 'partials/footer.php'; ?>

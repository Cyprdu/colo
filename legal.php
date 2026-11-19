<?php
// legal.php : informations légales (ancres : #mentions, #cgu, #cgv, #mediation, #confidentialite, #mineurs, #cookies, #accessibilite).
// Les informations de la société viennent de api/societe.php. Pas de publicité sur cette page.
require_once 'api/societe.php';
require_once 'partials/header3.php';

$s = function (string $k): string {
    $v = htmlspecialchars(SOCIETE[$k] ?? '');
    return str_contains($v, 'À COMPLÉTER') ? '<mark class="bg-[#f8f5ea] text-[#5b4a1c] font-semibold px-1 rounded">' . $v . '</mark>' : $v;
};
$mail = fn(string $k) => '<a class="text-[#3d6a8e] underline" href="mailto:' . htmlspecialchars(SOCIETE[$k]) . '">' . htmlspecialchars(SOCIETE[$k]) . '</a>';
$a = fn(string $href, string $txt, bool $ext = false) => '<a class="text-[#3d6a8e] underline" href="' . $href . '"' . ($ext ? ' rel="noopener" target="_blank"' : '') . '>' . $txt . '</a>';
$cnt = 'text-gray-600 leading-relaxed space-y-3';
$h2 = 'text-xl font-bold text-[#26343b] mb-3';
$h3 = 'font-bold text-[#26343b] mt-5';

$traitements = [
    ['Compte et connexion (y compris « Se connecter avec Google »)', 'Nom, prénom, e-mail, mot de passe (stocké haché), téléphone et date de naissance facultatifs, rôle', 'Exécution du contrat (CGU)', 'Tant que le compte existe ; suppression immédiate possible depuis « Mes données »'],
    ['Fiches des enfants et inscriptions aux séjours, transmission à l’organisateur', 'Identité de l’enfant, responsables légaux, coordonnées, autorisations, réponses aux questions de l’organisateur, photo', 'Exécution du contrat conclu par le responsable légal', 'Tant que le compte existe ; photo et documents : voir « Mineurs et santé »'],
    ['Informations et documents de santé de l’enfant', 'Fiche sanitaire, vaccins, allergies, traitements, régime, carnet de santé', 'Consentement explicite du responsable légal (art. 9.2.a RGPD)', '6 mois après la fin du dernier séjour, puis effacement automatique'],
    ['Paiements, remboursements, comptabilité', 'Montants, références de commande et de paiement, statut (jamais le numéro de carte)', 'Exécution du contrat ; obligation légale comptable', '10 ans (art. L123-22 du code de commerce), anonymisés si le compte est supprimé'],
    ['Messagerie entre familles, animateurs et organisateurs', 'Messages, date, participants', 'Exécution du contrat', 'Tant que le compte existe'],
    ['Équipes d’animation et candidatures', 'Profil, diplômes (BAFA…), CV, disponibilités, inscriptions aux équipes', 'Mesures précontractuelles et exécution du contrat', 'Tant que le compte existe ; CV supprimé avec le compte'],
    ['Demandes de contact', 'Nom, e-mail, message', 'Intérêt légitime (répondre aux demandes)', '3 ans au plus après le dernier échange'],
    ['Identification des auteurs de contenus (messages, séjours publiés, demandes)', 'Compte, date, adresse IP', 'Obligation légale (art. 6 LCEN, décret n° 2021-1362)', '12 mois, puis suppression automatique'],
    ['Sécurité et prévention de la fraude', 'Tentatives de connexion (empreinte non réversible), journal des ouvertures de documents de santé', 'Intérêt légitime ; obligation de sécurité (art. 32 RGPD)', '2 jours pour les tentatives, 12 mois pour le journal des accès'],
    ['Statistiques internes sans cookie et clics vers les sites des organismes', 'Compteurs de vues et de recherches ; clic : séjour, date, page d’origine, type d’appareil, adresse IP hachée avec une clé changée chaque jour', 'Intérêt légitime (mesurer l’audience envoyée aux organismes)', '25 mois'],
    ['Publicité Google AdSense', 'Identifiants et cookies publicitaires de Google, pages consultées', 'Consentement pour la publicité personnalisée ; à défaut, annonces non personnalisées sans cookie publicitaire', 'Selon Google ; votre choix est gardé 6 mois'],
    ['Lettre d’information', 'E-mail', 'Consentement (case à cocher dans votre profil)', 'Jusqu’au désabonnement'],
    ['Preuve des consentements', 'Choix, finalité, version du texte, date, adresse IP hachée, référence du choix cookies', 'Obligation légale (art. 7.1 RGPD : démontrer le consentement)', 'Cookies : 25 mois ; autres consentements : tant que le compte existe'],
];
?>
<main class="flex-1 w-full max-w-3xl mx-auto px-5 sm:px-6 py-14">
    <h1 class="text-3xl font-extrabold text-[#26343b] mb-2">Informations légales</h1>
    <p class="text-[#6b7780] mb-6"><?= $s('nom_commercial') ?>, plateforme de mise en relation entre familles, animateurs et organisateurs de séjours. Dernière mise à jour : <?= $s('maj') ?>.</p>
    <?php if (mc_lang() !== 'fr'): ?><p class="mb-6 rounded-xl bg-[#f8f5ea] border border-[#ebe3c8] px-4 py-3 text-[14px] text-[#5b4a1c]">Version anglaise fournie pour information : seule la version française fait foi.</p><?php endif; ?>

    <nav class="mb-12 flex flex-wrap gap-2 text-sm font-semibold" aria-label="Sommaire">
        <?php foreach (['mentions' => 'Mentions légales', 'cgu' => 'CGU', 'cgv' => 'Conditions de vente', 'mediation' => 'Médiation', 'confidentialite' => 'Confidentialité', 'mineurs' => 'Mineurs et santé', 'cookies' => 'Cookies', 'accessibilite' => 'Accessibilité'] as $id => $t): ?>
            <a href="#<?= $id ?>" class="px-4 py-2 rounded-full border border-gray-200 text-[#3b4a54] hover:bg-gray-50"><?= $t ?></a>
        <?php endforeach; ?>
    </nav>

    <section id="mentions" class="mb-12 scroll-mt-24">
        <h2 class="<?= $h2 ?>">Mentions légales</h2>
        <dl class="text-gray-600 leading-relaxed space-y-1">
            <?php foreach (['Éditeur' => $s('raison_sociale') . ' (' . $s('nom_commercial') . ')', 'Forme juridique' => $s('forme_juridique'), 'Capital social' => $s('capital'), 'Siège social' => $s('siege'), 'SIRET' => $s('siret'), 'Immatriculation' => $s('rcs'), 'TVA' => $s('tva'), 'Directeur de la publication' => $s('directeur_publication'), 'Courriel' => $mail('email'), 'Téléphone' => $s('telephone'),
                            'Hébergeur' => $s('hebergeur') . ', ' . $s('hebergeur_adresse') . ', tél. ' . $s('hebergeur_telephone') . ' ; serveurs situés : ' . $s('hebergeur_serveurs')] as $k => $v): ?>
                <div><dt class="inline font-semibold text-[#26343b]"><?= $k ?> : </dt><dd class="inline"><?= $v ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <div class="<?= $cnt ?> mt-4">
            <p><b>Rôle de la plateforme.</b> moncamp.fr est un opérateur de plateforme en ligne (art. L111-7 du code de la consommation) : il met en relation les familles et les animateurs avec des organismes indépendants. moncamp.fr n’organise pas les séjours ; chaque organisateur, professionnel ou association, est seul responsable de son offre, de ses déclarations administratives et de l’encadrement des mineurs. Les critères de classement des séjours sont expliqués sur la page <?= $a('classement', 'Comment sont classés les séjours ?') ?>.</p>
            <p><b>Propriété intellectuelle.</b> La marque, le logo, la charte et les textes de moncamp.fr sont protégés. Les textes, photos et logos des séjours appartiennent à leurs organisateurs, qui garantissent en détenir les droits.</p>
            <p><b>Signaler un contenu illicite.</b> Écrivez à <?= $mail('email') ?> en précisant l’adresse de la page, le contenu en cause et les raisons du signalement. Les contenus manifestement illicites sont retirés sans délai.</p>
        </div>
    </section>

    <section id="cgu" class="mb-12 scroll-mt-24">
        <h2 class="<?= $h2 ?>">Conditions générales d’utilisation</h2>
        <div class="<?= $cnt ?>">
            <p><b>Objet.</b> Les présentes conditions encadrent l’utilisation de moncamp.fr : recherche de séjours (colonies, camps, stages), inscription et paiement en ligne pour les familles, publication et gestion des séjours pour les organisateurs, candidatures et équipes d’animation pour les animateurs. Créer un compte vaut acceptation des CGU ; la version acceptée et sa date sont enregistrées.</p>
            <p><b>Compte.</b> La création d’un compte est gratuite. Un mineur de moins de 15 ans ne peut pas ouvrir de compte : les données d’un enfant sont saisies par son responsable légal. Vous êtes responsable de l’exactitude des informations saisies (notamment celles de l’enfant, de sa santé et de ses responsables légaux) et de la confidentialité de vos identifiants.</p>
            <p><b>Contenus publiés.</b> Les organisateurs garantissent que leurs séjours sont déclarés et encadrés conformément à la réglementation (accueils collectifs de mineurs, code du tourisme le cas échéant) et que les informations publiées (dates, lieux, prix, âges, conditions d’annulation) sont exactes et à jour.</p>
            <p><b>Comportement.</b> Il est interdit d’usurper une identité, de publier un contenu illicite, trompeur ou discriminatoire, de harceler d’autres utilisateurs, de contourner la plateforme pour des paiements initiés sur moncamp.fr ou de perturber le service. La messagerie est réservée aux échanges liés aux séjours.</p>
            <p><b>Suspension.</b> moncamp.fr peut suspendre un séjour ou un compte en cas de manquement, de contenu inexact, de signalement fondé ou de risque pour la sécurité d’un mineur, après avoir informé l’intéressé sauf urgence.</p>
            <p><b>Responsabilité.</b> moncamp.fr s’efforce d’assurer la disponibilité et l’exactitude du service sans pouvoir garantir l’absence d’interruption ou d’erreur. Le déroulement des séjours relève des organisateurs.</p>
            <p><b>Modification.</b> Les CGU peuvent évoluer ; les utilisateurs inscrits sont prévenus des changements importants. La version en vigueur est celle publiée sur cette page.</p>
            <p><b>Droit applicable.</b> Droit français. En cas de litige avec un consommateur, celui-ci peut recourir à la médiation (voir ci-dessous) ou saisir la juridiction compétente.</p>
        </div>
    </section>

    <section id="cgv" class="mb-12 scroll-mt-24">
        <h2 class="<?= $h2 ?>">Conditions de vente et de service</h2>
        <div class="<?= $cnt ?>">
            <h3 class="<?= $h3 ?>">Familles</h3>
            <p><b>Contrat de séjour.</b> En réservant, la famille conclut le contrat de séjour avec l’organisateur indiqué sur la fiche, selon ses conditions particulières (programme, encadrement, annulation). moncamp.fr n’est pas partie à ce contrat ; il fournit le service de réservation et de paiement en ligne.</p>
            <p><b>Prix.</b> Les prix sont indiqués en euros, toutes taxes comprises, avant la validation de l’inscription. La commission de service de moncamp.fr (frais de dossier et de gestion) est indiquée avant le paiement.</p>
            <p><b>Inscription et paiement.</b> L’inscription est ferme lorsque le paiement est confirmé. Le paiement par carte est opéré par Stripe : moncamp.fr ne voit ni ne conserve aucun numéro de carte. Certains organisateurs autorisent une réservation « en attente de paiement » : la place est gardée jusqu’à la date limite indiquée, puis libérée automatiquement faute de règlement. Lorsqu’un séjour accepte d’autres moyens (chèque, espèces, virement, Chèques-Vacances, aides…), la famille règle directement l’organisateur.</p>
            <p><b>Droit de rétractation.</b> Les séjours étant des prestations d’hébergement et de loisirs fournies à une date ou une période déterminée, le droit de rétractation de 14 jours ne s’applique pas (art. L221-28, 12° du code de la consommation). Les séjours qui constituent des forfaits touristiques relèvent en outre du code du tourisme, sous la responsabilité de l’organisateur.</p>
            <p><b>Annulation et remboursement.</b> Les conditions d’annulation sont fixées par chaque organisateur et indiquées sur la fiche du séjour. Vous pouvez annuler vous-même une inscription payée depuis « Mes réservations » : le remboursement est automatique et calculé selon ces conditions. <b>La commission de service de moncamp.fr reste acquise et est déduite du remboursement</b> ; l’organisateur n’est débité que de la part qu’il rembourse.</p>
            <p><b>Réclamations.</b> Contactez d’abord l’organisateur depuis la messagerie, puis moncamp.fr via la page <?= $a('contact', 'Contact') ?>. Sans réponse satisfaisante, vous pouvez saisir gratuitement le médiateur de la consommation.</p>

            <h3 class="<?= $h3 ?>">Rôle de moncamp.fr dans les paiements</h3>
            <p>moncamp.fr n’est pas un établissement de paiement et ne détient jamais les fonds des familles. Les paiements par carte sont encaissés par Stripe (Stripe Payments Europe, Ltd., Dublin, Irlande, établissement agréé par la Banque centrale d’Irlande) pour le compte de l’organisateur, sur son propre compte Stripe (Stripe Connect). Chaque organisateur donne mandat à moncamp.fr d’organiser cet encaissement en son nom ; le paiement de la famille la libère de sa dette envers l’organisateur à hauteur du montant réglé. moncamp.fr agit comme intermédiaire technique et perçoit sa commission au moment du paiement.</p>

            <h3 class="<?= $h3 ?>">Organisateurs</h3>
            <p><b>Compte de paiement.</b> Pour être payé, l’organisateur ouvre un compte Stripe et se soumet à la vérification d’identité de Stripe ; moncamp.fr ne conserve ni ses données bancaires ni ses pièces d’identité. Le paiement de la famille est enregistré directement sur le compte Stripe de l’organisateur, qui reçoit le montant payé diminué de la commission de moncamp.fr et des frais de paiement de Stripe.</p>
            <p><b>Commission de service et frais de paiement.</b> Pour chaque inscription payée par carte, l’organisateur paie deux montants distincts, détaillés dans son tableau de bord : (1) la <b>commission moncamp.fr</b> (pourcentage et part fixe indiqués dans l’espace organisateur), prélevée au moment du paiement ; (2) les <b>frais de paiement de Stripe</b>, au tarif réel de Stripe selon la carte utilisée, débités par Stripe. La commission n’est jamais remboursée, y compris en cas d’annulation ou de remboursement ; les frais de Stripe ne le sont pas non plus.</p>
            <p><b>Paiements hors carte.</b> Dès que l’organisateur confirme avoir reçu un règlement hors carte, moncamp.fr retient une commission de service (1 % du montant par défaut), prélevée sur ses paiements par carte suivants.</p>
            <p><b>Versements.</b> Les sommes disponibles sur le compte Stripe de l’organisateur sont virées chaque jour sur son compte bancaire dès que les fonds sont disponibles, en général 2 à 7 jours ouvrés après le paiement, sans attendre le début du séjour ; l’organisateur peut aussi déclencher un virement immédiat depuis son espace. moncamp.fr peut suspendre un versement en cas de litige, de remboursement en cours, de suspicion de fraude ou de séjour annulé. Les remboursements accordés aux familles sont pris sur le solde Stripe de l’organisateur ; s’il ne suffit pas, ce solde devient négatif : il est compensé par les paiements suivants, puis prélevé sur le compte bancaire de l’organisateur. Les sommes dues à moncamp.fr (commission sur les règlements hors carte, frais d’anciens paiements) sont retenues avec la commission sur ses paiements suivants (jamais plus que leur montant), prélevées par SEPA s’il a signé un mandat et qu’elles restent impayées 15 jours, et visibles dans son tableau de bord. Les paiements enregistrés avant la mise en place de ce fonctionnement restent versés selon les règles en vigueur à leur date.</p>
            <p><b>Données des familles.</b> L’organisateur reçoit les données des enfants inscrits à ses séjours en tant que responsable de traitement pour l’organisation du séjour. Il s’engage à ne les utiliser que pour ce séjour, à en limiter l’accès à son équipe d’encadrement et à respecter le RGPD.</p>
        </div>
    </section>

    <section id="mediation" class="mb-12 scroll-mt-24">
        <h2 class="<?= $h2 ?>">Médiation de la consommation</h2>
        <div class="<?= $cnt ?>">
            <p>Conformément aux articles L611-1 et suivants du code de la consommation, tout consommateur peut recourir gratuitement à un médiateur de la consommation pour résoudre à l’amiable un litige avec moncamp.fr. Il doit d’abord avoir adressé une réclamation écrite à moncamp.fr (page Contact ou courriel) et saisir le médiateur au plus tard un an après cette réclamation.</p>
            <p><b>Médiateur :</b> <?= $s('mediateur') ?> — <?= $s('mediateur_adresse') ?> — <?= $s('mediateur_site') ?>.</p>
            <p>Pour un litige portant sur le déroulement d’un séjour, adressez-vous au médiateur désigné par l’organisateur dans ses propres conditions.</p>
        </div>
    </section>

    <section id="confidentialite" class="mb-12 scroll-mt-24">
        <h2 class="<?= $h2 ?>">Politique de confidentialité</h2>
        <div class="<?= $cnt ?>">
            <p><b>Responsable du traitement.</b> <?= $s('raison_sociale') ?> (<?= $s('nom_commercial') ?>), <?= $s('siege') ?>. Contact pour vos données personnelles : <?= $mail('email_rgpd') ?>. Pour les données transmises à un organisateur, celui-ci est responsable de son propre traitement (organisation du séjour).</p>
            <p><b>Traitements, bases légales et durées de conservation.</b></p>
        </div>
        <ul class="mt-3 space-y-3">
            <?php foreach ($traitements as [$fin, $donnees, $base, $duree]): ?>
            <li class="rounded-xl border border-gray-200 p-4 text-[14px] leading-relaxed text-gray-600">
                <p class="font-bold text-[#26343b]"><?= $fin ?></p>
                <p class="mt-1"><span class="font-semibold text-[#26343b]">Données :</span> <?= $donnees ?></p>
                <p><span class="font-semibold text-[#26343b]">Base légale :</span> <?= $base ?></p>
                <p><span class="font-semibold text-[#26343b]">Conservation :</span> <?= $duree ?></p>
            </li>
            <?php endforeach; ?>
        </ul>
        <div class="<?= $cnt ?> mt-5">
            <p><b>Destinataires.</b> Les organisateurs des séjours réservés (et, pour une candidature, l’organisme concerné) ; nos sous-traitants, chacun pour sa mission : Hostinger (hébergement), Stripe (paiement), Google (connexion Google si vous la choisissez, envoi des e-mails, polices de caractères, publicité AdSense), Cloudflare (protection anti-robot de certains formulaires, fichiers techniques), jsDelivr et Tailwind (fichiers techniques de l’interface), OpenStreetMap (cartes). Les autorités judiciaires ou administratives sur réquisition. Aucune donnée n’est vendue.</p>
            <p><b>Transferts hors de l’Union européenne.</b> Google, Stripe et Cloudflare peuvent traiter des données aux États-Unis. Ces transferts sont encadrés par la décision d’adéquation de la Commission européenne du 10 juillet 2023 (Data Privacy Framework), à laquelle ces sociétés ont adhéré, et par les clauses contractuelles types de la Commission.</p>
            <p><b>Sécurité.</b> Connexion chiffrée (HTTPS), mots de passe hachés, documents de santé chiffrés au repos, accès des équipes de moncamp.fr limités par rôle et journalisés, purge automatique des données arrivées à échéance.</p>
            <p><b>Vos droits.</b> Accès, rectification, effacement, limitation, opposition, portabilité, retrait du consentement à tout moment, et directives sur le sort de vos données après votre décès. Vous pouvez <b>télécharger vos données et supprimer votre compte vous-même</b> depuis la page <?= $a('mes_donnees', 'Mes données') ?>, ou écrire à <?= $mail('email_rgpd') ?> : réponse sous un mois. Vous pouvez introduire une réclamation auprès de la CNIL (<?= $a('https://www.cnil.fr/fr/plaintes', 'cnil.fr', true) ?>).</p>
            <p><b>Liens vers les sites des organismes.</b> Quand un séjour se réserve sur le site de son organisme, le bouton « Réserver ma place » passe par une adresse moncamp.fr (/go/…) qui enregistre le clic (voir le tableau) avant de vous rediriger. L’adresse de destination reçoit les paramètres utm_source=moncamp.fr, utm_medium=referral, utm_campaign (identifiant du séjour) et mc_click (identifiant aléatoire du clic, sans lien avec votre compte) ; ce que l’organisme collecte ensuite relève de sa propre politique. Vous pouvez vous y opposer en écrivant à <?= $mail('email_rgpd') ?> ou en ouvrant directement le site de l’organisme.</p>
        </div>
    </section>

    <section id="mineurs" class="mb-12 scroll-mt-24">
        <h2 class="<?= $h2 ?>">Données des mineurs et données de santé</h2>
        <div class="<?= $cnt ?>">
            <p><b>Qui saisit les données.</b> Les données d’un enfant sont saisies par son responsable légal, qui décide de leur transmission en l’inscrivant à un séjour. Elles ne servent jamais à de la publicité ni à du profilage.</p>
            <p><b>Base légale.</b> L’inscription et la transmission de la fiche à l’organisateur reposent sur le contrat conclu par le responsable légal. Les informations de santé (fiche sanitaire de liaison, vaccins, allergies, traitements, carnet de santé), nécessaires à la sécurité de l’enfant pendant le séjour, ne sont enregistrées qu’avec le <b>consentement explicite</b> du responsable légal (art. 9.2.a RGPD), journalisé avec sa date et la version du texte ; il peut être retiré à tout moment depuis la fiche de l’enfant, ce qui efface ces informations.</p>
            <p><b>Protection.</b> Les documents (fiche sanitaire, carnet de santé, signature, photo de l’enfant) sont stockés sous un nom aléatoire, <b>chiffrés au repos (AES-256)</b> et hors de tout accès direct. Seuls peuvent les ouvrir : le responsable légal, le directeur d’un séjour auquel l’enfant est inscrit et les membres habilités de moncamp.fr. <b>Chaque ouverture par un tiers est enregistrée</b> (qui, quand, quel document), journal conservé 12 mois.</p>
            <p><b>Durée.</b> Informations et documents de santé : <b>effacés automatiquement 6 mois après la fin du dernier séjour de l’enfant</b> (ou 6 mois après la création de la fiche sans inscription). Le reste de la fiche est conservé tant que le compte du parent existe.</p>
        </div>
    </section>

    <section id="cookies" class="mb-12 scroll-mt-24">
        <h2 class="<?= $h2 ?>">Politique cookies</h2>
        <div class="<?= $cnt ?>">
            <p>Un cookie est un petit fichier déposé sur votre appareil. Les cookies nécessaires sont déposés d’office ; les autres seulement avec votre accord, demandé par le bandeau de consentement. Refuser n’empêche pas d’utiliser le site. Votre choix est gardé <b>6 mois</b>, puis redemandé ; il est modifiable à tout moment : <button type="button" data-cookies class="text-[#3d6a8e] underline font-semibold">Gérer les cookies</button>.</p>
            <p><b>Nécessaires (sans consentement).</b> PHPSESSID : session (connexion, panier d’inscription, sécurité des formulaires), effacé à la fermeture du navigateur ou à la déconnexion ; remember_me : « se souvenir de moi » si vous le cochez, 30 jours ; mc_consent : mémorisation de vos choix cookies, 6 mois ; mc_lang : langue choisie (français ou anglais), 1 an ; Stripe : cookies de sécurité et de lutte contre la fraude, uniquement sur les pages de paiement ; Cloudflare : vérification anti-robot de certains formulaires.</p>
            <p><b>Mesure d’audience (avec consentement).</b> Aucun outil tiers de mesure d’audience n’est utilisé à ce jour ; les statistiques internes de moncamp.fr (vues, recherches, clics) fonctionnent sans cookie. Si un tel outil est ajouté, il ne sera activé qu’avec votre accord.</p>
            <p><b>Publicité (avec consentement).</b> Sur les pages de contenu (accueil, recherche, fiches séjours, aide, à propos), moncamp.fr affiche des annonces Google AdSense. Le script de Google n’est chargé qu’après votre choix. Si vous acceptez, Google et ses partenaires peuvent utiliser des cookies pour personnaliser et mesurer les annonces. Si vous refusez, seules des annonces non personnalisées sont demandées et Google est informé de votre refus (mode de consentement Google) : il s’engage alors à ne pas lire ni déposer de cookie publicitaire. Plus d’informations : <?= $a('https://policies.google.com/technologies/ads', 'règles de Google sur la publicité', true) ?>.</p>
            <p><b>Preuve.</b> Chaque choix est enregistré (finalité, date, version du texte, adresse IP hachée et référence aléatoire gardée dans le cookie mc_consent) pendant 25 mois.</p>
        </div>
    </section>

    <section id="accessibilite" class="mb-6 scroll-mt-24">
        <h2 class="<?= $h2 ?>">Accessibilité</h2>
        <div class="<?= $cnt ?>">
            <p><b>État de conformité.</b> Le site moncamp.fr est en <b>conformité partielle</b> avec le référentiel général d’amélioration de l’accessibilité (RGAA 4.1.2). Il n’a pas encore fait l’objet d’un audit complet ; les points d’attention connus sont les cartes interactives, certains contenus publiés par les organisateurs (images sans description) et les documents PDF.</p>
            <p><b>Ce qui est fait.</b> Navigation au clavier, contrastes suffisants, textes alternatifs, structure de titres, respect de la préférence « réduire les animations », affichage adapté aux téléphones.</p>
            <p><b>Retour et contact.</b> Une difficulté d’accès à un contenu ou une fonctionnalité ? Écrivez à <?= $mail('email') ?> ou via la page <?= $a('contact', 'Contact') ?> : nous vous répondrons avec une alternative accessible.</p>
            <p><b>Voie de recours.</b> Sans réponse satisfaisante, vous pouvez saisir le Défenseur des droits (<?= $a('https://www.defenseurdesdroits.fr', 'defenseurdesdroits.fr', true) ?>).</p>
        </div>
    </section>
</main>
<?php require_once 'partials/footer3.php'; ?>

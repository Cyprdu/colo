<?php
// legal.php : mentions légales, CGU, confidentialité, cookies, accessibilité (ancres : #mentions, #cgu, #confidentialite, #cookies, #accessibilite)
// Pas de publicité sur cette page (page purement informative).
require_once 'partials/header3.php';

// À COMPLÉTER par l'éditeur : seules les lignes renseignées sont affichées dans les mentions légales.
$editeur = [
    'Éditeur du site'            => 'ColoMap',
    'Responsable de publication' => '',   // ex. : Prénom Nom
    'Statut / forme juridique'   => '',   // ex. : Association loi 1901, SAS, micro-entreprise…
    'SIRET'                      => '',
    'Adresse'                    => '',
    'Courriel'                   => 'auth@moncamp.fr',
];
$maj = '13 novembre 2026';
$cnt = 'text-gray-600 leading-relaxed';
?>
<main class="flex-1 w-full max-w-3xl mx-auto px-6 py-14">
    <h1 class="text-3xl font-extrabold text-[#26343b] mb-2">Informations légales</h1>
    <p class="text-[#6b7780] mb-6">ColoMap, plateforme de mise en relation entre familles et organisateurs de séjours. Dernière mise à jour : <?= $maj ?>.</p>

    <nav class="mb-12 flex flex-wrap gap-2 text-sm font-semibold" aria-label="Sommaire">
        <?php foreach (['mentions' => 'Mentions légales', 'cgu' => 'CGU', 'confidentialite' => 'Confidentialité', 'cookies' => 'Cookies', 'accessibilite' => 'Accessibilité'] as $id => $t): ?>
            <a href="#<?= $id ?>" class="px-4 py-2 rounded-full border border-gray-200 text-[#3b4a54] hover:bg-gray-50"><?= $t ?></a>
        <?php endforeach; ?>
    </nav>

    <section id="mentions" class="mb-12 scroll-mt-24">
        <h2 class="text-xl font-bold text-[#26343b] mb-3">Mentions légales</h2>
        <dl class="<?= $cnt ?> space-y-1">
            <?php foreach ($editeur as $k => $v): if ($v === '') continue; ?>
                <div><dt class="inline font-semibold text-[#26343b]"><?= htmlspecialchars($k) ?> : </dt><dd class="inline"><?= $k === 'Courriel' ? '<a class="text-[#3d6a8e] underline" href="mailto:' . htmlspecialchars($v) . '">' . htmlspecialchars($v) . '</a>' : htmlspecialchars($v) ?></dd></div>
            <?php endforeach; ?>
            <div><dt class="inline font-semibold text-[#26343b]">Hébergeur : </dt><dd class="inline">Hostinger International Ltd., 61 Lordou Vironos Street, 6023 Larnaca, Chypre.</dd></div>
        </dl>
        <p class="<?= $cnt ?> mt-3">Les séjours sont proposés et organisés par des organismes indépendants. ColoMap n'est pas l'organisateur des séjours : chaque organisateur reste responsable de son offre, de ses déclarations et de l'encadrement des mineurs.</p>
    </section>

    <section id="cgu" class="mb-12 scroll-mt-24">
        <h2 class="text-xl font-bold text-[#26343b] mb-3">Conditions générales d'utilisation</h2>
        <div class="<?= $cnt ?> space-y-3">
            <p><b>Objet.</b> ColoMap permet aux familles de rechercher des séjours (colonies, camps, stages), d'inscrire un enfant et de régler en ligne, aux organisateurs de présenter et gérer leurs séjours, et aux animateurs de trouver des missions.</p>
            <p><b>Compte.</b> La création d'un compte est gratuite. Vous êtes responsable de l'exactitude des informations saisies (notamment celles concernant l'enfant, sa santé et ses responsables légaux) et de la confidentialité de vos identifiants. Les informations de l'enfant sont transmises à l'organisateur des séjours auxquels il est inscrit.</p>
            <p><b>Inscription et paiement.</b> L'inscription est ferme lorsque le paiement est confirmé. Le paiement par carte est opéré par Stripe : ColoMap ne conserve aucun numéro de carte. Certains organisateurs autorisent une réservation « en attente de paiement » : la place est alors gardée jusqu'à la date limite indiquée sur la réservation, puis libérée automatiquement si le règlement n'a pas eu lieu.</p>
            <p><b>Annulation et remboursement.</b> Les conditions d'annulation et de remboursement sont fixées par chaque organisateur et indiquées sur la fiche du séjour. Vous pouvez annuler vous-même une inscription payée depuis « Mes réservations » : le remboursement est automatique et calculé selon ces conditions. <b>La commission de service de ColoMap (frais de dossier et de gestion, indiquée avant le paiement) reste acquise et est déduite du remboursement</b> ; l'organisateur n'est débité que de la part qu'il rembourse. En cas de difficulté, contactez d'abord l'organisateur depuis la messagerie ; vous pouvez ensuite nous écrire via la page <a class="text-[#3d6a8e] underline" href="contact">Contact</a>.</p>
            <p><b>Encaissement pour le compte des organisateurs (mandat de paiement).</b> En réservant, la famille règle ColoMap, qui encaisse le prix du séjour pour le compte de l'organisateur et le lui reverse après déduction de sa commission de service. Chaque organisateur donne mandat à ColoMap d'encaisser ces sommes en son nom ; le paiement de la famille libère celle-ci de sa dette envers l'organisateur à hauteur du montant réglé. L'encaissement et les versements sont opérés par Stripe (Stripe Connect), établissement de paiement agréé. L'organisateur ouvre pour cela un compte Stripe et se soumet à la vérification d'identité (KYC) de Stripe, condition pour être payé.</p>
            <p><b>Versement aux organisateurs.</b> Les sommes sont reversées automatiquement, nettes de commission, à partir du jour suivant le début du séjour. ColoMap peut suspendre un versement en cas de litige, de remboursement en cours, de suspicion de fraude ou de séjour annulé. Les remboursements accordés aux familles sont déduits des versements de l'organisateur, y compris sur ses séjours suivants si le versement a déjà eu lieu.</p>
            <p><b>Organisateurs.</b> Ils garantissent que leurs séjours sont déclarés et encadrés conformément à la réglementation applicable, et que les informations publiées (dates, lieux, prix, âges, conditions) sont exactes. ColoMap peut suspendre un séjour ou un compte en cas de manquement, de contenu inexact ou de signalement fondé.</p>
            <p><b>Comportement.</b> Il est interdit d'utiliser le site pour usurper une identité, publier un contenu illicite, harceler d'autres utilisateurs ou perturber le service. La messagerie est réservée aux échanges liés aux séjours.</p>
            <p><b>Responsabilité.</b> ColoMap s'efforce d'assurer la disponibilité et l'exactitude du service sans pouvoir garantir l'absence d'interruption ou d'erreur. Notre responsabilité ne peut être engagée pour le déroulement des séjours, qui relève des organisateurs.</p>
            <p><b>Droit applicable.</b> Le droit français s'applique. Les consommateurs peuvent recourir gratuitement à un médiateur de la consommation après une réclamation écrite restée sans réponse satisfaisante.</p>
        </div>
    </section>

    <section id="confidentialite" class="mb-12 scroll-mt-24">
        <h2 class="text-xl font-bold text-[#26343b] mb-3">Politique de confidentialité</h2>
        <div class="<?= $cnt ?> space-y-3">
            <p><b>Responsable du traitement.</b> ColoMap, joignable à <a class="text-[#3d6a8e] underline" href="mailto:auth@moncamp.fr">auth@moncamp.fr</a>.</p>
            <p><b>Données collectées.</b> Compte (nom, prénom, e-mail, date de naissance, rôle) ; fiche enfant (identité, coordonnées, informations de santé et d'autorisation, documents que vous téléversez) ; inscriptions et paiements (référence de commande, montant, statut : les données bancaires sont traitées uniquement par Stripe) ; messages échangés avec les organisateurs ; données techniques de connexion ; pour chaque message, demande de contact ou séjour publié ou modifié, l'identifiant du compte, la date et l'<b>adresse IP</b> de l'auteur.</p>
            <p><b>Finalités et bases légales.</b> Exécution du contrat (gérer votre compte, vos inscriptions et paiements, transmettre la fiche de l'enfant à l'organisateur concerné) ; obligation légale (conservation pendant 1 an des données d'identification de l'auteur d'un contenu mis en ligne, art. 6 de la loi pour la confiance dans l'économie numérique) ; intérêt légitime (sécurité, prévention de la fraude, amélioration du service) ; consentement (publicité personnalisée, newsletter). Les données de santé ne sont communiquées qu'à l'organisateur du séjour auquel l'enfant est inscrit, pour la sécurité de l'enfant.</p>
            <p><b>Destinataires.</b> Organisateurs des séjours réservés ; prestataires techniques (hébergement Hostinger, paiement Stripe, connexion Google si vous la choisissez, publicité Google AdSense avec votre consentement). Aucune vente de données personnelles.</p>
            <p><b>Durée de conservation.</b> Données de compte : tant que le compte est actif, puis jusqu'à 3 ans après le dernier contact. <b>Informations et documents de santé d'un enfant : effacés automatiquement 6 mois après la fin de son dernier séjour</b> (ou 6 mois après la création de la fiche sans inscription). Pièces comptables : conservées 10 ans, sous forme anonymisée si le compte est supprimé. Journaux de sécurité et adresses IP associées aux messages : 12 mois au plus, puis supprimés automatiquement ; ils ne sont communiqués qu'aux autorités judiciaires sur réquisition.</p>
            <p><b>Vos droits.</b> Vous pouvez <b>télécharger vos données et supprimer votre compte vous-même</b> depuis la page <a class="text-[#3d6a8e] underline" href="mes_donnees">Mes données</a>. Vos droits : accès, rectification, effacement, limitation, opposition, portabilité, et retrait du consentement à tout moment : écrivez à <a class="text-[#3d6a8e] underline" href="mailto:auth@moncamp.fr">auth@moncamp.fr</a>. Vous pouvez saisir la CNIL (<a class="text-[#3d6a8e] underline" href="https://www.cnil.fr" rel="noopener" target="_blank">cnil.fr</a>) en cas de désaccord.</p>
            <p><b>Mineurs.</b> Le site s'adresse aux parents et responsables légaux ; les données d'un enfant sont saisies par son responsable légal.</p>
        </div>
    </section>

    <section id="cookies" class="mb-12 scroll-mt-24">
        <h2 class="text-xl font-bold text-[#26343b] mb-3">Cookies</h2>
        <div class="<?= $cnt ?> space-y-3">
            <p><b>Cookies nécessaires.</b> Cookie de session (maintien de la connexion, panier d'inscription), cookie « se souvenir de moi » si vous le cochez, et mémorisation de vos préférences. Ils ne nécessitent pas de consentement.</p>
            <p><b>Publicité.</b> Sur les pages de contenu, ColoMap affiche des annonces Google AdSense. Google et ses partenaires peuvent utiliser des cookies pour mesurer et personnaliser les annonces, <b>uniquement si vous l'acceptez</b> dans le bandeau de consentement. Vous pouvez modifier ou retirer votre choix à tout moment depuis le lien « Paramètres de confidentialité » (ou en effaçant les cookies de votre navigateur). Plus d'informations : <a class="text-[#3d6a8e] underline" href="https://policies.google.com/technologies/ads" rel="noopener" target="_blank">règles de Google sur la publicité</a>.</p>
            <p><b>Paiement.</b> Stripe peut déposer des cookies de sécurité lors du paiement.</p>
        </div>
    </section>

    <section id="accessibilite" class="mb-6 scroll-mt-24">
        <h2 class="text-xl font-bold text-[#26343b] mb-3">Accessibilité</h2>
        <div class="<?= $cnt ?> space-y-3">
            <p>ColoMap s'efforce de rendre son site utilisable par tous : navigation au clavier, contrastes suffisants, textes alternatifs, respect de la préférence « réduire les animations ». Le site n'a pas encore fait l'objet d'un audit de conformité complet au RGAA.</p>
            <p>Une difficulté d'accès à un contenu ou une fonctionnalité ? Écrivez-nous via la page <a class="text-[#3d6a8e] underline" href="contact">Contact</a>, nous vous répondrons avec une alternative.</p>
        </div>
    </section>
</main>
<?php require_once 'partials/footer3.php'; ?>

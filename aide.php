<?php
// aide.php : centre d'aide (FAQ par profil, avec recherche). Les ancres #parents, #organisateurs, #animateurs et
// #paiements sont utilisées par le pied de page et par d'autres pages : ne pas les renommer.
$FAQ = json_decode(<<<'JSON_END'
[
  {
    "id": "parents",
    "icon": "fa-people-roof",
    "chip": "Parents",
    "title": "Questions Fréquentes des Parents",
    "qa": [
      {
        "q": "Comment inscrire mon enfant à un camp ?",
        "a": "L'inscription sur ColoMap est conçue pour être simple et rapide. Commencez par créer un compte parent gratuitement. Une fois connecté, vous pouvez ajouter le profil de vos enfants (nom, prénom, âge, allergies éventuelles). Ensuite, naviguez à travers notre catalogue de séjours en utilisant les filtres (âge, thème, lieu). Une fois le camp idéal trouvé, cliquez sur \"Réserver\". Vous devrez alors sélectionner l'enfant concerné et procéder au paiement de l'acompte ou de la totalité du séjour selon les conditions de l'organisateur. Un email de confirmation vous sera envoyé immédiatement avec le dossier d'inscription complet."
      },
      {
        "q": "Les colonies sont-elles vérifiées ?",
        "a": "Absolument. La sécurité est notre priorité numéro un. Chaque organisateur souhaitant publier sur ColoMap doit passer par un processus de validation strict. Nous vérifions leur numéro d'agrément Jeunesse et Sports, leur assurance responsabilité civile professionnelle ainsi que leur projet éducatif. De plus, nous encourageons vivement les parents à laisser des avis après chaque séjour pour maintenir un niveau de transparence total au sein de la communauté."
      },
      {
        "q": "Que faire si mon enfant a un problème de santé ?",
        "a": "Lors de l'inscription, une fiche sanitaire de liaison est obligatoire. Vous devez y renseigner toutes les informations médicales importantes : allergies, traitements en cours, régimes alimentaires spécifiques, etc. Ces informations sont transmises de manière sécurisée et confidentielle au directeur du séjour et à l'assistant sanitaire présent sur place. Si votre enfant tombe malade durant le séjour, l'équipe encadrante vous contactera immédiatement pour vous tenir informé et prendre les décisions nécessaires en accord avec un médecin."
      }
    ]
  },
  {
    "id": "organisateurs",
    "icon": "fa-building-user",
    "chip": "Organisateurs",
    "title": "Espace Organisateurs",
    "qa": [
      {
        "q": "Comment référencer mes séjours sur ColoMap ?",
        "a": "Pour devenir partenaire, créez un compte \"Organisateur\". Vous devrez fournir les justificatifs légaux de votre structure (association, entreprise, collectivité). Une fois votre compte validé par notre équipe (sous 24 à 48h), vous aurez accès à un tableau de bord complet. De là, vous pourrez créer des fiches détaillées pour chacun de vos séjours : description, photos, dates, tarifs, âges requis, et nombre de places disponibles."
      },
      {
        "q": "Quels sont les coûts pour les organisateurs ?",
        "a": "L'inscription et la publication des annonces sont entièrement gratuites. ColoMap se rémunère uniquement via une commission de service prélevée lors d'une réservation confirmée. Ce modèle \"gagnant-gagnant\" vous permet de bénéficier d'une visibilité nationale sans risque financier initial. Aucun frais caché, tout est transparent dès la création de votre compte."
      },
      {
        "q": "Comment gérer les inscriptions et les paiements ?",
        "a": "Tout est centralisé sur votre tableau de bord. Vous recevez une notification à chaque nouvelle inscription. Vous pouvez voir la liste des inscrits, télécharger leurs fiches sanitaires et suivre l'état des paiements en temps réel. Les fonds versés par les parents sont sécurisés via notre partenaire bancaire Stripe et vous sont reversés automatiquement selon l'échéancier défini (généralement une partie à la réservation et le solde avant le début du séjour)."
      }
    ]
  },
  {
    "id": "animateurs",
    "icon": "fa-bullhorn",
    "chip": "Animateurs",
    "title": "Espace Animation / Recrutement",
    "qa": [
      {
        "q": "Je cherche un poste d'animateur, comment faire ?",
        "a": "ColoMap dispose d'une section dédiée à l'emploi. Créez votre profil \"Animateur\", renseignez vos qualifications (BAFA, BAFD, PSC1, SB...), vos expériences passées et vos disponibilités. Vous pourrez alors postuler directement aux offres publiées par les organisateurs ou rendre votre CV visible dans la CVthèque consultée par les directeurs de séjours."
      },
      {
        "q": "Faut-il obligatoirement le BAFA ?",
        "a": "Bien que le BAFA (Brevet d'Aptitude aux Fonctions d'Animateur) soit le diplôme de référence et le plus demandé, certains séjours acceptent des animateurs stagiaires (en cours de formation) ou non diplômés dans la limite des quotas légaux imposés par la Jeunesse et Sports. N'hésitez pas à filtrer les offres d'emploi selon votre niveau de qualification."
      }
    ]
  },
  {
    "id": "paiements",
    "icon": "fa-lock",
    "chip": "Paiements & sécurité",
    "title": "Paiements & Sécurité",
    "qa": [
      {
        "q": "Le paiement en ligne est-il sécurisé ?",
        "a": "Oui, totalement. ColoMap utilise la technologie <strong>Stripe</strong>, leader mondial des paiements en ligne, utilisé par des entreprises comme Amazon ou Booking. Vos informations bancaires sont cryptées (protocole SSL/TLS) et ne sont jamais stockées sur nos serveurs. Nous prenons également en charge l'authentification forte (3D Secure) pour éviter toute fraude."
      },
      {
        "q": "Acceptez-vous les Chèques Vacances (ANCV) ou les aides CAF ?",
        "a": "Cela dépend de chaque organisateur. Sur la fiche de chaque séjour, une section \"Moyens de paiement acceptés\" vous indique si l'organisme est habilité à recevoir les Chèques Vacances (papier ou Connect) ou s'il est conventionné VACAF. Si c'est le cas, la procédure spécifique vous sera indiquée lors de la réservation (généralement : paiement d'un acompte en ligne, puis envoi des chèques ou attestation CAF pour le solde)."
      },
      {
        "q": "Quelle est la politique d'annulation ?",
        "a": "Chaque organisateur définit ses propres conditions générales de vente (CGV). Elles sont consultables avant toute validation de commande. En général, plus l'annulation est proche de la date de départ, moins le remboursement est important. ColoMap propose également une option \"Assurance Annulation\" lors du paiement pour vous couvrir en cas d'imprévu majeur (maladie, accident, etc.)."
      }
    ]
  }
]
JSON_END
, true);

$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Centre d'aide - ColoMap</title>
<style>
    .faq-item { border: 1.5px solid #e5e7eb; border-radius: 16px; background: #fff; transition: border-color .15s ease, box-shadow .2s ease; }
    .faq-item[open] { border-color: #a9b8c6; box-shadow: 0 8px 24px rgba(38, 52, 59, .07); }
    .faq-item summary { list-style: none; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px 20px; font-weight: 700; color: #26343b; font-size: 16px; }
    .faq-item summary::-webkit-details-marker { display: none; }
    .faq-item summary:hover { color: #3d6a8e; }
    .faq-item .chev { flex: none; width: 28px; height: 28px; border-radius: 9999px; background: #f1f3f5; display: flex; align-items: center; justify-content: center; transition: transform .2s ease, background .2s ease; color: #3b4a54; font-size: 12px; }
    .faq-item[open] .chev { transform: rotate(180deg); background: #2f3d45; color: #fff; }
    .faq-answer { padding: 0 20px 20px; color: #3b4a54; line-height: 1.7; font-size: 15.5px; }
    mark { background: #f3e9b8; color: inherit; border-radius: 3px; padding: 0 2px; }
    html { scroll-behavior: smooth; }
</style>

<main class="ds-page">
    <div class="text-center max-w-2xl mx-auto">
        <h1 class="ds-h1">Centre d'aide</h1>
        <p class="ds-lead">Tout ce qu'il faut savoir sur ColoMap, que vous soyez parent, organisateur ou animateur.</p>
        <div class="relative mt-8">
            <svg class="absolute left-4 top-1/2 -translate-y-1/2 text-[#9aa3ab]" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2.2"/><path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
            <input id="faq-search" type="search" placeholder="Rechercher une question…" autocomplete="off" class="ds-input" style="padding-left:44px;height:54px;border-radius:16px;font-size:16px" aria-label="Rechercher dans l'aide">
        </div>
    </div>

    <nav class="mt-8 flex flex-wrap justify-center gap-3" aria-label="Rubriques">
        <?php foreach ($FAQ as $sec): ?>
            <a href="#<?= $sec['id'] ?>" class="ds-chip"><i class="fa-solid <?= $sec['icon'] ?>"></i> <?= htmlspecialchars($sec['chip']) ?></a>
        <?php endforeach; ?>
    </nav>

    <p id="faq-empty" class="hidden mt-12 text-center text-[#6b7780]">Aucune question ne correspond à votre recherche. <a href="contact" class="underline font-semibold text-[#3d6a8e]">Écrivez-nous</a>.</p>

    <?php foreach ($FAQ as $i => $sec): ?>
    <section id="<?= $sec['id'] ?>" class="mt-14 scroll-mt-28" data-section>
        <div class="flex items-center gap-4 mb-5">
            <span class="w-12 h-12 flex-none rounded-2xl bg-[#2f3d45] text-white flex items-center justify-center text-[20px]"><i class="fa-solid <?= $sec['icon'] ?>"></i></span>
            <h2 class="text-[22px] md:text-[26px] font-extrabold text-[#26343b]"><?= htmlspecialchars($sec['title']) ?></h2>
        </div>
        <div class="space-y-3">
            <?php foreach ($sec['qa'] as $qa): ?>
                <details class="faq-item" data-faq>
                    <summary><span data-q><?= htmlspecialchars($qa['q']) ?></span><span class="chev"><i class="fa-solid fa-chevron-down"></i></span></summary>
                    <div class="faq-answer" data-a><?= $qa['a'] ?></div>
                </details>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($i === 0): ?>
        <div class="my-12 rounded-2xl bg-[#f8f5ea] p-4 text-center overflow-hidden">
            <span class="text-[10px] text-[#9aa3ab] uppercase tracking-widest block mb-3">Publicité</span>
            <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-3659884670016121" crossorigin="anonymous"></script>
            <ins class="adsbygoogle" style="display:block; text-align:center;" data-ad-layout="in-article" data-ad-format="fluid" data-ad-client="ca-pub-3659884670016121" data-ad-slot="6405652824"></ins>
            <script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
        </div>
    <?php endif; ?>
    <?php endforeach; ?>

    <section class="mt-16 rounded-3xl bg-[#f8f5ea] px-6 py-12 text-center">
        <h2 class="text-[24px] font-extrabold text-[#26343b]">Vous ne trouvez pas votre réponse ?</h2>
        <p class="mt-2 text-[#4b5964]">Notre équipe vous répond dès que possible.</p>
        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <a href="contact" class="ds-btn">Nous écrire</a>
            <a href="mailto:auth@moncamp.fr" class="ds-btn ds-btn-light"><i class="fa-regular fa-envelope"></i> auth@moncamp.fr</a>
        </div>
    </section>
</main>

<script>
// Recherche instantanée : filtre les questions, ouvre celles qui correspondent et surligne le terme
(function () {
    var input = document.getElementById('faq-search'), empty = document.getElementById('faq-empty');
    var items = [].slice.call(document.querySelectorAll('[data-faq]'));
    var norm = function (s) { return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); };
    var orig = items.map(function (it) { return { q: it.querySelector('[data-q]').innerHTML, a: it.querySelector('[data-a]').innerHTML }; });
    input.addEventListener('input', function () {
        var term = norm(input.value.trim()), shown = 0;
        items.forEach(function (it, i) {
            var q = it.querySelector('[data-q]'), a = it.querySelector('[data-a]');
            q.innerHTML = orig[i].q; a.innerHTML = orig[i].a;
            var match = !term || norm(q.textContent + ' ' + a.textContent).indexOf(term) !== -1;
            it.style.display = match ? '' : 'none';
            it.open = !!term && match;
            if (match) shown++;
        });
        document.querySelectorAll('[data-section]').forEach(function (sec) {
            sec.style.display = sec.querySelector('[data-faq]:not([style*="none"])') ? '' : 'none';
        });
        empty.classList.toggle('hidden', shown > 0);
    });
    // ouvre la question visée par une ancre #question éventuelle
    if (location.hash) { var s = document.querySelector(location.hash); if (s) { var d = s.querySelector('details'); if (d && !/^#(parents|organisateurs|animateurs|paiements)$/.test(location.hash)) d.open = true; } }
})();
</script>

<?php require_once 'partials/footer.php'; ?>

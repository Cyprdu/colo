<?php
// legal.php : pages légales (ancres : #mentions, #cgu, #confidentialite, #cookies, #accessibilite)
// Les textes définitifs sont à fournir par l'éditeur du site ; la structure et les liens sont en place.
require_once 'partials/header3.php';

$sections = [
    'mentions'        => 'Mentions légales',
    'cgu'             => "Conditions générales d'utilisation",
    'confidentialite' => 'Politique de confidentialité',
    'cookies'         => 'Cookies',
    'accessibilite'   => 'Accessibilité',
];
?>
<main class="flex-1 w-full max-w-3xl mx-auto px-6 py-14">
    <h1 class="text-3xl font-extrabold text-gray-900 mb-2">Informations légales</h1>
    <p class="text-gray-500 mb-10">ColoMap, plateforme de mise en relation entre familles et organisateurs de séjours.</p>

    <?php foreach ($sections as $id => $title): ?>
    <section id="<?= $id ?>" class="mb-10 scroll-mt-24">
        <h2 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars($title) ?></h2>
        <p class="text-gray-600 leading-relaxed">
            Ce document est en cours de rédaction. Pour toute question, <a href="contact" class="text-blue-600 underline">contactez-nous</a>.
        </p>
    </section>
    <?php endforeach; ?>
</main>
<?php require_once 'partials/footer3.php'; ?>

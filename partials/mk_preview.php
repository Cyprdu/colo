<?php
// partials/mk_preview.php : aperçu de ce que verront les familles (sans boost / avec boost). $mkPreviewName : nom du séjour (facultatif).
$n = htmlspecialchars($mkPreviewName ?? 'Votre séjour');
$line = function (string $t, string $m, string $cls = '', string $badge = '') {
    return '<div class="mk-pv-row ' . $cls . '"><span class="mk-pv-img"></span><span class="mk-pv-txt"><b>' . $t . '</b><i>' . $m . '</i></span>' . ($badge ? '<span class="mk-pv-badge">' . $badge . '</span>' : '') . '</div>';
};
?>
<div class="mk-grid mk-g2 mk-pv">
    <div>
        <p class="ds-eyebrow" style="margin-bottom:8px">Sans boost</p>
        <div class="mk-pv-box">
            <?= $line('Séjour nature, Alpes', '8-12 ans · 7 j') ?>
            <?= $line('Multi-activités, Bretagne', '6-10 ans · 10 j') ?>
            <?= $line($n, '9-13 ans · 7 j', 'me') ?>
        </div>
        <p class="mk-note" style="margin-top:8px">Votre séjour apparaît selon sa pertinence.</p>
    </div>
    <div>
        <p class="ds-eyebrow" style="margin-bottom:8px">Avec « À la une »</p>
        <div class="mk-pv-box">
            <?= $line($n, '9-13 ans · 7 j', 'me hot', 'Sponsorisé') ?>
            <?= $line('Séjour nature, Alpes', '8-12 ans · 7 j') ?>
            <?= $line('Multi-activités, Bretagne', '6-10 ans · 10 j') ?>
        </div>
        <p class="mk-note" style="margin-top:8px">En tête de la recherche et dans l'encart « À la une » de l'accueil.</p>
    </div>
</div>

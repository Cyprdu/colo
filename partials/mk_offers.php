<?php
// partials/mk_offers.php : les 3 formules de visibilité, comparables d'un coup d'œil (marketing.php et boost.php).
// $mkCamp (facultatif) : ['blocked'=>?string, 'bumpOn'=>bool, 'vedetteOn'=>bool, 'urgenceOn'=>bool, 'vedetteFin'=>?string, 'urgenceFin'=>?string, 'urgOk'=>bool]
// Sans $mkCamp : présentation seule, le bouton renvoie vers la liste des séjours.
require_once __DIR__ . '/../api/marketing_lib.php';
require_once __DIR__ . '/mk_icons.php';
$mkCamp = $mkCamp ?? null;
$B = mk_boosts();
$eur = function (int $pts): string { return rtrim(rtrim(number_format($pts * MK_POINT_EUR, 2, ',', ' '), '0'), ',') . ' €'; };
$cards = [
    'vedette' => [
        'icon' => 'star', 'rec' => true, 'title' => 'À la une', 'pitch' => 'En tête des résultats pendant 7 jours',
        'rows' => [['Résultats de recherche', 'En tête'], ['Accueil', 'Encart « À la une »'], ['Badge', 'Sponsorisé'], ['Durée', '7 jours']],
        'help' => 'Poids du séjour +55 % dans le classement pendant 7 jours. Si le boost est déjà actif, les jours s\'ajoutent. Un séjour complet est automatiquement rétrogradé.',
    ],
    'urgence' => [
        'icon' => 'clock', 'rec' => false, 'title' => 'Dernière minute', 'pitch' => 'Comblez les dernières places',
        'rows' => [['Résultats de recherche', 'Mieux placé'], ['Accueil', 'Non'], ['Badge', 'Dernière minute'], ['Durée', '3 jours']],
        'help' => 'Poids du séjour +20 % pendant 3 jours. Réservé aux séjours qui partent dans moins de 45 jours.',
    ],
    'bump' => [
        'icon' => 'up', 'rec' => false, 'title' => 'Remontée', 'pitch' => 'Un coup de projecteur tout de suite',
        'rows' => [['Résultats de recherche', 'Remonte aussitôt'], ['Accueil', 'Non'], ['Badge', 'Aucun'], ['Durée', 'Effet ~3 jours']],
        'help' => 'Poids du séjour jusqu\'à +50 % à l\'instant du boost, qui s\'estompe progressivement sur 3 jours. Cumulable avec les autres formules.',
    ],
];
?>
<div class="mk-grid mk-g3">
<?php foreach ($cards as $k => $c): $b = $B[$k]; $pts = (int)$b['cost']; ?>
    <div class="mk-offer<?= $c['rec'] ? ' rec' : '' ?>">
        <?php if ($c['rec']): ?><span class="tag">Le plus choisi</span><?php endif; ?>
        <div style="display:flex;align-items:center;gap:12px">
            <div class="ico"><?= mk_icon($c['icon']) ?></div>
            <div style="min-width:0;flex:1"><h3 style="margin:0"><?= $c['title'] ?></h3><p style="margin:2px 0 0;font-size:13.5px"><?= $c['pitch'] ?></p></div>
            <details class="mk-help"><summary aria-label="Détails : <?= $c['title'] ?>">?</summary><div><?= htmlspecialchars($c['help']) ?></div></details>
        </div>
        <dl class="mk-rows">
            <?php foreach ($c['rows'] as $r): ?><div><dt><?= $r[0] ?></dt><dd><?= $r[1] ?></dd></div><?php endforeach; ?>
        </dl>
        <div class="foot">
            <div class="mk-price"><?= $eur($pts) ?><small><?= $pts ?> points</small></div>
            <?php if ($mkCamp): $on = $k === 'vedette' ? $mkCamp['vedetteOn'] : ($k === 'urgence' ? $mkCamp['urgenceOn'] : $mkCamp['bumpOn']); $fin = $k === 'vedette' ? $mkCamp['vedetteFin'] : ($k === 'urgence' ? $mkCamp['urgenceFin'] : null); $off = $mkCamp['blocked'] || ($k === 'urgence' && !$mkCamp['urgOk']); ?>
                <div style="text-align:right">
                    <?php if ($on): ?><span class="mk-pill ok" style="margin-bottom:6px"><?= $fin ? 'Actif jusqu\'au ' . date('d/m', strtotime($fin)) : 'En cours' ?></span><br><?php endif; ?>
                    <button type="button" class="ds-btn mk-btn-sm<?= $c['rec'] ? '' : ' ds-btn-light' ?>" <?= $off ? 'disabled' : '' ?> onclick="mkBoost('<?= $k ?>', <?= $pts ?>, this)"><?= $on && $k !== 'bump' ? 'Prolonger' : 'Activer' ?></button>
                </div>
            <?php else: ?>
                <a href="#sejours" class="ds-btn mk-btn-sm<?= $c['rec'] ? '' : ' ds-btn-light' ?>">Choisir un séjour</a>
            <?php endif; ?>
        </div>
        <?php if ($mkCamp && $k === 'urgence' && !$mkCamp['urgOk']): ?><p class="mk-note" style="margin-top:8px">Disponible à moins de 45 jours du départ.</p><?php endif; ?>
    </div>
<?php endforeach; ?>
</div>

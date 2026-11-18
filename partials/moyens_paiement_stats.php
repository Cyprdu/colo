<?php
// partials/moyens_paiement_stats.php : encart « Répartition par moyen de paiement » d'un organisme.
// Utilisation : $mpOrgaId = <id organisme>; require 'partials/moyens_paiement_stats.php'; (après api/finance_lib.php et partials/mk_icons.php).
// Attend $pdo. Aucune donnée personnelle : uniquement des totaux par moyen.
$mpStats = isset($mpOrgaId) ? fin_moyens_stats($pdo, (int)$mpOrgaId) : [];
if (!$mpStats) return;
?>
<section class="mk-section" style="margin-top:20px">
    <h2>Moyens de paiement des inscriptions</h2>
    <p class="lead">Les paiements par carte vous sont versés automatiquement. Les autres (chèque, espèces, virement...) sont à marquer « reçu » dans la liste des inscrits : moncamp.fr retient alors <?= number_format(fin_hors_stripe_pct(), 1, ',', ' ') ?> %, déduits de vos prochains versements par carte.</p>
    <div class="mk-table-wrap" style="margin-top:14px"><table class="mk-table">
        <thead><tr><th>Moyen</th><th class="num">Inscriptions réglées</th><th class="num">Montant</th><th class="num">À encaisser</th><th class="num">Commission moncamp.fr</th></tr></thead><tbody>
        <?php foreach ($mpStats as $mp): ?>
            <tr><td><b><?= htmlspecialchars($mp['libelle']) ?></b></td><td class="num"><?= (int)$mp['nb'] ?></td><td class="num"><?= fin_eur($mp['montant_cents']) ?></td><td class="num"><?= $mp['a_regler_nb'] ? (int)$mp['a_regler_nb'] . ' · ' . fin_eur($mp['a_regler_cents']) : '-' ?></td><td class="num"><?= $mp['commission_cents'] ? fin_eur($mp['commission_cents']) : '-' ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
</section>

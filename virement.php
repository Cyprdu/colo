<?php
// virement.php?t=<jeton> : justificatif d'un versement (détail par inscription, brut / commission / net).
require_once 'api/config.php';
require_once 'api/finance_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user']) || (empty($_SESSION['user']['is_directeur']) && empty($_SESSION['user']['is_admin']))) { header('Location: index.php'); exit; }

$token = (string)($_GET['t'] ?? '');
$rec = null; $error = null;
try { $rec = fin_receipt($pdo, $token, (int)$_SESSION['user']['id'], !empty($_SESSION['user']['is_admin'])); }
catch (Throwable $e) { error_log('virement.php: ' . $e->getMessage()); }
if (!$rec) $error = 'Versement introuvable ou vous n\'êtes pas autorisé à le consulter.';

$body_class = 'bg-white';
require_once 'partials/header.php';
require_once 'partials/mk_icons.php';
?>
<title>Justificatif de versement - moncamp.fr</title>
<link rel="stylesheet" href="assets/mk.css?v=1">
<main class="mk-wrap" style="max-width:820px">
<?php if ($error): ?>
    <div class="ds-alert-err"><?= htmlspecialchars($error) ?></div>
    <a class="mk-back" style="margin-top:14px" href="public_infos.php"><?= mk_icon('back', 16) ?> Mes organismes</a>
<?php else: $v = $rec['v']; $connect = $rec['type'] === 'connect'; ?>
    <a href="dashboard_organisme.php?o=<?= htmlspecialchars(fin_orga_tok((int)$v['organisateur_id'])) ?>" class="mk-back"><?= mk_icon('back', 16) ?> Tableau de bord</a>
    <p class="ds-eyebrow">Justificatif de versement</p>
    <h1 class="ds-h1"><?= htmlspecialchars($v['orga_nom']) ?></h1>
    <p class="ds-lead">Référence <b><?= htmlspecialchars(substr($v['token'], 0, 12)) ?></b></p>

<?php if ($connect):
    $brut = 0; $com = 0; $net = 0; foreach ($rec['lignes'] as $l) { $brut += $l['brut_cents'] - $l['rembourse_cents']; $com += $l['commission_cents'] - ($l['brut_cents'] ? (int)round($l['commission_cents'] * $l['rembourse_cents'] / $l['brut_cents']) : 0); $net += $l['net_cents'] - $l['net_rembourse_cents']; $frais = ($frais ?? 0) + (int)($l['frais_cents'] ?? 0); }
    $lbl = ['envoye' => [$v['type'] === 'payout' ? 'Virement en cours' : 'Envoyé', 'ok'], 'paye' => ['Arrivé sur votre banque', 'ok'], 'en_cours' => ['En cours d\'envoi', 'info'], 'echec' => ['Échec', 'bad']][$v['statut']] ?? [$v['statut'], '']; ?>
    <div class="mk-grid mk-g4" style="margin-top:22px">
        <div class="mk-stat"><div class="k">Encaissé (brut)</div><div class="v"><?= fin_eur($brut) ?></div><div class="s"><?= count($rec['lignes']) ?> inscription<?= count($rec['lignes']) > 1 ? 's' : '' ?></div></div>
        <div class="mk-stat"><div class="k">Commission moncamp.fr</div><div class="v">- <?= fin_eur($com) ?></div><div class="s">au taux en vigueur à la vente</div></div>
        <div class="mk-stat"><div class="k">Frais de paiement Stripe</div><div class="v">- <?= fin_eur($frais ?? 0) ?></div><div class="s">frais réels de chaque paiement par carte, à votre charge</div></div>
        <div class="mk-stat dark"><div class="k">Versé</div><div class="v"><?= fin_eur((int)$v['montant_cents']) ?></div><div class="s"><span class="mk-pill <?= $lbl[1] ?>" style="background:rgba(255,255,255,.18);color:#fff"><?= $lbl[0] ?></span></div></div>
    </div>
    <?php if ((int)$v['montant_cents'] !== $net): ?><p class="mk-note" style="margin-top:10px">Le montant versé diffère du net des inscriptions (<?= fin_eur($net) ?>) : <?= $v['type'] === 'payout' ? 'un virement vers votre banque regroupe tout l\'argent disponible sur votre compte Stripe (autres séjours, anciens paiements), moins les remboursements à compenser.' : '' ?> <?php if ($v['type'] !== 'payout'): ?>une réserve de remboursement a été gardée (elle sera versée dès qu'aucune annulation n'est plus possible), ou des points marketing et des compensations de remboursements ont été déduits du solde.<?php endif; ?></p><?php endif; ?>

    <div class="mk-table-wrap" style="margin-top:20px"><table class="mk-table" style="min-width:560px">
        <thead><tr><th>Séjour</th><th>Départ</th><th>Inscr.</th><th class="num">Brut</th><th class="num">Commission</th><th class="num">Frais Stripe</th><th class="num">Net</th><th class="num">Versé ce jour</th></tr></thead><tbody>
        <?php foreach ($rec['lignes'] as $l): ?><tr><td><?= htmlspecialchars($l['camp_nom']) ?></td><td><?= date('d/m/Y', strtotime($l['date_debut'])) ?></td><td>#<?= (int)$l['inscription_id'] ?></td><td class="num"><?= fin_eur((int)$l['brut_cents']) ?></td><td class="num">- <?= fin_eur((int)$l['commission_cents']) ?></td><td class="num">- <?= fin_eur((int)($l['frais_cents'] ?? 0)) ?></td><td class="num"><b><?= fin_eur((int)$l['net_cents']) ?></b><?= $l['rembourse_cents'] ? '<div class="mk-note">dont remboursé : ' . fin_eur((int)$l['rembourse_cents']) . '</div>' : '' ?></td><td class="num"><b><?= fin_eur((int)($l['montant_ligne'] ?? ((int)$l['net_cents'] - (int)$l['net_rembourse_cents']))) ?></b></td></tr><?php endforeach; ?>
        </tbody></table></div>

    <div class="ds-card" style="margin-top:20px;padding:20px;font-size:14px;line-height:1.7">
        <div><b>Date :</b> <?= date('d/m/Y à H:i', strtotime($v['created_at'])) ?></div>
        <div><b>Identifiant Stripe :</b> <?= htmlspecialchars($v['stripe_transfer_id'] ?: 'en attente') ?></div>
        <div class="mk-note" style="margin-top:6px">Stripe verse ensuite ce montant sur votre compte bancaire (généralement sous 2 à 3 jours ouvrés). Vous retrouvez le détail dans « Mon espace Stripe ».</div>
    </div>
<?php else: ?>
    <div class="mk-grid mk-g3" style="margin-top:22px">
        <div class="mk-stat"><div class="k">Montant brut</div><div class="v"><?= fin_eur((int)round($v['montant_total'] * 100)) ?></div></div>
        <div class="mk-stat"><div class="k">Commission (<?= number_format($v['commission_rate'], 2, ',', ' ') ?> %)</div><div class="v">- <?= fin_eur((int)round(($v['montant_total'] - $v['montant_apres_commission']) * 100)) ?></div></div>
        <div class="mk-stat dark"><div class="k">Net</div><div class="v"><?= fin_eur((int)round($v['montant_apres_commission'] * 100)) ?></div><div class="s"><?= $v['effectue'] ? 'Effectué le ' . date('d/m/Y', strtotime($v['date_virement_effectue'])) : 'En attente' ?></div></div>
    </div>
    <p class="mk-note" style="margin-top:14px">Versement effectué avec l'ancien système (demande manuelle). Demandé le <?= date('d/m/Y', strtotime($v['date_demande'])) ?>.</p>
<?php endif; ?>
    <div style="margin-top:20px"><a class="ds-btn" target="_blank" href="pdf_export_virement.php?t=<?= urlencode($v['token']) ?>"><?= mk_icon('doc', 18) ?> Télécharger le justificatif PDF</a></div>
<?php endif; ?>
</main>
<?php require_once 'partials/footer.php'; ?>

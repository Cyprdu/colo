<?php
// annulations_organisme.php?o=<jeton organisme> : conditions d'annulation par séjour (réglées par l'organisme) et annulations faites par les familles.
// Les remboursements sont automatiques (fin_annul_executer) ; le solde de l'organisme est ajusté par le webhook.
require_once 'api/config.php';
require_once 'api/annulation_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) { header('Location: index.php'); exit; }
$userId = (int)$_SESSION['user']['id'];

fin_ensure($pdo);
$tok = (string)($_GET['o'] ?? '');
$orgaId = fin_orga_from_tok($pdo, $tok, $userId); // uniquement les organismes de l'utilisateur connecté
if (!$orgaId) { header('Location: public_infos.php'); exit; }

$msg = null; $err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fin_csrf_ok()) $err = 'Session expirée, rechargez la page.';
    else {
        try {
            $act = (string)($_POST['act'] ?? '');
            if ($act === 'conditions') {
                $jt = (int)($_POST['jt'] ?? -1); $jm = (int)($_POST['jm'] ?? -1);
                if ($jt < 0 || $jt > 365 || $jm < 0 || $jm > $jt) $err = 'Jours invalides : le seuil 50 % ne peut pas dépasser le seuil 100 %.';
                else {
                    $up = $pdo->prepare("UPDATE camps SET annul_jours_total = ?, annul_jours_moitie = ? WHERE token = ? AND organisateur_id = ?");
                    $up->execute([$jt, $jm, (string)($_POST['camp'] ?? ''), $orgaId]);
                    $msg = $up->rowCount() ? 'Conditions enregistrées (elles s\'appliquent aux prochaines demandes).' : 'Aucun changement.';
                }
            }
        } catch (Throwable $e) { error_log('annulations_organisme: ' . $e->getMessage()); $err = 'Une erreur est survenue.'; }
    }
}

$list = fin_annul_liste($pdo, $orgaId);
$pdo->prepare("UPDATE fin_annulations SET lu = 1 WHERE organisateur_id = ?")->execute([$orgaId]);
$st = $pdo->prepare("SELECT token, nom, date_debut, annul_jours_total jt, annul_jours_moitie jm FROM camps WHERE organisateur_id = ? AND COALESCE(supprime, 0) = 0 ORDER BY date_debut DESC LIMIT 100");
$st->execute([$orgaId]);
$camps = $st->fetchAll(PDO::FETCH_ASSOC);

$body_class = 'bg-white';
require_once 'partials/header.php';
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$stl = ['demande' => 'En attente', 'acceptee' => 'Acceptée', 'refusee' => 'Refusée'];
?>
<title>Demandes d'annulation - ColoMap</title>
<main class="ds-page" style="max-width:960px">
  <h1 class="ds-h1">Annulations</h1>
  <p class="ds-lead">Les familles annulent elles-mêmes, selon vos conditions ci-dessous ; le remboursement est automatique. La commission ColoMap est conservée : seul votre net remboursé est déduit de votre solde (compensé sur vos prochains versements si vous avez déjà été payé).</p>
  <?php if ($msg): ?><div class="ds-alert-ok" style="margin:16px 0" role="status"><?= $h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="ds-alert-err" style="margin:16px 0" role="alert"><?= $h($err) ?></div><?php endif; ?>

  <div class="ds-card mt-6 divide-y divide-[#f1f3f5]">
  <?php if (!$list): ?><p class="p-6 ds-lead">Aucune annulation pour le moment.</p><?php endif; ?>
  <?php foreach ($list as $a): ?>
    <div class="p-5">
      <div class="flex flex-wrap justify-between gap-2">
        <div><b><?= $h($a['prenom'] . ' ' . $a['nom']) ?></b> · <?= $h($a['camp']) ?> <span class="ds-lead">(début <?= $h(date('d/m/Y', strtotime($a['date_debut']))) ?>)</span></div>
        <span class="ds-lead"><?= $h(date('d/m/Y', strtotime($a['created_at']))) ?></span>
      </div>
      <p class="mt-1">Motif : <?= $h(FIN_ANNUL_MOTIFS[$a['motif']] ?? $a['motif']) ?><?= $a['commentaire'] ? ' — « ' . $h($a['commentaire']) . ' »' : '' ?></p>
      <p class="mt-1">Remboursé à la famille (<?= (int)$a['pct'] ?> %) : <b><?= $h(fin_eur((int)$a['rembourse_cents'])) ?></b> · commission ColoMap retenue : <?= $h(fin_eur((int)$a['commission_retenue_cents'])) ?></p>
    </div>
  <?php endforeach; ?>
  </div>

  <h2 class="ds-h2 mt-10">Conditions d'annulation par séjour</h2>
  <p class="ds-lead">Remboursé à 100 % jusqu'à N jours avant le début, à 50 % jusqu'à M jours, puis rien. Valeurs par défaut : 30 et 15 jours. Ces conditions sont affichées aux familles avant le paiement.</p>
  <div class="ds-card mt-3 divide-y divide-[#f1f3f5]">
  <?php foreach ($camps as $c): ?>
    <form method="post" class="p-4 flex flex-wrap gap-3 items-center">
      <input type="hidden" name="csrf" value="<?= $h(fin_csrf()) ?>"><input type="hidden" name="act" value="conditions"><input type="hidden" name="camp" value="<?= $h($c['token']) ?>">
      <span class="mr-auto font-semibold"><?= $h($c['nom']) ?></span>
      <label>100 % jusqu'à <input class="ds-input !w-20 !py-1" type="number" name="jt" min="0" max="365" value="<?= (int)$c['jt'] ?>"> j</label>
      <label>50 % jusqu'à <input class="ds-input !w-20 !py-1" type="number" name="jm" min="0" max="365" value="<?= (int)$c['jm'] ?>"> j</label>
      <button class="ds-btn ds-btn-light" type="submit">Enregistrer</button>
    </form>
  <?php endforeach; ?>
  </div>
</main>
<?php require_once 'partials/footer.php'; ?>

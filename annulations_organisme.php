<?php
// annulations_organisme.php?o=<jeton organisme> : demandes d'annulation des familles (accepter / refuser) et conditions d'annulation par séjour.
// Acceptation = remboursement Stripe (fin_annul_traiter) ; le solde de l'organisme est ajusté par le webhook.
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
            if ($act === 'accepter' || $act === 'refuser') {
                [$ok, $m] = fin_annul_traiter($pdo, (string)($_POST['a'] ?? ''), $orgaId, $act === 'accepter', (string)($_POST['reponse'] ?? ''), 'orga:' . $userId);
                if ($ok) $msg = $m; else $err = $m;
            } elseif ($act === 'conditions') {
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
  <h1 class="ds-h1">Demandes d'annulation</h1>
  <p class="ds-lead">Accepter déclenche le remboursement de la famille par carte. Votre part remboursée est déduite de votre solde ; si vous avez déjà été payé, elle est compensée sur vos prochains versements.</p>
  <?php if ($msg): ?><div class="ds-alert-ok" style="margin:16px 0" role="status"><?= $h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="ds-alert-err" style="margin:16px 0" role="alert"><?= $h($err) ?></div><?php endif; ?>

  <div class="ds-card mt-6 divide-y divide-[#f1f3f5]">
  <?php if (!$list): ?><p class="p-6 ds-lead">Aucune demande pour le moment.</p><?php endif; ?>
  <?php foreach ($list as $a): ?>
    <div class="p-5">
      <div class="flex flex-wrap justify-between gap-2">
        <div><b><?= $h($a['prenom'] . ' ' . $a['nom']) ?></b> · <?= $h($a['camp']) ?> <span class="ds-lead">(début <?= $h(date('d/m/Y', strtotime($a['date_debut']))) ?>)</span></div>
        <span class="ds-chip !py-1 !px-3 !text-[13px]"><?= $h($stl[$a['statut']] ?? $a['statut']) ?></span>
      </div>
      <p class="mt-1">Motif : <?= $h(FIN_GARANTIE_MOTIFS[$a['motif']] ?? $a['motif']) ?><?= $a['commentaire'] ? ' — « ' . $h($a['commentaire']) . ' »' : '' ?></p>
      <p class="mt-1">Payé <?= $h(fin_eur((int)$a['brut_cents'])) ?> · à votre charge selon vos conditions : <b><?= $h(fin_eur((int)$a['organisme_cents'])) ?></b>
        <?php if ((int)$a['garantie_pec_cents'] > 0): ?> · complété par la garantie annulation ColoMap : <?= $h(fin_eur((int)$a['garantie_pec_cents'])) ?> (sans coût pour vous)<?php endif; ?></p>
      <?php if ($a['statut'] === 'demande'): ?>
      <form method="post" class="mt-3 flex flex-wrap gap-2 items-center">
        <input type="hidden" name="csrf" value="<?= $h(fin_csrf()) ?>"><input type="hidden" name="a" value="<?= $h($a['token']) ?>">
        <input class="ds-input !w-72 !py-2" name="reponse" maxlength="500" placeholder="Message à la famille (facultatif)" aria-label="Message à la famille">
        <button class="ds-btn" name="act" value="accepter" type="submit">Accepter et rembourser</button>
        <button class="ds-btn ds-btn-light" name="act" value="refuser" type="submit">Refuser</button>
      </form>
      <?php elseif ($a['reponse']): ?><p class="ds-lead mt-1">Votre réponse : <?= $h($a['reponse']) ?></p><?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>

  <h2 class="ds-h2 mt-10">Conditions d'annulation par séjour</h2>
  <p class="ds-lead">Remboursé à 100 % jusqu'à N jours avant le début, à 50 % jusqu'à M jours, puis rien. Valeurs par défaut : 30 et 14 jours.</p>
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

<?php
// annuler_inscription.php?r=<jeton de réservation> : le parent demande l'annulation d'une inscription payée.
// Le montant remboursable est calculé ICI (serveur) selon les conditions d'annulation du séjour ; le navigateur n'envoie que le motif.
require_once 'api/config.php';
require_once 'api/annulation_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$r = (string)($_GET['r'] ?? '');
if (empty($_SESSION['user']['id'])) { header('Location: login.php?redirect=' . urlencode('annuler_inscription.php?r=' . $r)); exit; }
$userId = (int)$_SESSION['user']['id'];

$msg = null; $err = null;
$c = null;
try {
    $c = fin_annul_context($pdo, $r, $userId);
    if ($c && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!fin_csrf_ok()) { $err = 'Session expirée, rechargez la page.'; }
        else {
            [$ok, $m] = fin_annul_demander($pdo, $r, $userId, (string)($_POST['motif'] ?? ''), (string)($_POST['commentaire'] ?? ''));
            if ($ok) $msg = $m; else $err = $m;
            $c = fin_annul_context($pdo, $r, $userId);
        }
    }
} catch (Throwable $e) { error_log('annuler_inscription: ' . $e->getMessage()); $err = 'Une erreur est survenue, réessayez plus tard.'; }

$body_class = 'bg-white';
require_once 'partials/header.php';
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$hasG = $c && (int)$c['garantie_cents'] > 0;
$pct = $c ? fin_remboursable_pct($c['jours'], (int)$c['jt'], (int)$c['jm']) : 0;
$orgaCents = $c ? (int)round($c['restant'] * $pct / 100) : 0;
$d = $c ? $c['demande'] : null;
?>
<title>Annuler une inscription - ColoMap</title>
<main class="ds-page" style="max-width:720px">
  <a href="reservations" class="text-sm font-semibold text-[#6b7780] hover:text-[#26343b]">&larr; Mes réservations</a>
  <h1 class="ds-h1 mt-2">Annuler une inscription</h1>

  <?php if ($msg): ?><div class="ds-alert-ok" style="margin:16px 0" role="status"><?= $h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="ds-alert-err" style="margin:16px 0" role="alert"><?= $h($err) ?></div><?php endif; ?>

  <?php if (!$c): ?>
    <p class="ds-lead">Inscription introuvable, ou non payée en ligne.</p>
  <?php else: ?>
  <div class="ds-card p-6 mt-4">
    <p class="font-extrabold text-[#26343b]"><?= $h($c['camp']) ?></p>
    <p class="ds-lead"><?= $h($c['prenom'] . ' ' . $c['nom']) ?> · début le <?= $h(date('d/m/Y', strtotime($c['date_debut']))) ?> (dans <?= max(0, (int)$c['jours']) ?> jour<?= $c['jours'] > 1 ? 's' : '' ?>)</p>
    <p class="mt-3">Payé : <b><?= $h(fin_eur((int)$c['brut_cents'])) ?></b><?= $hasG ? ' + garantie annulation ' . $h(fin_eur((int)$c['garantie_cents'])) : '' ?></p>
  </div>

  <?php if ($c['annule']): ?>
    <p class="ds-lead mt-4">Cette inscription est annulée.<?= $d && $d['statut'] === 'acceptee' ? ' Remboursement de ' . $h(fin_eur((int)$d['organisme_cents'] + (int)$d['garantie_pec_cents'])) . ' en cours (quelques jours selon votre banque).' : '' ?></p>
  <?php elseif ($d && $d['statut'] === 'demande'): ?>
    <div class="ds-card p-6 mt-4"><b>Demande en attente de réponse de l'organisme.</b>
      <p class="ds-lead">Remboursement prévu si elle est acceptée : <b><?= $h(fin_eur((int)$d['organisme_cents'] + (int)$d['garantie_pec_cents'])) ?></b>.</p></div>
  <?php elseif (!$c['annulable']): ?>
    <p class="ds-lead mt-4"><?= $c['jours'] < 0 ? 'Le séjour a commencé : l\'annulation en ligne n\'est plus possible, contactez l\'organisme.' : 'Cette inscription ne peut pas être annulée en ligne.' ?></p>
  <?php else: ?>
    <?php if ($d && $d['statut'] === 'refusee'): ?><div class="ds-alert-err" style="margin-top:16px">Votre précédente demande a été refusée<?= !empty($d['reponse']) ? ' : ' . $h($d['reponse']) : '' ?>. Vous pouvez en déposer une nouvelle.</div><?php endif; ?>
    <div class="ds-card p-6 mt-4">
      <h2 class="font-extrabold text-[#26343b] mb-2">Montant remboursable</h2>
      <p>Selon les conditions de l'organisme (100 % jusqu'à <?= (int)$c['jt'] ?> jours avant le début, 50 % jusqu'à <?= (int)$c['jm'] ?> jours, puis rien) :
         <b><?= $pct ?> %</b> soit <b><?= $h(fin_eur($orgaCents)) ?></b>.</p>
      <?php if ($hasG): ?>
        <p class="mt-2">Vous avez souscrit la <b>garantie annulation</b> (service ColoMap) : pour maladie ou accident de l'enfant, ou décès d'un proche, ColoMap prend en charge le reste, soit <b><?= $h(fin_eur((int)$c['restant'])) ?></b> remboursés au total. Les frais de garantie eux-mêmes ne sont pas remboursés.</p>
      <?php endif; ?>
      <p class="ds-lead mt-2" style="font-size:13px">Le montant définitif est confirmé par l'organisme, qui accepte ou refuse la demande.</p>
    </div>
    <form method="post" class="ds-card p-6 mt-4">
      <input type="hidden" name="csrf" value="<?= $h(fin_csrf()) ?>">
      <label class="block font-semibold mb-1" for="motif">Motif</label>
      <select id="motif" name="motif" class="ds-input" required>
        <option value="">Choisir…</option>
        <?php foreach (FIN_GARANTIE_MOTIFS as $k => $l): ?><option value="<?= $h($k) ?>"><?= $h($l) ?></option><?php endforeach; ?>
      </select>
      <label class="block font-semibold mt-4 mb-1" for="commentaire">Précisions (facultatif)</label>
      <textarea id="commentaire" name="commentaire" class="ds-input" rows="3" maxlength="500"></textarea>
      <button type="submit" class="ds-btn mt-4">Envoyer la demande d'annulation</button>
    </form>
  <?php endif; ?>
  <?php endif; ?>
</main>
<?php require_once 'partials/footer.php'; ?>

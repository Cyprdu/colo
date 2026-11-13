<?php
// connect_statut.php?o=<jeton> : étape avant l'inscription Stripe. L'organisme indique son statut (particulier, micro-entrepreneur,
// association, société) ; le compte Stripe Express est alors créé avec ce statut et le profil pré-rempli (site, description, activité),
// pour que Stripe ne repose pas ces questions. Jeton opaque dans l'URL : aucun identifiant numérique.
require_once 'api/config.php';
require_once 'api/finance_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) { header('Location: login.php'); exit; }

$userId = (int)$_SESSION['user']['id'];
$error = null; $orga = null;
try {
    fin_ensure($pdo);
    $orgaId = fin_orga_from_tok($pdo, (string)($_GET['o'] ?? ''), $userId);
    if ($orgaId) { $st = $pdo->prepare("SELECT id, nom, email, web, stripe_account_id FROM organisateurs WHERE id = ? AND user_id = ?"); $st->execute([$orgaId, $userId]); $orga = $st->fetch(PDO::FETCH_ASSOC); }
} catch (Throwable $e) { error_log('connect_statut: ' . $e->getMessage()); $error = 'Une erreur est survenue. Réessayez dans un instant.'; }
if (!$orga && !$error) $error = "Organisme introuvable ou vous n'êtes pas autorisé à y accéder.";
$tok = $orga ? fin_orga_tok((int)$orga['id']) : '';
$statuts = fin_connect_statuts();

// Compte déjà créé : on reprend directement l'inscription Stripe
if ($orga && !empty($orga['stripe_account_id'])) { header('Location: api/connect_onboarding.php?o=' . $tok); exit; }

if ($orga && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!fin_csrf_ok()) throw new Exception('Session expirée, rechargez la page.');
        $choix = (string)($_POST['statut'] ?? '');
        if (!isset($statuts[$choix])) throw new Exception('Choisissez votre statut.');
        fin_create_connect_account($pdo, $orga, $choix, $userId);
        header('Location: api/connect_onboarding.php?o=' . $tok); exit;
    } catch (Throwable $e) {
        error_log('connect_statut: ' . $e->getMessage());
        $m = $e->getMessage();
        $error = in_array($m, ['Choisissez votre statut.', 'Session expirée, rechargez la page.'], true) ? $m : "L'activation des versements est momentanément indisponible. Réessayez dans quelques minutes.";
    }
}

$body_class = 'bg-white';
require_once 'partials/header.php';
require_once 'partials/mk_icons.php';
$csrf = fin_csrf();
?>
<title>Activer mes versements</title>
<link rel="stylesheet" href="assets/mk.css?v=1">
<main class="mk-wrap" style="max-width:760px">
<a href="<?= $orga ? 'dashboard_organisme.php?organisateur_id=' . (int)$orga['id'] : 'public_infos.php' ?>" class="mk-back"><?= mk_icon('back', 16) ?> Retour</a>
<?php if (!$orga): ?>
    <div class="ds-alert-err"><?= htmlspecialchars($error ?? '') ?></div>
<?php else: ?>
    <p class="ds-eyebrow">Activer mes versements · étape 1 sur 2</p>
    <h1 class="ds-h1">Quel est votre statut ?</h1>
    <p class="ds-lead">Vous n'avez pas besoin d'être une entreprise : particuliers et associations peuvent aussi recevoir le paiement des inscriptions. Cette réponse permet de ne vous poser que les bonnes questions à l'étape suivante, chez Stripe, notre prestataire de paiement.</p>
    <?php if ($error): ?><div class="ds-alert-err" style="margin-top:16px" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" style="margin-top:22px">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <div style="display:grid;gap:12px">
        <?php foreach ($statuts as $k => [$label, $desc]): ?>
            <label class="ds-card" style="padding:18px 20px;display:flex;gap:14px;align-items:flex-start;cursor:pointer">
                <input type="radio" name="statut" value="<?= htmlspecialchars($k) ?>" required style="margin-top:4px;accent-color:#2f3d45">
                <span><b class="text-[#26343b]" style="font-size:16px"><?= htmlspecialchars($label) ?></b><br><span class="mk-note"><?= htmlspecialchars($desc) ?></span></span>
            </label>
        <?php endforeach; ?>
        </div>
        <div class="mk-tip" style="margin-top:18px"><?= mk_icon('shield', 20) ?><div>À l'étape suivante, Stripe vous demandera votre identité et votre IBAN. <b>ColoMap ne voit ni ne conserve ces informations.</b> Compter 5 minutes. Le site de l'organisme et la nature de l'activité (séjours de vacances pour enfants) sont déjà renseignés.</div></div>
        <div style="margin-top:20px"><button class="ds-btn" type="submit">Continuer vers Stripe <?= mk_icon('arrow', 16) ?></button></div>
    </form>
<?php endif; ?>
</main>
<?php require_once 'partials/footer.php'; ?>

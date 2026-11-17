<?php
// add_child.php : création rapide d'un enfant (identité), puis on complète la fiche (santé, documents…) dans modif_child.
require_once 'api/config.php';
require_once 'api/enfants.php';
require_once 'api/security.php';
require_once 'api/sante.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user']['id'])) { header('Location: login.php?redirect=add_child'); exit; }
$uid = (int)$_SESSION['user']['id'];
$error = '';
$v = ['prenom' => '', 'nom' => (string)($_SESSION['user']['nom'] ?? ''), 'date_naissance' => '', 'sexe' => 'Homme'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) $v[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, 100);
    try {
        if (!csrf_check()) throw new Exception("Session expirée : rechargez la page puis réessayez.");
        if (sec_blocked($pdo, 'child_add', (string)$uid, 20, 60)) throw new Exception("Trop de créations d'affilée. Réessayez plus tard.");
        sec_hit($pdo, 'child_add', (string)$uid);
        if (!sec_turnstile_ok()) throw new Exception("Vérification anti-robot échouée : réessayez.");
        $d = DateTime::createFromFormat('Y-m-d', $v['date_naissance']);
        if ($v['prenom'] === '' || $v['nom'] === '') throw new Exception("Le prénom et le nom sont obligatoires.");
        if (!$d || $d->format('Y-m-d') !== $v['date_naissance'] || $d > new DateTime() || $d < new DateTime('1990-01-01')) throw new Exception("Date de naissance invalide.");
        if (!in_array($v['sexe'], ['Homme', 'Femme', 'Autre'], true)) throw new Exception("Sexe invalide.");
        if (empty($_POST['accord_parental'])) throw new Exception("Vous devez certifier avoir l'autorité parentale.");

        enfants_ensure($pdo); sante_ensure($pdo);
        $u = $_SESSION['user'];
        $pdo->prepare("INSERT INTO enfants (parent_id, prenom, nom, date_naissance, sexe, resp1_nom, resp1_prenom, resp1_email, resp1_tel, accord_parental, date_creation, public_id)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?)")
            ->execute([$uid, $v['prenom'], $v['nom'], $v['date_naissance'], $v['sexe'], $u['nom'] ?? null, $u['prenom'] ?? null, $u['email'] ?? null, $u['telephone'] ?? null, enfant_new_public_id()]);
        $_SESSION['flash_child'] = ['success', $v['prenom'] . " est ajouté(e). Complétez sa fiche : santé et documents."];
        header('Location: modif_child?id=' . urlencode(enfant_jeton($pdo, (int)$pdo->lastInsertId())) . '#sante');
        exit;
    } catch (Throwable $e) {
        if ($e instanceof PDOException) { error_log('add_child: ' . $e->getMessage()); $error = "Erreur lors de l'enregistrement."; }
        else $error = $e->getMessage();
    }
}

$h = fn($s) => htmlspecialchars((string)$s);
$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Ajouter un enfant - ColoMap</title>

<main class="ds-page ds-fade" style="max-width:640px">
    <a href="children" class="inline-flex items-center gap-2 text-[14px] font-semibold text-[#3b4a54] hover:text-[#26343b]">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12H4m6-6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Mes enfants
    </a>
    <h1 class="ds-h1 mt-5">Ajouter un enfant</h1>
    <p class="ds-lead">Quelques informations pour commencer. Vous complétez ensuite sa fiche (santé, documents, autorisations) quand vous voulez.</p>

    <?php if ($error): ?><div class="ds-alert-err mt-6 font-semibold" role="alert"><?= $h($error) ?></div><?php endif; ?>

    <form method="POST" class="ds-card p-6 sm:p-8 mt-8 space-y-5">
        <?= csrf_field() ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div><label class="ds-label" for="prenom">Prénom *</label><input class="ds-input" id="prenom" name="prenom" required value="<?= $h($v['prenom']) ?>"></div>
            <div><label class="ds-label" for="nom">Nom *</label><input class="ds-input" id="nom" name="nom" required value="<?= $h($v['nom']) ?>"></div>
            <div><label class="ds-label" for="dn">Date de naissance *</label><input class="ds-input" id="dn" type="date" name="date_naissance" required max="<?= date('Y-m-d') ?>" value="<?= $h($v['date_naissance']) ?>"></div>
            <div><label class="ds-label" for="sexe">Sexe *</label><select class="ds-select" id="sexe" name="sexe">
                <?php foreach (['Homme' => 'Garçon', 'Femme' => 'Fille', 'Autre' => 'Autre'] as $k => $l): ?><option value="<?= $k ?>"<?= $v['sexe'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        </div>
        <label class="flex items-start gap-3 cursor-pointer"><input type="checkbox" name="accord_parental" value="1" required class="mt-1 w-4 h-4"><span class="text-[14px] text-[#26343b]">Je certifie avoir l'autorité parentale sur cet enfant et l'exactitude de ces informations. *</span></label>
        <?= sec_turnstile_widget() ?>
        <div class="flex justify-end"><button type="submit" class="ds-btn">Créer la fiche</button></div>
    </form>
</main>

<?php require_once 'partials/footer.php'; ?>

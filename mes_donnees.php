<?php
// mes_donnees.php : mes données personnelles (RGPD) : ce que moncamp.fr conserve, export, suppression du compte.
require_once 'api/config.php';
require_once 'api/security.php';
require_once 'api/rgpd_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user']['id'])) { header('Location: login.php?next=mes_donnees'); exit; }
$uid = (int)$_SESSION['user']['id'];
$error = '';

// Suppression du compte : jeton CSRF + mot de passe + phrase de confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'supprimer') {
    try {
        if (!csrf_check()) throw new Exception('Session expirée : rechargez la page et réessayez.');
        if (sec_blocked($pdo, 'suppr', (string)$uid, 5, 30)) throw new Exception('Trop d\'essais. Réessayez dans 30 minutes.');
        $st = $pdo->prepare("SELECT password FROM users WHERE id = ?"); $st->execute([$uid]);
        $hash = (string)$st->fetchColumn();
        if (trim((string)($_POST['confirmation'] ?? '')) !== 'SUPPRIMER') throw new Exception('Tapez SUPPRIMER en majuscules pour confirmer.');
        if ($hash !== '' && !password_verify((string)($_POST['mdp'] ?? ''), $hash)) { sec_hit($pdo, 'suppr', (string)$uid); throw new Exception('Mot de passe incorrect.'); }
        if (($obstacle = rgpd_obstacle($pdo, $uid)) !== null) throw new Exception($obstacle);
        rgpd_delete_account($pdo, $uid);
        $_SESSION = []; session_destroy();
        header('Location: index.php?compte_supprime=1'); exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if (!($e instanceof Exception) || strpos($e->getMessage(), 'SQLSTATE') !== false) { error_log('mes_donnees: ' . $e->getMessage()); $error = 'La suppression n\'a pas pu aboutir. Réessayez ou contactez-nous.'; }
    }
}

// Aperçu de ce que l'on détient
$nbEnfants = $nbInsc = $nbMsg = 0;
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM enfants WHERE parent_id = ?"); $st->execute([$uid]); $nbEnfants = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM inscriptions i JOIN enfants e ON e.id = i.enfant_id WHERE e.parent_id = ?"); $st->execute([$uid]); $nbInsc = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = ?"); $st->execute([$uid]); $nbMsg = (int)$st->fetchColumn();
} catch (Throwable $e) {}

$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Mes données personnelles - moncamp.fr</title>
<main class="ds-page" style="max-width:820px">
    <a href="edit_profile" class="inline-flex items-center gap-2 text-[14px] font-semibold text-[#3b4a54] hover:text-[#26343b]">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12H4m6-6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Mon profil
    </a>
    <p class="ds-eyebrow mt-6">Vie privée</p>
    <h1 class="ds-h1">Mes données personnelles</h1>
    <p class="ds-lead">Vous gardez la main sur vos informations : consultez ce que nous conservons, téléchargez-le, ou supprimez votre compte.</p>

    <section class="ds-card p-6 sm:p-8 mt-8" aria-labelledby="h-conserve">
        <h2 id="h-conserve" class="ds-eyebrow mb-4">Ce que nous conservons</h2>
        <dl class="grid grid-cols-3 gap-3 text-center">
            <div class="rounded-2xl bg-[#f8f5ea] p-4"><dt class="text-[12.5px] text-[#6b7780]">Fiches enfant</dt><dd class="text-[24px] font-extrabold text-[#26343b]"><?= $nbEnfants ?></dd></div>
            <div class="rounded-2xl bg-[#f1f3f5] p-4"><dt class="text-[12.5px] text-[#6b7780]">Inscriptions</dt><dd class="text-[24px] font-extrabold text-[#26343b]"><?= $nbInsc ?></dd></div>
            <div class="rounded-2xl bg-[#f1f3f5] p-4"><dt class="text-[12.5px] text-[#6b7780]">Messages</dt><dd class="text-[24px] font-extrabold text-[#26343b]"><?= $nbMsg ?></dd></div>
        </dl>
        <p class="mt-4 text-[13.5px] text-[#6b7780]">Détail, finalités et durées de conservation : <a class="underline" href="legal#confidentialite">politique de confidentialité</a>. Les informations de santé des enfants ne sont visibles que de vous et de l'organisateur du séjour auquel l'enfant est inscrit.</p>
    </section>

    <section class="ds-card p-6 sm:p-8 mt-6" aria-labelledby="h-export">
        <h2 id="h-export" class="ds-eyebrow mb-2">Télécharger mes données</h2>
        <p class="text-[14.5px] text-[#4b5964]">Un fichier unique (JSON) avec votre compte, vos enfants, vos inscriptions, vos messages, vos consentements, les accès aux documents de santé de vos enfants et, si vous êtes animateur, votre profil de candidature, vos préférences, vos candidatures et vos inscriptions à des équipes d'animation (votre CV est supprimé avec votre compte).</p>
        <form method="POST" action="api/rgpd_export.php" class="mt-4"><?= csrf_field() ?><button class="ds-btn" type="submit">Télécharger mes données</button></form>
    </section>

    <section class="ds-card p-6 sm:p-8 mt-6" aria-labelledby="h-suppr" style="border-color:#e6c9c4">
        <h2 id="h-suppr" class="ds-eyebrow mb-2" style="color:#9a3b2e">Supprimer mon compte</h2>
        <p class="text-[14.5px] text-[#4b5964]">Vos informations personnelles, celles de vos enfants (dont leurs documents de santé) et vos messages sont effacés. Les <b>pièces comptables</b> des paiements déjà effectués sont conservées sous forme anonyme, comme la loi l'impose. <b>Cette action est définitive.</b></p>
        <?php if ($error): ?><div class="ds-alert-err mt-4" role="alert"><p class="font-semibold"><?= htmlspecialchars($error) ?></p></div><?php endif; ?>
        <form method="POST" class="mt-5 space-y-4" autocomplete="off">
            <?= csrf_field() ?><input type="hidden" name="action" value="supprimer">
            <div><label class="ds-label" for="mdp-suppr">Votre mot de passe</label><input id="mdp-suppr" class="ds-input" type="password" name="mdp" autocomplete="current-password" required></div>
            <div><label class="ds-label" for="conf-suppr">Tapez SUPPRIMER pour confirmer</label><input id="conf-suppr" class="ds-input" type="text" name="confirmation" autocomplete="off" required></div>
            <button class="ds-btn" type="submit" style="background:#9a3b2e;border-color:#9a3b2e" data-confirm="Supprimer définitivement votre compte et les données de vos enfants ?" data-danger data-confirm-label="Supprimer mon compte">Supprimer définitivement mon compte</button>
        </form>
    </section>
</main>
<?php require_once 'partials/footer.php'; ?>

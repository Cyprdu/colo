<?php
// edit_profile.php : mon profil (identité, photo, téléphone, mot de passe, préférences)
require_once 'api/config.php';
require_once 'api/profil.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user']['id'])) { header('Location: login.php?next=edit_profile'); exit; }

profil_ensure($pdo);
$uid = (int)$_SESSION['user']['id'];
$message = ''; $error = '';

if (empty($_SESSION['csrf_profil'])) $_SESSION['csrf_profil'] = bin2hex(random_bytes(16));

$st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$st->execute([$uid]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) { header('Location: login.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf_profil'], (string)($_POST['csrf'] ?? ''))) throw new Exception("Session expirée, rechargez la page.");
        $action = $_POST['action'] ?? 'infos';

        if ($action === 'infos') {
            $nom = trim($_POST['nom'] ?? ''); $prenom = trim($_POST['prenom'] ?? ''); $email = trim($_POST['email'] ?? '');
            $tel = trim($_POST['telephone'] ?? ''); $dn = trim($_POST['date_naissance'] ?? '');
            if ($nom === '' || $prenom === '' || $email === '') throw new Exception("Nom, prénom et e-mail sont obligatoires.");
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Format d'e-mail invalide.");
            if ($tel !== '' && !preg_match('/^[0-9 +().-]{6,30}$/', $tel)) throw new Exception("Numéro de téléphone invalide.");
            if ($dn !== '') {
                $d = DateTime::createFromFormat('Y-m-d', $dn);
                if (!$d || $d->format('Y-m-d') !== $dn || $d > new DateTime() || $d < new DateTime('1900-01-01')) throw new Exception("Date de naissance invalide.");
            }
            $c = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?"); $c->execute([$email, $uid]);
            if ($c->fetch()) throw new Exception("Cet e-mail est déjà utilisé par un autre compte.");
            $pdo->prepare("UPDATE users SET nom=?, prenom=?, email=?, telephone=?, date_naissance=?, newsletter=? WHERE id=?")
                ->execute([$nom, $prenom, $email, $tel ?: null, $dn ?: null, isset($_POST['newsletter']) ? 1 : 0, $uid]);
            $message = "Vos informations ont été enregistrées.";

        } elseif ($action === 'photo') {
            if (!empty($_POST['retirer'])) {
                profil_delete_photo($u['photo_url'] ?? null);
                $pdo->prepare("UPDATE users SET photo_url = NULL WHERE id = ?")->execute([$uid]);
                $message = "Photo supprimée.";
            } else {
                $url = profil_save_photo($_FILES['photo'] ?? []);
                $pdo->prepare("UPDATE users SET photo_url = ? WHERE id = ?")->execute([$url, $uid]);
                profil_delete_photo($u['photo_url'] ?? null);
                $message = "Photo mise à jour.";
            }

        } elseif ($action === 'password') {
            $cur = (string)($_POST['current'] ?? ''); $new = (string)($_POST['new'] ?? ''); $conf = (string)($_POST['confirm'] ?? '');
            if (!empty($u['password']) && !password_verify($cur, $u['password'])) throw new Exception("Mot de passe actuel incorrect.");
            if (strlen($new) < 8) throw new Exception("Le nouveau mot de passe doit faire au moins 8 caractères.");
            if ($new !== $conf) throw new Exception("La confirmation ne correspond pas.");
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            session_regenerate_id(true);
            $message = "Mot de passe modifié.";
        }

        // Session = base : l'en-tête (photo, nom) reflète aussitôt les changements
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        $_SESSION['user'] = array_merge($_SESSION['user'], $u);
    } catch (Throwable $e) {
        if ($e instanceof PDOException) { error_log('edit_profile: ' . $e->getMessage()); $error = "Erreur lors de l'enregistrement."; }
        else $error = $e->getMessage();
    }
}

$v = fn($k) => htmlspecialchars((string)($u[$k] ?? ''));
$csrf = $_SESSION['csrf_profil'];
$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Mes informations - ColoMap</title>

<main class="ds-page ds-fade" style="max-width:760px">
    <a href="profile" class="inline-flex items-center gap-2 text-[14px] font-semibold text-[#3b4a54] hover:text-[#26343b]">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12H4m6-6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Mon espace
    </a>
    <h1 class="ds-h1 mt-5">Mes informations</h1>
    <p class="ds-lead">Votre profil, votre photo et la sécurité de votre compte.</p>

    <?php if ($message): ?><div class="ds-alert-ok mt-6 font-semibold" role="status"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="ds-alert-err mt-6 font-semibold" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <section class="ds-card p-6 sm:p-8 mt-8" aria-labelledby="h-photo">
        <h2 id="h-photo" class="ds-eyebrow mb-5">Photo de profil</h2>
        <form method="POST" enctype="multipart/form-data" class="flex flex-wrap items-center gap-5">
            <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="photo">
            <?php if (!empty($u['photo_url'])): ?>
                <img src="<?= $v('photo_url') ?>" alt="Votre photo" class="w-20 h-20 rounded-full object-cover">
            <?php else: ?>
                <span class="w-20 h-20 rounded-full bg-[#3a4852] text-white text-[30px] font-extrabold flex items-center justify-center"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$u['prenom'] ?: 'U', 0, 1))) ?></span>
            <?php endif; ?>
            <div class="flex-1 min-w-[220px]">
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="block w-full text-[14px] text-[#3b4a54]">
                <p class="text-[12.5px] text-[#6b7780] mt-1">JPEG, PNG ou WebP, 5 Mo maximum. L'image est recadrée en carré.</p>
            </div>
            <div class="flex gap-2">
                <button class="ds-btn" type="submit">Envoyer</button>
                <?php if (!empty($u['photo_url'])): ?><button class="ds-btn ds-btn-light" type="submit" name="retirer" value="1">Retirer</button><?php endif; ?>
            </div>
        </form>
    </section>

    <form method="POST" class="ds-card p-6 sm:p-8 mt-6" aria-labelledby="h-infos">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="infos">
        <h2 id="h-infos" class="ds-eyebrow mb-5">Identité et contact</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div><label class="ds-label" for="prenom">Prénom</label><input class="ds-input" id="prenom" name="prenom" value="<?= $v('prenom') ?>" required autocomplete="given-name"></div>
            <div><label class="ds-label" for="nom">Nom</label><input class="ds-input" id="nom" name="nom" value="<?= $v('nom') ?>" required autocomplete="family-name"></div>
            <div><label class="ds-label" for="email">Adresse e-mail</label><input class="ds-input" id="email" type="email" name="email" value="<?= $v('email') ?>" required autocomplete="email"></div>
            <div><label class="ds-label" for="telephone">Téléphone</label><input class="ds-input" id="telephone" type="tel" name="telephone" value="<?= $v('telephone') ?>" autocomplete="tel" placeholder="06 12 34 56 78"></div>
            <div><label class="ds-label" for="dn">Date de naissance</label><input class="ds-input" id="dn" type="date" name="date_naissance" value="<?= $v('date_naissance') ?>" max="<?= date('Y-m-d') ?>" autocomplete="bday"></div>
        </div>
        <label class="mt-6 flex items-start gap-3 cursor-pointer"><input type="checkbox" name="newsletter" value="1" class="mt-1 w-4 h-4"<?= !empty($u['newsletter']) ? ' checked' : '' ?>>
            <span><span class="block text-[14px] font-semibold text-[#26343b]">Newsletter</span><span class="block text-[12.5px] text-[#6b7780]">Recevoir les actualités et nouveaux séjours par e-mail.</span></span></label>
        <div class="mt-6 flex justify-end"><button class="ds-btn" type="submit">Enregistrer</button></div>
    </form>

    <form method="POST" class="ds-card p-6 sm:p-8 mt-6" aria-labelledby="h-pw">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="password">
        <h2 id="h-pw" class="ds-eyebrow mb-5">Mot de passe</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <?php if (!empty($u['password'])): ?><div class="sm:col-span-2"><label class="ds-label" for="current">Mot de passe actuel</label><input class="ds-input" id="current" type="password" name="current" required autocomplete="current-password"></div><?php endif; ?>
            <div><label class="ds-label" for="new">Nouveau mot de passe</label><input class="ds-input" id="new" type="password" name="new" required minlength="8" autocomplete="new-password" placeholder="8 caractères minimum"></div>
            <div><label class="ds-label" for="confirm">Confirmation</label><input class="ds-input" id="confirm" type="password" name="confirm" required minlength="8" autocomplete="new-password"></div>
        </div>
        <div class="mt-6 flex justify-end"><button class="ds-btn" type="submit">Changer le mot de passe</button></div>
    </form>
</main>

<?php require_once 'partials/footer.php'; ?>

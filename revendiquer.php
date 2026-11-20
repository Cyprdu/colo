<?php
// revendiquer.php?o=<adresse de l'organisme> : l'organisme réel revendique une fiche importée (preuve puis validation par l'équipe).
require_once 'api/config.php';
require_once 'api/security.php';
require_once 'api/adm_core.php';
require_once 'api/organismes.php';
require_once 'api/revendication_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
orga_ensure($pdo);
rev_ensure($pdo);

$slug = strtolower((string)($_GET['o'] ?? ''));
$orga = null;
if (preg_match('/^[a-z0-9-]{1,120}$/', $slug)) {
    $st = $pdo->prepare("SELECT id, nom, slug, web, non_revendique FROM organisateurs WHERE slug = ?");
    $st->execute([$slug]);
    $orga = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$uid = (int)($_SESSION['user']['id'] ?? 0);
$dom = $orga ? rev_domaine($orga['web']) : '';
$err = ''; $info = ''; $done = false;
$v = fn($k) => htmlspecialchars(trim((string)($_POST[$k] ?? ($k === 'prenom' || $k === 'nom' ? ($_SESSION['user'][$k] ?? '') : ''))));

if ($orga && $uid && (int)$orga['non_revendique'] === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $email = strtolower(trim((string)($_POST['email_pro'] ?? '')));
    $key = 'rev:' . $orga['id'];
    if (!csrf_check()) $err = 'Session expirée, rechargez la page.';
    elseif ($act === 'code') {
        if (!rev_email_ok($email, $dom)) $err = "L'adresse doit se terminer par @$dom.";
        elseif (sec_blocked($pdo, 'rev_code', 'u' . $uid, 5, 60)) $err = 'Trop de codes demandés. Réessayez dans une heure.';
        else {
            sec_hit($pdo, 'rev_code', 'u' . $uid);
            $code = (string)random_int(100000, 999999);
            $_SESSION[$key] = ['h' => password_hash($code, PASSWORD_DEFAULT), 'email' => $email, 'exp' => time() + 1800, 'n' => 0];
            rev_mail($email, 'Votre code de vérification moncamp.fr', '<p>Code pour revendiquer la fiche « ' . htmlspecialchars($orga['nom']) . ' » : <b style="font-size:20px;letter-spacing:2px">' . $code . '</b></p><p>Valable 30 minutes. Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet e-mail.</p>');
            $info = "Code envoyé à $email. Saisissez-le ci-dessous puis envoyez la demande.";
        }
    } elseif ($act === 'envoyer') {
        $f = array_map(fn($x) => mb_substr(trim((string)$x), 0, 120), ['prenom' => $_POST['prenom'] ?? '', 'nom' => $_POST['nom'] ?? '', 'fonction' => $_POST['fonction'] ?? '', 'tel' => $_POST['tel'] ?? '']);
        $preuve = (string)($_POST['preuve'] ?? '');
        $verifie = 0; $fichier = null; $ftype = null;
        $st = $pdo->prepare("SELECT COUNT(*) FROM orga_revendications WHERE organisateur_id = ? AND user_id = ? AND statut = 'attente'");
        $st->execute([$orga['id'], $uid]);
        if ($f['prenom'] === '' || $f['nom'] === '' || $f['fonction'] === '') $err = 'Indiquez votre prénom, votre nom et votre fonction.';
        elseif ((int)$st->fetchColumn()) $err = 'Vous avez déjà une demande en cours pour cette fiche.';
        elseif (empty($_POST['atteste'])) $err = "Cochez l'attestation sur l'honneur.";
        elseif ($preuve === 'email') {
            $s = $_SESSION[$key] ?? null;
            if (!$s || $s['exp'] < time() || $s['email'] !== $email || $s['n'] >= 5) $err = 'Demandez un nouveau code de vérification.';
            elseif (!password_verify(preg_replace('/\D/', '', (string)($_POST['code'] ?? '')), $s['h'])) { $_SESSION[$key]['n']++; $err = 'Code incorrect.'; }
            else $verifie = 1;
        } elseif ($preuve === 'doc') {
            $u = $_FILES['justificatif'] ?? null;
            $types = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
            $mime = $u && $u['error'] === UPLOAD_ERR_OK ? (new finfo(FILEINFO_MIME_TYPE))->file($u['tmp_name']) : '';
            if (!isset($types[$mime])) $err = 'Joignez un justificatif PDF, JPG ou PNG.';
            elseif ($u['size'] > REV_MAX) $err = 'Fichier trop lourd (6 Mo maximum).';
            else {
                if (!is_dir(REV_DIR)) { mkdir(REV_DIR, 0750, true); file_put_contents(REV_DIR . '.htaccess', "Require all denied\n"); }
                $fichier = bin2hex(random_bytes(24)) . '.' . $types[$mime]; $ftype = $mime;
                if (!move_uploaded_file($u['tmp_name'], REV_DIR . $fichier)) { $err = "Le fichier n'a pas pu être enregistré."; $fichier = null; }
            }
        } else $err = 'Choisissez une preuve.';
        if ($err === '') {
            $tok = bin2hex(random_bytes(16));
            $pdo->prepare("INSERT INTO orga_revendications (token, organisateur_id, user_id, prenom, nom, fonction, tel, email_pro, email_verifie, fichier, fichier_type, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$tok, $orga['id'], $uid, $f['prenom'], $f['nom'], $f['fonction'], $f['tel'] ?: null, $verifie ? $email : null, $verifie, $fichier, $ftype, mb_substr(trim((string)($_POST['message'] ?? '')), 0, 2000) ?: null]);
            unset($_SESSION[$key]);
            adm_notif_add('revendication', 'r' . $tok, 'Revendication : ' . $orga['nom'] . ' par ' . $f['prenom'] . ' ' . $f['nom'], 'admin_revendications.php', 'orgas.write');
            foreach (adm_staff_emails('orgas.write') as $to) rev_mail($to, 'Nouvelle revendication de fiche', '<p>' . htmlspecialchars($f['prenom'] . ' ' . $f['nom']) . ' revendique « ' . htmlspecialchars($orga['nom']) . ' ».</p><p><a href="' . rtrim(BASE_URL, '/') . '/admin_revendications.php">Examiner la demande</a></p>');
            $done = true;
        }
    }
}

if (!$orga) http_response_code(404);
$page_title = 'Revendiquer une fiche organisme - moncamp.fr';
$page_noindex = true;
$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<main class="ds-page" style="max-width:720px">
<?php if (!$orga): ?>
    <h1 class="ds-h1">Fiche introuvable</h1><p class="ds-lead">Ce lien ne correspond à aucun organisme.</p>
<?php elseif ((int)$orga['non_revendique'] !== 1): ?>
    <h1 class="ds-h1"><?= htmlspecialchars($orga['nom']) ?></h1><p class="ds-lead">Cette fiche est déjà gérée par son organisme. Pour toute question, <a class="underline" href="contact.php">contactez-nous</a>.</p>
<?php elseif ($done): ?>
    <h1 class="ds-h1">Demande envoyée</h1>
    <p class="ds-lead">Merci. Notre équipe vérifie votre demande pour « <?= htmlspecialchars($orga['nom']) ?> » et vous répond par e-mail, en général sous 48 h. Après validation, la fiche et tous ses séjours apparaîtront dans votre espace organisateur.</p>
    <p class="mt-6"><a class="ds-btn" href="organisme/<?= htmlspecialchars($orga['slug']) ?>">Retour à la fiche</a></p>
<?php elseif (!$uid): ?>
    <h1 class="ds-h1">Revendiquer « <?= htmlspecialchars($orga['nom']) ?> »</h1>
    <p class="ds-lead">Cette fiche a été créée par moncamp.fr à partir d'informations publiques. Connectez-vous ou créez un compte : la fiche et ses séjours y seront rattachés après vérification.</p>
    <div class="mt-6 flex flex-wrap gap-3"><a class="ds-btn" href="login.php?redirect=<?= urlencode('/revendiquer?o=' . $orga['slug']) ?>">Se connecter</a><a class="ds-btn ds-btn-light" href="register.php">Créer un compte</a></div>
<?php else: ?>
    <h1 class="ds-h1">Revendiquer « <?= htmlspecialchars($orga['nom']) ?> »</h1>
    <p class="ds-lead">Prouvez que vous représentez cet organisme. Une personne de l'équipe vérifie chaque demande avant de vous confier la fiche et ses séjours.</p>
    <?php if ($err): ?><p class="ds-alert-err mt-5" role="alert"><?= htmlspecialchars($err) ?></p><?php endif; ?>
    <?php if ($info): ?><p class="ds-alert-ok mt-5" role="status"><?= htmlspecialchars($info) ?></p><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="ds-card mt-6 p-6 space-y-5">
        <?= csrf_field() ?>
        <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="ds-label" for="r-prenom">Prénom</label><input class="ds-input" id="r-prenom" name="prenom" required maxlength="80" value="<?= $v('prenom') ?>" autocomplete="given-name"></div>
            <div><label class="ds-label" for="r-nom">Nom</label><input class="ds-input" id="r-nom" name="nom" required maxlength="80" value="<?= $v('nom') ?>" autocomplete="family-name"></div>
            <div><label class="ds-label" for="r-fonction">Fonction dans l'organisme</label><input class="ds-input" id="r-fonction" name="fonction" required maxlength="120" value="<?= $v('fonction') ?>" placeholder="Directeur, président, responsable des séjours…"></div>
            <div><label class="ds-label" for="r-tel">Téléphone (facultatif)</label><input class="ds-input" id="r-tel" name="tel" maxlength="30" value="<?= $v('tel') ?>" autocomplete="tel"></div>
        </div>
        <fieldset>
            <legend class="ds-label">Preuve</legend>
            <?php $pr = (string)($_POST['preuve'] ?? ($dom ? 'email' : 'doc')); ?>
            <?php if ($dom): ?>
            <label class="flex gap-2 items-start text-[15px]"><input type="radio" name="preuve" value="email" class="mt-1"<?= $pr === 'email' ? ' checked' : '' ?>> <span>Une adresse e-mail professionnelle en <b>@<?= htmlspecialchars($dom) ?></b> (domaine du site officiel)</span></label>
            <div class="mt-3 ml-6 grid sm:grid-cols-[1fr_auto] gap-2 items-end" data-preuve="email">
                <div><label class="ds-label" for="r-email">E-mail professionnel</label><input class="ds-input" id="r-email" type="email" name="email_pro" maxlength="190" value="<?= $v('email_pro') ?>" placeholder="prenom@<?= htmlspecialchars($dom) ?>"></div>
                <button class="ds-btn ds-btn-light" type="submit" name="act" value="code" formnovalidate>Recevoir un code</button>
                <div class="sm:col-span-2"><label class="ds-label" for="r-code">Code reçu</label><input class="ds-input" id="r-code" name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" style="max-width:180px"></div>
            </div>
            <?php endif; ?>
            <label class="flex gap-2 items-start text-[15px] mt-4"><input type="radio" name="preuve" value="doc" class="mt-1"<?= $pr === 'doc' ? ' checked' : '' ?>> <span>Une pièce justificative : extrait Kbis, statuts ou récépissé de déclaration d'association, ou attestation sur papier à en-tête</span></label>
            <div class="mt-3 ml-6" data-preuve="doc"><input class="block text-[14px]" type="file" name="justificatif" accept="application/pdf,image/jpeg,image/png" aria-label="Pièce justificative"><p class="text-[13px] text-[#6b7780] mt-1">PDF, JPG ou PNG, 6 Mo maximum. Visible uniquement par l'équipe de vérification.</p></div>
        </fieldset>
        <div><label class="ds-label" for="r-msg">Message (facultatif)</label><textarea class="ds-textarea" id="r-msg" name="message" rows="3" maxlength="2000"><?= $v('message') ?></textarea></div>
        <label class="flex gap-2 items-start text-[14px] text-[#3b4a54]"><input type="checkbox" name="atteste" value="1" class="mt-1" required> <span>J'atteste sur l'honneur représenter cet organisme et être habilité(e) à gérer sa présence sur moncamp.fr.</span></label>
        <button class="ds-btn w-full" type="submit" name="act" value="envoyer">Envoyer la demande</button>
    </form>
    <p class="text-[13px] text-[#6b7780] mt-4">Vous ne souhaitez pas apparaître sur moncamp.fr ? <a class="underline" href="contact.php?motif=<?= urlencode('Retrait de fiche') ?>">Demandez le retrait de la fiche</a>.</p>
<?php endif; ?>
</main>
<?php require_once 'partials/footer.php'; ?>

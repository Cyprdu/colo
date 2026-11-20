<?php
define('ADM_PAGE_2FA', 1);
require_once __DIR__ . '/admin_lib.php';
require_once __DIR__ . '/api/totp_lib.php';
adm_guard();
tfa_ensure($pdo);
$uid = (int)$_SESSION['user']['id'];
$compte = tfa_compte($pdo, $uid);
$requis = tfa_requis(adm_perms_brut($uid));
$err = ''; $codes = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $code = (string)($_POST['code'] ?? '');
    if (adm_limited('2fa', 'u' . $uid, 5, 15)) { $err = 'Trop de tentatives. Réessayez dans 15 minutes.'; adm_audit('2fa_bloque'); }
    elseif ($act === 'activer' && !$compte) {
        $s = (string)($_SESSION['adm_2fa_nouveau'] ?? '');
        $c6 = preg_replace('/\D/', '', $code);
        if ($s !== '' && in_array(true, array_map(fn($d) => hash_equals(tfa_code($s, intdiv(time(), 30) + $d), $c6), [-1, 0, 1]), true)) {
            $codes = tfa_activer($pdo, $uid, $s);
            unset($_SESSION['adm_2fa_nouveau']);
            session_regenerate_id(true);
            $_SESSION['adm_2fa'] = $uid;
            adm_audit('2fa_activee');
            $compte = tfa_compte($pdo, $uid);
        } else { adm_hit('2fa', 'u' . $uid); $err = 'Code incorrect. Vérifiez l\'heure de votre téléphone et réessayez.'; }
    } elseif ($act === 'verifier' && $compte) {
        if (tfa_verifier($pdo, $uid, $code)) {
            session_regenerate_id(true);
            $_SESSION['adm_2fa'] = $uid;
            adm_audit('2fa_ok', null, preg_match('/^\d{6}$/', trim($code)) ? 'application' : 'code de secours');
            $go = (string)($_SESSION['adm_2fa_retour'] ?? 'admin.php');
            unset($_SESSION['adm_2fa_retour']);
            header('Location: ' . (preg_match('#^admin[a-z_0-9]*(\.php)?(\?[^\s]*)?$#', $go) ? $go : 'admin.php')); exit;
        }
        adm_hit('2fa', 'u' . $uid); adm_audit('2fa_echec'); $err = 'Code incorrect.';
    } elseif ($act === 'codes' && $compte && tfa_ok($uid)) {
        [$codes, $h] = tfa_codes_secours();
        $pdo->prepare("UPDATE adm_2fa SET secours = ? WHERE user_id = ?")->execute([json_encode($h), $uid]);
        adm_audit('2fa_codes_regeneres');
    }
}
if (!$compte && empty($_SESSION['adm_2fa_nouveau'])) $_SESSION['adm_2fa_nouveau'] = tfa_b32(random_bytes(20));
$secret = (string)($_SESSION['adm_2fa_nouveau'] ?? '');
$uri = 'otpauth://totp/' . rawurlencode('moncamp.fr:' . ($_SESSION['user']['email'] ?? '')) . '?secret=' . $secret . '&issuer=moncamp.fr&digits=6&period=30';
$restants = $compte ? count(json_decode($compte['secours'], true) ?: []) : 0;

adm_head('Double authentification');
?>
<?php if ($err): ?><div class="note err"><?= h($err) ?></div><?php endif; ?>
<?php if ($codes): ?>
<section class="card"><h2 class="sec"><?= ic('key') ?> Vos codes de secours</h2>
  <p>Chaque code sert <b>une seule fois</b> si vous n'avez plus votre téléphone. Notez-les maintenant dans un endroit sûr : ils ne seront plus affichés.</p>
  <ul class="mono" style="columns:2;max-width:380px;font-size:16px;line-height:1.9"><?php foreach ($codes as $c): ?><li><?= h($c) ?></li><?php endforeach; ?></ul>
  <a class="btn mt" href="admin.php"><?= ic('check') ?> J'ai noté mes codes</a></section>
<?php elseif (!$compte): ?>
<section class="card" style="max-width:640px"><h2 class="sec"><?= ic('shield') ?> Activer la double authentification</h2>
  <p><?= $requis ? 'Obligatoire pour votre rôle (finances, technique ou administrateur suprême).' : 'Recommandée pour protéger votre accès.' ?> Il vous faut une application d'authentification : Google Authenticator, Microsoft Authenticator, 1Password…</p>
  <ol class="stack" style="padding-left:18px;list-style:decimal">
    <li>Sur votre téléphone, <a class="lnk-q" href="<?= h($uri) ?>">ouvrez ce lien</a>, ou dans l'application choisissez « Saisir une clé de configuration » :
      <div class="mono" style="font-size:18px;letter-spacing:2px;margin:8px 0;word-break:break-all"><?= h(implode(' ', str_split($secret, 4))) ?></div>
      <span class="muted small">Compte : moncamp.fr · type : basé sur l'heure</span></li>
    <li>Saisissez le code à 6 chiffres affiché par l'application :</li>
  </ol>
  <form method="post" class="row-l wrap-row"><input type="hidden" name="_csrf" value="<?= h(adm_csrf()) ?>"><input type="hidden" name="act" value="activer">
    <input class="in" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required placeholder="123456" style="max-width:160px;font-size:18px" autofocus>
    <button class="btn" type="submit"><?= ic('check') ?> Activer</button></form></section>
<?php elseif (!tfa_ok($uid)): ?>
<section class="card" style="max-width:520px"><h2 class="sec"><?= ic('lock') ?> Code de vérification</h2>
  <p>Saisissez le code à 6 chiffres de votre application d'authentification, ou un code de secours.</p>
  <form method="post" class="row-l wrap-row"><input type="hidden" name="_csrf" value="<?= h(adm_csrf()) ?>"><input type="hidden" name="act" value="verifier">
    <input class="in" name="code" autocomplete="one-time-code" maxlength="20" required placeholder="123456" style="max-width:200px;font-size:18px" autofocus>
    <button class="btn" type="submit"><?= ic('check') ?> Valider</button></form>
  <p class="muted small mt">Téléphone perdu et plus de codes de secours ? Demandez à l'administrateur suprême de réinitialiser votre double authentification.</p></section>
<?php else: ?>
<section class="card" style="max-width:640px"><h2 class="sec"><?= ic('shield') ?> Double authentification active</h2>
  <p>Activée le <?= h(adm_date($compte['active_at'])) ?>. Codes de secours restants : <b><?= $restants ?></b> / 10.</p>
  <form method="post"><input type="hidden" name="_csrf" value="<?= h(adm_csrf()) ?>"><input type="hidden" name="act" value="codes">
    <button class="btn ghost sm" type="submit"><?= ic('refresh') ?> Générer de nouveaux codes de secours</button></form></section>
<?php endif; ?>
<?php adm_foot(); ?>

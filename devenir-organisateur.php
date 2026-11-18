<?php
require_once 'api/config.php';
require_once __DIR__ . '/api/security.php';
require_once __DIR__ . '/api/demande_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user']['id'])) { header('Location: login.php?next=devenir-organisateur'); exit; }
$uid = (int)$_SESSION['user']['id'];
$st = $pdo->prepare("SELECT is_directeur, is_verified, email, prenom, nom FROM users WHERE id = ?");
$st->execute([$uid]);
$me = $st->fetch(PDO::FETCH_ASSOC) ?: [];
if (!empty($me['is_directeur'])) $_SESSION['user']['is_directeur'] = 1;
$d = demande_current($pdo, $uid);
$err = []; $ok = '';
$editable = !$d || in_array($d['statut'], ['complements', 'refusee'], true);
$f = $d ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($me['is_directeur'])) {
    [$f, $err] = demande_fields($_POST);
    if (!csrf_check()) $err = ['Session expirée : rechargez la page.'];
    elseif (!$editable) $err = ['Votre demande est déjà en cours d’examen.'];
    elseif (sec_blocked($pdo, 'demande_orga', (string)$uid, 5, 1440)) $err = ['Trop de demandes aujourd’hui. Réessayez demain.'];
    if (!$err) {
        sec_hit($pdo, 'demande_orga', (string)$uid);
        $qui = trim(($me['prenom'] ?? '') . ' ' . ($me['nom'] ?? ''));
        if ($d && $d['statut'] === 'complements') {
            $pdo->prepare("UPDATE demandes_organisateur SET structure = ?, type_structure = ?, siret = ?, site = ?, tel = ?, ville = ?, sejours_an = ?, agrement = ?, message = ?, statut = 'attente' WHERE id = ?")
                ->execute([$f['structure'], $f['type_structure'], $f['siret'], $f['site'], $f['tel'], $f['ville'], $f['sejours_an'], $f['agrement'], $f['message'], $d['id']]);
            $did = (int)$d['id']; $tok = $d['token'];
            demande_event($pdo, $did, 'complete', 'Demande complétée par le demandeur.', $uid, $qui);
        } else {
            $tok = bin2hex(random_bytes(16));
            $pdo->prepare("INSERT INTO demandes_organisateur (token, user_id, structure, type_structure, siret, site, tel, ville, sejours_an, agrement, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$tok, $uid, $f['structure'], $f['type_structure'], $f['siret'], $f['site'], $f['tel'], $f['ville'], $f['sejours_an'], $f['agrement'], $f['message']]);
            $did = (int)$pdo->lastInsertId();
            demande_event($pdo, $did, 'creee', null, $uid, $qui);
        }
        $pdo->prepare("UPDATE users SET demande_en_cours = 1, is_refused = 0 WHERE id = ?")->execute([$uid]);
        $_SESSION['user']['demande_en_cours'] = 1;
        adm_notif_add('demande', 'd' . $did . ':' . time(), 'Demande organisateur : ' . $f['structure'], 'admin_demandes.php?d=' . $tok, 'demandes.read');
        foreach (adm_staff_emails('demandes.write') as $to) adm_mail($to, 'Nouvelle demande organisateur : ' . $f['structure'], '<p>' . htmlspecialchars($qui . ' (' . ($me['email'] ?? '') . ')') . ' demande un accès organisateur pour <b>' . htmlspecialchars($f['structure']) . '</b>.</p><p><a href="' . htmlspecialchars(demande_site_url() . '/admin_demandes.php?d=' . $tok) . '">Examiner la demande</a></p>');
        header('Location: devenir-organisateur.php?envoye=1'); exit;
    }
}

$body_class = 'bg-white';
require_once 'partials/header.php';
$types = demande_types();
$val = fn($k) => htmlspecialchars((string)($f[$k] ?? ''));
?>
<title>Devenir organisateur - moncamp.fr</title>
<main class="ds-page">
  <div class="max-w-2xl">
    <h1 class="ds-h1">Devenir organisateur</h1>
    <p class="ds-lead">Publiez vos séjours sur moncamp.fr. Chaque demande est vérifiée par notre équipe, en général sous 48 heures.</p>

    <?php if (!empty($me['is_directeur'])): ?>
      <div class="ds-card p-6 mt-8"><p class="font-bold text-[#26343b]">Votre accès organisateur est actif.</p><a class="ds-btn mt-4" href="organisateurs">Ouvrir l'espace organisateur</a></div>
    <?php elseif ($d && $d['statut'] === 'attente'): ?>
      <div class="ds-card p-6 mt-8">
        <p class="font-bold text-[#26343b]"><?= isset($_GET['envoye']) ? 'Demande envoyée, merci !' : 'Demande en cours d’examen' ?></p>
        <p class="text-[14.5px] text-[#6b7780] mt-1">Structure : <b><?= $val('structure') ?></b> · envoyée le <?= date('d/m/Y', strtotime($d['created_at'])) ?>. Vous recevrez un e-mail dès la décision.</p>
      </div>
    <?php else: ?>
      <?php if ($d && $d['statut'] === 'complements'): ?>
        <div class="mt-8 rounded-2xl border border-[#ebe3c8] bg-[#f8f5ea] p-5"><p class="font-bold text-[#5b4a1c]">Notre équipe a besoin de compléments</p><p class="text-[14.5px] text-[#5b4a1c] mt-1 whitespace-pre-line"><?= htmlspecialchars((string)$d['motif']) ?></p></div>
      <?php elseif ($d && $d['statut'] === 'refusee'): ?>
        <div class="mt-8 rounded-2xl border border-[#ebc5c5] bg-[#f8e4e4] p-5"><p class="font-bold text-[#9b2c2c]">Votre précédente demande n'a pas été acceptée</p><?php if ($d['motif']): ?><p class="text-[14.5px] text-[#9b2c2c] mt-1 whitespace-pre-line"><?= htmlspecialchars((string)$d['motif']) ?></p><?php endif; ?><p class="text-[13.5px] text-[#6b7780] mt-2">Vous pouvez envoyer une nouvelle demande.</p></div>
      <?php endif; ?>
      <?php if (empty($me['is_verified'])): ?><p class="mt-6 text-[14px] text-[#8a5a12]">Pensez à confirmer votre adresse e-mail : c’est l’un des contrôles de votre demande.</p><?php endif; ?>
      <?php if ($err): ?><div class="mt-6 rounded-2xl border border-[#ebc5c5] bg-[#f8e4e4] p-4 text-[14px] text-[#9b2c2c]"><?php foreach ($err as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?></div><?php endif; ?>
      <form method="post" class="ds-card p-6 mt-8 space-y-5">
        <?= csrf_field() ?>
        <div><label class="ds-label" for="o-structure">Nom de la structure</label><input id="o-structure" name="structure" class="ds-input" required maxlength="160" value="<?= $val('structure') ?>"></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <div><label class="ds-label" for="o-type">Type</label><select id="o-type" name="type_structure" class="ds-select" required><option value="">Choisir…</option><?php foreach ($types as $k => $l): ?><option value="<?= $k ?>"<?= ($f['type_structure'] ?? '') === $k ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?></select></div>
          <div><label class="ds-label" for="o-siret">SIRET</label><input id="o-siret" name="siret" class="ds-input" inputmode="numeric" maxlength="20" placeholder="14 chiffres" value="<?= $val('siret') ?>"></div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <div><label class="ds-label" for="o-site">Site web</label><input id="o-site" name="site" class="ds-input" maxlength="255" placeholder="www.exemple.fr" value="<?= $val('site') ?>"></div>
          <div><label class="ds-label" for="o-tel">Téléphone</label><input id="o-tel" name="tel" class="ds-input" type="tel" required maxlength="30" value="<?= $val('tel') ?>"></div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <div><label class="ds-label" for="o-ville">Ville du siège</label><input id="o-ville" name="ville" class="ds-input" maxlength="120" value="<?= $val('ville') ?>"></div>
          <div><label class="ds-label" for="o-nb">Séjours par an</label><select id="o-nb" name="sejours_an" class="ds-select"><option value="">—</option><?php foreach (['1' => '1', '2-5' => '2 à 5', '6-20' => '6 à 20', '20+' => 'Plus de 20'] as $k => $l): ?><option value="<?= $k ?>"<?= ($f['sejours_an'] ?? '') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        </div>
        <div><label class="ds-label" for="o-agr">Agrément ou déclaration (facultatif)</label><input id="o-agr" name="agrement" class="ds-input" maxlength="120" placeholder="Ex. agrément JEP, déclaration SDJES…" value="<?= $val('agrement') ?>"></div>
        <div><label class="ds-label" for="o-msg">Présentez votre activité</label><textarea id="o-msg" name="message" rows="6" required maxlength="3000" class="ds-textarea" placeholder="Public accueilli, types de séjours, expérience…"><?= $val('message') ?></textarea></div>
        <button class="ds-btn w-full" type="submit"><?= $d && $d['statut'] === 'complements' ? 'Envoyer les compléments' : 'Envoyer ma demande' ?></button>
      </form>
    <?php endif; ?>
  </div>
</main>
<?php require_once 'partials/footer.php'; ?>

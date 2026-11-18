<?php
require_once __DIR__ . '/admin_lib.php';
adm_guard(false, 'contacts.read');
$uid = (int)$_SESSION['user']['id'];
$canW = adm_can('contacts.write');
$dec = fn($v) => html_entity_decode((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

$dossiers = ['inbox' => ['Boîte de réception', 'inbox'], 'archive' => ['Archivés', 'archive'], 'spam' => ['Spam', 'flag']];
$etats = ['nouveau' => 'Nouveau', 'en_cours' => 'En cours', 'traite' => 'Traité'];
$d = isset($dossiers[$_GET['d'] ?? '']) ? (string)$_GET['d'] : 'inbox';
$e = isset($etats[$_GET['e'] ?? '']) ? (string)$_GET['e'] : '';
$lu = in_array($_GET['lu'] ?? '', ['0', '1'], true) ? (string)$_GET['lu'] : '';
$mine = ($_GET['moi'] ?? '') === '1';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$page = max(1, (int)($_GET['page'] ?? 1)); $per = 25;
$mtok = preg_match('/^[a-f0-9]{64}$/', (string)($_GET['m'] ?? '')) ? (string)$_GET['m'] : '';

$where = 'm.dossier = ?'; $p = [$d];
if ($e !== '') { $where .= ' AND m.etat = ?'; $p[] = $e; }
if ($lu !== '') { $where .= ' AND m.lu = ?'; $p[] = (int)$lu; }
if ($mine) { $where .= ' AND m.assigne_a = ?'; $p[] = $uid; }
if ($q !== '') { $like = '%' . addcslashes($q, '%_\\') . '%'; $where .= ' AND (m.nom LIKE ? OR m.prenom LIKE ? OR m.email LIKE ? OR m.motif LIKE ? OR m.message LIKE ?)'; array_push($p, $like, $like, $like, $like, $like); }
$total = (int)adm_one("SELECT COUNT(*) FROM contact_messages m WHERE $where", $p);
$pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
$rows = adm_rows("SELECT m.id, m.token, m.nom, m.prenom, m.email, m.motif, LEFT(m.message, 140) AS apercu, m.created_at, m.lu, m.etat, m.spam_raison, a.prenom AS a_prenom
    FROM contact_messages m LEFT JOIN users a ON a.id = m.assigne_a WHERE $where ORDER BY m.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
$cnt = [];
foreach (adm_rows("SELECT dossier, COUNT(*) c FROM contact_messages WHERE lu = 0 GROUP BY dossier") as $r) $cnt[$r['dossier']] = (int)$r['c'];

$cur = null; $replies = []; $notes = []; $staff = [];
if ($mtok !== '') {
    $cur = adm_rows("SELECT m.*, u.email AS u_email FROM contact_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.token = ?", [$mtok])[0] ?? null;
    if ($cur) {
        if (!(int)$cur['lu']) { $pdo->prepare("UPDATE contact_messages SET lu = 1 WHERE id = ?")->execute([$cur['id']]); $cur['lu'] = 1; }
        $pdo->prepare("INSERT IGNORE INTO adm_notif_lus (notif_id, user_id) SELECT id, ? FROM adm_notifs WHERE type = 'contact' AND ref = ?")->execute([$uid, 'm' . $cur['id']]);
        $replies = adm_rows("SELECT auteur, texte, envoye, created_at FROM contact_reponses WHERE message_id = ? ORDER BY id", [$cur['id']]);
        $notes = adm_rows("SELECT auteur, texte, created_at FROM adm_notes WHERE objet = 'contact' AND ref = ? ORDER BY id DESC", [$mtok]);
        $staff = array_filter(adm_rows("SELECT id, prenom, nom FROM users WHERE is_admin = 1 ORDER BY prenom"), fn($s) => adm_can('contacts.read', (int)$s['id']));
    }
}
$url = fn(array $o = []) => 'admin_contacts.php?' . http_build_query(array_filter(array_merge(['d' => $d === 'inbox' ? '' : $d, 'e' => $e, 'lu' => $lu, 'moi' => $mine ? '1' : '', 'q' => $q, 'page' => $page > 1 ? $page : '', 'm' => $mtok], $o), fn($v) => $v !== '' && $v !== null));

adm_head('Messages de contact');
?>
<div class="toolbar">
  <div class="seg">
  <?php foreach ($dossiers as $k => [$l, $icon]): ?>
    <a class="<?= $k === $d ? 'on' : '' ?>" href="<?= h($url(['d' => $k === 'inbox' ? '' : $k, 'page' => '', 'm' => ''])) ?>"><?= ic($icon, 'ic ic-sm') ?> <?= h($l) ?><?php if (!empty($cnt[$k]) && $k !== 'spam'): ?> <b><?= $cnt[$k] ?></b><?php endif; ?></a>
  <?php endforeach; ?>
  </div>
  <form class="bar inl-bar" method="get" action="admin_contacts.php">
    <?php if ($d !== 'inbox'): ?><input type="hidden" name="d" value="<?= h($d) ?>"><?php endif; ?>
    <label class="search"><?= ic('search') ?><input class="in" type="search" name="q" value="<?= h($q) ?>" placeholder="Nom, e-mail, motif, texte…" maxlength="80"></label>
    <select class="in sel" name="e" aria-label="Statut" data-autosubmit><option value="">Tous les statuts</option><?php foreach ($etats as $k => $l): ?><option value="<?= $k ?>"<?= $k === $e ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
    <select class="in sel" name="lu" aria-label="Lecture" data-autosubmit><option value="">Lus et non lus</option><option value="0"<?= $lu === '0' ? ' selected' : '' ?>>Non lus</option><option value="1"<?= $lu === '1' ? ' selected' : '' ?>>Lus</option></select>
    <label class="chk"><input type="checkbox" name="moi" value="1"<?= $mine ? ' checked' : '' ?> data-autosubmit> Assignés à moi</label>
  </form>
</div>
<?php if ($d === 'spam'): ?><div class="note warn"><?= ic('flag') ?> Messages classés automatiquement comme indésirables (pot de miel, envoi trop rapide, liens, mots-clés). Vérifiez-les de temps en temps : « Boîte de réception » les remet en place.</div><?php endif; ?>

<div class="split">
  <section class="card flush">
    <?php if ($canW): ?>
    <div class="bulk" id="bulk" hidden><b><span data-selcount>0</span> sélectionné(s)</b>
      <button class="btn ghost sm" type="button" data-api="contact.bulk" data-sel="1" data-op="read">Lu</button>
      <button class="btn ghost sm" type="button" data-api="contact.bulk" data-sel="1" data-op="unread">Non lu</button>
      <?php if ($d !== 'archive'): ?><button class="btn ghost sm" type="button" data-api="contact.bulk" data-sel="1" data-op="archive">Archiver</button><?php endif; ?>
      <?php if ($d !== 'inbox'): ?><button class="btn ghost sm" type="button" data-api="contact.bulk" data-sel="1" data-op="inbox">Boîte de réception</button><?php endif; ?>
      <?php if ($d !== 'spam'): ?><button class="btn ghost sm" type="button" data-api="contact.bulk" data-sel="1" data-op="spam">Spam</button><?php endif; ?>
      <button class="btn ghost sm" type="button" data-api="contact.bulk" data-sel="1" data-op="delete" data-confirm="Supprimer définitivement les messages sélectionnés ? Cette action est irréversible.">Supprimer</button>
    </div>
    <?php endif; ?>
    <div class="list-h"><?php if ($canW && $rows): ?><label class="chk-l"><input type="checkbox" data-selall> Tout sélectionner</label><?php endif; ?><span class="count"><?= number_format($total, 0, ',', ' ') ?> message<?= $total > 1 ? 's' : '' ?></span></div>
    <div class="list">
    <?php if (!$rows): ?><?= adm_empty('inbox', $q !== '' ? 'Aucun message ne correspond.' : 'Aucun message ici.') ?><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <div class="it<?= $r['lu'] ? '' : ' unread' ?><?= $r['token'] === $mtok ? ' on' : '' ?>">
        <?php if ($canW): ?><input type="checkbox" name="sel[]" value="<?= h(adm_tok('cm', (int)$r['id'])) ?>" aria-label="Sélectionner"><?php endif; ?>
        <a class="it-main" href="<?= h($url(['m' => $r['token']])) ?>">
          <div class="t1"><b><?= h(trim($dec($r['prenom']) . ' ' . $dec($r['nom']))) ?></b><span class="muted small"><?= h(adm_date($r['created_at'])) ?></span></div>
          <div class="t2"><?= h($dec($r['motif'])) ?> · <?= h($dec($r['apercu'])) ?></div>
          <div class="t2"><span class="badge-st <?= h($r['etat']) ?>"><?= h($etats[$r['etat']] ?? $r['etat']) ?></span><?= $r['a_prenom'] ? ' · ' . h($r['a_prenom']) : '' ?><?= $r['spam_raison'] ? ' · ' . h($r['spam_raison']) : '' ?></div>
        </a>
      </div>
    <?php endforeach; ?>
    </div>
    <?= adm_pager($page, $pages, fn($n) => $url(['page' => $n])) ?>
  </section>

  <section class="card panel">
  <?php if (!$cur): ?>
    <?= adm_empty('mail', 'Choisissez un message pour le lire.') ?>
  <?php else: $mt = adm_tok('cm', (int)$cur['id']); ?>
    <div class="row-l wrap-row">
      <h2 class="h3 grow"><?= h($dec($cur['motif'])) ?></h2>
      <a class="btn ghost sm" href="mailto:<?= h(rawurlencode($cur['email'])) ?>?subject=<?= h(rawurlencode('Re: ' . $dec($cur['motif']))) ?>"><?= ic('external') ?> Client mail</a>
    </div>
    <dl class="dl">
      <dt>De</dt><dd><?= h(trim($dec($cur['prenom']) . ' ' . $dec($cur['nom']))) ?> · <?= h($cur['email']) ?></dd>
      <dt>Compte</dt><dd><?php if ($cur['user_id'] && adm_can('users.read')): ?><a class="lnk" href="admin_user.php?t=<?= h(adm_tok('u', (int)$cur['user_id'])) ?>">Voir la fiche</a><?php elseif ($cur['user_id']): ?>Compte connecté<?php else: ?><span class="muted">Visiteur non connecté</span><?php endif; ?></dd>
      <dt>Reçu le</dt><dd><?= h(adm_date($cur['created_at'])) ?><?= $cur['ip'] ? ' · IP ' . h($cur['ip']) : '' ?></dd>
      <?php if ($cur['spam_raison']): ?><dt>Spam</dt><dd class="neg"><?= h($cur['spam_raison']) ?></dd><?php endif; ?>
    </dl>
    <?php if ($canW): ?>
    <form class="row-l wrap-row" data-api="contact.set" data-ok="Enregistré.">
      <input type="hidden" name="m" value="<?= h($mt) ?>">
      <label class="fld-inline">Statut <select class="in sel" name="etat"><?php foreach ($etats as $k => $l): ?><option value="<?= $k ?>"<?= $k === $cur['etat'] ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
      <label class="fld-inline">Assigné à <select class="in sel" name="assigne"><option value="">Personne</option><?php foreach ($staff as $s): $st = adm_tok('u', (int)$s['id']); ?><option value="<?= h($st) ?>"<?= (int)$cur['assigne_a'] === (int)$s['id'] ? ' selected' : '' ?>><?= h(trim($s['prenom'] . ' ' . $s['nom'])) ?></option><?php endforeach; ?></select></label>
      <button class="btn ghost sm" type="submit"><?= ic('check') ?> Appliquer</button>
    </form>
    <?php endif; ?>
    <div class="thread">
      <div class="msg"><header><b><?= h($dec($cur['prenom'])) ?></b><span><?= h(adm_date($cur['created_at'])) ?></span></header><p><?= h($dec($cur['message'])) ?></p></div>
      <?php if (!$replies && $cur['reponse']): ?><div class="msg out"><header><b>Réponse</b><span><?= h(adm_date($cur['replied_at'])) ?></span></header><p><?= h($dec(strip_tags(str_replace(['<br />', '<br>'], "\n", (string)$cur['reponse'])))) ?></p></div><?php endif; ?>
      <?php foreach ($replies as $r): ?>
        <div class="msg out"><header><b><?= h($r['auteur']) ?></b><span><?= h(adm_date($r['created_at'])) ?><?= $r['envoye'] ? '' : ' · e-mail non envoyé' ?></span></header><p><?= h($r['texte']) ?></p></div>
      <?php endforeach; ?>
    </div>
    <?php if ($canW && $cur['dossier'] !== 'spam'): ?>
    <form class="stack mt" data-api="contact.reply" data-ok="Réponse envoyée.">
      <input type="hidden" name="m" value="<?= h($mt) ?>">
      <label class="fld"><span class="fl">Répondre par e-mail <?= adm_help('Le texte est envoyé à l’adresse du contact, avec un rappel de sa demande. Il reste consultable sur le site via le lien de réponse.') ?></span>
        <textarea class="in" name="texte" rows="6" maxlength="5000" required placeholder="Bonjour, …"></textarea></label>
      <div class="row-l wrap-row"><label class="chk-l"><input type="checkbox" name="traite" value="1" checked> Marquer comme traité</label><span class="grow"></span><button class="btn" type="submit"><?= ic('send') ?> Envoyer</button></div>
    </form>
    <?php endif; ?>
    <?php if ($canW): ?>
    <div class="row-l wrap-row mt">
      <?php if ($cur['dossier'] !== 'archive'): ?><button class="btn ghost sm" type="button" data-api="contact.bulk" data-ids="<?= h($mt) ?>" data-op="archive"><?= ic('archive') ?> Archiver</button><?php endif; ?>
      <?php if ($cur['dossier'] !== 'inbox'): ?><button class="btn ghost sm" type="button" data-api="contact.bulk" data-ids="<?= h($mt) ?>" data-op="inbox"><?= ic('inbox') ?> Boîte de réception</button><?php endif; ?>
      <?php if ($cur['dossier'] !== 'spam'): ?><button class="btn ghost sm" type="button" data-api="contact.bulk" data-ids="<?= h($mt) ?>" data-op="spam"><?= ic('flag') ?> Spam</button><?php endif; ?>
      <button class="btn ghost sm" type="button" data-api="contact.bulk" data-ids="<?= h($mt) ?>" data-op="unread" data-go="<?= h($url(['m' => ''])) ?>"><?= ic('mail') ?> Non lu</button>
      <button class="btn ghost sm danger-t" type="button" data-api="contact.bulk" data-ids="<?= h($mt) ?>" data-op="delete" data-go="<?= h($url(['m' => ''])) ?>" data-confirm="Supprimer définitivement ce message ?"><?= ic('trash') ?> Supprimer</button>
    </div>
    <?php endif; ?>
    <?= adm_notes_box('contact', $mtok, $notes) ?>
  <?php endif; ?>
  </section>
</div>
<?php adm_foot(); ?>

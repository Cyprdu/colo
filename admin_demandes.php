<?php
require_once __DIR__ . '/admin_lib.php';
require_once __DIR__ . '/api/demande_lib.php';
adm_guard(false, 'demandes.read');
$canW = adm_can('demandes.write');
$st = demande_statuts();
$types = demande_types();
$s = isset($st[$_GET['s'] ?? '']) || ($_GET['s'] ?? '') === 'toutes' ? (string)$_GET['s'] : 'attente';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$page = max(1, (int)($_GET['page'] ?? 1)); $per = 25;
$dtok = preg_match('/^[a-f0-9]{32}$/', (string)($_GET['d'] ?? '')) ? (string)$_GET['d'] : '';

$where = $s === 'toutes' ? '1=1' : 'd.statut = ?'; $p = $s === 'toutes' ? [] : [$s];
if ($q !== '') { $like = '%' . addcslashes($q, '%_\\') . '%'; $where .= ' AND (d.structure LIKE ? OR d.siret LIKE ? OR u.email LIKE ? OR CONCAT_WS(\' \', u.prenom, u.nom) LIKE ?)'; array_push($p, $like, $like, $like, $like); }
$total = (int)adm_one("SELECT COUNT(*) FROM demandes_organisateur d JOIN users u ON u.id = d.user_id WHERE $where", $p);
$pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
$rows = adm_rows("SELECT d.token, d.structure, d.type_structure, d.statut, d.created_at, d.siret, u.prenom, u.nom, u.email, u.is_verified
    FROM demandes_organisateur d JOIN users u ON u.id = d.user_id WHERE $where ORDER BY d.statut = 'attente' DESC, d.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
$cnt = [];
foreach (adm_rows("SELECT statut, COUNT(*) c FROM demandes_organisateur GROUP BY statut") as $r) $cnt[$r['statut']] = (int)$r['c'];

$d = null;
if ($dtok !== '') {
    $d = adm_rows("SELECT d.*, u.prenom, u.nom, u.email, u.tel AS u_tel, u.created_at AS u_created, u.is_verified, u.is_directeur, u.bloque, u.google_id
        FROM demandes_organisateur d JOIN users u ON u.id = d.user_id WHERE d.token = ?", [$dtok])[0] ?? null;
    if ($d) {
        $pdo->prepare("INSERT IGNORE INTO adm_notif_lus (notif_id, user_id) SELECT id, ? FROM adm_notifs WHERE type = 'demande' AND ref LIKE ?")->execute([(int)$_SESSION['user']['id'], 'd' . $d['id'] . ':%']);
        $evts = adm_rows("SELECT qui, action, texte, created_at FROM demandes_organisateur_evts WHERE demande_id = ? ORDER BY id DESC", [$d['id']]);
        $autres = adm_rows("SELECT token, structure, statut, created_at FROM demandes_organisateur WHERE user_id = ? AND id <> ? ORDER BY id DESC", [$d['user_id'], $d['id']]);
        $stats = ['enfants' => (int)adm_one("SELECT COUNT(*) FROM enfants WHERE parent_id = ?", [$d['user_id']]), 'orgas' => (int)adm_one("SELECT COUNT(*) FROM organisateurs WHERE user_id = ?", [$d['user_id']])];
        $dupSiret = $d['siret'] ? (int)adm_one("SELECT COUNT(*) FROM demandes_organisateur WHERE siret = ? AND id <> ? AND statut = 'validee'", [$d['siret'], $d['id']]) : 0;
        $dupNom = (int)adm_one("SELECT COUNT(*) FROM organisateurs WHERE nom = ?", [$d['structure']]);
        $mailDom = strtolower(substr(strrchr((string)$d['email'], '@'), 1));
        $siteDom = $d['site'] ? strtolower(preg_replace('/^www\./', '', (string)parse_url($d['site'], PHP_URL_HOST))) : '';
        $notes = adm_notes('demande', $dtok);
    }
}
$url = fn(array $o = []) => 'admin_demandes.php?' . http_build_query(array_filter(array_merge(['s' => $s === 'attente' ? '' : $s, 'q' => $q, 'page' => $page > 1 ? $page : '', 'd' => $dtok], $o), fn($v) => $v !== '' && $v !== null));
$chk = fn(?bool $ok, string $yes, string $no, string $na = 'Non renseigné') => $ok === null ? '<span class="chk-na">' . h($na) . '</span>' : ($ok ? '<span class="chk-ok">✓ ' . h($yes) . '</span>' : '<span class="chk-ko">✗ ' . h($no) . '</span>');
$pillSt = ['attente' => 'warn', 'complements' => 'info', 'validee' => 'ok', 'refusee' => 'bad'];

adm_head('Demandes organisateur');
?>
<div class="toolbar">
  <div class="seg">
    <?php foreach ($st + ['toutes' => 'Toutes'] as $k => $l): ?><a class="<?= $k === $s ? 'on' : '' ?>" href="<?= h($url(['s' => $k === 'attente' ? '' : $k, 'page' => '', 'd' => ''])) ?>"><?= h($l) ?><?php if (!empty($cnt[$k]) && in_array($k, ['attente', 'complements'], true)): ?> <b><?= $cnt[$k] ?></b><?php endif; ?></a><?php endforeach; ?>
  </div>
  <form class="bar inl-bar" method="get" action="admin_demandes.php"><?php if ($s !== 'attente'): ?><input type="hidden" name="s" value="<?= h($s) ?>"><?php endif; ?>
    <label class="search"><?= ic('search') ?><input class="in" type="search" name="q" value="<?= h($q) ?>" placeholder="Structure, SIRET, demandeur…" maxlength="80"></label></form>
  <?= adm_help('Une demande arrive par la page « Devenir organisateur ». Valider donne l’accès organisateur au compte ; le demandeur est prévenu par e-mail à chaque décision.') ?>
</div>

<div class="split">
  <section class="card flush">
    <div class="list-h"><span class="count"><?= number_format($total, 0, ',', ' ') ?> demande<?= $total > 1 ? 's' : '' ?></span></div>
    <div class="list">
    <?php if (!$rows): ?><?= adm_empty('user-check', $s === 'attente' ? 'Aucune demande en attente.' : 'Aucune demande.') ?><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <div class="it<?= $r['token'] === $dtok ? ' on' : '' ?><?= $r['statut'] === 'attente' ? ' unread' : '' ?>">
        <a class="it-main" href="<?= h($url(['d' => $r['token']])) ?>">
          <div class="t1"><b><?= h($r['structure']) ?></b><span class="muted small"><?= h(adm_date($r['created_at'])) ?></span></div>
          <div class="t2"><?= h(trim($r['prenom'] . ' ' . $r['nom'])) ?> · <?= h($r['email']) ?></div>
          <div class="t2"><span class="pill <?= $pillSt[$r['statut']] ?? '' ?>"><?= h($st[$r['statut']] ?? $r['statut']) ?></span> <?= h($types[$r['type_structure']] ?? '') ?></div>
        </a>
      </div>
    <?php endforeach; ?>
    </div>
    <?= adm_pager($page, $pages, fn($n) => $url(['page' => $n])) ?>
  </section>

  <section class="card panel">
  <?php if (!$d): ?>
    <?= adm_empty('user-check', 'Choisissez une demande pour l’examiner.') ?>
  <?php else: ?>
    <div class="row-l wrap-row"><h2 class="h3 grow"><?= h($d['structure']) ?></h2><span class="pill <?= $pillSt[$d['statut']] ?? '' ?>"><?= h($st[$d['statut']] ?? '') ?></span></div>
    <h3 class="sub">Demande</h3>
    <dl class="dl">
      <dt>Type</dt><dd><?= h($types[$d['type_structure']] ?? $d['type_structure']) ?></dd>
      <dt>SIRET</dt><dd><?= $d['siret'] ? h(trim(chunk_split((string)$d['siret'], 3, ' '))) . ' <a class="lnk small" href="https://annuaire-entreprises.data.gouv.fr/etablissement/' . h(rawurlencode((string)$d['siret'])) . '" target="_blank" rel="noopener noreferrer">annuaire</a>' : '<span class="muted">—</span>' ?></dd>
      <dt>Site</dt><dd><?= $d['site'] ? '<a class="lnk" href="' . h($d['site']) . '" target="_blank" rel="noopener noreferrer nofollow">' . h($d['site']) . '</a>' : '<span class="muted">—</span>' ?></dd>
      <dt>Téléphone</dt><dd><?= h($d['tel'] ?: '—') ?></dd>
      <dt>Ville</dt><dd><?= h($d['ville'] ?: '—') ?></dd>
      <dt>Séjours / an</dt><dd><?= h($d['sejours_an'] ?: '—') ?></dd>
      <dt>Agrément</dt><dd><?= h($d['agrement'] ?: '—') ?></dd>
      <dt>Envoyée le</dt><dd><?= h(adm_date($d['created_at'])) ?></dd>
    </dl>
    <?php if ($d['message']): ?><div class="quote"><?= h($d['message']) ?></div><?php endif; ?>

    <h3 class="sub">Contrôles <?= adm_help('Vérifications automatiques : elles aident à décider mais ne remplacent pas un coup d’œil au site et à l’annuaire des entreprises.') ?></h3>
    <dl class="dl">
      <dt>SIRET</dt><dd><?= $chk($d['siret'] ? demande_siret_ok((string)$d['siret']) : null, 'format et clé valides', 'numéro invalide') ?><?= $dupSiret ? ' · <span class="chk-ko">déjà utilisé par une demande validée</span>' : '' ?></dd>
      <dt>Site web</dt><dd><?= $chk($d['site'] ? (bool)demande_site_norm((string)$d['site']) : null, 'adresse valide', 'adresse invalide') ?><?php if ($d['site']): ?> <button class="btn ghost sm" type="button" data-api="demande.check" data-d="<?= h($dtok) ?>" data-stay>Tester le site</button><?php endif; ?></dd>
      <dt>E-mail</dt><dd><?= $chk((bool)$d['is_verified'], 'vérifié', 'non vérifié') ?><?= $siteDom && $mailDom && str_ends_with($mailDom, $siteDom) ? ' · <span class="chk-ok">même domaine que le site</span>' : '' ?></dd>
      <dt>Doublon</dt><dd><?= $dupNom ? '<span class="chk-ko">un organisme porte déjà ce nom</span>' : '<span class="chk-ok">✓ aucun organisme du même nom</span>' ?></dd>
    </dl>

    <h3 class="sub">Demandeur</h3>
    <dl class="dl">
      <dt>Nom</dt><dd><?= h(trim($d['prenom'] . ' ' . $d['nom'])) ?><?php if (adm_can('users.read')): ?> · <a class="lnk" href="admin_user.php?t=<?= h(adm_tok('u', (int)$d['user_id'])) ?>">fiche</a><?php endif; ?></dd>
      <dt>E-mail</dt><dd><?= h($d['email']) ?></dd>
      <dt>Téléphone</dt><dd><?= h($d['u_tel'] ?: '—') ?></dd>
      <dt>Inscrit le</dt><dd><?= h(adm_date($d['u_created'])) ?><?= $d['google_id'] ? ' · Google' : '' ?></dd>
      <dt>Activité</dt><dd><?= $stats['enfants'] ?> enfant(s) · <?= $stats['orgas'] ?> organisme(s)<?= $d['bloque'] ? ' · <span class="chk-ko">compte bloqué</span>' : '' ?></dd>
      <?php if ($autres): ?><dt>Autres demandes</dt><dd><?php foreach ($autres as $a): ?><a class="lnk" href="<?= h($url(['d' => $a['token']])) ?>"><?= h($a['structure']) ?></a> (<?= h($st[$a['statut']] ?? '') ?>) <?php endforeach; ?></dd><?php endif; ?>
    </dl>

    <?php if ($canW && in_array($d['statut'], ['attente', 'complements'], true)): ?>
    <div class="row-l wrap-row mt">
      <button class="btn" type="button" data-api="demande.valider" data-d="<?= h($dtok) ?>" data-confirm="Donner l'accès organisateur à <?= h($d['email']) ?> ?"><?= ic('check') ?> Valider</button>
      <details class="pop"><summary class="btn ghost"><?= ic('x') ?> Refuser</summary><div class="pop-body">
        <form data-api="demande.refuser" data-ok="Demande refusée."><input type="hidden" name="d" value="<?= h($dtok) ?>"><h3 class="h3">Refuser la demande</h3>
          <label class="fld"><span class="fl">Motif (envoyé au demandeur)</span><textarea class="in" name="motif" rows="4" required minlength="10" maxlength="2000"></textarea></label>
          <div class="row"><button class="btn" type="submit">Refuser</button></div></form></div></details>
      <details class="pop"><summary class="btn ghost"><?= ic('reply') ?> Demander des compléments</summary><div class="pop-body">
        <form data-api="demande.complements" data-ok="Demande de compléments envoyée."><input type="hidden" name="d" value="<?= h($dtok) ?>"><h3 class="h3">Demander des compléments</h3>
          <label class="fld"><span class="fl">Ce qu'il manque (envoyé au demandeur)</span><textarea class="in" name="motif" rows="4" required minlength="10" maxlength="2000"></textarea></label>
          <div class="row"><button class="btn" type="submit">Envoyer</button></div></form></div></details>
    </div>
    <?php elseif ($canW && $d['statut'] === 'validee' && !$d['is_directeur']): ?>
      <div class="note warn mt">Demande validée mais l'accès organisateur a été retiré depuis.</div>
    <?php endif; ?>
    <?php if ($canW || adm_can('contacts.write')): ?>
    <div class="row-l wrap-row mt">
      <details class="pop"><summary class="btn ghost sm"><?= ic('mail') ?> Contacter</summary><div class="pop-body">
        <form data-api="demande.contact" data-ok="E-mail envoyé."><input type="hidden" name="d" value="<?= h($dtok) ?>"><h3 class="h3">Écrire à <?= h($d['email']) ?></h3>
          <label class="fld"><span class="fl">Objet</span><input class="in" name="objet" required maxlength="150" value="Votre demande organisateur : <?= h($d['structure']) ?>"></label>
          <label class="fld"><span class="fl">Message</span><textarea class="in" name="texte" rows="6" required maxlength="5000"></textarea></label>
          <div class="row"><button class="btn" type="submit"><?= ic('send') ?> Envoyer</button></div></form></div></details>
      <a class="btn ghost sm" href="mailto:<?= h(rawurlencode((string)$d['email'])) ?>"><?= ic('external') ?> Client mail</a>
      <?php if ($d['tel'] ?: $d['u_tel']): ?><a class="btn ghost sm" href="tel:<?= h(preg_replace('/[^0-9+]/', '', (string)($d['tel'] ?: $d['u_tel']))) ?>">Appeler</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <h3 class="sub">Historique</h3>
    <ul class="feed"><?php foreach ($evts as $e): ?>
      <li><span class="dot"></span><div><b><?= h(['creee' => 'Demande créée', 'complete' => 'Demande complétée', 'validee' => 'Validée', 'refusee' => 'Refusée', 'complements' => 'Compléments demandés', 'contact' => 'E-mail envoyé', 'site' => 'Site testé'][$e['action']] ?? $e['action']) ?></b><?= $e['qui'] ? ' · ' . h($e['qui']) : '' ?>
        <?php if ($e['texte']): ?><div class="small wrap"><?= nl2br(h($e['texte'])) ?></div><?php endif; ?><small><?= h(adm_date($e['created_at'])) ?></small></div></li>
    <?php endforeach; ?><?php if (!$evts): ?><li class="muted">Aucun événement.</li><?php endif; ?></ul>
    <?= adm_notes_box('demande', $dtok, $notes) ?>
  <?php endif; ?>
  </section>
</div>
<?php adm_foot(); ?>

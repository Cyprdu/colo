<?php
require_once __DIR__ . '/admin_lib.php';
adm_guard();
$views = ['crons' => ['Tâches planifiées', 'tech.crons'], 'securite' => ['Sécurité', 'tech.security'], 'sauvegardes' => ['Sauvegardes', 'tech.backup']];
$v = isset($views[$_GET['v'] ?? '']) ? (string)$_GET['v'] : 'crons';
adm_need($views[$v][1], false);

if ($v === 'sauvegardes' && isset($_GET['dump'])) {
    if (!adm_reauth_ok()) { $_SESSION['adm_flash'] = ['err', 'Confirmez votre mot de passe puis relancez la sauvegarde.']; header('Location: admin_tech.php?v=sauvegardes'); exit; }
    if (adm_limited('backup', 'u' . (int)$_SESSION['user']['id'], 5, 60)) { http_response_code(429); exit('Trop de sauvegardes, patientez.'); }
    adm_hit('backup', 'u' . (int)$_SESSION['user']['id']);
    adm_audit('sauvegarde_telechargee', 'base', null);
    @set_time_limit(600);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="moncamp-' . date('Y-m-d-His') . '.sql.gz"');
    $z = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
    $w = function (string $s) use ($z) { echo deflate_add($z, $s, ZLIB_NO_FLUSH); };
    $w("-- moncamp.fr sauvegarde " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $q = '`' . str_replace('`', '``', $t) . '`';
        $w("\nDROP TABLE IF EXISTS $q;\n" . $pdo->query("SHOW CREATE TABLE $q")->fetchColumn(1) . ";\n");
        $st = $pdo->query("SELECT * FROM $q");
        $buf = [];
        while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) {
            $buf[] = '(' . implode(',', array_map(fn($x) => $x === null ? 'NULL' : $pdo->quote((string)$x), $r)) . ')';
            if (count($buf) >= 200) { $w("INSERT INTO $q VALUES " . implode(",\n", $buf) . ";\n"); $buf = []; flush(); }
        }
        $st->closeCursor();
        if ($buf) $w("INSERT INTO $q VALUES " . implode(",\n", $buf) . ";\n");
    }
    $w("SET FOREIGN_KEY_CHECKS=1;\n");
    echo deflate_add($z, '', ZLIB_FINISH);
    exit;
}

adm_head($views[$v][0]);
if ($v === 'crons'):
    $jobs = ['cron_virements' => ['Versements et reprises', 'Transferts des anciens paiements, reprises sur remboursement, relevé des versements Stripe.'],
             'cron_rgpd' => ['Purge RGPD', 'Efface les données échues (dossiers de santé, comptes inactifs).'],
             'cron_alertes_animateur' => ['Alertes animateurs', 'E-mails de missions selon la fréquence choisie par chaque animateur.']];
    $kv = [];
    foreach (adm_rows("SELECT k, v FROM adm_kv WHERE k LIKE 'cron:%'") as $r) $kv[substr($r['k'], 5)] = json_decode($r['v'], true);
?>
<p class="muted">Un seul cron toutes les heures lance les trois tâches (<code>api/cron_tout.php?key=…</code>, à créer dans hPanel &gt; Avancé &gt; Tâches Cron). <?= adm_help('Si « Dernier passage » dépasse 2 heures, le cron ne tourne plus : vérifiez la tâche dans hPanel et la constante CRON_SECRET.') ?></p>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Tâche</th><th>Rôle</th><th>Dernier passage</th><th>Durée</th><th>État</th></tr></thead><tbody>
<?php foreach ($jobs as $k => [$l, $d]): $r = $kv[$k] ?? null; $late = !$r || strtotime((string)$r['at']) < time() - 7200; ?>
  <tr><td data-l="Tâche"><b><?= h($l) ?></b></td><td data-l="Rôle" class="small"><?= h($d) ?></td><td data-l="Dernier passage"><?= $r ? h(adm_date($r['at'])) : '<span class="muted">jamais vu</span>' ?></td><td data-l="Durée"><?= $r ? (int)$r['ms'] . ' ms' : '' ?></td>
    <td data-l="État"><?php if (!$r): ?><span class="pill warn">Inconnu</span><?php elseif (!$r['ok']): ?><span class="pill bad">Erreur</span><?php elseif ($late): ?><span class="pill warn">En retard</span><?php else: ?><span class="pill ok">OK</span><?php endif; ?><?= $r && $r['out'] ? '<div class="mono small wrap muted">' . h($r['out']) . '</div>' : '' ?></td></tr>
<?php endforeach; ?></tbody></table></div>

<?php elseif ($v === 'securite'):
    require_once __DIR__ . '/api/security.php';
    sec_ensure($pdo);
    $att = adm_rows("SELECT kind, COUNT(*) n, COUNT(DISTINCT who) w FROM admin_attempts WHERE created_at > (NOW() - INTERVAL 1 DAY) GROUP BY kind ORDER BY n DESC");
    $sec = adm_rows("SELECT type, COUNT(*) n FROM sec_tentatives WHERE created_at > (NOW() - INTERVAL 1 DAY) GROUP BY type ORDER BY n DESC");
    $evt = adm_rows("SELECT created_at, email, ip, action, cible, detail FROM admin_audit WHERE action IN ('acces_refuse', 'csrf_refuse', 'reauth_echec', 'reauth_bloque', 'session_invalide', 'session_expiree', 'sql_echec') ORDER BY id DESC LIMIT 40");
    $staff = adm_rows("SELECT u.email, u.prenom, u.nom, (SELECT MAX(created_at) FROM admin_audit a WHERE a.user_id = u.id) vu FROM users u WHERE u.is_admin = 1 ORDER BY vu DESC");
    $spam = (int)adm_one("SELECT COUNT(*) FROM contact_messages WHERE dossier = 'spam' AND created_at > (NOW() - INTERVAL 7 DAY)");
?>
<div class="kpis compact">
  <div class="kpi"><div><b><?= defined('TURNSTILE_SITE_KEY') && TURNSTILE_SITE_KEY !== '' ? 'Actif' : 'Inactif' ?></b><span>Turnstile (anti-robot) <?= adm_help('Clés TURNSTILE_SITE_KEY et TURNSTILE_SECRET dans api/config.php. Sans elles, le formulaire de contact se protège par pot de miel, délai et limite par IP.') ?></span></div></div>
  <div class="kpi"><div><b><?= $spam ?></b><span>Spams bloqués (7 j)</span></div></div>
  <div class="kpi"><div><b><?= (int)(ADM_IDLE_MAX / 60) ?> min</b><span>Expiration de session admin</span></div></div>
  <div class="kpi"><div><b><?= (int)(ADM_REAUTH_TTL / 60) ?> min</b><span>Validité de la ré-authentification</span></div></div>
</div>
<div class="grid2">
  <section class="card"><h2 class="sec"><?= ic('shield') ?> Tentatives (24 h)</h2>
    <dl class="dl"><?php foreach ($att as $a): ?><dt>Admin · <?= h($a['kind']) ?></dt><dd><?= (int)$a['n'] ?> (<?= (int)$a['w'] ?> source(s))</dd><?php endforeach; ?>
    <?php foreach ($sec as $a): ?><dt>Site · <?= h($a['type']) ?></dt><dd><?= (int)$a['n'] ?></dd><?php endforeach; ?></dl>
    <?php if (!$att && !$sec): ?><p class="muted">Rien à signaler.</p><?php endif; ?>
    <p class="mt"><a class="btn ghost sm" href="tableau_de_controle.php"><?= ic('check') ?> Tableau de contrôle complet</a></p></section>
  <section class="card"><h2 class="sec"><?= ic('users') ?> Équipe : dernière activité</h2>
    <ul class="feed"><?php foreach ($staff as $s): ?><li><span class="dot"></span><div><b><?= h(trim($s['prenom'] . ' ' . $s['nom'])) ?></b> · <?= h($s['email']) ?><small><?= $s['vu'] ? h(adm_date($s['vu'])) : 'aucune action' ?></small></div></li><?php endforeach; ?></ul></section>
</div>
<h2 class="sec"><?= ic('alert') ?> Événements de sécurité</h2>
<?php if (!$evt): ?><p class="muted">Aucun.</p><?php else: ?>
<div class="tbl-wrap flat"><table class="tbl"><thead><tr><th>Date</th><th>Événement</th><th>Compte</th><th>Page</th><th>IP</th></tr></thead><tbody>
<?php foreach ($evt as $r): ?><tr><td data-l="Date" class="small"><?= h(adm_date($r['created_at'])) ?></td><td data-l="Événement"><span class="pill bad"><?= h($r['action']) ?></span> <span class="small muted"><?= h((string)$r['detail']) ?></span></td><td data-l="Compte"><?= h($r['email']) ?></td><td data-l="Page" class="small"><?= h($r['cible']) ?></td><td data-l="IP" class="small muted"><?= h($r['ip']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>

<?php else:
    $sz = adm_rows("SELECT table_name t, table_rows n, ROUND((data_length + index_length) / 1048576, 2) mo FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY (data_length + index_length) DESC LIMIT 15");
    $last = adm_rows("SELECT created_at, email FROM admin_audit WHERE action = 'sauvegarde_telechargee' ORDER BY id DESC LIMIT 5");
?>
<div class="grid2">
  <section class="card"><h2 class="sec"><?= ic('download') ?> Télécharger une sauvegarde <?= adm_help('Export SQL complet et compressé (structure et données). Contient des données personnelles et de santé : conservez-le chiffré, hors du serveur. Hostinger garde aussi des sauvegardes automatiques (hPanel > Fichiers > Sauvegardes).') ?></h2>
    <p class="muted">Mot de passe demandé. L'action est journalisée.</p>
    <button class="btn" type="button" data-api="tech.backup" data-go="admin_tech.php?v=sauvegardes&amp;dump=1"><?= ic('download') ?> Sauvegarde complète (.sql.gz)</button>
    <?php if ($last): ?><h3 class="sub">Dernières sauvegardes</h3><ul class="feed"><?php foreach ($last as $l): ?><li><span class="dot"></span><div><?= h(adm_date($l['created_at'])) ?><small><?= h($l['email']) ?></small></div></li><?php endforeach; ?></ul><?php endif; ?></section>
  <section class="card"><h2 class="sec"><?= ic('database') ?> Plus grosses tables</h2>
    <div class="tbl-wrap flat"><table class="tbl"><thead><tr><th>Table</th><th class="num">Lignes ≈</th><th class="num">Mo</th></tr></thead><tbody>
    <?php foreach ($sz as $r): ?><tr><td class="mono small"><?= h($r['t']) ?></td><td class="num"><?= number_format((int)$r['n'], 0, ',', ' ') ?></td><td class="num"><?= h((string)$r['mo']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</div>
<?php endif; ?>
<?php adm_foot(); ?>

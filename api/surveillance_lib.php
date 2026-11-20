<?php
// api/surveillance_lib.php : état du site (base, disque, cron, erreurs, sauvegarde) et alertes e-mail à l'administrateur suprême.
// Le cron horaire enregistre un battement ; api/sante_site.php (appelé par un service externe toutes les 5 min) lève l'alerte s'il cesse.
const SV_CRON_MAX = 7200;
const SV_ALERTE_PAUSE = 3600;

function sv_kv(PDO $pdo, string $k, ?string $v = null): string {
    $pdo->exec("CREATE TABLE IF NOT EXISTS adm_kv (k VARCHAR(40) NOT NULL PRIMARY KEY, v VARCHAR(255) NOT NULL) DEFAULT CHARSET=utf8mb4");
    if ($v !== null) { $pdo->prepare("INSERT INTO adm_kv (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$k, $v]); return $v; }
    $st = $pdo->prepare("SELECT v FROM adm_kv WHERE k = ?");
    $st->execute([$k]);
    return (string)$st->fetchColumn();
}

function sv_battement(PDO $pdo): void { sv_kv($pdo, 'cron:battement', (string)time()); }

function sv_etat(PDO $pdo): array {
    $t0 = microtime(true);
    $db = (int)$pdo->query("SELECT 1")->fetchColumn() === 1;
    $e = ['db' => ['ok' => $db, 'ms' => (int)((microtime(true) - $t0) * 1000)]];
    $dir = dirname(__DIR__);
    $free = @disk_free_space($dir); $tot = @disk_total_space($dir);
    $e['disque'] = ['ok' => !$free || !$tot || $free / $tot > 0.05, 'libre_mo' => $free ? (int)($free / 1048576) : null, 'libre_pct' => $free && $tot ? round($free / $tot * 100, 1) : null];
    $bat = (int)sv_kv($pdo, 'cron:battement');
    $e['cron'] = ['ok' => $bat > 0 && time() - $bat <= SV_CRON_MAX, 'dernier' => $bat ? date('c', $bat) : null, 'il_y_a_min' => $bat ? (int)((time() - $bat) / 60) : null];
    $h1 = $h24 = $moy = 0;
    try {
        $r = $pdo->query("SELECT SUM(created_at > NOW() - INTERVAL 1 HOUR) h1, SUM(created_at > NOW() - INTERVAL 1 DAY) h24, COUNT(*) / 168 moy FROM app_erreurs WHERE created_at > NOW() - INTERVAL 7 DAY")->fetch(PDO::FETCH_ASSOC);
        [$h1, $h24, $moy] = [(int)$r['h1'], (int)$r['h24'], (float)$r['moy']];
    } catch (Throwable $e2) {}
    $e['erreurs'] = ['ok' => $h1 < max(20, 5 * $moy), 'derniere_heure' => $h1, 'dernieres_24h' => $h24, 'moyenne_horaire_7j' => round($moy, 1)];
    $bk = (int)sv_kv($pdo, 'sauvegarde:derniere');
    $e['sauvegarde'] = ['ok' => $bk > time() - 2 * 86400, 'derniere' => $bk ? date('c', $bk) : null];
    $e['ok'] = $e['db']['ok'] && $e['disque']['ok'] && $e['cron']['ok'] && $e['erreurs']['ok'];
    return $e;
}

function sv_supremes(PDO $pdo): array {
    try {
        return $pdo->query("SELECT DISTINCT u.email FROM users u JOIN adm_user_roles ur ON ur.user_id = u.id JOIN adm_roles r ON r.id = ur.role_id WHERE r.code = 'supreme' AND u.is_admin = 1")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return []; }
}

/** Envoie une alerte si quelque chose cloche (cron arrêté, erreurs en flèche, disque plein), une par heure au plus. Retourne les problèmes signalés. */
function sv_alerter(PDO $pdo, array $e): array {
    $pb = [];
    if (!$e['cron']['ok']) $pb[] = 'Le cron horaire ne passe plus' . ($e['cron']['dernier'] ? ' depuis ' . $e['cron']['il_y_a_min'] . ' min (dernier passage ' . date('d/m H:i', strtotime($e['cron']['dernier'])) . ')' : ' (aucun passage enregistré)') . '.';
    if (!$e['erreurs']['ok']) $pb[] = $e['erreurs']['derniere_heure'] . ' erreurs applicatives dans la dernière heure (moyenne : ' . $e['erreurs']['moyenne_horaire_7j'] . ' par heure).';
    if (!$e['disque']['ok']) $pb[] = 'Espace disque presque plein : ' . $e['disque']['libre_pct'] . ' % libre.';
    if (!$pb || time() - (int)sv_kv($pdo, 'alerte:derniere') < SV_ALERTE_PAUSE) return [];
    sv_kv($pdo, 'alerte:derniere', (string)time());
    $html = '<p>Surveillance de moncamp.fr :</p><ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $pb)) . '</li></ul><p><a href="' . rtrim(defined('BASE_URL') ? BASE_URL : 'https://moncamp.fr', '/') . '/admin_erreurs.php">Erreurs</a> · <a href="' . rtrim(defined('BASE_URL') ? BASE_URL : 'https://moncamp.fr', '/') . '/admin_tech.php?v=crons">Tâches planifiées</a></p><p style="color:#6b7780;font-size:13px">Alerte suivante au plus tôt dans une heure.</p>';
    foreach (sv_supremes($pdo) as $to)
        @mail($to, '=?UTF-8?B?' . base64_encode('[moncamp.fr] Alerte surveillance') . '?=', '<div style="font-family:Arial,sans-serif;font-size:15px;color:#26343b">' . $html . '</div>', "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: moncamp.fr <auth@moncamp.fr>\r\n");
    return $pb;
}

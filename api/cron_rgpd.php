<?php
// api/cron_rgpd.php : purge automatique (minimisation des données). À lancer chaque jour par une tâche planifiée.
//   CLI :  php api/cron_rgpd.php
//   HTTP : https://moncamp.fr/api/cron_rgpd.php?key=CRON_SECRET   (même clé que cron_virements, définie dans api/config.php)
// Règle : les informations et documents de santé d'un enfant sont effacés quand il n'a plus eu d'activité
// (création de la fiche ou fin du dernier séjour) depuis RGPD_SANTE_MOIS mois (défaut 6, à ajuster d'après la durée de la norme CNIL n° 58).
require_once 'config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/rgpd_lib.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    $key = (string)($_GET['key'] ?? '');
    if (!defined('CRON_SECRET') || CRON_SECRET === '' || !hash_equals((string)CRON_SECRET, $key)) { http_response_code(403); exit('interdit'); }
    header('Content-Type: application/json');
}

$mois = defined('RGPD_SANTE_MOIS') ? max(1, (int)RGPD_SANTE_MOIS) : 6;
$rep = ['sante_purgee' => 0, 'fichiers_supprimes' => 0, 'journaux_purges' => 0];
try {
    sec_ensure($pdo);
    $cols = rgpd_cols($pdo, 'enfants');
    $dateCol = in_array('date_creation', $cols, true) ? 'e.date_creation' : 'NOW()';
    $st = $pdo->query("SELECT e.id, e.carnet_sante_token, e.fiche_sanitaire_token, e.signature_token,
            GREATEST(COALESCE($dateCol, '1970-01-01'), COALESCE((SELECT MAX(COALESCE(c.date_fin, c.date_debut)) FROM inscriptions i JOIN camps c ON c.id = i.camp_id WHERE i.enfant_id = e.id), '1970-01-01')) AS derniere_activite
        FROM enfants e
        WHERE (COALESCE(e.carnet_sante_token, '') <> '' OR COALESCE(e.fiche_sanitaire_token, '') <> '' OR COALESCE(e.signature_token, '') <> '' OR COALESCE(e.infos_sante, '') <> '' OR COALESCE(e.allergies, '') <> '')
        HAVING derniere_activite < (NOW() - INTERVAL " . (int)$mois . " MONTH) LIMIT 200");
    $root = dirname(__DIR__) . '/uploads/';
    $clean = ['infos_sante' => '', 'allergies' => '', 'regime_alimentaire' => '', 'medecin_nom' => '', 'medecin_tel' => '', 'vaccins_data' => null,
              'carnet_sante_token' => null, 'fiche_sanitaire_token' => null, 'signature_token' => null];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $set = []; $vals = [];
        foreach ($clean as $c => $v) if (in_array($c, $cols, true)) { $set[] = "`$c` = ?"; $vals[] = $v; }
        $vals[] = (int)$e['id'];
        $pdo->prepare("UPDATE enfants SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
        foreach (['sante' => 'carnet_sante_token', 'sanitaire' => 'fiche_sanitaire_token', 'signatures' => 'signature_token'] as $dir => $col) {
            if (!empty($e[$col]) && preg_match('/^[a-f0-9]{64}\.[a-z0-9]{2,5}$/i', $e[$col])) { $f = $root . $dir . '/' . $e[$col]; if (is_file($f) && @unlink($f)) $rep['fichiers_supprimes']++; }
        }
        $rep['sante_purgee']++;
    }
    // journaux techniques : on ne garde pas plus de 12 mois
    $rep['journaux_purges'] = (int)$pdo->exec("DELETE FROM acces_sante WHERE created_at < (NOW() - INTERVAL 12 MONTH)");
    echo json_encode($rep), "\n";
} catch (Throwable $e) {
    error_log('cron_rgpd: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erreur' => 'echec']), "\n";
}

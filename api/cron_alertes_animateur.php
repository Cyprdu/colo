<?php
// api/cron_alertes_animateur.php : alertes e-mail « nouvelle mission qui vous correspond » (à lancer une fois par jour par un cron).
//   CLI :  php api/cron_alertes_animateur.php
//   HTTP : https://moncamp.fr/api/cron_alertes_animateur.php?key=CRON_SECRET   (clé définie dans api/config.php)
// Pour chaque animateur ayant activé l'alerte (quotidienne ou hebdomadaire, selon sa fréquence), on envoie les séjours publiés depuis le
// dernier envoi (identifiant supérieur à alerte_dernier_camp) dont le score de correspondance est d'au moins 45. Aucun e-mail si rien de nouveau.
require_once 'config.php';
require_once 'animateurs.php';
require_once 'anim_match.php';
require_once 'mail_config.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    $key = (string)($_GET['key'] ?? '');
    if (!defined('CRON_SECRET') || CRON_SECRET === '' || !hash_equals((string)CRON_SECRET, $key)) { http_response_code(403); exit('interdit'); }
    header('Content-Type: application/json');
}
const ALERTE_SEUIL = 45;
try {
    anim_ensure($pdo); anim_match_ensure($pdo);
    $site = rtrim(defined('BASE_URL') ? BASE_URL : 'https://moncamp.fr', '/');
    $rows = $pdo->query("SELECT p.user_id, p.alerte_freq, p.alerte_dernier_envoi, p.alerte_dernier_camp, u.prenom, u.email, u.date_naissance
        FROM animateur_prefs p JOIN users u ON u.id = p.user_id
        WHERE p.alerte_email = 1 AND p.alerte_freq <> 'jamais' AND u.is_animateur = 1 AND u.is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $sent = 0;
    foreach ($rows as $r) {
        $due = !$r['alerte_dernier_envoi'] || strtotime($r['alerte_dernier_envoi']) <= time() - ($r['alerte_freq'] === 'hebdo' ? 6.5 : 0.9) * 86400;
        if (!$due) continue;
        $uid = (int)$r['user_id'];
        $prefs = anim_prefs($pdo, $uid); $profil = anim_profil($pdo, $uid);
        $quals = anim_qualifs_effectives($prefs, $profil); $age = anim_age($r);
        $maxId = (int)$r['alerte_dernier_camp']; $match = [];
        foreach (anim_camps_ouverts($pdo, $uid) as $c) {
            if ((int)$c['id'] <= (int)$r['alerte_dernier_camp']) continue;
            $maxId = max($maxId, (int)$c['id']);
            $m = anim_score($c, $prefs, $quals, $age);
            if ($m && $m['score'] >= ALERTE_SEUIL) $match[] = [$c, $m];
        }
        if ($match) {
            usort($match, fn($a, $b) => $b[1]['score'] <=> $a[1]['score']);
            $li = '';
            foreach (array_slice($match, 0, 8) as [$c, $m]) {
                $card = anim_carte($c, $m);
                $li .= '<li style="margin:0 0 12px"><a href="' . htmlspecialchars($site . '/info-camp-animateur.php?t=' . $card['token']) . '"><b>' . htmlspecialchars($card['nom']) . '</b></a> — '
                     . htmlspecialchars($card['ville']) . '<br><span style="color:#6b7780">' . htmlspecialchars(implode(' · ', $m['raisons'])) . '</span></li>';
            }
            $body = '<p>Bonjour ' . htmlspecialchars($r['prenom']) . ',</p><p>' . count($match) . ' nouvelle' . (count($match) > 1 ? 's missions correspondent' : ' mission correspond') . ' à votre profil :</p><ul style="padding-left:18px">' . $li . '</ul>'
                  . '<p><a href="' . htmlspecialchars($site . '/missions.php') . '">Voir toutes les missions</a></p>'
                  . '<p style="font-size:12px;color:#6b7780">Vous recevez cet e-mail car vous avez activé une alerte. <a href="' . htmlspecialchars($site . '/preferences-animateur.php') . '">Modifier ou désactiver mes alertes</a>.</p>';
            if (sendMailGmail($r['email'], $r['prenom'], 'Nouvelles missions d\'animation pour vous', $body)) $sent++;
        }
        $pdo->prepare("UPDATE animateur_prefs SET alerte_dernier_envoi = NOW(), alerte_dernier_camp = ? WHERE user_id = ?")->execute([$maxId, $uid]);
    }
    echo json_encode(['alertes_envoyees' => $sent, 'animateurs_examines' => count($rows)]), "\n";
} catch (Throwable $e) {
    error_log('cron_alertes_animateur: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erreur' => 'echec']), "\n";
}

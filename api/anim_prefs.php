<?php
// api/anim_prefs.php : préférences de l'animateur connecté.
//   GET  : lit ses préférences + les listes de choix       POST (JSON, CSRF) : les enregistre
require_once 'config.php';
require_once 'animateurs.php';
require_once 'anim_match.php';

$uid = anim_require_animateur();
anim_ensure($pdo); anim_match_ensure($pdo);
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        anim_prefs_save($pdo, $uid, anim_body());
        anim_json(['success' => true]);
    }
    $themes = [];
    try { $themes = $pdo->query("SELECT name FROM camp_types ORDER BY name")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
    anim_json(['prefs' => anim_prefs($pdo, $uid), 'choix' => ['qualifs' => ANIM_QUALIFS, 'periodes' => ANIM_PERIODES, 'types' => ANIM_TYPES, 'freqs' => ANIM_FREQS, 'rayons' => ANIM_RAYONS, 'themes' => $themes]]);
} catch (Throwable $e) {
    error_log('anim_prefs: ' . $e->getMessage());
    anim_json(['error' => 'Service momentanément indisponible.'], 500);
}

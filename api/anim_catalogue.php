<?php
// api/anim_catalogue.php : catalogue des séjours qui recrutent, pour les animateurs.
// Même moteur de filtres que la recherche (api/get_camps_recherche.php, restreint aux séjours qui recrutent via ANIM_CATALOGUE),
// enrichi de la rémunération prévue et des exigences. Réservé aux comptes animateurs.
require_once 'config.php';
require_once 'animateurs.php';

if (!anim_user_id() || !anim_is_animateur()) anim_json(['status' => 'error', 'message' => 'Réservé aux animateurs.'], 403);
anim_ensure($pdo);
define('ANIM_CATALOGUE', true);

ob_start();
require __DIR__ . '/get_camps_recherche.php';
$res = json_decode(ob_get_clean(), true);
if (!is_array($res) || ($res['status'] ?? '') !== 'success') { anim_json($res ?: ['status' => 'error', 'message' => 'Indisponible.'], 500); }

$tokens = array_column($res['camps'], 'token');
$info = [];
if ($tokens) {
    $in = implode(',', array_fill(0, count($tokens), '?'));
    $st = $pdo->prepare("SELECT token, anim_remuneration, bafa_obligatoire, anim_plus_18, quota_max_anim FROM camps WHERE token IN ($in)");
    $st->execute($tokens);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $info[$r['token']] = $r;
    $me = $pdo->prepare("SELECT c.token, cd.statut FROM candidatures cd JOIN camps c ON c.id = cd.camp_id WHERE cd.user_id = ? AND c.token IN ($in)");
    $me->execute(array_merge([anim_user_id()], $tokens));
    $mine = $me->fetchAll(PDO::FETCH_KEY_PAIR);
}
foreach ($res['camps'] as &$c) {
    $r = $info[$c['token']] ?? [];
    $c['remuneration'] = isset($r['anim_remuneration']) && $r['anim_remuneration'] !== null ? (float)$r['anim_remuneration'] : null;
    $c['bafa_obligatoire'] = !empty($r['bafa_obligatoire']);
    $c['majeur'] = !empty($r['anim_plus_18']);
    $c['ma_candidature'] = $mine[$c['token']] ?? null;
    unset($c['id']);
}
if (!empty($_GET['remunere'])) { $res['camps'] = array_values(array_filter($res['camps'], fn($c) => $c['remuneration'] !== null && $c['remuneration'] > 0)); $res['total'] = count($res['camps']); }
if (!empty($_GET['sans_bafa'])) { $res['camps'] = array_values(array_filter($res['camps'], fn($c) => !$c['bafa_obligatoire'])); $res['total'] = count($res['camps']); }
anim_json($res);

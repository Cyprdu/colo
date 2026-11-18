<?php
// api/gestion_actions.php : actions groupées de gestion_camp.php. POST (csrf = fin_csrf, t = jeton d'une session du séjour)
//   a=relance  r[]=<jetons de réservation>           -> e-mail aux familles dont la fiche est incomplète (lien direct vers la fiche)
//   a=message  s=<jeton de session ou vide> sujet, texte -> e-mail à toutes les familles inscrites (une fois par adresse)
// Accès : propriétaire de l'organisme ou administrateur. Envois limités par heure.
require_once 'config.php';
require_once 'security.php';
require_once 'finance_lib.php';
require_once 'gestion_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function gest_json(array $d, int $c = 200): void { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
if (empty($_SESSION['user']['id'])) gest_json(['error' => 'Connectez-vous pour continuer.'], 401);
$uid = (int)$_SESSION['user']['id'];
$admin = !empty($_SESSION['user']['is_admin']);
if (!$admin && empty($_SESSION['user']['is_directeur'])) gest_json(['error' => 'Accès réservé aux organisateurs.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !fin_csrf_ok()) gest_json(['error' => 'Session expirée : rechargez la page.'], 419);

try {
    $ctx = gestion_ouvrir($pdo, (string)($_POST['t'] ?? ''), $uid, $admin);
    if (!$ctx) gest_json(['error' => "Ce séjour n'existe pas ou ne vous appartient pas."], 404);
    $a = (string)($_POST['a'] ?? '');
    $D = gestion_inscrits($pdo, $ctx, $a === 'message' ? (string)($_POST['s'] ?? '') : '');
    $nomCamp = html_entity_decode((string)$ctx['principal']['nom']);
    $orga = html_entity_decode((string)($ctx['open']['orga_nom'] ?? '')) ?: "L'organisateur";
    $site = rtrim(defined('BASE_URL') ? BASE_URL : 'https://moncamp.fr', '/');
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    require_once 'mail_config.php';
    $envoyer = function (string $to, string $nom, string $sujet, string $html): bool {
        try { sendMailGmail($to, $nom, $sujet, $html); return true; } catch (Throwable $e) { error_log('gestion_actions mail: ' . $e->getMessage()); return false; }
    };

    if ($a === 'relance') {
        $voulu = array_map('strval', (array)($_POST['r'] ?? []));
        $cibles = array_filter($D['enfants'], fn($e) => in_array($e['reservation_token'], $voulu, true) && $e['manque']);
        if (!$cibles) gest_json(['error' => 'Aucun dossier incomplet sélectionné.'], 400);
        if (sec_blocked($pdo, 'gest_relance', (string)$uid, 60, 60)) gest_json(['error' => "Trop de relances envoyées cette heure-ci. Réessayez plus tard."], 429);
        $n = 0;
        foreach ($cibles as $e) {
            $to = $e['resp1_email'] ?: $e['parent_email'];
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
            sec_hit($pdo, 'gest_relance', (string)$uid);
            $lien = $site . '/modif_child?id=' . urlencode($e['_public_id'] ?: enfant_jeton($pdo, $e['_enfant_id']));
            $ok = $envoyer($to, trim($e['parent_prenom'] . ' ' . $e['parent_nom_famille']), 'Dossier à compléter : ' . $e['prenom'] . ' · ' . $nomCamp,
                '<p>Bonjour,</p><p>' . $h($orga) . ' vous rappelle que la fiche de <b>' . $h($e['prenom']) . '</b> pour le séjour <b>' . $h($nomCamp) . '</b> est incomplète. Il manque :</p><ul>'
                . implode('', array_map(fn($m) => '<li>' . $h($m) . '</li>', $e['manque'])) . '</ul><p><a href="' . $h($lien) . '">Compléter la fiche</a></p><p>Merci,<br>' . $h($orga) . ' via moncamp.fr</p>');
            if ($ok) $n++;
        }
        gest_json(['ok' => true, 'message' => $n . ' relance' . ($n > 1 ? 's' : '') . ' envoyée' . ($n > 1 ? 's' : '') . '.']);
    }

    if ($a === 'message') {
        $sujet = mb_substr(trim(strip_tags((string)($_POST['sujet'] ?? ''))), 0, 150);
        $texte = mb_substr(trim(strip_tags((string)($_POST['texte'] ?? ''))), 0, 5000);
        if ($sujet === '' || $texte === '') gest_json(['error' => 'Indiquez un objet et un message.'], 400);
        $dest = [];
        foreach ($D['enfants'] as $e) { $to = strtolower(trim((string)($e['resp1_email'] ?: $e['parent_email']))); if (filter_var($to, FILTER_VALIDATE_EMAIL)) $dest[$to] = trim($e['parent_prenom'] . ' ' . $e['parent_nom_famille']); }
        if (!$dest) gest_json(['error' => 'Aucune famille inscrite pour cette sélection.'], 400);
        if (sec_blocked($pdo, 'gest_message', (string)$uid, 5, 60)) gest_json(['error' => 'Limite atteinte : 5 messages groupés par heure.'], 429);
        sec_hit($pdo, 'gest_message', (string)$uid);
        $n = 0;
        foreach ($dest as $to => $nom) {
            if ($envoyer($to, $nom, $sujet . ' · ' . $nomCamp, '<p>' . nl2br($h($texte)) . '</p><p style="color:#6b7780;font-size:13px">' . $h($orga) . ' · séjour « ' . $h($nomCamp) . ' » · envoyé via moncamp.fr. Pour répondre, utilisez la messagerie de votre compte.</p>')) $n++;
        }
        gest_json(['ok' => true, 'message' => 'Message envoyé à ' . $n . ' famille' . ($n > 1 ? 's' : '') . '.']);
    }
    gest_json(['error' => 'Action inconnue.'], 400);
} catch (Throwable $e) {
    error_log('gestion_actions: ' . $e->getMessage());
    gest_json(['error' => "L'action n'a pas pu aboutir. Réessayez dans un instant."], 500);
}

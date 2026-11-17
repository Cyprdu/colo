<?php
// api/add_session.php : un directeur ajoute une SESSION (nouvelles dates) à l'un de ses camps.
//
// POST (JSON ou formulaire) : token (du camp source), date_debut, date_fin (Y-m-d),
//                             date_limite_inscription (optionnel), prix (optionnel)
//
// La session est une copie du camp (description, images, âges, quotas, tarifs...) avec ses propres dates,
// ses propres réservations (aucune n'est copiée) et le même groupe_id : elle apparaît comme
// une session du même camp, et non comme un camp différent.

require_once 'config.php';
require_once 'sessions.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) sendJson(['error' => 'Accès interdit'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendJson(['error' => 'Méthode non autorisée'], 405);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

$isDate = function ($s) { $d = DateTime::createFromFormat('Y-m-d', (string)$s); return $d && $d->format('Y-m-d') === $s; };
$token = (string)($in['token'] ?? '');
$debut = (string)($in['date_debut'] ?? '');
$fin   = (string)($in['date_fin'] ?? '');
$limit = (string)($in['date_limite_inscription'] ?? '');

if ($token === '' || !$isDate($debut) || !$isDate($fin)) sendJson(['error' => 'Dates invalides.'], 400);
if ($fin < $debut)            sendJson(['error' => 'La date de fin doit suivre la date de début.'], 400);
if ($debut < date('Y-m-d'))   sendJson(['error' => 'La session doit commencer aujourd\'hui ou plus tard.'], 400);
if ($limit !== '' && (!$isDate($limit) || $limit > $debut)) sendJson(['error' => "La date limite d'inscription doit précéder le début."], 400);

if (!campsHaveGroupColumn($pdo)) sendJson(['error' => "Les sessions multiples ne sont pas encore activées sur le site (migration à lancer par l'administrateur)."], 409);

try {
    // Les créations de table/colonne (DDL) valident implicitement une transaction : elles se font AVANT d'en ouvrir une
    require_once 'etapes.php'; require_once 'options.php'; require_once 'attente.php';
    require_once 'champs.php';
    etapes_ensure($pdo); options_ensure($pdo); attente_ensure($pdo); champs_ensure($pdo);
    // Le camp source doit appartenir à un organisme de l'utilisateur connecté
    $stmt = $pdo->prepare("SELECT c.* FROM camps c JOIN organisateurs o ON o.id = c.organisateur_id
                           WHERE c.token = ? AND o.user_id = ? AND COALESCE(c.supprime, 0) = 0");
    $stmt->execute([$token, $_SESSION['user']['id']]);
    $src = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$src) sendJson(['error' => 'Séjour introuvable.'], 404);

    // Date limite par défaut : même avance que la session d'origine
    if ($limit === '') {
        if (!empty($src['date_limite_inscription']) && !empty($src['date_debut'])) {
            $lead = (strtotime($src['date_debut']) - strtotime($src['date_limite_inscription'])) / 86400;
            $limit = date('Y-m-d', strtotime($debut . ' -' . max(0, (int)$lead) . ' days'));
        } else {
            $limit = null;
        }
    }

    $new = $src;
    foreach (['id', 'vues', 'date_creation', 'date_bump', 'boost_points', 'boost_vedette_fin', 'boost_urgence_fin', 'solde_points'] as $k) unset($new[$k]);
    $newToken = bin2hex(random_bytes(8));
    $new['token'] = $newToken;
    $new['date_debut'] = $debut;
    $new['date_fin'] = $fin;
    $new['date_limite_inscription'] = $limit ?: null;
    $new['groupe_id'] = (int)($src['groupe_id'] ?: $src['id']);
    // La nouvelle session reprend TOUS les réglages de la session principale (modifiables ensuite, section par section, depuis « Modifier »)
    require_once 'camp_groupe.php';
    // Duplication : la copie garde l'héritage de la session copiée (principale -> tout est hérité) ; seules les dates (et le lieu, s'il est donné) changent
    $srcPrincipal = (int)$src['id'] === (int)($src['groupe_id'] ?: $src['id']);
    $herit = $srcPrincipal ? CAMP_HERITAGE_CLES : camp_heritage_lire($src, false);
    $lieu = trim((string)($in['ville'] ?? '')) !== '' || trim((string)($in['adresse'] ?? '')) !== '';
    if ($lieu) {
        $new['adresse'] = mb_substr(trim((string)($in['adresse'] ?? $src['adresse'])), 0, 255);
        $new['ville'] = mb_substr(trim((string)($in['ville'] ?? $src['ville'])), 0, 120);
        $new['code_postal'] = mb_substr(trim((string)($in['code_postal'] ?? $src['code_postal'])), 0, 20);
        $herit = array_values(array_diff($herit, ['lieu']));
        if (array_key_exists('geo_hash', $new)) $new['geo_hash'] = null;   // la position sera recalculée pour la nouvelle adresse
    }
    $new['heritage'] = json_encode(array_fill_keys($herit, true) ?: new stdClass());
    $new['perso_description'] = in_array('description', $herit, true) ? 0 : 1; $new['perso_lieu'] = in_array('lieu', $herit, true) ? 0 : 1;
    if (isset($in['prix']) && is_numeric($in['prix']) && (float)$in['prix'] >= 0) $new['prix'] = (float)$in['prix'];

    $pdo->beginTransaction();
    if (empty($src['groupe_id'])) {
        $pdo->prepare("UPDATE camps SET groupe_id = id WHERE id = ?")->execute([$src['id']]);
    }
    $cols = array_keys($new);
    $sql = "INSERT INTO camps (`" . implode('`,`', $cols) . "`, date_bump) VALUES (:" . implode(',:', array_map(function ($c, $i) { return 'p' . $i; }, $cols, array_keys($cols))) . ", NOW())";
    $st = $pdo->prepare($sql);
    foreach ($cols as $i => $c) $st->bindValue(':p' . $i, $new[$c]);
    $st->execute();
    $newId = (int)$pdo->lastInsertId();

    // Tarifs et moyens de paiement rattachés au camp
    foreach (['camps_tarifs' => 'tarif_id', 'camps_paiements' => 'paiement_id'] as $table => $col) {
        try {
            $pdo->prepare("INSERT IGNORE INTO $table (camp_id, $col) SELECT ?, $col FROM $table WHERE camp_id = ?")->execute([$newId, $src['id']]);
        } catch (Throwable $e) { /* table absente : sans importance */ }
    }
    try {
        $pdo->prepare("INSERT INTO camp_champs (camp_id, libelle, type, options, obligatoire, position) SELECT ?, libelle, type, options, obligatoire, position FROM camp_champs WHERE camp_id = ?")->execute([$newId, $src['id']]);
    } catch (Throwable $e) { }
    try {
        $pdo->prepare("INSERT INTO camp_etapes (camp_id, jour, lieu, description, ordre) SELECT ?, jour, lieu, description, ordre FROM camp_etapes WHERE camp_id = ?")->execute([$newId, $src['id']]);
    } catch (Throwable $e) { }
    $pdo->commit();
    require_once __DIR__ . '/security.php'; sec_trace($pdo, (int)$_SESSION['user']['id'], 'session_ajoutee', $newId);

    sendJson(['success' => true, 'id' => $newId, 'token' => $newToken]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('add_session: ' . $e->getMessage());
    sendJson(['error' => "Impossible d'ajouter la session."], 500);
}

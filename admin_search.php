<?php
require_once __DIR__ . '/admin_lib.php';
require_once __DIR__ . '/api/orga_lib.php';
adm_guard(true);
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
if (mb_strlen($q) < 2) adm_json(['groups' => []]);
if (adm_limited('search', 'u' . (int)$_SESSION['user']['id'], 120, 1)) adm_json(['groups' => []], 429);
adm_hit('search', 'u' . (int)$_SESSION['user']['id']);
$like = '%' . addcslashes($q, '%_\\') . '%';
$exact = $q;
$G = [];
$add = function (string $label, string $icon, array $items) use (&$G) { if ($items) $G[] = ['label' => $label, 'icon' => $icon, 'items' => array_slice($items, 0, 6)]; };

if (adm_can('users.read')) {
    $add('Utilisateurs', 'users', array_map(fn($r) => ['title' => trim($r['prenom'] . ' ' . $r['nom']) ?: $r['email'], 'sub' => $r['email'], 'href' => 'admin_user.php?t=' . adm_tok('u', (int)$r['id'])],
        adm_rows("SELECT id, nom, prenom, email FROM users WHERE email LIKE ? OR CONCAT_WS(' ', prenom, nom) LIKE ? OR CONCAT_WS(' ', nom, prenom) LIKE ? OR tel LIKE ? ORDER BY id DESC LIMIT 6", [$like, $like, $like, $like])));
}
if (adm_can('orgas.read')) {
    $add('Organismes', 'building', array_map(fn($r) => ['title' => $r['nom'], 'sub' => (string)$r['email'], 'href' => 'admin_orga.php?t=' . fin_orga_tok((int)$r['id'])],
        adm_rows("SELECT id, nom, email FROM organisateurs WHERE nom LIKE ? OR email LIKE ? OR public_id = ? ORDER BY nom LIMIT 6", [$like, $like, $exact])));
}
if (adm_can('camps.read')) {
    $add('Séjours', 'tent', array_map(fn($r) => ['title' => $r['nom'], 'sub' => trim(($r['ville'] ?? '') . ' · ' . ($r['org'] ?? ''), ' ·'), 'href' => 'admin_list.php?e=camps&f=all&q=' . rawurlencode((string)$r['nom'])],
        adm_rows("SELECT c.nom, c.ville, o.nom org FROM camps c LEFT JOIN organisateurs o ON o.id = c.organisateur_id WHERE c.nom LIKE ? OR c.token = ? ORDER BY c.id DESC LIMIT 6", [$like, $exact])));
}
if (adm_can('paiements.read')) {
    $add('Paiements', 'card', array_map(fn($r) => ['title' => number_format($r['brut_cents'] / 100, 2, ',', ' ') . ' € · ' . ($r['org'] ?? ''), 'sub' => ($r['payment_intent'] ?: $r['session_id']) . ' · ' . $r['statut'], 'href' => 'admin_paiement.php?t=' . adm_tok('pay', (int)$r['id'])],
        adm_rows("SELECT p.id, p.brut_cents, p.payment_intent, p.session_id, p.statut, o.nom org FROM fin_paiements p LEFT JOIN organisateurs o ON o.id = p.organisateur_id LEFT JOIN inscriptions i ON i.id = p.inscription_id
                  WHERE p.payment_intent = ? OR p.session_id = ? OR (LENGTH(?) >= 8 AND (p.payment_intent LIKE ? OR p.session_id LIKE ?)) OR i.token = ? ORDER BY p.id DESC LIMIT 6", [$exact, $exact, $q, $like, $like, $exact])));
}
if (adm_can('inscriptions.read')) {
    $add('Inscriptions', 'ticket', array_map(fn($r) => ['title' => trim($r['enfant']) ?: 'Participant', 'sub' => ($r['camp'] ?? '') . ' · ' . $r['statut'], 'href' => 'admin_list.php?e=inscriptions&f=all&q=' . rawurlencode((string)($r['camp'] ?? ''))],
        adm_rows("SELECT CONCAT_WS(' ', e.prenom, e.nom) enfant, c.nom camp, i.statut FROM inscriptions i LEFT JOIN enfants e ON e.id = i.enfant_id LEFT JOIN camps c ON c.id = i.camp_id
                  WHERE i.token = ? OR CONCAT_WS(' ', e.prenom, e.nom) LIKE ? ORDER BY i.id DESC LIMIT 6", [$exact, $like])));
}
if (adm_can('contacts.read')) {
    $add('Messages de contact', 'mail', array_map(fn($r) => ['title' => trim($r['prenom'] . ' ' . $r['nom']) . ' · ' . $r['motif'], 'sub' => $r['email'], 'href' => 'admin_contacts.php?m=' . rawurlencode((string)$r['token'])],
        adm_rows("SELECT nom, prenom, email, motif, token FROM contact_messages WHERE email LIKE ? OR token = ? OR CONCAT_WS(' ', prenom, nom) LIKE ? ORDER BY id DESC LIMIT 6", [$like, $exact, $like])));
}
if (adm_can('versements.read') && preg_match('/^(tr_|po_|acct_)/', $q)) {
    $add('Versements', 'bank', array_map(fn($r) => ['title' => number_format($r['montant_cents'] / 100, 2, ',', ' ') . ' € · ' . ($r['org'] ?? ''), 'sub' => (string)$r['stripe_transfer_id'] . ' · ' . $r['statut'], 'href' => 'admin_compta.php?v=versements'],
        adm_rows("SELECT t.montant_cents, t.stripe_transfer_id, t.statut, o.nom org FROM fin_transferts t LEFT JOIN organisateurs o ON o.id = t.organisateur_id WHERE t.stripe_transfer_id = ? OR t.stripe_account_id = ? LIMIT 6", [$exact, $exact])));
}
adm_json(['groups' => $G]);

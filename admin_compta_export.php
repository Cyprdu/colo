<?php
// admin_compta_export.php : exports CSV de la comptabilité (Excel FR : « ; », UTF-8 avec BOM).
// Même garde que tout le back-office (admin vérifié en base, session liée) + ré-authentification récente exigée (données financières complètes).
// Paramètres : t = paiements | transferts | organismes | payouts | journal | gains ; p/du/au = période ; o = jeton d'organisme (jamais d'identifiant numérique).
require_once __DIR__ . '/admin_lib.php';
require_once __DIR__ . '/api/compta_lib.php';
adm_guard();

if (!adm_reauth_ok()) {
    $_SESSION['adm_flash'] = ['err', 'Confirmez votre mot de passe pour exporter les données financières.'];
    header('Location: admin_compta.php?v=export'); exit;
}
$_SESSION['adm_reauth'] = time();
if (adm_limited('export', 'u' . (int)$_SESSION['user']['id'], 30, 10)) { http_response_code(429); exit('Trop d\'exports, patientez quelques minutes.'); }
adm_hit('export', 'u' . (int)$_SESSION['user']['id']);

fin_ensure($pdo);
[$from, $to, $label, $pk] = compta_period($_GET);
$orgaId = 0;
if (!empty($_GET['o'])) { $orgaId = fin_orga_from_tok($pdo, (string)$_GET['o']); if (!$orgaId) { http_response_code(404); exit; } }
$type = (string)($_GET['t'] ?? '');
$set = compta_export($pdo, $type, $from, $to, $orgaId);
if (!$set) { http_response_code(400); exit('Export inconnu.'); }

adm_audit('compta_export', $type, $label . ($orgaId ? ' (un organisme)' : ''));
compta_csv('colomap_' . $type . '_' . substr($from, 0, 10) . '_' . substr($to, 0, 10) . '.csv', $set[0], $set[1]);

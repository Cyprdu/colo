<?php
// api/gestion_export.php : exports d'un séjour (gestion_camp.php). GET t=<jeton d'une session> & quoi=... [& s=<jeton de session> pour filtrer]
//   CSV : enfants | animateurs | tous          PDF : liste | appel | sante | urgences
// Accès : propriétaire de l'organisme ou administrateur (gestion_ouvrir). Les exports santé sont journalisés (acces_sante).
require_once 'config.php';
require_once 'security.php';
require_once 'gestion_lib.php';
require_once 'pdf_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: no-store');

function export_err(string $m, int $c): void { http_response_code($c); header('Content-Type: text/plain; charset=utf-8'); echo $m; exit; }
if (empty($_SESSION['user']['id'])) export_err('Connectez-vous pour continuer.', 401);
$uid = (int)$_SESSION['user']['id'];
$admin = !empty($_SESSION['user']['is_admin']);
if (!$admin && empty($_SESSION['user']['is_directeur'])) export_err('Accès réservé aux organisateurs.', 403);

$ctx = gestion_ouvrir($pdo, (string)($_GET['t'] ?? ''), $uid, $admin);
if (!$ctx) export_err("Ce séjour n'existe pas ou ne vous appartient pas.", 404);
$session = (string)($_GET['s'] ?? '');
$D = gestion_inscrits($pdo, $ctx, $session);
$quoi = (string)($_GET['quoi'] ?? 'enfants');
$nomCamp = html_entity_decode((string)$ctx['principal']['nom']);
$sexe = fn($s) => ['Homme' => 'Garçon', 'Femme' => 'Fille'][$s] ?? (string)$s;
$statut = function (array $e): string {
    if ($e['expire']) return 'Délai dépassé';
    return ['EN_ATTENTE' => 'En attente de paiement', 'A_REGLER' => 'À encaisser', 'GRATUIT' => 'Gratuit', 'PAYE' => 'Payé'][$e['statut_paiement']] ?? (string)$e['statut_paiement'];
};
$sousTitre = $nomCamp . ($session !== '' ? ' · session ' . ($D['sessions'][$session] ?? '') : ' · toutes les sessions');
$aucun = fn($v) => trim((string)$v) === '' || fiche_est_negatif((string)$v);

// ---------------- CSV ----------------
if (in_array($quoi, ['enfants', 'animateurs', 'tous'], true)) {
    $cell = function ($v) { $v = (string)$v; if (preg_match('/^[=+\-@\t\r]/', $v)) $v = "'" . $v; return '"' . str_replace('"', '""', $v) . '"'; };
    $L = [];
    $hE = ['Type', 'Session', 'Prénom', 'Nom', 'Sexe', 'Naissance', 'Âge', 'Allergies', 'Régime', 'Traitement', 'Santé', 'Responsable / BAFA', 'E-mail', 'Téléphone', 'Urgence', 'Tél. urgence', 'Tarif', 'Prix', 'Payé', 'Reste', 'Statut', 'Fiche complète %'];
    $hA = ['Type', 'Session', 'Prénom', 'Nom', 'Sexe', 'Naissance', 'Âge', 'Allergies', 'Régime', 'Traitement', 'Santé', 'BAFA / diplôme', 'E-mail', 'Téléphone', 'Urgence', 'Tél. urgence', 'Tarif', 'Prix', 'Payé', 'Reste', 'Statut', ''];
    if ($quoi !== 'animateurs') foreach ($D['enfants'] as $e)
        $L[] = ['Enfant', $D['sessions'][$e['session_token']] ?? '', $e['prenom'], $e['nom'], $sexe($e['sexe']), pdf_date($e['date_naissance']), gestion_age($e['date_naissance']), $e['allergies'], $e['regime_alimentaire'], $e['traitements'], $e['infos_sante'],
            trim($e['resp1_prenom'] . ' ' . $e['resp1_nom']) ?: trim($e['parent_prenom'] . ' ' . $e['parent_nom_famille']), $e['resp1_email'] ?: $e['parent_email'], $e['resp1_tel'] ?: $e['parent_tel'], $e['urgence_nom'], $e['urgence_tel'],
            $e['tarif_nom'], $e['prix_final'], $e['montant_paye'], gestion_reste($e), $statut($e), $e['pct']];
    if ($quoi !== 'enfants') foreach ($D['animateurs'] as $a)
        $L[] = ['Animateur', $D['sessions'][$a['session_token']] ?? '', $a['prenom'], $a['nom'], '', pdf_date($a['date_naissance']), gestion_age($a['date_naissance']), $a['allergies'], $a['regime'], '', '', trim($a['bafa_statut'] . ' ' . $a['diplome']),
            $a['email'], $a['tel'], $a['urgence_nom'], $a['urgence_tel'], $a['tarif_nom'], $a['prix_final'], $a['montant_paye'], max(0, (float)$a['prix_final'] - (float)$a['montant_paye']), $a['statut'], ''];
    if ($quoi === 'animateurs') $L = array_map(fn($l) => array_slice($l, 0, 21), $L);
    $head = $quoi === 'animateurs' ? array_slice($hA, 0, 21) : $hE;
    if ($quoi !== 'animateurs') foreach ($D['enfants'] as $e) sante_log($pdo, $uid, $e['_enfant_id'], 'export');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ['enfants' => 'inscrits-enfants', 'animateurs' => 'inscrits-animateurs', 'tous' => 'inscrits-enfants-animateurs'][$quoi] . '.csv"');
    echo "\xEF\xBB\xBF" . implode("\r\n", array_map(fn($l) => implode(';', array_map($cell, $l)), [$head, ...$L]));
    exit;
}

// ---------------- PDF ----------------
$E = $D['enfants'];
$sessTxt = fn($tok) => $D['sessions'][$tok] ?? '';
$tel = fn($e) => $e['resp1_tel'] ?: $e['parent_tel'];

if ($quoi === 'liste') {
    $pdf = new MoncampPdf('Liste des inscrits', $sousTitre, 'L');
    $pdf->section('Enfants (' . count($E) . ')');
    $pdf->table(['Nom', 'Âge', 'Sexe', 'Session', 'Responsable', 'Téléphone', 'Tarif', 'Payé', 'Reste', 'Statut', 'Fiche'],
        array_map(fn($e) => [mb_strtoupper($e['nom']) . ' ' . $e['prenom'], gestion_age($e['date_naissance']), $sexe($e['sexe']), $sessTxt($e['session_token']), trim($e['parent_prenom'] . ' ' . $e['parent_nom_famille']),
            $tel($e), $e['tarif_nom'], pdf_eur($e['montant_paye']), pdf_eur(gestion_reste($e)), $statut($e), $e['pct'] . ' %'], $E),
        [42, 10, 14, 34, 36, 26, 26, 20, 20, 30, 12], [5 => 'L', 1 => 'C', 7 => 'R', 8 => 'R', 10 => 'R']);
    if ($D['animateurs']) {
        $pdf->section('Animateurs (' . count($D['animateurs']) . ')');
        $pdf->table(['Nom', 'Âge', 'Session', 'E-mail', 'Téléphone', 'BAFA', 'Statut'],
            array_map(fn($a) => [mb_strtoupper($a['nom']) . ' ' . $a['prenom'], gestion_age($a['date_naissance']), $sessTxt($a['session_token']), $a['email'], $a['tel'], $a['bafa_statut'], $a['statut']], $D['animateurs']),
            [44, 10, 40, 60, 28, 30, 30], [1 => 'C']);
    }
    $pdf->envoyer('Inscrits_' . $nomCamp . '.pdf', empty($_GET['dl']));
}

if ($quoi === 'appel') {
    $pdf = null;
    foreach ($ctx['rows'] as $r) {
        if ($session !== '' && $r['token'] !== $session) continue;
        $liste = array_values(array_filter($E, fn($e) => $e['session_token'] === $r['token']));
        if (!$liste && $session === '') continue;
        [$a, $b] = normalizeSessionDates($r['date_debut'], $r['date_fin']);
        $jours = []; for ($d = clone $a; $d <= $b && count($jours) < 16; $d->modify('+1 day')) $jours[] = $d->format('d/m');
        $titre = "Liste d'appel · " . gestion_label($r);
        if (!$pdf) $pdf = new MoncampPdf($titre, $nomCamp . ' · ' . count($liste) . ' enfant(s)', 'L'); else { $pdf->titre = $titre; $pdf->sousTitre = $nomCamp . ' · ' . count($liste) . ' enfant(s)'; $pdf->AddPage(); }
        $pdf->table(array_merge(['Nom', 'Âge', 'Attention', 'Tél. responsable'], $jours),
            array_map(fn($e) => array_merge([mb_strtoupper($e['nom']) . ' ' . $e['prenom'], gestion_age($e['date_naissance'], $r['date_debut']),
                implode(' ', array_filter([$aucun($e['allergies']) ? '' : 'Allergie', $aucun($e['traitements']) ? '' : 'Traitement', $aucun($e['regime_alimentaire']) ? '' : 'Régime'])), $tel($e)], array_fill(0, count($jours), '')), $liste),
            array_merge([48, 10, 34, 28], array_fill(0, count($jours), 12)), [1 => 'C']);
    }
    if (!$pdf) { $pdf = new MoncampPdf("Liste d'appel", $sousTitre, 'L'); $pdf->table(['Nom'], [], [1]); }
    $pdf->envoyer('Appel_' . $nomCamp . '.pdf', empty($_GET['dl']));
}

if ($quoi === 'sante') {
    $L = array_values(array_filter($E, fn($e) => !$aucun($e['allergies']) || !$aucun($e['regime_alimentaire']) || !$aucun($e['traitements']) || !$aucun($e['infos_sante'])));
    foreach ($L as $e) sante_log($pdo, $uid, $e['_enfant_id'], 'export');
    $pdf = new MoncampPdf('Allergies, régimes et traitements', $sousTitre . ' · ' . count($L) . ' enfant(s) concerné(s) sur ' . count($E), 'L');
    $v = fn($x) => $aucun($x) ? '' : $x;
    $pdf->table(['Nom', 'Âge', 'Session', 'Allergies', 'Régime', 'Traitement', 'Santé', 'Médecin'],
        array_map(fn($e) => [mb_strtoupper($e['nom']) . ' ' . $e['prenom'], gestion_age($e['date_naissance']), $sessTxt($e['session_token']), $v($e['allergies']), $v($e['regime_alimentaire']), $v($e['traitements']), $v($e['infos_sante']),
            trim($e['medecin_nom'] . ' ' . $e['medecin_tel'])], $L), [38, 10, 30, 44, 30, 40, 44, 34], [1 => 'C']);
    $A = array_values(array_filter($D['animateurs'], fn($a) => !$aucun($a['allergies']) || !$aucun($a['regime'])));
    if ($A) { $pdf->section('Animateurs'); $pdf->table(['Nom', 'Allergies', 'Régime'], array_map(fn($a) => [mb_strtoupper($a['nom']) . ' ' . $a['prenom'], $v($a['allergies']), $v($a['regime'])], $A), [50, 110, 110]); }
    $pdf->envoyer('Sante_' . $nomCamp . '.pdf', empty($_GET['dl']));
}

if ($quoi === 'urgences') {
    foreach ($E as $e) sante_log($pdo, $uid, $e['_enfant_id'], 'export');
    $pdf = new MoncampPdf("Contacts d'urgence", $sousTitre, 'L');
    $pdf->table(['Enfant', 'Session', 'Responsable 1', 'Responsable 2', "Personne à prévenir", 'Médecin'],
        array_map(fn($e) => [mb_strtoupper($e['nom']) . ' ' . $e['prenom'], $sessTxt($e['session_token']), trim($e['resp1_prenom'] . ' ' . $e['resp1_nom'] . "\n" . $tel($e)),
            trim($e['resp2_prenom'] . ' ' . $e['resp2_nom'] . "\n" . $e['resp2_tel']), trim($e['urgence_nom'] . ($e['urgence_lien'] ? ' (' . $e['urgence_lien'] . ')' : '') . "\n" . $e['urgence_tel']),
            trim($e['medecin_nom'] . "\n" . $e['medecin_tel'])], $E), [44, 34, 46, 46, 50, 46]);
    if ($D['animateurs']) { $pdf->section('Animateurs'); $pdf->table(['Animateur', 'Téléphone', 'Personne à prévenir'], array_map(fn($a) => [mb_strtoupper($a['nom']) . ' ' . $a['prenom'], $a['tel'], trim($a['urgence_nom'] . ' ' . $a['urgence_tel'])], $D['animateurs']), [70, 60, 136]); }
    $pdf->envoyer('Urgences_' . $nomCamp . '.pdf', empty($_GET['dl']));
}
export_err('Export inconnu.', 400);

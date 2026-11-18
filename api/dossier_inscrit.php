<?php
// api/dossier_inscrit.php : dossier complet d'un inscrit, pour le panneau de gestion_camp.php et la page child_details.php.
//   GET ?r=<jeton de réservation>  -> enfant (identité, parents, santé, documents, autorisations, paiement, réponses, historique)
//   GET ?a=<jeton d'inscription animateur> -> animateur
//   + &format=pdf : le même dossier en PDF (FPDF, 1 à 2 pages A4) ; &dl=1 pour le télécharger
// Accès : propriétaire de l'organisme du séjour, ou administrateur. L'ouverture d'un dossier enfant est journalisée (table acces_sante).
// Les documents ne sont jamais servis ici : seulement des liens vers api/secure_file.php (déchiffrement + journal à chaque ouverture).
require_once 'config.php';
require_once 'security.php';
require_once 'sante.php';
require_once 'champs.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: no-store');

function dossier_fin(array $d, int $code = 200): void {
    if ($code === 200 && ($_GET['format'] ?? '') === 'pdf') dossier_pdf($d);
    http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit;
}

/** Dossier en PDF : tableaux compacts, 1 à 2 pages A4 */
function dossier_pdf(array $d): void {
    require_once __DIR__ . '/pdf_lib.php';
    $oui = fn($b) => $b ? 'Oui' : 'Non';
    $statut = fn($p) => trim(($p['statut'] ?? '') . ' · ' . pdf_eur($p['paye'] ?? 0) . ' payé' . (isset($p['prix']) ? ' / ' . pdf_eur($p['prix']) : ''), ' ·');
    if ($d['type'] === 'animateur') {
        $pdf = new MoncampPdf('Dossier animateur : ' . $d['nom'], $d['camp']);
        $pdf->section('Identité');
        $pdf->kv(['Nom' => $d['nom'], 'Naissance' => pdf_date($d['date_naissance']), 'E-mail' => $d['email'], 'Téléphone' => $d['tel'], 'BAFA' => $d['bafa'], 'Diplôme' => $d['diplome'], 'Statut' => $d['statut']]);
        $pdf->section('Santé et urgence');
        $pdf->kv(['Allergies' => $d['allergies'], 'Régime' => $d['regime'], 'Urgence' => trim(($d['urgence']['nom'] ?? '') . ' ' . ($d['urgence']['tel'] ?? ''))]);
        $pdf->section('Paiement');
        $pdf->kv(['Tarif' => $d['paiement']['tarif'], 'Paiement' => $statut($d['paiement'])]);
        if ($d['reponses']) { $pdf->section('Réponses au formulaire'); $pdf->table(['Question', 'Réponse'], array_map(fn($r) => [$r['libelle'], $r['valeur']], $d['reponses']), [70, 120]); }
        $pdf->envoyer('Dossier_' . $d['nom'] . '.pdf', empty($_GET['dl']));
    }
    $e = $d['enfant']; $s = $d['sante']; $v = (array)($s['vaccins'] ?? []);
    $vacc = [];
    foreach (FICHE_VACCINS as $k => $l) { $st = $v["v_$k"] ?? ''; if ($st !== '') $vacc[] = $l . ($st === 'oui' ? (!empty($v["d_$k"]) ? ' ' . fiche_date_fr($v["d_$k"]) : ' oui') : ' : non'); }
    if (!$vacc && !empty($v['dtp'])) $vacc[] = 'DTP ' . $v['dtp'];
    $pdf = new MoncampPdf('Dossier : ' . $e['prenom'] . ' ' . $e['nom'], $d['camp'] . ($d['ville'] ? ' · ' . $d['ville'] : '') . ' · du ' . implode(' au ', array_map('pdf_date', explode(' → ', $d['dates']))));
    $pdf->section('Enfant');
    $pdf->kv(['Nom' => $e['prenom'] . ' ' . $e['nom'], 'Naissance' => pdf_date($e['date_naissance']), 'Sexe' => ['Homme' => 'Garçon', 'Femme' => 'Fille'][$e['sexe'] ?? ''] ?? ($e['sexe'] ?? ''),
        'Adresse' => trim(($e['adresse'] ?? '') . ' ' . ($e['pays'] ?? '')), 'E-mail' => $e['email'], 'Téléphone' => $e['tel']]);
    $pdf->section('Responsables légaux');
    $pdf->table(['Responsable', 'Lien', 'Téléphone', 'E-mail', 'Profession'], array_map(fn($p) => [trim(($p['civilite'] ?? '') . ' ' . ($p['prenom'] ?? '') . ' ' . ($p['nom'] ?? '')), $p['lien'], $p['tel'], $p['email'], $p['profession']], $d['parents']), [48, 24, 30, 54, 34]);
    $pdf->section('Santé');
    $pdf->kv(['Allergies' => $s['allergies'], 'Problème de santé' => $s['infos'], 'Traitement' => $s['traitements'], 'Régime' => $s['regime'],
        'Antécédents' => $v['antecedents'] ?? null, 'Recommandations' => $v['reco'] ?? null, 'Taille / poids' => trim(($s['taille'] ? $s['taille'] . ' cm' : '') . ($s['poids'] ? ' / ' . $s['poids'] . ' kg' : '')),
        'Médecin' => $s['medecin'], 'Urgence' => trim(($s['urgence']['nom'] ?? '') . ' ' . (($s['urgence']['lien'] ?? '') ? '(' . $s['urgence']['lien'] . ') ' : '') . ($s['urgence']['tel'] ?? '')), 'Vaccins' => implode(', ', $vacc)]);
    $pdf->section('Autorisations, documents et paiement');
    $a = $d['autorisations'];
    $pdf->kv(['Accord parental' => $oui($a['accord_parental']), "Droit à l'image" => $oui($a['droit_image']), 'Contact direct' => $oui($a['contact']), 'CGV' => $oui($a['cgv']),
        'Documents' => $d['documents'] ? implode(', ', array_column($d['documents'], 'label')) . ' (en ligne)' : 'Aucun', 'Tarif' => $d['paiement']['tarif'], 'Paiement' => $statut($d['paiement'])]);
    if ($d['reponses']) { $pdf->section("Questions de l'organisateur"); $pdf->table(['Question', 'Réponse'], array_map(fn($r) => [$r['libelle'] ?? $r['question'] ?? '', $r['valeur'] ?? $r['reponse'] ?? ''], $d['reponses']), [70, 120]); }
    if ($d['commentaires']) { $pdf->section('Commentaires de la famille'); $pdf->kv(['' => $d['commentaires']], 1); }
    $pdf->envoyer('Dossier_' . $e['prenom'] . '_' . $e['nom'] . '.pdf', empty($_GET['dl']));
}
if (empty($_SESSION['user']['id'])) dossier_fin(['error' => 'Connectez-vous pour continuer.'], 401);
$uid = (int)$_SESSION['user']['id'];
$admin = !empty($_SESSION['user']['is_admin']);
if (!$admin && empty($_SESSION['user']['is_directeur'])) dossier_fin(['error' => 'Accès réservé aux organisateurs.'], 403);
$mois = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

try {
    sante_ensure($pdo);
    $own = $admin ? '' : ' AND o.user_id = ' . $uid;

    // ---------------- animateur ----------------
    if (isset($_GET['a'])) {
        require_once 'equipe.php'; equipe_ensure($pdo);
        $st = $pdo->prepare("SELECT q.*, c.token session_token, c.nom camp_nom, c.date_debut, c.date_fin FROM equipe_inscriptions q JOIN camps c ON c.id = q.camp_id
                             JOIN organisateurs o ON o.id = c.organisateur_id WHERE q.token = ?$own");
        $st->execute([(string)$_GET['a']]);
        $q = $st->fetch(PDO::FETCH_ASSOC);
        if (!$q) dossier_fin(['error' => 'Dossier introuvable ou accès refusé.'], 404);
        $r = $pdo->prepare("SELECT libelle, valeur FROM equipe_reponses WHERE equipe_id = ? ORDER BY id"); $r->execute([$q['id']]);
        dossier_fin(['type' => 'animateur', 'nom' => trim($q['prenom'] . ' ' . $q['nom']), 'date_naissance' => $q['date_naissance'], 'email' => $q['email'], 'tel' => $q['tel'],
            'bafa' => $q['bafa_statut'], 'diplome' => $q['diplome'], 'allergies' => $q['allergies'], 'regime' => $q['regime'],
            'urgence' => ['nom' => $q['urgence_nom'], 'tel' => $q['urgence_tel']], 'camp' => $q['camp_nom'], 'session_token' => $q['session_token'],
            'paiement' => ['tarif' => $q['tarif_nom'], 'prix' => (float)$q['prix_final'], 'paye' => (float)$q['montant_paye'], 'statut' => $q['statut_paiement']],
            'statut' => $q['statut'], 'reponses' => $r->fetchAll(PDO::FETCH_ASSOC),
            'historique' => [['date' => $q['created_at'], 'label' => 'Inscription reçue']]]);
    }

    // ---------------- enfant ----------------
    $st = $pdo->prepare("SELECT i.id inscription_id, i.reservation_token, i.date_inscription, i.montant_paye, i.prix_final, i.statut, i.statut_paiement, i.moyen_paiement, i.mode_paiement,
                                c.token session_token, c.nom camp_nom, c.date_debut, c.date_fin, c.ville camp_ville, t.nom tarif_nom, e.*
                         FROM inscriptions i JOIN enfants e ON e.id = i.enfant_id JOIN camps c ON c.id = i.camp_id JOIN organisateurs o ON o.id = c.organisateur_id
                         LEFT JOIN tarifs t ON t.id = i.tarif_id WHERE i.reservation_token = ?$own");
    $st->execute([(string)($_GET['r'] ?? '')]);
    $e = $st->fetch(PDO::FETCH_ASSOC);
    if (!$e) dossier_fin(['error' => 'Dossier introuvable ou accès refusé.'], 404);

    $doc = fn(string $type, string $col, string $label) => !empty($e[$col]) ? ['label' => $label, 'url' => 'api/secure_file.php?type=' . $type . '&file=' . rawurlencode($e[$col])] : null;
    $docs = array_values(array_filter([$doc('sanitaire', 'fiche_sanitaire_token', 'Fiche sanitaire de liaison'), $doc('sante', 'carnet_sante_token', 'Carnet de santé'), $doc('signatures', 'signature_token', 'Signature du responsable')]));
    $photo = !empty($e['photo_token']) ? 'api/secure_file.php?type=photos&file=' . rawurlencode($e['photo_token']) : null;
    $g = fn($k) => ($e[$k] ?? '') === '' ? null : $e[$k];
    $parent = fn($n) => ['civilite' => $g("resp{$n}_civilite"), 'prenom' => $g("resp{$n}_prenom"), 'nom' => $g("resp{$n}_nom"), 'lien' => $g("resp{$n}_statut"),
                         'tel' => $g("resp{$n}_tel"), 'email' => $g("resp{$n}_email"), 'profession' => $g("resp{$n}_profession")];

    sante_log($pdo, $uid, (int)$e['id'], 'fiche');   // journal : qui a ouvert la fiche (santé comprise)

    $hist = [['date' => $e['date_inscription'], 'label' => 'Inscription au séjour']];
    if ($e['statut_paiement'] === 'PAYE' || $e['statut_paiement'] === 'GRATUIT') $hist[] = ['date' => null, 'label' => $e['statut_paiement'] === 'GRATUIT' ? 'Inscription gratuite' : 'Paiement enregistré'];
    $nbAcces = 0;
    try { $a = $pdo->prepare("SELECT COUNT(*) FROM acces_sante WHERE enfant_id = ? AND user_id = ?"); $a->execute([$e['id'], $uid]); $nbAcces = (int)$a->fetchColumn(); } catch (Throwable $x) {}

    dossier_fin(['type' => 'enfant', 'reservation' => $e['reservation_token'], 'session_token' => $e['session_token'], 'camp' => $e['camp_nom'], 'ville' => $e['camp_ville'],
        'dates' => $e['date_debut'] . ' → ' . $e['date_fin'],
        'enfant' => ['prenom' => $e['prenom'], 'nom' => $e['nom'], 'date_naissance' => $e['date_naissance'], 'sexe' => $e['sexe'], 'photo' => $photo,
                     'adresse' => trim(($e['adresse'] ?? '') . ', ' . ($e['code_postal'] ?? '') . ' ' . ($e['ville'] ?? ''), ' ,') ?: null, 'pays' => $g('pays'),
                     'email' => $g('email_enfant'), 'tel' => $g('tel_mobile_enfant') ?? $g('tel_fixe_enfant')],
        'parents' => array_values(array_filter([$parent(1), !empty($e['resp2_nom']) ? $parent(2) : null])),
        'sante' => ['infos' => $g('infos_sante'), 'allergies' => $g('allergies'), 'traitements' => $g('traitements'), 'regime' => $g('regime_alimentaire'),
                    'medecin' => trim(($e['medecin_nom'] ?? '') . ' ' . ($e['medecin_tel'] ?? '')) ?: null, 'taille' => $g('taille'), 'poids' => $g('poids'),
                    'urgence' => ['nom' => $g('urgence_nom'), 'tel' => $g('urgence_tel'), 'lien' => $g('urgence_lien')], 'vaccins' => json_decode((string)($e['vaccins_data'] ?? ''), true) ?: null],
        'documents' => $docs,
        'autorisations' => ['droit_image' => !empty($e['droit_image']), 'contact' => !empty($e['autorisation_contact']), 'accord_parental' => !empty($e['accord_parental']), 'cgv' => !empty($e['cgv_accepte'])],
        'paiement' => ['tarif' => $e['tarif_nom'], 'prix' => isset($e['prix_final']) ? (float)$e['prix_final'] : null, 'paye' => (float)$e['montant_paye'], 'statut' => $e['statut_paiement'], 'moyen' => $e['moyen_paiement'], 'mode' => $e['mode_paiement']],
        'reponses' => champs_reponses($pdo, (int)$e['inscription_id']), 'commentaires' => $g('commentaires'),
        'historique' => $hist, 'acces' => $nbAcces]);
} catch (Throwable $e) {
    error_log('dossier_inscrit: ' . $e->getMessage());
    dossier_fin(['error' => 'Impossible de charger ce dossier pour le moment.'], 500);
}

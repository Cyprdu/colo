<?php
// edit_camp.php : modifier un séjour (adresse à jeton ; le formulaire est dans partials/camp_form.php)
require_once 'api/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) { header('Location: index.php'); exit; }

$token = (string)($_GET['t'] ?? '');
if ($token === '') { header('Location: mes_camps.php'); exit; }

require_once 'api/options.php';
require_once 'api/attente.php';
require_once 'api/tarifs.php';
require_once 'api/champs.php';
require_once 'api/etapes.php';
options_ensure($pdo); attente_ensure($pdo); tarifs_ensure($pdo);

// Le séjour doit appartenir à un organisme du directeur connecté
$stmt = $pdo->prepare("SELECT c.* FROM camps c JOIN organisateurs o ON c.organisateur_id = o.id WHERE c.token = ? AND o.user_id = ? AND COALESCE(c.supprime, 0) = 0");
$stmt->execute([$token, $_SESSION['user']['id']]);
$camp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$camp) { http_response_code(404); header('Location: mes_camps.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM organisateurs WHERE user_id = ? ORDER BY nom ASC");
$stmt->execute([$_SESSION['user']['id']]);
$organisateurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT t.* FROM tarifs t JOIN camps_tarifs ct ON t.id = ct.tarif_id WHERE ct.camp_id = ? ORDER BY t.nom");
$stmt->execute([$camp['id']]);
$currentTarifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
require_once 'api/camp_groupe.php';
$groupeLignes = camp_groupe_lignes($pdo, (int)$_SESSION['user']['id'], $camp);
$sessionsMeta = camp_sessions_meta($groupeLignes, camp_principal($groupeLignes));
$etapesActuelles = etapes_du_camp($pdo, (int)$camp['id']);
$champsActuels = champs_du_camp($pdo, (int)$camp['id']);
$att = ['attente_paiement' => $camp['attente_paiement'] ?? 1, 'attente_jours' => $camp['attente_jours'] ?? ATTENTE_DEFAUT_JOURS];

$themesList = ["Archéologie","Art plastique","Astronomie","Aventure","Basket","Bien-être","Chant","Cheval","Cinéma","Cirque","Contes","Cuisine","Danse","Danse contemporaine","Développement durable","Dessins","Écologie","Écriture","École","Escalade","Espionnage","Films","Folk","Football","Futur","Histoire","Humanitaire","Improvisation","Journalisme","Jeux de société","Jeux vidéo","Jazz","Lecture","Légendes","Magie","Manga","Méditation","Montagne","Musique","Natation","Nature","Océan","Photographie","Photographie nature","Photographie urbaine","Pirate","Planète","Pâtisserie","Prière","Radio","Radio-théâtre","Rap","Randonnée","Recyclage","Relaxation","Robotique","Rock","Scoutisme","Science","Science-fiction","Ski","Sorcellerie","Sport","Storytelling","Survie","Théâtre","Théâtre musical","Voyage","Voyage solidaire","Yoga","Zombies","Autre"];
sort($themesList);
$paysList = ["France", "Belgique", "Suisse", "Espagne", "Italie", "Royaume-Uni", "Allemagne", "Portugal", "Grèce", "Canada", "États-Unis", "Maroc", "Tunisie", "Irlande", "Croatie", "Autre"];

$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Modifier le séjour - moncamp.fr</title>
<?php include 'partials/camp_form.php'; ?>
<?php require_once 'partials/footer.php'; ?>

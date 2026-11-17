<?php
// create_camp.php : « Publier un séjour » (le formulaire est dans partials/camp_form.php, partagé avec edit_camp.php)
require_once 'api/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user']['id'])) { header('Location: login.php?next=create_camp'); exit; }
if (empty($_SESSION['user']['is_directeur'])) { header('Location: index.php'); exit; }

require_once 'api/options.php';
require_once 'api/attente.php';
require_once 'api/tarifs.php';
options_ensure($pdo); attente_ensure($pdo); tarifs_ensure($pdo);

$stmt = $pdo->prepare("SELECT * FROM organisateurs WHERE user_id = ? ORDER BY nom ASC");
$stmt->execute([$_SESSION['user']['id']]);
$organisateurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$themesList = ["Archéologie","Art plastique","Astronomie","Aventure","Basket","Bien-être","Chant","Cheval","Cinéma","Cirque","Contes","Cuisine","Danse","Danse contemporaine","Développement durable","Dessins","Écologie","Écriture","École","Escalade","Espionnage","Films","Folk","Football","Futur","Histoire","Humanitaire","Improvisation","Journalisme","Jeux de société","Jeux vidéo","Jazz","Lecture","Légendes","Magie","Manga","Méditation","Montagne","Musique","Natation","Nature","Océan","Photographie","Photographie nature","Photographie urbaine","Pirate","Planète","Pâtisserie","Prière","Radio","Radio-théâtre","Rap","Randonnée","Recyclage","Relaxation","Robotique","Rock","Scoutisme","Science","Science-fiction","Ski","Sorcellerie","Sport","Storytelling","Survie","Théâtre","Théâtre musical","Voyage","Voyage solidaire","Yoga","Zombies","Autre"];
sort($themesList);
$paysList = ["France", "Belgique", "Suisse", "Espagne", "Italie", "Royaume-Uni", "Allemagne", "Portugal", "Grèce", "Canada", "États-Unis", "Maroc", "Tunisie", "Irlande", "Croatie", "Autre"];

$sessionsMeta = []; $etapesActuelles = []; $camp = null; $currentTarifs = []; $champsActuels = []; $att = [];
$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Publier un séjour - ColoMap</title>
<?php include 'partials/camp_form.php'; ?>
<?php require_once 'partials/footer.php'; ?>

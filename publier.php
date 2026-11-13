<?php
// publier.php : point d'entrée unique de « Publier une annonce / un séjour »
//  - visiteur non connecté  -> connexion, puis retour ici
//  - directeur d'organisme  -> création de séjour
//  - autre compte connecté  -> profil (où l'accès directeur se demande)
require_once 'api/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user']['id'])) {
    header('Location: login.php?next=publier');
} elseif (!empty($_SESSION['user']['is_directeur'])) {
    header('Location: create_camp.php');
} else {
    header('Location: profile.php');
}
exit;

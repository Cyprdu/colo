<?php
// api/anim_mode.php : bascule « Parent / Animateur » pour un compte qui est AUSSI animateur (mémorisé en session).
// POST m=parent|animateur + csrf  ->  redirige vers l'accueil du mode choisi.
require_once 'config.php';
require_once 'security.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user']['id']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) { header('Location: ../index.php'); exit; }
$m = ($_POST['m'] ?? '') === 'parent' ? 'parent' : 'animateur';
if ($m === 'animateur' && empty($_SESSION['user']['is_animateur'])) $m = 'parent';
$_SESSION['mode_compte'] = $m;
header('Location: ../' . ($m === 'animateur' ? 'animateur.php' : 'profile.php'));
exit;

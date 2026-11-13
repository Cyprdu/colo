<?php
// Fichier: api/get_home_category.php
require_once 'config.php';

// Paramètres
$category = $_GET['cat'] ?? 'recommended';
$user_id = $_GET['uid'] ?? 0;
$dept_user = $_GET['dept'] ?? ''; 
$seed = $_GET['seed'] ?? rand(); // Graine aléatoire pour varier l'ordre à chaque chargement

// --- 1. CONFIGURATION "ULTRA COMPLÈTE" DES MOTS-CLÉS ---
// Format : 
// 'required' => Mots obligatoires (au moins un doit être présent, sinon poubelle) - Optionnel
// 'bonus'    => Rapporte des points
// 'ban'      => Si trouvé, le camp est EXCLU DIRECTEMENT (Ex: Harry Potter hors de Nature)

$keywords_config = [
    // --- SAISONS ---
    'winter' => [
        'bonus' => ['ski', 'neige', 'snowboard', 'luge', 'hiver', 'raquette', 'chiens de traineau', 'station', 'altitude', 'montagne'],
        'ban'   => ['été', 'summer', 'juillet', 'août', 'plage', 'mer', 'sable', 'surf', 'océan', 'nautique', 'printemps', 'toussaint']
    ],
    'sea' => [
        'bonus' => ['mer', 'océan', 'plage', 'surf', 'vague', 'cotier', 'marin', 'baignade', 'voile', 'sable', 'dune', 'catamaran', 'paddle'],
        'ban'   => ['montagne', 'neige', 'ski', 'altitude', 'ferme', 'campagne', 'paris', 'neige']
    ],
    
    // --- NATURE & ANIMAUX ---
    'nature' => [
        'bonus' => ['nature', 'forêt', 'arbre', 'vert', 'campagne', 'écologie', 'animaux', 'ferme', 'biologie', 'faune', 'flore', 'jardin'],
        'ban'   => ['harry potter', 'sorcier', 'magie', 'poudlard', 'geek', 'robot', 'ordinateur', 'ville', 'urbain', 'paris', 'lyon', 'marseille', 'disney', 'parc d\'attraction', 'karting', 'moto']
    ],
    'horse' => [
        'bonus' => ['cheval', 'poney', 'équitation', 'centre équestre', 'galop', 'hippologie', 'cavalier', 'destrier'],
        'ban'   => [] // Généralement assez safe
    ],
    
    // --- THÉMATIQUES SPÉCIFIQUES ---
    'religion' => [
        'bonus' => ['prière', 'dieu', 'foi', 'église', 'pèlerinage', 'spiritualité', 'catholique', 'chrétien', 'jésus', 'messe', 'bible', 'lourdes', 'taizé', 'abbaye'],
        'ban'   => ['harry potter', 'sorcier', 'magie', 'baguette', 'poudlard', 'fantastique', 'imaginaire', 'halloween', 'vampire', 'loup-garou']
    ],
    'science' => [
        'bonus' => ['science', 'robotique', 'espace', 'astronomie', 'tech', 'gaming', 'codage', 'chimie', 'laboratoire', 'ordinateur', 'fusée', 'programmation', 'vidéo'],
        'ban'   => ['prière', 'messe', 'cheval', 'poney', 'surf']
    ],
    'cooking' => [
        'bonus' => ['cuisine', 'gastronomie', 'pâtisserie', 'chef', 'repas', 'gourmand', 'recette', 'culinaire', 'fourneau', 'chocolat'],
        'ban'   => []
    ],
    'arts' => [
        'bonus' => ['art', 'théâtre', 'cinéma', 'danse', 'musique', 'peinture', 'dessin', 'spectacle', 'comédie', 'cirque', 'chant', 'instrument'],
        'ban'   => []
    ],
    'survival' => [
        'bonus' => ['survie', 'aventure', 'bivouac', 'bushcraft', 'feu', 'cabane', 'orientation', 'trek', 'sauvage', 'autonomie'],
        'ban'   => ['hôtel', 'confort', 'ville', 'codage']
    ],
    
    // --- SPORTS ---
    'sport_team' => [
        'bonus' => ['football', 'rugby', 'basket', 'handball', 'volley', 'équipe', 'tournoi', 'collectif', 'ballon', 'stade'],
        'ban'   => []
    ],
    'karting' => [
        'bonus' => ['karting', 'moto', 'quad', 'moteur', 'pilotage', 'vitesse', 'circuit', 'mécanique', 'cross'],
        'ban'   => []
    ],
    
    // --- VOYAGES ---
    'itinerant' => [
        'bonus' => ['itinérant', 'voyage', 'route', 'découverte', 'road trip', 'sac à dos', 'circuit', 'étapes'],
        'ban'   => ['sur place', 'fixe', 'centre de vacances']
    ],
    'language' => [
        'bonus' => ['langue', 'anglais', 'espagnol', 'allemand', 'linguistique', 'bilingue', 'immersion', 'cours', 'cambridge'],
        'ban'   => []
    ]
];

// --- 2. REQUÊTE SQL DE BASE (Optimisée) ---
// On récupère un large pool de camps valides pour les filtrer ensuite.
// Si c'est une catégorie "abroad", "cheap", etc., on filtre en SQL.
// Sinon, on prend au hasard pour avoir de la variété avant le filtrage PHP.

$sql = "SELECT * FROM camps WHERE date_debut > NOW() AND valide=1 AND supprime=0";
$params = [];

// Filtres SQL "Durs"
if ($category === 'boosted') {
    $sql .= " AND (
                (boost_vedette_fin IS NOT NULL AND boost_vedette_fin > NOW()) 
                OR 
                (boost_urgence_fin IS NOT NULL AND boost_urgence_fin > NOW())
              ) ORDER BY boost_vedette_fin DESC";
} elseif ($category === 'nearby' && !empty($dept_user)) {
    $dept_prefix = substr($dept_user, 0, 2); 
    $sql .= " AND code_postal LIKE :cp_prefix LIMIT 20"; 
    $params[':cp_prefix'] = $dept_prefix . '%';
} elseif ($category === 'abroad') {
    $sql .= " AND (pays IS NOT NULL AND pays != '' AND pays NOT LIKE '%France%') ORDER BY RAND($seed) LIMIT 40";
} elseif ($category === 'cheap') {
    $sql .= " ORDER BY prix ASC LIMIT 40";
} elseif ($category === 'last_minute') {
    $sql .= " ORDER BY date_debut ASC LIMIT 40";
} elseif ($category === 'short_stay') {
    $sql .= " AND DATEDIFF(date_fin, date_debut) < 7 ORDER BY RAND($seed) LIMIT 40";
} elseif ($category === 'long_stay') {
    $sql .= " AND DATEDIFF(date_fin, date_debut) >= 10 ORDER BY RAND($seed) LIMIT 40";
} else {
    // Pour les catégories thématiques, on prend 150 camps au hasard pour être sûr d'en trouver qui matchent
    $sql .= " ORDER BY RAND($seed) LIMIT 150";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$camps = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3. INTELLIGENCE ARTIFICIELLE DE TRI (PHP) ---
if (isset($keywords_config[$category])) {
    $config = $keywords_config[$category];
    $bonuses = $config['bonus'];
    $bans    = $config['ban'];

    $filtered_camps = [];

    foreach ($camps as $camp) {
        $score = 0;
        
        // Concaténation de toutes les infos pour recherche textuelle (en minuscule)
        // On inclut le type, le thème, le nom, la description et la ville
        $haystack = mb_strtolower(
            ($camp['type']??'').' '.
            ($camp['theme']??'').' '.
            ($camp['nom']??'').' '.
            ($camp['description']??'').' '.
            ($camp['ville']??'')
        );

        // A. VÉRIFICATION DES BANNIS (ÉLIMINATION DIRECTE)
        $is_banned = false;
        foreach ($bans as $bad_word) {
            // Utilisation de "word boundary" simulé ou strpos simple
            // Pour être strict, strpos suffit souvent si les mots clés sont bien choisis
            if (mb_strpos($haystack, $bad_word) !== false) {
                $is_banned = true;
                break; // Pas la peine de continuer, il est out
            }
        }
        if ($is_banned) continue; // On passe au camp suivant

        // B. CALCUL DES POINTS
        $match_found = false;
        foreach ($bonuses as $good_word) {
            if (mb_strpos($haystack, $good_word) !== false) {
                $match_found = true;
                
                // Système de poids
                // Si le mot est dans le TITRE ou le TYPE -> Gros bonus
                $title_type = mb_strtolower(($camp['nom']??'').' '.($camp['type']??''));
                if (mb_strpos($title_type, $good_word) !== false) {
                    $score += 10;
                } else {
                    $score += 2; // Dans la description uniquement
                }
            }
        }

        // C. VALIDATION
        // On garde le camp SI et seulement SI il a un score positif (donc au moins un mot clé trouvé)
        if ($match_found && $score > 0) {
            $camp['_score'] = $score;
            $filtered_camps[] = $camp;
        }
    }

    // D. TRI PAR PERTINENCE
    usort($filtered_camps, function ($a, $b) {
        return $b['_score'] <=> $a['_score'];
    });

    $camps = $filtered_camps;
}

// --- 4. NETTOYAGE (Doublons de noms) ---
$final_camps = [];
$seen = [];
foreach ($camps as $c) {
    $name = trim(mb_strtolower($c['nom']));
    if (!in_array($name, $seen)) {
        $seen[] = $name;
        $final_camps[] = $c;
    }
}
$final_camps = array_slice($final_camps, 0, 15); // On garde les 15 meilleurs

// --- 5. SORTIE HTML ---
if (empty($final_camps)) {
    // IMPORTANT : On ne renvoie RIEN du tout (chaine vide).
    // Le JS détectera ça et passera à la catégorie suivante.
    echo ""; 
    exit;
}

// Helper image
function getImg($dbPath) {
    if (empty($dbPath)) return 'assets/img/default_camp.jpg'; // Image locale par défaut plus sûre
    if (filter_var($dbPath, FILTER_VALIDATE_URL)) return $dbPath;
    $dbPath = ltrim($dbPath, '/');
    if (strpos($dbPath, 'uploads/') !== 0) return 'uploads/camps/' . $dbPath;
    return $dbPath;
}

foreach ($final_camps as $camp):
    $id = $camp['id'];
    $img = getImg($camp['image_url'] ?? '');
    $nom = htmlspecialchars($camp['nom']);
    $ville = htmlspecialchars($camp['ville'] ?? '');
    $pays = htmlspecialchars($camp['pays'] ?? 'France');
    $loc = ($pays !== 'France' && !empty($pays)) ? $pays : $ville;
    $prix = number_format($camp['prix'], 0, ',', ' ');
?>
    <a href="camp_details.php?t=<?= urlencode($camp['token']) ?>" class="camp-card">
        <div class="camp-img-wrapper">
            <img src="<?= $img ?>" alt="<?= $nom ?>" class="camp-img" loading="lazy" 
                 referrerpolicy="no-referrer"
                 onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1506477331477-33d5d8b3dc85?w=500';">
        </div>
        <div class="camp-info">
            <div class="camp-title"><?= $nom ?></div>
            <div class="camp-meta"><i class="fa-solid fa-location-dot"></i> <?= $loc ?></div>
            <div class="camp-price">dès <?= $prix ?>€</div>
        </div>
    </a>
<?php endforeach; ?>
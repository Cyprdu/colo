<?php
// api/catalogue.php : catégories de séjours et « précisions » (facettes), leur stockage et leur lecture.
//
// MODÈLE (tout est créé automatiquement au premier usage, idempotent) :
//   cat_categories  : une ligne par catégorie (slug, nom, alias pour retrouver les anciens thèmes, thème historique)
//   cat_facettes    : les questions d'une catégorie (ex. Montagne -> « Niveau »), type single | multi | scale
//   cat_valeurs     : les réponses possibles d'une facette (ex. débutant, intermédiaire, avancé), dans l'ordre
//   camp_categories : les catégories d'un séjour (la première = principale)
//   camp_facettes   : les réponses choisies pour un séjour (une ligne par valeur : filtrable par index)
//   sq_meta         : petits marqueurs (version du catalogue, dernier rattrapage des anciens séjours)
// Le catalogue officiel est CAT_DATA ci-dessous ; il est recopié en base (INSERT ... ON DUPLICATE KEY UPDATE).
// Pour ajouter une catégorie ou une précision : ajouter une entrée ici et augmenter CAT_VERSION.
// Les anciens champs camps.theme / camps.type ne sont JAMAIS modifiés : ils sont simplement rattachés aux catégories (cat_backfill).
//
// Types de facette :
//   single : un seul choix       (ex. confession)
//   multi  : plusieurs choix     (ex. activités)
//   scale  : niveaux ordonnés, plusieurs niveaux possibles (ex. débutant + intermédiaire)

require_once __DIR__ . '/search_text.php';

const CAT_VERSION = 1;
const CAT_MAX_PAR_SEJOUR = 4;

/** Catalogue officiel. facette = [nom, type, aide, [valeur => libellé]] ; catégorie = [nom, thème historique, alias, facettes] */
function cat_data(): array {
    $niv3 = ['debutant' => 'Débutant', 'intermediaire' => 'Intermédiaire', 'avance' => 'Avancé'];
    $niv4 = ['decouverte' => 'Découverte', 'loisir' => 'Loisir', 'confirme' => 'Confirmé', 'competition' => 'Compétition'];
    return [
        'spirituel' => ['Spirituel & religieux', 'Prière', 'spirituel,spiritualite,spiritualite priere,priere,religion,religieux,messe,pastorale,retraite,foi,chretien,bible,adoration,pelerinage', [
            'confession' => ['Confession ou tradition', 'single', 'Les familles filtrent souvent sur ce critère.', ['catholique' => 'Catholique', 'protestant' => 'Protestant', 'evangelique' => 'Évangélique', 'oecumenique' => 'Œcuménique', 'orthodoxe' => 'Orthodoxe', 'musulman' => 'Musulman', 'juif' => 'Juif', 'laic' => 'Laïc, sans confession']],
            'temps' => ['Temps spirituels proposés', 'multi', 'Ce qui rythme les journées.', ['messe' => 'Messe', 'priere_matin_soir' => 'Prière du matin et du soir', 'adoration' => 'Adoration', 'partage_biblique' => 'Partage biblique ou enseignement', 'confession_sacrement' => 'Réconciliation (confession)', 'silence' => 'Temps de silence', 'chants' => 'Chants et louange', 'pelerinage' => 'Pèlerinage ou marche']],
            'format' => ['Format', 'single', null, ['colo' => 'Colonie avec une dimension spirituelle', 'retraite' => 'Retraite', 'camp_jeunes' => 'Camp de jeunes', 'pelerinage' => 'Pèlerinage']],
        ]],
        'montagne' => ['Montagne', 'Montagne', 'montagne,randonnee,rando,alpinisme,escalade,alpes,trek,via ferrata,canyoning', [
            'niveau' => ['Niveau', 'scale', 'Le niveau physique demandé.', $niv3],
            'altitude' => ['Altitude', 'single', null, ['moins_1000' => 'Moins de 1 000 m', '1000_2000' => '1 000 à 2 000 m', 'plus_2000' => 'Plus de 2 000 m']],
            'formule' => ['Formule', 'multi', null, ['itinerant' => 'Itinérant', 'bivouac' => 'Bivouac', 'refuge' => 'Refuge ou gîte', 'base_fixe' => 'Base fixe']],
            'activites' => ['Activités', 'multi', null, ['randonnee' => 'Randonnée', 'alpinisme' => 'Alpinisme', 'escalade' => 'Escalade', 'via_ferrata' => 'Via ferrata', 'canyoning' => 'Canyoning', 'vtt' => 'VTT', 'trail' => 'Trail']],
        ]],
        'ski' => ['Ski & neige', 'Ski', 'ski,snowboard,neige,ski de fond,raquettes,glisse', [
            'niveau' => ['Niveau (étoiles, flèches)', 'scale', 'Du niveau « ourson » aux flèches.', ['debutant' => 'Débutant (ourson, flocon)', 'etoiles' => '1re à 3e étoile', 'etoile_bronze_or' => 'Étoile de bronze ou d\'or', 'fleches' => 'Flèches', 'competiteur' => 'Compétiteur']],
            'discipline' => ['Discipline', 'multi', null, ['ski_alpin' => 'Ski alpin', 'snowboard' => 'Snowboard', 'ski_fond' => 'Ski de fond', 'raquettes' => 'Raquettes', 'freestyle' => 'Freestyle']],
            'inclus' => ['Inclus dans le prix', 'multi', null, ['cours' => 'Cours avec moniteurs', 'forfait' => 'Forfait remontées', 'materiel' => 'Location du matériel']],
        ]],
        'sport' => ['Sports', 'Sport', 'sport,sports,football,foot,basket,basketball,rugby,handball,volley,tennis,natation,athletisme,judo,arts martiaux,gymnastique,multisport,badminton,boxe,escrime,golf', [
            'discipline' => ['Discipline', 'multi', null, ['multisports' => 'Multisports', 'football' => 'Football', 'basket' => 'Basket', 'rugby' => 'Rugby', 'handball' => 'Handball', 'volley' => 'Volley', 'tennis' => 'Tennis', 'natation' => 'Natation', 'athletisme' => 'Athlétisme', 'arts_martiaux' => 'Arts martiaux', 'gymnastique' => 'Gymnastique', 'escrime' => 'Escrime']],
            'niveau' => ['Niveau', 'scale', null, $niv4],
        ]],
        'cheval' => ['Équitation', 'Cheval', 'cheval,equitation,poney,poneys,galop,equestre,voltige', [
            'galop' => ['Niveau (galops)', 'scale', 'Le niveau des cavaliers accueillis.', ['poney' => 'Poney, initiation', 'debutant' => 'Débutant', 'galop_1_2' => 'Galop 1 à 2', 'galop_3_4' => 'Galop 3 à 4', 'galop_5_7' => 'Galop 5 à 7']],
            'monture' => ['Monture', 'multi', null, ['poney' => 'Poney', 'cheval' => 'Cheval']],
            'discipline' => ['Discipline', 'multi', null, ['dressage' => 'Dressage', 'cso' => 'Saut d\'obstacles', 'cross' => 'Cross', 'voltige' => 'Voltige', 'randonnee' => 'Randonnée à cheval', 'soins' => 'Soins et vie à l\'écurie']],
        ]],
        'mer' => ['Mer & nautique', 'Océan', 'mer,mer littoral,littoral,ocean,plage,voile,nautique,catamaran,surf,kayak,paddle,plongee,bord de mer,planche a voile', [
            'activites' => ['Activités nautiques', 'multi', null, ['voile' => 'Voile, catamaran', 'planche' => 'Planche à voile', 'kayak' => 'Kayak, paddle', 'surf' => 'Surf, bodyboard', 'plongee' => 'Plongée, snorkeling', 'char_voile' => 'Char à voile', 'peche' => 'Pêche à pied']],
            'niveau' => ['Niveau', 'scale', null, $niv3],
            'test' => ['Test d\'aisance aquatique', 'single', 'Souvent exigé pour les activités nautiques.', ['requis' => 'Exigé', 'non_requis' => 'Non exigé']],
        ]],
        'nature' => ['Nature & aventure', 'Nature', 'nature,aventure,survie,bushcraft,foret,campagne,accrobranche,speleologie,orientation,cabane,bivouac,camping', [
            'activites' => ['Activités', 'multi', null, ['survie' => 'Survie, bushcraft', 'orientation' => 'Orientation', 'cabanes' => 'Cabanes', 'accrobranche' => 'Accrobranche', 'speleologie' => 'Spéléologie', 'canoe' => 'Canoë', 'jeux_piste' => 'Jeux de piste']],
            'formule' => ['Formule', 'single', null, ['base_fixe' => 'Base fixe', 'itinerant' => 'Itinérant', 'camping' => 'Camping sous tente']],
            'niveau' => ['Engagement physique', 'scale', null, ['decouverte' => 'Découverte', 'sportif' => 'Sportif', 'expert' => 'Expert']],
        ]],
        'voyage-langues' => ['Voyage & langues', 'Voyage', 'voyage,voyages,langue,langues,linguistique,anglais,espagnol,allemand,sejour linguistique,immersion,etranger', [
            'pays' => ['Pays', 'multi', null, ['france' => 'France', 'royaume_uni' => 'Royaume-Uni', 'irlande' => 'Irlande', 'espagne' => 'Espagne', 'italie' => 'Italie', 'allemagne' => 'Allemagne', 'malte' => 'Malte', 'etats_unis' => 'États-Unis', 'canada' => 'Canada', 'autre' => 'Autre pays']],
            'langue' => ['Langue pratiquée', 'multi', null, ['anglais' => 'Anglais', 'espagnol' => 'Espagnol', 'allemand' => 'Allemand', 'italien' => 'Italien', 'chinois' => 'Chinois', 'autre' => 'Autre langue']],
            'hebergement' => ['Hébergement', 'single', null, ['famille' => 'Famille d\'accueil', 'residence' => 'Résidence ou campus', 'hotel' => 'Hôtel ou auberge', 'camping' => 'Camping']],
            'intensite' => ['Intensité des cours', 'scale', null, ['loisir' => 'Loisir (peu de cours)', 'standard' => 'Environ 15 h par semaine', 'intensif' => '20 h par semaine ou plus']],
        ]],
        'arts-creatifs' => ['Arts créatifs', 'Art plastique', 'art plastique,arts plastiques,art,dessin,dessins,peinture,sculpture,poterie,manga,bd,creatif,couture,bricolage,arts creatifs', [
            'discipline' => ['Discipline', 'multi', null, ['dessin' => 'Dessin, peinture', 'sculpture' => 'Sculpture, poterie', 'manga' => 'Manga, bande dessinée', 'couture' => 'Couture, mode', 'recup' => 'Récup et bricolage', 'street_art' => 'Street art']],
            'niveau' => ['Niveau', 'scale', null, $niv3],
        ]],
        'musique' => ['Musique & chant', 'Musique', 'musique,chant,chorale,guitare,piano,batterie,orchestre,jazz,rock,rap,folk,dj,slam', [
            'discipline' => ['Discipline', 'multi', null, ['chant' => 'Chant, chorale', 'guitare' => 'Guitare', 'piano' => 'Piano', 'batterie' => 'Batterie', 'dj' => 'DJ, musique assistée', 'orchestre' => 'Orchestre, ensemble', 'rap' => 'Rap, slam', 'comedie_musicale' => 'Comédie musicale']],
            'niveau' => ['Niveau', 'scale', null, $niv3],
            'restitution' => ['Restitution', 'single', null, ['concert' => 'Concert de fin de séjour', 'enregistrement' => 'Enregistrement', 'aucune' => 'Aucune']],
        ]],
        'spectacle' => ['Théâtre, danse & cirque', 'Théâtre', 'theatre,theatre musical,comedie,improvisation,danse,danse contemporaine,cirque,magie,spectacle,clown,hip hop', [
            'discipline' => ['Discipline', 'multi', null, ['theatre' => 'Théâtre', 'improvisation' => 'Improvisation', 'danse_classique' => 'Danse classique', 'danse_moderne' => 'Danse moderne, hip-hop', 'cirque' => 'Cirque, jonglage', 'magie' => 'Magie']],
            'niveau' => ['Niveau', 'scale', null, $niv3],
            'restitution' => ['Spectacle de fin de séjour', 'single', null, ['oui' => 'Oui', 'non' => 'Non']],
        ]],
        'sciences-numerique' => ['Sciences & numérique', 'Science', 'science,sciences,astronomie,robotique,futur,science fiction,informatique,programmation,code,jeux video,numerique,electronique,technologie', [
            'discipline' => ['Discipline', 'multi', null, ['astronomie' => 'Astronomie', 'robotique' => 'Robotique', 'code' => 'Programmation', 'jeu_video' => 'Création de jeux vidéo', 'electronique' => 'Électronique', 'chimie_physique' => 'Chimie, physique', 'biologie' => 'Biologie']],
            'niveau' => ['Niveau', 'scale', null, $niv3],
        ]],
        'cuisine' => ['Cuisine & pâtisserie', 'Cuisine', 'cuisine,patisserie,gastronomie', [
            'specialite' => ['Spécialité', 'multi', null, ['patisserie' => 'Pâtisserie', 'cuisine_monde' => 'Cuisine du monde', 'cuisine_saison' => 'Produits de saison', 'sans_gluten' => 'Sans gluten possible']],
            'niveau' => ['Niveau', 'scale', null, $niv3],
        ]],
        'scoutisme' => ['Scoutisme & vie de camp', 'Scoutisme', 'scoutisme,scout,scouts,guide,guides', [
            'mouvement' => ['Mouvement', 'single', null, ['sgdf' => 'Scouts et Guides de France', 'eedf' => 'Éclaireurs et Éclaireuses de France', 'sue' => 'Scouts d\'Europe', 'suf' => 'Scouts unitaires de France', 'autre' => 'Autre mouvement', 'aucun' => 'Sans mouvement']],
            'formule' => ['Formule', 'multi', null, ['camp_fixe' => 'Camp fixe', 'itinerant' => 'Itinérant', 'grand_jeu' => 'Grands jeux', 'vie_camp' => 'Vie de camp (cuisine au feu, constructions)']],
        ]],
        'solidarite' => ['Solidarité & humanitaire', 'Humanitaire', 'humanitaire,solidaire,voyage solidaire,benevolat,chantier,solidarite', [
            'type' => ['Type de mission', 'multi', null, ['chantier' => 'Chantier', 'service' => 'Service auprès de personnes', 'intergenerationnel' => 'Rencontre intergénérationnelle', 'environnement' => 'Action pour l\'environnement']],
            'lieu' => ['Lieu', 'single', null, ['france' => 'En France', 'etranger' => 'À l\'étranger']],
        ]],
        'environnement-animaux' => ['Écologie, ferme & animaux', 'Écologie', 'ecologie,developpement durable,environnement,recyclage,planete,ferme,animaux,jardin,potager', [
            'sujets' => ['Sujets', 'multi', null, ['ferme' => 'Ferme pédagogique', 'animaux' => 'Soin des animaux', 'potager' => 'Potager, jardin', 'recyclage' => 'Recyclage, zéro déchet', 'biodiversite' => 'Biodiversité']],
        ]],
        'bien-etre' => ['Bien-être', 'Bien-être', 'bien etre,yoga,meditation,relaxation,sophrologie,zen', [
            'pratiques' => ['Pratiques', 'multi', null, ['yoga' => 'Yoga', 'meditation' => 'Méditation', 'relaxation' => 'Relaxation', 'sophrologie' => 'Sophrologie', 'emotions' => 'Gestion des émotions']],
        ]],
        'jeux-imaginaire' => ['Jeux & imaginaire', 'Jeux de société', 'jeux de societe,jeux de role,jeu,jeux,legendes,contes,sorcellerie,pirate,zombies,espionnage,escape game,grand jeu,enquete', [
            'univers' => ['Univers', 'multi', null, ['jeux_role' => 'Jeux de rôle', 'jeux_societe' => 'Jeux de société', 'escape' => 'Escape game, enquêtes', 'fantasy' => 'Fantasy, magie', 'pirates' => 'Pirates, aventure', 'espions' => 'Espionnage', 'grands_jeux' => 'Grands jeux']],
        ]],
        'medias' => ['Cinéma, médias & écriture', 'Cinéma', 'cinema,films,film,radio,radio theatre,journalisme,photographie,photographie nature,photographie urbaine,ecriture,lecture,storytelling,video,presse,podcast', [
            'discipline' => ['Discipline', 'multi', null, ['cinema' => 'Cinéma, tournage', 'radio' => 'Radio, podcast', 'journalisme' => 'Journalisme', 'photo' => 'Photographie', 'ecriture' => 'Écriture', 'lecture' => 'Lecture, contes']],
            'niveau' => ['Niveau', 'scale', null, $niv3],
        ]],
        'histoire-patrimoine' => ['Histoire & patrimoine', 'Histoire', 'histoire,archeologie,patrimoine,chateau,moyen age', [
            'epoque' => ['Thème', 'multi', null, ['archeologie' => 'Archéologie', 'moyen_age' => 'Moyen Âge', 'antiquite' => 'Antiquité', 'histoire_contemporaine' => 'Histoire contemporaine', 'chateaux' => 'Châteaux et patrimoine']],
        ]],
        'velo' => ['Vélo & itinérance', 'Sport', 'velo,vtt,cyclisme,cyclo,bmx', [
            'type' => ['Type de vélo', 'multi', null, ['route' => 'Route', 'vtt' => 'VTT', 'cyclo_camping' => 'Cyclo-camping', 'electrique' => 'Vélo électrique']],
            'distance' => ['Distance par jour', 'scale', null, ['moins_30' => 'Moins de 30 km', '30_60' => '30 à 60 km', 'plus_60' => 'Plus de 60 km']],
            'niveau' => ['Niveau', 'scale', null, $niv3],
        ]],
        'multi-activites' => ['Colonie multi-activités', 'Autre', 'multi activites,multiactivites,colo classique,activites variees', [
            'activites' => ['Activités', 'multi', null, ['piscine' => 'Piscine, baignade', 'grands_jeux' => 'Grands jeux', 'veillees' => 'Veillées', 'ateliers' => 'Ateliers manuels', 'sorties' => 'Sorties et visites', 'sport' => 'Sports collectifs']],
            'rythme' => ['Rythme', 'single', null, ['calme' => 'Calme', 'equilibre' => 'Équilibré', 'dynamique' => 'Très actif']],
        ]],
    ];
}

/** Catalogue sous sa forme d'usage : [slug => [slug, nom, theme, alias[], position, facettes => [cle => [cle, nom, type, aide, valeurs => [cle => libellé]]]]] */
function cat_depuis_code(): array {
    $out = []; $pos = 0;
    foreach (cat_data() as $slug => [$nom, $theme, $alias, $facettes]) {
        $f = []; $fp = 0;
        foreach ($facettes as $fk => [$fnom, $type, $aide, $vals]) $f[$fk] = ['cle' => $fk, 'nom' => $fnom, 'type' => $type, 'aide' => $aide, 'valeurs' => $vals, 'position' => $fp++];
        $out[$slug] = ['slug' => $slug, 'nom' => $nom, 'theme' => $theme, 'alias' => array_values(array_filter(explode(',', $alias))), 'position' => $pos++, 'facettes' => $f];
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------
// Base de données
// ---------------------------------------------------------------------------------------------
function cat_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sq_meta (k VARCHAR(40) NOT NULL PRIMARY KEY, v VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cat_categories (
            slug VARCHAR(40) NOT NULL PRIMARY KEY, nom VARCHAR(80) NOT NULL, alias TEXT NULL, theme_legacy VARCHAR(100) NULL,
            position INT NOT NULL DEFAULT 0, actif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cat_facettes (
            categorie VARCHAR(40) NOT NULL, cle VARCHAR(40) NOT NULL, nom VARCHAR(100) NOT NULL, type VARCHAR(10) NOT NULL DEFAULT 'single',
            aide VARCHAR(255) NULL, position INT NOT NULL DEFAULT 0, PRIMARY KEY (categorie, cle)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cat_valeurs (
            categorie VARCHAR(40) NOT NULL, facette VARCHAR(40) NOT NULL, cle VARCHAR(40) NOT NULL, libelle VARCHAR(100) NOT NULL,
            position INT NOT NULL DEFAULT 0, PRIMARY KEY (categorie, facette, cle)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS camp_categories (
            camp_id INT NOT NULL, categorie VARCHAR(40) NOT NULL, principale TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (camp_id, categorie), KEY idx_cat (categorie)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS camp_facettes (
            camp_id INT NOT NULL, categorie VARCHAR(40) NOT NULL, facette VARCHAR(40) NOT NULL, valeur VARCHAR(60) NOT NULL,
            PRIMARY KEY (camp_id, categorie, facette, valeur), KEY idx_fac (categorie, facette, valeur)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $v = $pdo->query("SELECT v FROM sq_meta WHERE k = 'catalogue'")->fetchColumn();
        if ((string)$v !== (string)CAT_VERSION) cat_seed($pdo);
    } catch (Throwable $e) { error_log('cat_ensure: ' . $e->getMessage()); }
}

function cat_seed(PDO $pdo): void {
    $c = $pdo->prepare("INSERT INTO cat_categories (slug, nom, alias, theme_legacy, position) VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE nom = VALUES(nom), alias = VALUES(alias), theme_legacy = VALUES(theme_legacy), position = VALUES(position)");
    $f = $pdo->prepare("INSERT INTO cat_facettes (categorie, cle, nom, type, aide, position) VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE nom = VALUES(nom), type = VALUES(type), aide = VALUES(aide), position = VALUES(position)");
    $v = $pdo->prepare("INSERT INTO cat_valeurs (categorie, facette, cle, libelle, position) VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE libelle = VALUES(libelle), position = VALUES(position)");
    foreach (cat_depuis_code() as $cat) {
        $c->execute([$cat['slug'], $cat['nom'], implode(',', $cat['alias']), $cat['theme'], $cat['position']]);
        foreach ($cat['facettes'] as $fa) {
            $f->execute([$cat['slug'], $fa['cle'], $fa['nom'], $fa['type'], $fa['aide'], $fa['position']]);
            $p = 0;
            foreach ($fa['valeurs'] as $vk => $vl) $v->execute([$cat['slug'], $fa['cle'], $vk, $vl, $p++]);
        }
    }
    $pdo->prepare("REPLACE INTO sq_meta (k, v) VALUES ('catalogue', ?)")->execute([(string)CAT_VERSION]);
}

/** Catalogue actif lu en base (repli sur le code si la base est indisponible). Mis en cache pour la requête. */
function cat_load(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        cat_ensure($pdo);
        $out = [];
        foreach ($pdo->query("SELECT slug, nom, alias, theme_legacy, position FROM cat_categories WHERE actif = 1 ORDER BY position, slug")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['slug']] = ['slug' => $r['slug'], 'nom' => $r['nom'], 'theme' => (string)$r['theme_legacy'], 'alias' => array_values(array_filter(explode(',', (string)$r['alias']))), 'position' => (int)$r['position'], 'facettes' => []];
        }
        foreach ($pdo->query("SELECT categorie, cle, nom, type, aide, position FROM cat_facettes ORDER BY position, cle")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($out[$r['categorie']])) $out[$r['categorie']]['facettes'][$r['cle']] = ['cle' => $r['cle'], 'nom' => $r['nom'], 'type' => $r['type'], 'aide' => $r['aide'], 'valeurs' => [], 'position' => (int)$r['position']];
        }
        foreach ($pdo->query("SELECT categorie, facette, cle, libelle FROM cat_valeurs ORDER BY position, cle")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($out[$r['categorie']]['facettes'][$r['facette']])) $out[$r['categorie']]['facettes'][$r['facette']]['valeurs'][$r['cle']] = $r['libelle'];
        }
        if ($out) return $cache = $out;
    } catch (Throwable $e) { error_log('cat_load: ' . $e->getMessage()); }
    return $cache = cat_depuis_code();
}

// ---------------------------------------------------------------------------------------------
// Sélection d'un séjour (formulaire du directeur)
// ---------------------------------------------------------------------------------------------
/**
 * Valide la sélection envoyée par le formulaire : JSON {"order":["spirituel","montagne"],"values":{"montagne":{"niveau":["debutant"]}}}
 * Retourne ['order' => [slug...], 'values' => [slug => [facette => [valeur...]]]] : catégories et réponses connues du catalogue seulement,
 * CAT_MAX_PAR_SEJOUR catégories au plus, réponses dans l'ordre du catalogue, une seule réponse pour une facette « single ».
 */
function cat_valider_selection(array $catalogue, $raw): array {
    $in = is_array($raw) ? $raw : json_decode((string)$raw, true);
    $order = []; $values = [];
    if (!is_array($in)) return ['order' => [], 'values' => []];
    foreach ((array)($in['order'] ?? []) as $slug) {
        $slug = (string)$slug;
        if (isset($catalogue[$slug]) && !in_array($slug, $order, true) && count($order) < CAT_MAX_PAR_SEJOUR) $order[] = $slug;
    }
    foreach ($order as $slug) {
        foreach ($catalogue[$slug]['facettes'] as $fk => $fa) {
            $picked = (array)($in['values'][$slug][$fk] ?? []);
            $ok = [];
            foreach (array_keys($fa['valeurs']) as $vk) if (in_array((string)$vk, array_map('strval', $picked), true)) $ok[] = (string)$vk;
            if ($fa['type'] === 'single') $ok = array_slice($ok, 0, 1);
            if ($ok) $values[$slug][$fk] = $ok;
        }
    }
    return ['order' => $order, 'values' => $values];
}

/** Thème historique à enregistrer dans camps.theme : celui déjà posté s'il reste cohérent, sinon celui de la catégorie principale. */
function cat_theme_pour(array $catalogue, array $sel, string $themePoste): string {
    if (!$sel['order']) return $themePoste;
    $themePoste = trim($themePoste);
    if ($themePoste !== '') {
        foreach (cat_legacy_guess($catalogue, $themePoste, '') as $slug) if (in_array($slug, $sel['order'], true)) return $themePoste;
    }
    return (string)($catalogue[$sel['order'][0]]['theme'] ?? '') ?: 'Autre';
}

function cat_enregistrer_camp(PDO $pdo, int $campId, array $sel): void {
    $pdo->prepare("DELETE FROM camp_categories WHERE camp_id = ?")->execute([$campId]);
    $pdo->prepare("DELETE FROM camp_facettes WHERE camp_id = ?")->execute([$campId]);
    $c = $pdo->prepare("INSERT INTO camp_categories (camp_id, categorie, principale) VALUES (?, ?, ?)");
    $f = $pdo->prepare("INSERT IGNORE INTO camp_facettes (camp_id, categorie, facette, valeur) VALUES (?, ?, ?, ?)");
    foreach ($sel['order'] as $i => $slug) {
        $c->execute([$campId, $slug, $i === 0 ? 1 : 0]);
        foreach ($sel['values'][$slug] ?? [] as $fk => $vals) foreach ($vals as $v) $f->execute([$campId, $slug, $fk, $v]);
    }
}

/** Sélection enregistrée d'un séjour, au format de cat_valider_selection (principale en premier). */
function cat_selection_du_camp(PDO $pdo, int $campId): array {
    $order = []; $values = [];
    try {
        $st = $pdo->prepare("SELECT categorie FROM camp_categories WHERE camp_id = ? AND categorie <> '_aucune' ORDER BY principale DESC, categorie");
        $st->execute([$campId]);
        $order = $st->fetchAll(PDO::FETCH_COLUMN);
        $st = $pdo->prepare("SELECT categorie, facette, valeur FROM camp_facettes WHERE camp_id = ?");
        $st->execute([$campId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $values[$r['categorie']][$r['facette']][] = $r['valeur'];
    } catch (Throwable $e) { error_log('cat_selection_du_camp: ' . $e->getMessage()); }
    return ['order' => $order, 'values' => $values];
}

// ---------------------------------------------------------------------------------------------
// Anciens thèmes -> catégories
// ---------------------------------------------------------------------------------------------
/** Catégories qui correspondent à un ancien thème (camps.theme) et à ses mots-clés libres (camps.type, séparés par des virgules). Le thème d'abord. */
function cat_legacy_guess(array $catalogue, string $theme, string $type): array {
    $index = [];
    foreach ($catalogue as $slug => $c) {
        $index[sq_norm($c['nom'])][] = $slug;
        foreach ($c['alias'] as $a) $index[sq_norm($a)][] = $slug;
    }
    $out = [];
    $mots = array_merge([$theme], preg_split('/\s*,\s*/', $type) ?: []);
    foreach ($mots as $m) {
        $k = sq_norm((string)$m);
        if ($k === '' || empty($index[$k])) continue;
        foreach ($index[$k] as $slug) if (!in_array($slug, $out, true)) $out[] = $slug;
    }
    return array_slice($out, 0, CAT_MAX_PAR_SEJOUR);
}

/**
 * Rattache les séjours qui n'ont encore aucune catégorie : leur thème et leurs mots-clés historiques deviennent catégories et mots-clés.
 * Ne modifie jamais camps.theme ni camps.type. Par lots ; rejoué au plus une fois par jour (rattrape les séjours créés par d'autres chemins).
 */
function cat_backfill(PDO $pdo, int $lot = 400): int {
    static $ran = false;
    if ($ran) return 0;
    $ran = true;
    $n = 0;
    try {
        $last = (string)$pdo->query("SELECT v FROM sq_meta WHERE k = 'backfill'")->fetchColumn();
        if ($last !== '' && $last === date('Y-m-d')) return 0;
        require_once __DIR__ . '/motscles.php';
        mc_ensure($pdo);
        $catalogue = cat_load($pdo);
        $rows = $pdo->query("SELECT c.id, c.theme, c.type FROM camps c LEFT JOIN camp_categories cc ON cc.camp_id = c.id WHERE cc.camp_id IS NULL ORDER BY c.id LIMIT " . (int)$lot)->fetchAll(PDO::FETCH_ASSOC);
        $ins = $pdo->prepare("INSERT IGNORE INTO camp_categories (camp_id, categorie, principale) VALUES (?, ?, ?)");
        foreach ($rows as $r) {
            $slugs = cat_legacy_guess($catalogue, (string)$r['theme'], (string)$r['type']);
            foreach ($slugs as $i => $s) $ins->execute([(int)$r['id'], $s, $i === 0 ? 1 : 0]);
            if (!$slugs) $ins->execute([(int)$r['id'], '_aucune', 0]);   // marqueur : déjà examiné, rien à rattacher (ignoré partout)
            mc_depuis_legacy($pdo, (int)$r['id'], (string)$r['type']);
            $n++;
        }
        if (count($rows) < $lot) $pdo->prepare("REPLACE INTO sq_meta (k, v) VALUES ('backfill', ?)")->execute([date('Y-m-d')]);
    } catch (Throwable $e) { error_log('cat_backfill: ' . $e->getMessage()); }
    return $n;
}

/** Catalogue sous la forme envoyée aux navigateurs (formulaire du directeur, page de recherche). */
function cat_pour_js(array $catalogue): array {
    $out = [];
    foreach ($catalogue as $c) {
        $f = [];
        foreach ($c['facettes'] as $fa) {
            $v = [];
            foreach ($fa['valeurs'] as $k => $l) $v[] = ['cle' => (string)$k, 'libelle' => $l];
            $f[] = ['cle' => $fa['cle'], 'nom' => $fa['nom'], 'type' => $fa['type'], 'aide' => $fa['aide'], 'valeurs' => $v];
        }
        $out[] = ['slug' => $c['slug'], 'nom' => $c['nom'], 'theme' => $c['theme'], 'facettes' => $f];
    }
    return $out;
}

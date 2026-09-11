<?php
// Fichier: index2.php
require_once 'api/config.php';
require_once 'partials/header.php';

// --- USER INFO ---
$user_prenom = 'Voyageur'; 
$user_id = 0;
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT prenom FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch();
    if ($u && !empty($u['prenom'])) $user_prenom = htmlspecialchars(ucfirst($u['prenom']));
}
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet">

<style>
    /* CSS Identique mais propre */
    :root { --primary: #FF385C; --dark: #222222; --gray: #717171; --radius: 12px; }
    body { font-family: 'Inter', sans-serif; background: #fff; color: var(--dark); margin:0; overflow-x:hidden; }

    .hero-container { padding: 40px 40px 20px 40px; max-width: 1400px; margin: 0 auto; }
    .greeting-text { font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800; margin-bottom: 5px; color: var(--dark); }
    .greeting-text span { color: var(--primary); }
    .subtitle-text { font-size: 1.1rem; color: var(--gray); margin-bottom: 20px; }

    /* Animation d'apparition fluide */
    .section-wrapper { max-width: 1400px; margin: 40px auto; padding-left: 40px; opacity: 0; animation: fadeIn 0.6s forwards; min-height: 280px; }
    @keyframes fadeIn { to { opacity: 1; } }

    .section-header { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; padding-right: 40px; }
    .section-title { font-size: 1.4rem; font-weight: 700; margin: 0; font-family: 'Poppins', sans-serif; }

    .carousel-container { position: relative; }
    .carousel-track { display: flex; gap: 20px; overflow-x: auto; padding: 5px 40px 25px 0; scroll-behavior: smooth; scrollbar-width: none; }
    .carousel-track::-webkit-scrollbar { display: none; }

    .nav-arrow { position: absolute; top: 40%; transform: translateY(-50%); width: 40px; height: 40px; background: white; border-radius: 50%; box-shadow: 0 2px 10px rgba(0,0,0,0.1); cursor: pointer; z-index: 10; display: flex; align-items: center; justify-content: center; opacity: 0; transition: 0.2s; }
    .carousel-container:hover .nav-arrow { opacity: 1; }
    .nav-prev { left: -20px; } .nav-next { right: 0; }

    .camp-card { flex: 0 0 270px; width: 270px; text-decoration: none; color: inherit; display: flex; flex-direction: column; }
    .camp-img-wrapper { width: 100%; height: 185px; border-radius: var(--radius); overflow: hidden; margin-bottom: 10px; background: #f0f0f0; }
    .camp-img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s; }
    .camp-card:hover .camp-img { transform: scale(1.05); }
    .camp-title { font-weight: 600; font-size: 1rem; color: var(--dark); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 3px; }
    .camp-meta { color: var(--gray); font-size: 0.85rem; }
    .camp-price { font-weight: 700; font-size: 0.95rem; margin-top: 2px; }

    .adsense-container { max-width: 1000px; margin: 50px auto; padding: 10px; background: #f9f9f9; border-radius: 8px; text-align: center; border: 1px dashed #ddd; }
    
    @media (max-width: 768px) {
        .hero-container { padding: 25px 20px; }
        .greeting-text { font-size: 1.8rem; }
        .section-wrapper { padding-left: 20px; margin: 30px 0; min-height: auto; }
        .camp-card { flex: 0 0 85%; width: 85%; }
        .nav-arrow { display: none !important; }
    }
    
    /* Loader de fin */
    #sentinel { padding: 40px; text-align: center; color: #aaa; font-size: 0.9rem; }
    .skeleton { width: 100%; height: 100%; background: #eee; animation: pulse 1.5s infinite; }
    @keyframes pulse { 0% {opacity: 0.6;} 50% {opacity: 1;} 100% {opacity: 0.6;} }
</style>

<div class="hero-container">
    <div class="greeting-text">Bienvenue, <span><?= $user_prenom ?></span> !</div>
    <div class="subtitle-text">Trouvez le camp idéal pour vos enfants (et profitez de vos vacances !).</div>
</div>

<div id="main-content">
    <div id="boost-section"></div>
    <div id="nearby-section"></div>
    </div>

<div id="sentinel">
    <i class="fa-solid fa-spinner fa-spin"></i> Recherche de nouveaux horizons...
</div>

<script>
    const USER_ID = <?= $user_id ?>;
    
    // --- LISTE MASSIVE (120+ Combinaisons) ---
    // On répète les types avec des titres différents pour créer de la variété
    const VARIATIONS = [
        { title: "Dernière minute", cat: "last_minute", icon: "fa-hourglass-half" },
        { title: "Petits prix", cat: "cheap", icon: "fa-tag" },
        { title: "Sports d'Hiver", cat: "winter", icon: "fa-snowflake" },
        { title: "Grands espaces & Nature", cat: "nature", icon: "fa-tree" },
        { title: "Bord de mer", cat: "sea", icon: "fa-umbrella-beach" },
        { title: "Colonies Apprenantes", cat: "science", icon: "fa-microscope" },
        { title: "Aventure & Frissons", cat: "survival", icon: "fa-fire" },
        { title: "Passion Cheval", cat: "horse", icon: "fa-horse" },
        { title: "Arts & Spectacle", cat: "arts", icon: "fa-masks-theater" },
        { title: "Cuisine des Chefs", cat: "cooking", icon: "fa-utensils" },
        { title: "Langues & International", cat: "language", icon: "fa-flag" },
        { title: "Séjours à l'étranger", cat: "abroad", icon: "fa-plane" },
        { title: "Sports Mécaniques", cat: "karting", icon: "fa-motorcycle" },
        { title: "Esprit d'Équipe", cat: "sport_team", icon: "fa-futbol" },
        { title: "Voyages Itinérants", cat: "itinerant", icon: "fa-map-location" },
        // Variations sémantiques pour la boucle
        { title: "Envie de Neige ?", cat: "winter", icon: "fa-mitten" },
        { title: "Cap sur l'Océan", cat: "sea", icon: "fa-water" },
        { title: "Déconnexion Totale", cat: "nature", icon: "fa-leaf" },
        { title: "Futurs Ingénieurs", cat: "science", icon: "fa-robot" },
        { title: "Comme à Koh-Lanta", cat: "survival", icon: "fa-compass" },
        { title: "Au Galop !", cat: "horse", icon: "fa-horse-head" },
        { title: "Stars de la Scène", cat: "arts", icon: "fa-music" },
        { title: "Petits Gourmands", cat: "cooking", icon: "fa-pizza-slice" },
        { title: "Become Bilingual", cat: "language", icon: "fa-comment-dots" },
        { title: "Découvrir le Monde", cat: "abroad", icon: "fa-earth-europe" },
        { title: "Vitesse & Sensations", cat: "karting", icon: "fa-gauge-high" },
        { title: "Sports de Balle", cat: "sport_team", icon: "fa-basketball" },
        { title: "Road Trip Ados", cat: "itinerant", icon: "fa-van-shuttle" },
        { title: "Moins de 7 jours", cat: "short_stay", icon: "fa-calendar-day" },
        { title: "Longs Séjours", cat: "long_stay", icon: "fa-calendar-days" }
    ];
    
    // On triple la liste pour l'effet "Infini" avant que ça boucle mathématiquement
    const ALL_CATS = [...VARIATIONS, ...VARIATIONS, ...VARIATIONS]; 

    // --- PUB HTML ---
    const AD_HTML = `
    <div class="adsense-container">
        <small style="color:#ccc; display:block;margin-bottom:5px;">Publicité</small>
        <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-3659884670016121" crossorigin="anonymous"><\/script>
        <ins class="adsbygoogle" style="display:block" data-ad-client="ca-pub-3659884670016121" data-ad-slot="5863082567" data-ad-format="auto" data-full-width-responsive="true"></ins>
        <script>(adsbygoogle = window.adsbygoogle || []).push({});<\/script>
    </div>`;

    document.addEventListener('DOMContentLoaded', () => {
        // Chargement initial
        loadSection('boosted', 'boost-section', 'Coups de Cœur', 'fa-heart', '#FF385C');
        initGeo();
        initInfiniteScroll();
    });

    // --- LOGIQUE ROBUSTE DE CHARGEMENT ---
    let currentIndex = 0;
    let validSectionsCount = 0;
    let isFetching = false;

    function initInfiniteScroll() {
        const obs = new IntersectionObserver((entries) => {
            if(entries[0].isIntersecting && !isFetching) {
                loadNextBatch(3); // On essaie d'en charger 3
            }
        }, { rootMargin: "600px" }); // Charge très en avance
        obs.observe(document.getElementById('sentinel'));
        
        // Premier lancement
        loadNextBatch(3);
    }

    // Fonction Récursive : continue d'essayer tant qu'on n'a pas rempli le quota
    async function loadNextBatch(targetCount) {
        isFetching = true;
        let loaded = 0;
        let safetyCounter = 0; // Pour éviter boucle infinie si TOUT est vide

        while(loaded < targetCount && safetyCounter < 20) {
            safetyCounter++;
            
            // Sélection cyclique
            const catData = ALL_CATS[currentIndex % ALL_CATS.length];
            currentIndex++;

            // Pub toutes les 6 valides
            if(validSectionsCount > 1 && validSectionsCount % 6 === 0) {
                const adDiv = document.createElement('div');
                adDiv.innerHTML = AD_HTML;
                document.getElementById('main-content').appendChild(adDiv);
                validSectionsCount++; // On décale pour pas remettre une pub direct
            }

            // Création conteneur temporaire
            const container = document.getElementById('main-content');
            const wrapperId = 'cat-' + Math.random().toString(36).substr(2, 9);
            const div = document.createElement('div');
            div.id = wrapperId;
            container.appendChild(div);

            // Fetch
            const success = await fetchAndInject(div, catData.cat, catData.title, catData.icon);
            
            if(success) {
                loaded++;
                validSectionsCount++;
            } else {
                div.remove(); // Si vide, on retire et on réessaie au prochain tour de boucle while
            }
        }
        
        isFetching = false;
    }

    async function fetchAndInject(container, catKey, title, icon) {
        const seed = Math.floor(Math.random() * 100000); // Pour varier l'aléatoire PHP
        const uniqueId = 'track-' + seed;
        
        // Structure Skeleton
        container.innerHTML = `
        <div class="section-wrapper">
            <div class="section-header">
                <i class="fa-solid ${icon}" style="color:#222;"></i>
                <h3 class="section-title">${title}</h3>
            </div>
            <div class="carousel-container">
                <div class="carousel-track" id="${uniqueId}">
                    <div class="camp-card"><div class="camp-img-wrapper"><div class="skeleton"></div></div></div>
                    <div class="camp-card"><div class="camp-img-wrapper"><div class="skeleton"></div></div></div>
                    <div class="camp-card"><div class="camp-img-wrapper"><div class="skeleton"></div></div></div>
                </div>
            </div>
        </div>`;

        try {
            const res = await fetch(`api/get_home_category.php?cat=${catKey}&uid=${USER_ID}&seed=${seed}`);
            const html = await res.text();
            
            if(!html.trim()) {
                return false; // Échec : API vide
            }
            
            // Succès : On met le contenu réel et les flèches
            const track = document.getElementById(uniqueId);
            track.innerHTML = html;
            
            // Ajout flèches navigation
            const parent = track.parentElement;
            const btnPrev = document.createElement('div'); 
            btnPrev.className = 'nav-arrow nav-prev';
            btnPrev.innerHTML = '<i class="fa-solid fa-chevron-left"></i>';
            btnPrev.onclick = () => track.scrollBy({left: -300, behavior:'smooth'});
            
            const btnNext = document.createElement('div'); 
            btnNext.className = 'nav-arrow nav-next';
            btnNext.innerHTML = '<i class="fa-solid fa-chevron-right"></i>';
            btnNext.onclick = () => track.scrollBy({left: 300, behavior:'smooth'});
            
            parent.appendChild(btnPrev);
            parent.appendChild(btnNext);
            
            return true;

        } catch(e) {
            console.error(e);
            return false;
        }
    }

    // Fonction simplifiée pour Boost & Geo
    function loadSection(catKey, divId, title, icon, color) {
        const div = document.getElementById(divId);
        if(!div) return;
        // ... (Logique similaire mais sans boucle while, juste one-shot)
        fetchAndInject(div, catKey, title, icon).then(success => {
            if(success && color) {
                div.querySelector('i').style.color = color;
            } else if (!success) {
                div.style.display = 'none';
            }
        });
    }

    function initGeo() {
        if(!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(async pos => {
            try {
                const r = await fetch(`https://api-adresse.data.gouv.fr/reverse/?lon=${pos.coords.longitude}&lat=${pos.coords.latitude}`);
                const j = await r.json();
                if(j.features && j.features.length) {
                    const cp = j.features[0].properties.postcode;
                    const city = j.features[0].properties.city;
                    const div = document.getElementById('nearby-section');
                    // On construit l'appel spécifique pour nearby
                    const seed = Math.random();
                    const url = `api/get_home_category.php?cat=nearby&dept=${cp}&uid=${USER_ID}&seed=${seed}`;
                    // Inject manuellement car paramètre spécial dept
                    const res = await fetch(url);
                    const html = await res.text();
                    if(html.trim()) {
                        div.innerHTML = `
                        <div class="section-wrapper">
                            <div class="section-header">
                                <i class="fa-solid fa-location-dot" style="color:#222;"></i>
                                <h3 class="section-title">Autour de chez moi (${city})</h3>
                            </div>
                            <div class="carousel-container">
                                <div class="carousel-track">${html}</div>
                            </div>
                        </div>`;
                    }
                }
            } catch(e){}
        });
    }
</script>

<?php require_once 'partials/footer.php'; ?>
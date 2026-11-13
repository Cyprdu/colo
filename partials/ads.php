<?php
/**
 * partials/ads.php : emplacements publicitaires AdSense (protocole d'optimisation)
 *
 *  - un conteneur à identifiant unique avec min-height réservée : aucun décalage de mise en page (CLS)
 *  - l'annonce n'est demandée que lorsque l'emplacement approche de l'écran (IntersectionObserver, assets/ads.js)
 *  - jamais d'actualisation automatique, jamais de pop-up ; les annonces ancrées et vignettes se règlent
 *    dans AdSense > Annonces automatiques (vignette plafonnée à 1 fois par heure)
 *  - emplacement vide si l'identifiant de bloc n'est pas encore renseigné : rien ne s'affiche, rien ne casse
 *
 * Usage : ad_slot('accueil-1', ADS_SLOTS['accueil'], ['minh' => 280]);
 */
const ADS_CLIENT = 'ca-pub-3659884670016121';

/** Identifiants de blocs d'annonces (AdSense > Annonces > Par bloc d'annonces) */
const ADS_SLOTS = [
    'article'  => '6405652824',   // In-article  : aide, contenus longs
    'detail'   => '7384171500',   // Display     : fiche séjour (colonne de droite)
    'contact'  => '1183215923',   // Horizontal  : contact
    'favoris'  => '8390131413',   // In-feed     : favoris
    'accueil'  => '',             // Display     : accueil, entre deux carrousels
    'recherche' => '',            // Display     : bas de la recherche
    'detail_article' => '',       // In-article  : fiche séjour, sous la description
];

function ad_slot(string $id, string $slot, array $o = []): void {
    if ($slot === '') return;                          // pas encore créé dans AdSense
    $minh   = (int)($o['minh'] ?? 250);
    $format = (string)($o['format'] ?? 'auto');        // auto | fluid | horizontal | rectangle
    $layout = (string)($o['layout'] ?? '');            // in-article | (clé d'in-feed)
    $key    = preg_replace('/[^a-z0-9_-]/i', '', $id);
    echo '<aside class="ad-wrap ' . htmlspecialchars((string)($o['class'] ?? '')) . '" aria-label="Publicité">'
       . '<span class="ad-label">Publicité</span>'
       . '<div id="adbox-' . $key . '" class="ad-box" data-ad-slot="' . htmlspecialchars($slot) . '" data-ad-format="' . htmlspecialchars($format) . '"'
       . ($layout !== '' ? ' data-ad-layout="' . htmlspecialchars($layout) . '"' : '') . '></div></aside>'
       // la règle est posée PAR IDENTIFIANT : le script d'AdSense ne peut pas l'annuler
       . '<style>#adbox-' . $key . '{min-height:' . $minh . 'px}</style>';
}

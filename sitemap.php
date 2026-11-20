<?php
// sitemap.php (servi en /sitemap.xml) : pages fixes, organismes ayant des séjours publics, séjours publics à venir (une adresse par séjour), versions FR et EN.
require_once 'api/config.php';
require_once 'api/organismes.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
orga_ensure($pdo);
$B = 'https://moncamp.fr/';
$urls = [];
foreach (['' => '1.0', 'recherche' => '0.9', 'organisme' => '0.7', 'tarifs-organisateurs' => '0.6', 'devenir-organisateur' => '0.6', 'a_propos' => '0.5', 'aide' => '0.5', 'contact' => '0.4', 'classement' => '0.3', 'legal' => '0.2'] as $p => $prio)
    $urls[] = [$B . $p, $prio];
$pub = "c.valide = 1 AND c.en_attente = 0 AND c.refuse = 0 AND COALESCE(c.supprime, 0) = 0 AND COALESCE(c.prive, 0) = 0 AND COALESCE(c.date_fin, c.date_debut) >= CURDATE()";
foreach ($pdo->query("SELECT o.slug FROM organisateurs o JOIN camps c ON c.organisateur_id = o.id WHERE o.slug IS NOT NULL AND $pub GROUP BY o.id, o.slug") as $o)
    $urls[] = [$B . 'organisme/' . $o['slug'], '0.6'];
$vus = [];
foreach ($pdo->query("SELECT c.token, c.groupe_id, c.id FROM camps c WHERE c.token IS NOT NULL AND c.token <> '' AND $pub ORDER BY c.date_debut, c.id") as $c) {
    $g = $c['groupe_id'] ? 'g' . $c['groupe_id'] : 'c' . $c['id'];
    if (isset($vus[$g])) continue;
    $vus[$g] = 1;
    $urls[] = [$B . 'camp_details?t=' . $c['token'], '0.8'];
}
$x = fn($s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">', "\n";
foreach ($urls as [$u, $prio]) {
    $en = $u . (str_contains($u, '?') ? '&' : '?') . 'lang=en';
    echo '<url><loc>', $x($u), '</loc><priority>', $prio, '</priority>',
         '<xhtml:link rel="alternate" hreflang="fr" href="', $x($u), '"/><xhtml:link rel="alternate" hreflang="en" href="', $x($en), '"/><xhtml:link rel="alternate" hreflang="x-default" href="', $x($u), '"/></url>', "\n";
}
echo '</urlset>', "\n";

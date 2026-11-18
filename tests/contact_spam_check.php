<?php
require __DIR__ . '/../api/adm_core.php';
require __DIR__ . '/../api/contact_lib.php';
function ok($c, $m) { if (!$c) { fwrite(STDERR, "ECHEC : $m\n"); exit(1); } echo "ok  $m\n"; }
session_id('testsession123');
$old = (time() - 30) . '.' . substr(hash_hmac('sha256', 'contact|' . (time() - 30) . '|testsession123', adm_key('contact')), 0, 20);
$f = ['ts' => $old, 'nom' => 'Martin', 'prenom' => 'Julie', 'message' => 'Bonjour, mon fils peut-il partir avec son frère sur le séjour de juillet ?'];
ok(contact_spam_reason($f, false) === null, 'message normal accepté');
ok(contact_spam_reason($f + ['site_web' => 'x'], false) === 'pot de miel rempli', 'pot de miel');
ok(contact_spam_reason(['ts' => contact_form_ts()] + $f, false) === 'envoyé trop vite', 'délai minimal');
ok(contact_spam_reason(['ts' => ''] + $f, false) === 'jeton de formulaire absent', 'jeton absent');
ok(contact_spam_reason(['ts' => '1234567890.aaaaaaaaaaaaaaaaaaaa'] + $f, false) === 'jeton de formulaire absent', 'jeton forgé');
ok(contact_spam_reason(['message' => 'Visit https://cheap.example now'] + $f, false) === 'liens externes', 'lien externe anonyme');
ok(contact_spam_reason(['message' => 'Voir https://moncamp.fr/camp_details?t=abc merci beaucoup'] + $f, false) === null, 'lien moncamp.fr accepté');
ok(contact_spam_reason(['message' => 'Voici https://a.example et https://b.example'] + $f, true) === 'liens externes', 'deux liens externes connecté');
ok(contact_spam_reason(['message' => 'Best SEO services and backlinks for you'] + $f, false) === 'mots-clés de spam', 'mots-clés');
ok(contact_spam_reason(['message' => 'Привет, хотим предложить вам продвижение сайта'] + $f, false) === 'alphabet inhabituel', 'alphabet');
ok(contact_spam_reason(['message' => '[url=http://x]x[/url] bonjour'] + $f, true) === 'balises de lien', 'BBCode');
echo "Tous les contrôles passent.\n";

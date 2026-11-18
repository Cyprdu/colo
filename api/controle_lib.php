<?php
// api/controle_lib.php : tests du « Tableau de contrôle » (tableau_de_controle.php, administrateurs uniquement).
// Chaque test retourne ['s' => ok|warn|ko|info, 'd' => constat, 't' => quoi faire (optionnel)]. Lecture seule : aucun test ne modifie de donnée.
// Les secrets ne sont jamais affichés en entier (ctl_mask). Seuls les services du projet sont appelés (Stripe, Google, Cloudflare, ce site).
require_once __DIR__ . '/finance_lib.php';

function ctl_r(string $s, string $d, string $t = ''): array { return ['s' => $s, 'd' => $d, 't' => $t]; }
function ctl_mask(string $v): string { return strlen($v) > 12 ? substr($v, 0, 7) . '…' . substr($v, -3) : '(court)'; }
function ctl_site(): string { return rtrim(defined('BASE_URL') ? BASE_URL : 'https://moncamp.fr', '/'); }
/** [code HTTP, corps, ms] ; $post = tableau (formulaire) ou null ; $head = true : en-têtes seulement */
function ctl_http(string $url, ?array $post = null, bool $head = false): array {
    $t = microtime(true); $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 9, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'moncamp.fr-controle/1.0', CURLOPT_NOBODY => $head]);
    if ($post !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $b = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    return [$b === false ? 0 : $c, $b === false ? $err : (string)$b, (int)round((microtime(true) - $t) * 1000)];
}
function ctl_def(string $n): string { return defined($n) ? (string)constant($n) : ''; }
function ctl_file(string $rel): string { $p = __DIR__ . '/../' . $rel; return is_file($p) ? (string)file_get_contents($p) : ''; }
function ctl_stripe_call(callable $f): array {
    if (!preg_match('/^(sk|rk)_(test|live)_/', ctl_def('STRIPE_SECRET_KEY'))) return ctl_r('ko', 'Clé secrète Stripe absente ou invalide.', 'Renseignez STRIPE_SECRET_KEY dans api/config.php (Stripe > Développeurs > Clés API).');
    try { return $f(fin_stripe()); } catch (\Stripe\Exception\AuthenticationException $e) { return ctl_r('ko', 'Stripe refuse la clé : ' . $e->getMessage(), 'Générez une nouvelle clé secrète dans Stripe > Développeurs > Clés API et mettez-la dans api/config.php.'); }
    catch (Throwable $e) { return ctl_r('warn', 'Appel Stripe impossible : ' . mb_substr($e->getMessage(), 0, 180)); }
}

/** Liste des tests : id => [groupe, libellé, fonction] */
function ctl_tests(): array {
    global $pdo;
    $T = [];
    $add = function (string $id, string $g, string $l, callable $f) use (&$T) { $T[$id] = [$g, $l, $f]; };

    // ------------------------------------------------------------ Serveur et site
    $add('php', 'Serveur et site', 'Version de PHP et extensions', function () {
        $need = ['pdo_mysql', 'openssl', 'curl', 'mbstring', 'gd', 'fileinfo', 'json']; $miss = array_values(array_filter($need, fn($e) => !extension_loaded($e)));
        if (version_compare(PHP_VERSION, '8.0.0', '<')) return ctl_r('warn', 'PHP ' . PHP_VERSION . ' : ancien.', 'Passez en PHP 8.1 ou plus dans hPanel Hostinger > Avancé > Configuration PHP.');
        return $miss ? ctl_r('ko', 'Extensions manquantes : ' . implode(', ', $miss), 'Activez-les dans hPanel > Configuration PHP > Extensions.') : ctl_r('ok', 'PHP ' . PHP_VERSION . ', extensions requises présentes.');
    });
    $add('https', 'Serveur et site', 'Site accessible en HTTPS, HTTP redirigé', function () {
        $s = ctl_site(); [$c, , $ms] = ctl_http($s . '/', null, true);
        if ($c !== 200) return ctl_r('ko', "La page d'accueil répond $c.", 'Vérifiez le déploiement Hostinger et le certificat SSL.');
        [$c2] = ctl_http(preg_replace('#^https:#', 'http:', $s) . '/', null, true);
        return in_array($c2, [301, 302, 308], true) ? ctl_r('ok', "Accueil en $ms ms ; HTTP redirige vers HTTPS.") : ctl_r('warn', "Accueil OK ($ms ms) mais HTTP répond $c2 sans redirection.", 'Activez « Forcer HTTPS » dans hPanel > Sécurité > SSL.');
    });
    $add('pages', 'Serveur et site', 'Pages clés (recherche, connexion, sitemap, robots)', function () {
        $s = ctl_site(); $bad = [];
        foreach (['recherche', 'login.php', 'sitemap.xml', 'robots.txt', 'organisme'] as $p) { [$c] = ctl_http("$s/$p", null, true); if ($c !== 200) $bad[] = "$p ($c)"; }
        return $bad ? ctl_r('ko', 'Réponse anormale : ' . implode(', ', $bad), 'Ouvrez ces pages dans un navigateur et consultez les journaux d\'erreurs (admin_erreurs.php).') : ctl_r('ok', 'Toutes répondent 200.');
    });
    $add('expose', 'Serveur et site', 'Fichiers sensibles non accessibles depuis Internet', function () {
        $s = ctl_site(); $open = [];
        foreach (['.git/config', '.env', 'securite.patch', 'reset_inscriptions_finances.sql', 'chek.json', 'fail.json', 'composer.lock', 'vendor/composer/installed.json', 'api/config.php.bak', 'uploads/sante/.htaccess', 'DOCUMENTS_RGPD.md', 'RAPPORT_RGPD_CONFORMITE.md', 'CE_QUE_VOUS_DEVEZ_FAIRE.pdf'] as $p) { [$c] = ctl_http("$s/$p", null, true); if ($c === 200) $open[] = $p; }
        return $open ? ctl_r('ko', 'Lisibles publiquement : ' . implode(', ', $open), 'Supprimez ces fichiers du dépôt ou bloquez-les dans .htaccess (<FilesMatch>). Ils ne servent pas au site.') : ctl_r('ok', 'Aucun des fichiers testés n\'est lisible.');
    });
    $add('disque', 'Serveur et site', 'Espace disque et dossiers d\'envoi', function () {
        $free = @disk_free_space(__DIR__ . '/..'); $bad = [];
        foreach (['uploads', 'uploads/sante'] as $d) { $p = __DIR__ . '/../' . $d; if (is_dir($p) && !is_writable($p)) $bad[] = $d; }
        if ($bad) return ctl_r('ko', 'Dossiers non inscriptibles : ' . implode(', ', $bad), 'Mettez les droits 755 (ou 775) sur ces dossiers via le gestionnaire de fichiers.');
        return $free !== false && $free < 500 * 1048576 ? ctl_r('warn', 'Espace libre faible : ' . round($free / 1048576) . ' Mo.') : ctl_r('ok', 'Dossiers inscriptibles' . ($free !== false ? ', ' . round($free / 1073741824, 1) . ' Go libres.' : '.'));
    });
    $add('erreurs', 'Serveur et site', 'Erreurs applicatives récentes (24 h)', function () use ($pdo) {
        try { $n = (int)$pdo->query("SELECT COUNT(*) FROM app_erreurs WHERE created_at > NOW() - INTERVAL 1 DAY")->fetchColumn(); }
        catch (Throwable $e) { return ctl_r('info', 'Journal d\'erreurs indisponible.'); }
        return $n > 20 ? ctl_r('warn', "$n erreurs en 24 h.", 'Ouvrez admin_erreurs.php pour voir les plus fréquentes.') : ctl_r('ok', "$n erreur(s) en 24 h.");
    });

    // ------------------------------------------------------------ Base de données
    $add('db', 'Base de données', 'Connexion et tables essentielles', function () use ($pdo) {
        $t = microtime(true); $pdo->query('SELECT 1'); $ms = (int)round((microtime(true) - $t) * 1000);
        $have = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $miss = array_values(array_diff(['users', 'organisateurs', 'camps', 'inscriptions', 'enfants', 'tarifs', 'fin_paiements', 'fin_transferts', 'stripe_events', 'acces_sante'], $have));
        return $miss ? ctl_r('warn', 'Tables absentes (créées au premier usage) : ' . implode(', ', $miss)) : ctl_r('ok', count($have) . " tables, requête en $ms ms.");
    });
    $add('admins', 'Base de données', 'Comptes administrateurs', function () use ($pdo) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn();
        return $n < 1 ? ctl_r('ko', 'Aucun administrateur.') : ($n > 3 ? ctl_r('warn', "$n administrateurs.", 'Réduisez-les au strict nécessaire (admin_list.php?e=users).') : ctl_r('ok', "$n administrateur(s)."));
    });

    // ------------------------------------------------------------ Stripe : paiements
    $add('s_mode', 'Stripe : paiements', 'Mode (test ou production) et cohérence des clés', function () {
        $sk = ctl_def('STRIPE_SECRET_KEY'); $pk = ctl_def('STRIPE_PUBLISHABLE_KEY');
        $m = str_contains($sk, '_live_') ? 'live' : (str_contains($sk, '_test_') ? 'test' : '?');
        if ($m === '?') return ctl_r('ko', 'Clé secrète illisible.', 'Vérifiez STRIPE_SECRET_KEY dans api/config.php.');
        if ($pk !== '' && !str_contains($pk, "_{$m}_")) return ctl_r('ko', "Clé publiable et secrète de modes différents (secrète : $m).", 'Prenez les deux clés dans le même mode (Stripe > Clés API).');
        return $m === 'live' ? ctl_r('ok', 'MODE PRODUCTION : les paiements sont réels (' . ctl_mask($sk) . ').') : ctl_r('warn', 'MODE TEST : aucun vrai paiement ne passe (' . ctl_mask($sk) . ').', 'Pour ouvrir au public : activez votre compte Stripe, remplacez STRIPE_SECRET_KEY, STRIPE_PUBLISHABLE_KEY et STRIPE_WEBHOOK_SECRET par les clés « live », et recréez les webhooks en mode live.');
    });
    $add('s_api', 'Stripe : paiements', 'Clé secrète acceptée par Stripe, solde de la plateforme', fn() => ctl_stripe_call(function ($st) {
        $b = $st->balance->retrieve(); $a = $p = 0;
        foreach ($b->available as $x) if ($x->currency === 'eur') $a += $x->amount; foreach ($b->pending as $x) if ($x->currency === 'eur') $p += $x->amount;
        return ctl_r('ok', 'API joignable (' . ($b->livemode ? 'live' : 'test') . '). Disponible ' . fin_eur($a) . ' · en attente ' . fin_eur($p) . '. Les versements aux organismes sont prélevés sur le solde DISPONIBLE.');
    }));
    $add('s_account', 'Stripe : paiements', 'Compte Stripe de la plateforme activé', fn() => ctl_stripe_call(function ($st) {
        $a = $st->accounts->retrieve();
        if (!$a->charges_enabled) return ctl_r('ko', 'Encaissement désactivé sur le compte.', 'Terminez l\'activation du compte dans le Dashboard Stripe (identité, IBAN).');
        return ctl_r($a->payouts_enabled ? 'ok' : 'warn', 'Encaissement activé, pays ' . ($a->country ?? '?') . ', virements vers banque ' . ($a->payouts_enabled ? 'activés' : 'DÉSACTIVÉS') . '.', $a->payouts_enabled ? '' : 'Ajoutez un IBAN dans Stripe > Paramètres > Comptes bancaires.');
    }));
    $add('s_checkout', 'Stripe : paiements', 'Création d\'une session de paiement (test sans débit) et moyens proposés', fn() => ctl_stripe_call(function ($st) {
        $s = $st->checkout->sessions->create(['mode' => 'payment', 'locale' => 'fr', 'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'eur', 'unit_amount' => 100, 'product_data' => ['name' => 'Test tableau de contrôle']]]],
            'success_url' => ctl_site() . '/', 'cancel_url' => ctl_site() . '/', 'expires_at' => time() + 1800]);
        try { $st->checkout->sessions->expire($s->id); } catch (Throwable $e) {}
        $pm = implode(', ', (array)$s->payment_method_types);
        return ctl_r(in_array('card', (array)$s->payment_method_types, true) ? 'ok' : 'ko', 'Session créée puis expirée. Moyens proposés aux familles : ' . ($pm ?: 'aucun') . '.', 'Les moyens se règlent dans Stripe > Paramètres > Moyens de paiement.');
    }));
    $add('s_methods', 'Stripe : paiements', 'Apple Pay, Google Pay, Link activés', fn() => ctl_stripe_call(function ($st) {
        $out = []; foreach ($st->paymentMethodConfigurations->all(['limit' => 5])->data as $c) { if (!$c->is_default) continue; foreach (['card', 'apple_pay', 'google_pay', 'link'] as $k) $out[$k] = ($c->$k->display_preference->value ?? 'off') !== 'off' && !empty($c->$k->available); }
        $miss = array_keys(array_filter($out, fn($v) => !$v));
        return !$out ? ctl_r('info', 'Configuration par défaut introuvable.') : ($miss ? ctl_r('warn', 'Non disponibles : ' . implode(', ', $miss), 'Stripe > Paramètres > Moyens de paiement : activez-les (Apple Pay demande aussi la vérification du domaine).') : ctl_r('ok', 'Carte, Apple Pay, Google Pay et Link disponibles.'));
    }));
    $add('s_applepay', 'Stripe : paiements', 'Domaine vérifié pour Apple Pay', fn() => ctl_stripe_call(function ($st) {
        $d = array_map(fn($x) => $x->domain_name, $st->applePayDomains->all(['limit' => 20])->data); $h = parse_url(ctl_site(), PHP_URL_HOST);
        return in_array($h, $d, true) || in_array('www.' . $h, $d, true) ? ctl_r('ok', "Domaine $h enregistré.") : ctl_r('warn', "Domaine $h non enregistré pour Apple Pay.", 'Stripe > Paramètres > Moyens de paiement > Apple Pay > Ajouter un nouveau domaine : moncamp.fr (fichier de vérification à déposer dans .well-known).');
    }));

    // ------------------------------------------------------------ Stripe : webhooks
    $add('w_secret', 'Stripe : webhooks', 'Secrets de signature des webhooks', function () {
        $a = ctl_def('STRIPE_WEBHOOK_SECRET'); $b = ctl_def('STRIPE_CONNECT_WEBHOOK_SECRET'); $m = [];
        if (!str_starts_with($a, 'whsec_')) $m[] = 'STRIPE_WEBHOOK_SECRET';
        if (!str_starts_with($b, 'whsec_')) $m[] = 'STRIPE_CONNECT_WEBHOOK_SECRET';
        return $m ? ctl_r('ko', 'Manquant : ' . implode(', ', $m), 'Stripe > Développeurs > Webhooks > votre endpoint > « Secret de signature », à copier dans api/config.php (un endpoint « Votre compte », un « Comptes connectés »).') : ctl_r('ok', 'Les deux secrets sont renseignés.');
    });
    $add('w_endpoints', 'Stripe : webhooks', 'Endpoints enregistrés chez Stripe et événements écoutés', fn() => ctl_stripe_call(function ($st) {
        $need = ['checkout.session.completed', 'checkout.session.expired', 'checkout.session.async_payment_succeeded', 'charge.refunded', 'charge.dispute.created', 'charge.dispute.closed', 'transfer.reversed', 'account.updated', 'payout.failed'];
        $ev = []; $found = 0; $off = 0;
        foreach ($st->webhookEndpoints->all(['limit' => 30])->data as $e) { if (!str_contains($e->url, '/api/stripe_webhook.php')) continue; $found++; if ($e->status !== 'enabled') $off++; foreach ($e->enabled_events as $x) $ev[$x] = true; }
        if (!$found) return ctl_r('ko', 'Aucun endpoint vers /api/stripe_webhook.php.', 'Stripe > Développeurs > Webhooks > Ajouter : https://moncamp.fr/api/stripe_webhook.php (2 endpoints : votre compte + comptes connectés).');
        $miss = isset($ev['*']) ? [] : array_values(array_diff($need, array_keys($ev)));
        if ($off) return ctl_r('ko', "$off endpoint(s) désactivé(s).", 'Réactivez-les dans Stripe > Webhooks.');
        return $miss ? ctl_r('warn', "$found endpoint(s). Événements non écoutés : " . implode(', ', $miss), 'Ajoutez ces événements à vos endpoints.') : ctl_r('ok', "$found endpoint(s) actif(s), tous les événements nécessaires écoutés.");
    }));
    $add('w_recus', 'Stripe : webhooks', 'Webhooks effectivement reçus', function () use ($pdo) {
        fin_ensure($pdo); $r = $pdo->query("SELECT MAX(created_at) d, COUNT(*) n FROM stripe_events")->fetch(PDO::FETCH_ASSOC);
        if (!(int)$r['n']) return ctl_r('warn', 'Aucun événement reçu à ce jour.', 'Faites un paiement test ; sinon Stripe > Webhooks > votre endpoint > « Événements » indique l\'erreur renvoyée par le site.');
        return ctl_r('ok', $r['n'] . ' événements, dernier le ' . date('d/m/Y H:i', strtotime($r['d'])) . '.');
    });

    // ------------------------------------------------------------ Versements
    $add('v_cron', 'Versements aux organismes', 'Tâche planifiée (cron) active', function () use ($pdo) {
        fin_ensure($pdo);
        if (ctl_def('CRON_SECRET') === '') return ctl_r('ko', 'CRON_SECRET non défini : le cron HTTP est inutilisable.', "Ajoutez define('CRON_SECRET', '<chaîne aléatoire>'); dans api/config.php puis planifiez dans hPanel > Avancé > Tâches Cron : une fois par heure, URL " . ctl_site() . '/api/cron_tout.php?key=VOTRE_CLE');
        $d = $pdo->query("SELECT MAX(created_at) FROM fin_audit WHERE action = 'cron_deblocage'")->fetchColumn();
        if (!$d) return ctl_r('warn', 'Le cron n\'a jamais tourné.', 'hPanel > Avancé > Tâches Cron : toutes les heures, GET ' . ctl_site() . '/api/cron_tout.php?key=VOTRE_CLE');
        $h = (time() - strtotime($d)) / 3600;
        return $h > 3 ? ctl_r('ko', 'Dernier passage il y a ' . round($h) . ' h (' . date('d/m H:i', strtotime($d)) . ').', 'Le cron est arrêté : vérifiez la tâche dans hPanel.') : ctl_r('ok', 'Dernier passage ' . date('d/m H:i', strtotime($d)) . '.');
    });
    $add('v_transferts', 'Versements aux organismes', 'Transferts en échec ou bloqués', function () use ($pdo) {
        fin_ensure($pdo);
        $e = (int)$pdo->query("SELECT COUNT(*) FROM fin_transferts WHERE statut = 'echec' AND created_at > NOW() - INTERVAL 30 DAY")->fetchColumn();
        $b = (int)$pdo->query("SELECT COUNT(*) FROM fin_transferts WHERE statut = 'en_cours' AND created_at < NOW() - INTERVAL 1 HOUR")->fetchColumn();
        return ($e || $b) ? ctl_r('warn', "$e échec(s) sur 30 j, $b en cours depuis plus d'une heure.", 'Admin > Versements : voir l\'erreur de chaque transfert (solde Stripe insuffisant, compte non prêt…).') : ctl_r('ok', 'Aucun échec récent.');
    });
    $add('v_comptes', 'Versements aux organismes', 'Organismes avec de l\'argent à recevoir mais compte Stripe non prêt', function () use ($pdo) {
        fin_ensure($pdo);
        $r = $pdo->query("SELECT o.nom FROM fin_paiements p JOIN organisateurs o ON o.id = p.organisateur_id LEFT JOIN stripe_comptes s ON s.organisateur_id = o.id
                          WHERE p.statut = 'attente' AND (s.organisateur_id IS NULL OR s.transfers_actif = 0 OR s.payouts_enabled = 0) GROUP BY o.id, o.nom LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
        return $r ? ctl_r('warn', 'À relancer : ' . implode(', ', array_map('html_entity_decode', $r)), 'Ces organismes doivent terminer l\'inscription Stripe depuis leur tableau de bord.') : ctl_r('ok', 'Tous les organismes concernés sont prêts.');
    });
    $add('v_regles', 'Versements aux organismes', 'Règles en vigueur', function () {
        $r = fin_reglages(true);
        return ctl_r('info', 'Délai de sécurité ' . $r['delai_securite_jours'] . ' j · réserve ' . $r['reserve_pct'] . ' % · commission ' . $r['commission_pct'] . ' % + ' . fin_eur($r['commission_fixe_cents']) . ' (hors carte ' . $r['commission_hors_pct'] . ' %).');
    });

    // ------------------------------------------------------------ Connexion Google
    $g = function () { $f = ctl_file('api/google_config.php'); preg_match("/google_client_id\s*=\s*'([^']*)'/", $f, $a); preg_match("/google_client_secret\s*=\s*'([^']*)'/", $f, $b); preg_match("/google_redirect_url\s*=\s*'([^']*)'/", $f, $c); return [$a[1] ?? '', $b[1] ?? '', $c[1] ?? '']; };
    $add('g_conf', 'Connexion Google', 'Identifiants OAuth dans api/google_config.php', function () use ($g) {
        [$id, $sec, $red] = $g();
        if (!str_ends_with($id, '.apps.googleusercontent.com') || strlen($sec) < 20) return ctl_r('ko', 'Identifiant ou secret client absent ou mal formé.', 'Voir le tutoriel « Réparer la connexion Google » ci-dessous.');
        if ($red !== ctl_site() . '/api/google_callback.php') return ctl_r('warn', "URL de retour : $red (attendue : " . ctl_site() . '/api/google_callback.php).');
        return ctl_r('ok', 'ID client ' . ctl_mask($id) . ', secret présent, URL de retour conforme.');
    });
    $add('g_lib', 'Connexion Google', 'Bibliothèque Google chargée', fn() => class_exists('Google_Client') ? ctl_r('ok', 'Google_Client disponible.') : ctl_r('ko', 'Classe Google_Client introuvable.', 'Le dossier vendor/google est absent du déploiement : relancez « composer install » ou redéployez vendor/.'));
    $add('g_client', 'Connexion Google', 'Google reconnaît l\'ID client et l\'URL de retour', function () use ($g) {
        [$id, , $red] = $g(); [$c, $b] = ctl_http('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(['client_id' => $id, 'redirect_uri' => $red, 'response_type' => 'code', 'scope' => 'email profile']));
        if (str_contains($b, 'redirect_uri_mismatch')) return ctl_r('ko', 'Google : redirect_uri_mismatch.', "Google Cloud > API et services > Identifiants > votre client OAuth > « URI de redirection autorisés » : ajoutez EXACTEMENT $red");
        if (str_contains($b, 'deleted_client') || str_contains($b, 'invalid_client') || $c === 401) return ctl_r('ko', 'Google : client supprimé ou inconnu.', 'Le client OAuth n\'existe plus (ou mauvais projet). Créez-en un nouveau (tutoriel ci-dessous).');
        if (str_contains($b, 'access_denied') || str_contains($b, 'disallowed_useragent')) return ctl_r('warn', 'Google refuse l\'accès : ' . mb_substr(strip_tags($b), 0, 100));
        return $c === 200 || $c === 302 || $c === 303 ? ctl_r('ok', 'Google accepte le client et l\'URL de retour (code ' . $c . ').') : ctl_r('warn', "Réponse inattendue ($c).");
    });
    $add('g_secret', 'Connexion Google', 'Secret client valide (échange de jeton à blanc)', function () use ($g) {
        [$id, $sec, $red] = $g(); [$c, $b] = ctl_http('https://oauth2.googleapis.com/token', ['grant_type' => 'authorization_code', 'code' => 'test-controle', 'redirect_uri' => $red, 'client_id' => $id, 'client_secret' => $sec]);
        $j = json_decode($b, true); $e = $j['error'] ?? '';
        if ($e === 'invalid_grant') return ctl_r('ok', 'Google accepte l\'ID et le secret (le faux code est refusé, c\'est normal).');
        if ($e === 'invalid_client') return ctl_r('ko', 'Secret client refusé (invalid_client : ' . ($j['error_description'] ?? '') . ').', 'C\'est très probablement la cause du blocage : le secret a été régénéré/désactivé. Google Cloud > Identifiants > client OAuth > ajoutez un nouveau secret et copiez-le dans api/google_config.php.');
        return ctl_r('warn', "Réponse : $c " . mb_substr($b, 0, 120));
    });
    $add('g_users', 'Connexion Google', 'Comptes utilisant Google', function () use ($pdo) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE google_id IS NOT NULL AND google_id <> ''")->fetchColumn();
        return ctl_r('info', "$n compte(s) liés à Google.", 'Écran de consentement OAuth : il doit être « En production » (pas « Test »), sinon seuls 100 testeurs déclarés peuvent se connecter et la session expire après 7 jours.');
    });

    // ------------------------------------------------------------ Anti-robot
    $add('t_conf', 'Anti-robot (Turnstile)', 'Clés Turnstile configurées', function () {
        $k = ctl_def('TURNSTILE_SITE_KEY'); $s = ctl_def('TURNSTILE_SECRET');
        if ($k === '' || $s === '') return ctl_r('warn', 'Turnstile inactif : les formulaires ne sont pas protégés par CAPTCHA.', "Cloudflare > Turnstile > Ajouter un site (moncamp.fr), puis dans api/config.php : define('TURNSTILE_SITE_KEY','…'); define('TURNSTILE_SECRET','…');");
        return str_starts_with($k, '1x0000') || str_starts_with($k, '2x0000') || str_starts_with($k, '3x0000') ? ctl_r('warn', 'Clés de TEST Turnstile (elles laissent tout passer).', 'Remplacez-les par les vraies clés du site moncamp.fr.') : ctl_r('ok', 'Clés renseignées (' . ctl_mask($k) . ').');
    });
    $add('t_api', 'Anti-robot (Turnstile)', 'Secret Turnstile accepté par Cloudflare', function () {
        if (ctl_def('TURNSTILE_SECRET') === '') return ctl_r('info', 'Non configuré.');
        [, $b] = ctl_http('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['secret' => TURNSTILE_SECRET, 'response' => 'test-controle']); $j = json_decode($b, true); $e = (array)($j['error-codes'] ?? []);
        if (in_array('invalid-input-secret', $e, true)) return ctl_r('ko', 'Secret Turnstile refusé.', 'Recopiez le secret depuis Cloudflare > Turnstile.');
        return in_array('invalid-input-response', $e, true) ? ctl_r('ok', 'Cloudflare accepte le secret.') : ctl_r('warn', 'Réponse : ' . mb_substr($b, 0, 120));
    });
    $add('t_forms', 'Anti-robot (Turnstile)', 'Formulaires protégés', function () {
        $n = 0; foreach (['login.php', 'register.php', 'contact.php'] as $f) if (str_contains(ctl_file($f), 'turnstile')) $n++;
        return $n >= 2 ? ctl_r('ok', "$n/3 formulaires (connexion, inscription, contact) utilisent le CAPTCHA.") : ctl_r('warn', "Seulement $n/3 formulaires protégés.", 'Ajoutez sec_turnstile_widget() et sec_turnstile_ok() aux formulaires publics.');
    });

    // ------------------------------------------------------------ Publicités Google
    $add('a_txt', 'Publicités Google', 'ads.txt en ligne avec votre identifiant', function () {
        [$c, $b] = ctl_http(ctl_site() . '/ads.txt'); $pub = 'pub-3659884670016121';
        return $c === 200 && str_contains($b, $pub) ? ctl_r('ok', 'ads.txt publié avec ' . $pub . '.') : ctl_r('ko', "ads.txt introuvable ou sans $pub (code $c).", 'Déposez ads.txt à la racine du site (ligne : google.com, ' . $pub . ', DIRECT, f08c47fec0942fa0).');
    });
    $add('a_script', 'Publicités Google', 'Script AdSense présent dans les pages', function () {
        [$c, $b] = ctl_http(ctl_site() . '/');
        return str_contains($b, 'adsbygoogle.js?client=ca-pub-3659884670016121') ? ctl_r('ok', 'Script AdSense chargé sur l\'accueil.') : ctl_r('warn', 'Script AdSense absent de l\'accueil.', 'Vérifiez partials/header.php.');
    });
    $add('a_statut', 'Publicités Google', 'Validation du site par AdSense', fn() => ctl_r('info', 'Non vérifiable automatiquement.', 'AdSense > Sites : moncamp.fr doit être « Prêt ». Les pubs n\'apparaissent qu\'après validation, et seulement si le consentement cookies est donné (RGPD).'));

    // ------------------------------------------------------------ E-mail
    $add('m_smtp', 'E-mail', 'Serveur d\'envoi joignable', function () {
        $f = ctl_file('api/mail_config.php'); if (!str_contains($f, 'smtp.gmail.com')) return ctl_r('info', 'Configuration d\'envoi non standard.');
        $s = @fsockopen('smtp.gmail.com', 587, $en, $es, 5);
        if (!$s) return ctl_r('ko', 'Connexion à smtp.gmail.com:587 impossible (' . $es . ').', 'Hostinger bloque peut-être le port 587 : essayez le port 465 (SSL) ou un SMTP Hostinger.');
        fclose($s);
        return ctl_r('ok', 'smtp.gmail.com:587 joignable.', 'Les identifiants (mot de passe d\'application) ne sont testés qu\'à l\'envoi réel : faites un « mot de passe oublié » pour valider.');
    });

    // ------------------------------------------------------------ Sécurité et RGPD
    $add('x_key', 'Sécurité et RGPD', 'Clé de chiffrement des documents de santé', function () {
        require_once __DIR__ . '/sante.php';
        if (!defined('HEALTH_KEY') || HEALTH_KEY === '') return ctl_r('ko', 'HEALTH_KEY absente : documents de santé stockés EN CLAIR.', "Générez : php -r \"echo bin2hex(random_bytes(32));\" puis define('HEALTH_KEY','…'); dans api/config.php. Sauvegardez la clé à part.");
        try { $ok = sante_decrypt(sante_encrypt('controle')) === 'controle' && strncmp(sante_encrypt('x'), SANTE_MAGIC, 6) === 0; } catch (Throwable $e) { $ok = false; }
        return $ok ? ctl_r('ok', 'Chiffrement AES-256-GCM opérationnel (aller-retour réussi).') : ctl_r('ko', 'Le chiffrement échoue.', 'Vérifiez l\'extension openssl et le format de la clé (64 caractères hexadécimaux).');
    });
    $add('x_secrets', 'Sécurité et RGPD', 'Secrets écrits en clair dans le code', function () {
        $hits = [];
        if (preg_match("/define\('STRIPE_SECRET_KEY',\s*'(sk|rk)_/", ctl_file('api/config.php'))) $hits[] = 'clé Stripe (config.php)';
        if (preg_match("/DB_PASS',\s*'[^']{3,}'/", ctl_file('api/config.php'))) $hits[] = 'mot de passe base de données (config.php)';
        if (preg_match("/GOCSPX-/", ctl_file('api/google_config.php'))) $hits[] = 'secret Google';
        if (preg_match("/Password\s*=\s*'[^']{8,}'/", ctl_file('api/mail_config.php'))) $hits[] = 'mot de passe e-mail';
        return $hits ? ctl_r('warn', 'Présents dans les fichiers du dépôt : ' . implode(', ', $hits) . '.', 'Si le dépôt GitHub est ou a été public, régénérez ces secrets. À terme : un fichier api/secrets.php hors Git (.gitignore) inclus par config.php.') : ctl_r('ok', 'Aucun secret en clair détecté.');
    });
    $add('x_sante', 'Sécurité et RGPD', 'Journal des accès aux données de santé', function () use ($pdo) {
        try { $n = (int)$pdo->query("SELECT COUNT(*) FROM acces_sante WHERE created_at > NOW() - INTERVAL 30 DAY")->fetchColumn(); } catch (Throwable $e) { return ctl_r('warn', 'Table acces_sante absente.'); }
        return ctl_r('ok', "$n accès enregistrés sur 30 jours (purge à 12 mois par cron_rgpd.php).");
    });
    $add('x_hdr', 'Sécurité et RGPD', 'En-têtes de sécurité du site', function () {
        $c = curl_init(ctl_site() . '/'); curl_setopt_array($c, [CURLOPT_NOBODY => true, CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]); $h = strtolower((string)curl_exec($c)); curl_close($c);
        $miss = array_values(array_filter(['strict-transport-security', 'x-content-type-options', 'x-frame-options'], fn($k) => !str_contains($h, $k . ':')));
        return $miss ? ctl_r('warn', 'Absents : ' . implode(', ', $miss), 'Ajoutez-les dans .htaccess (Header always set …).') : ctl_r('ok', 'HSTS, nosniff et anti-frame présents.');
    });
    return $T;
}

<?php
// test_stripe.php : diagnostic de la configuration (Stripe, webhooks, tâches planifiées, sécurité).
// Réservé aux administrateurs. LECTURE SEULE : aucune action financière, aucun secret affiché (seulement leur présence et leur mode).
require_once __DIR__ . '/admin_lib.php';
adm_guard(false, 'tech.security');

$checks = [];   // [section, libellé, statut ok|warn|bad, détail]
$add = function (string $sec, string $label, string $st, string $detail = '') use (&$checks) { $checks[] = [$sec, $label, $st, $detail]; };
$def = fn(string $c) => defined($c) && (string)constant($c) !== '';
$mode = fn(string $k) => strpos($k, '_live_') !== false ? 'production' : (strpos($k, '_test_') !== false ? 'test' : 'inconnu');

// ---------------------------------------------------------------- 1. Clés dans config.php
$S = 'Clés dans api/config.php';
$sk = $def('STRIPE_SECRET_KEY') ? (string)STRIPE_SECRET_KEY : '';
$pk = $def('STRIPE_PUBLISHABLE_KEY') ? (string)STRIPE_PUBLISHABLE_KEY : '';
$wh = $def('STRIPE_WEBHOOK_SECRET') ? (string)STRIPE_WEBHOOK_SECRET : '';
$whc = $def('STRIPE_CONNECT_WEBHOOK_SECRET') ? (string)STRIPE_CONNECT_WEBHOOK_SECRET : '';
$add($S, 'Clé secrète Stripe', $sk === '' ? 'bad' : (preg_match('/^(sk|rk)_(test|live)_/', $sk) ? 'ok' : 'bad'), $sk === '' ? 'Vide ou absente.' : (preg_match('/^(sk|rk)_(test|live)_/', $sk) ? 'Présente, mode ' . $mode($sk) . '.' : 'Format inattendu (doit commencer par sk_test_ ou sk_live_).'));
$add($S, 'Clé publiable Stripe', $pk === '' ? 'bad' : (preg_match('/^pk_(test|live)_/', $pk) ? 'ok' : 'bad'), $pk === '' ? 'Vide ou absente.' : (preg_match('/^pk_(test|live)_/', $pk) ? 'Présente, mode ' . $mode($pk) . '.' : 'Format inattendu (doit commencer par pk_test_ ou pk_live_).'));
if ($sk !== '' && $pk !== '') {
    $same = $mode($sk) === $mode($pk);
    $add($S, 'Clés du même mode (test/production)', $same ? 'ok' : 'bad', $same ? 'Les deux sont en mode ' . $mode($sk) . '.' : 'La clé secrète est en ' . $mode($sk) . ' et la publiable en ' . $mode($pk) . ' : elles doivent être du même mode.');
}
$add($S, 'Clé de signature du webhook principal', $wh === '' ? 'bad' : (strpos($wh, 'whsec_') === 0 ? 'ok' : 'warn'), $wh === '' ? "Absente : sans elle, les paiements ne sont jamais confirmés." : (strpos($wh, 'whsec_') === 0 ? 'Présente.' : 'Présente mais ne commence pas par whsec_.'));
$add($S, 'Clé de signature du webhook Connect (organismes)', $whc === '' ? 'warn' : (strpos($whc, 'whsec_') === 0 ? 'ok' : 'warn'), $whc === '' ? "Absente (STRIPE_CONNECT_WEBHOOK_SECRET) : nécessaire pour suivre l'inscription des organismes chez Stripe." : 'Présente.');
$add($S, 'Devise', $def('STRIPE_CURRENCY') && STRIPE_CURRENCY === 'eur' ? 'ok' : 'warn', 'STRIPE_CURRENCY = ' . ($def('STRIPE_CURRENCY') ? STRIPE_CURRENCY : 'non définie') . ' (attendu : eur).');
$add($S, 'Adresse du site (BASE_URL)', $def('BASE_URL') && strpos(BASE_URL, 'https://') === 0 ? 'ok' : 'bad', 'BASE_URL = ' . ($def('BASE_URL') ? BASE_URL : 'non définie') . " (doit être en https pour les retours de paiement).");

// ---------------------------------------------------------------- 2. Communication avec Stripe (lecture seule)
$S = 'Communication avec Stripe';
$client = null; $account = null;
if ($sk !== '' && class_exists('\Stripe\StripeClient')) {
    try {
        $client = new \Stripe\StripeClient($sk);
    } catch (Throwable $e) { $client = null; }
}
if (!$client && $sk !== '' && !class_exists('\Stripe\StripeClient')) $add($S, 'Bibliothèque Stripe', 'bad', 'Introuvable : le dossier vendor/ est-il bien déployé sur le serveur ?');
if ($client) {
    try {
        $client->balance->retrieve();
        $add($S, 'La clé secrète est acceptée par Stripe', 'ok', 'Appel de lecture réussi.');
    } catch (Throwable $e) {
        $add($S, 'La clé secrète est acceptée par Stripe', 'bad', 'Refusée : ' . mb_substr(preg_replace('/(sk|rk)_(test|live)_\w+/', '[clé masquée]', $e->getMessage()), 0, 200));
        $client = null;
    }
}
if ($client) {
    try {
        $account = $client->accounts->retrieve();
        $add($S, 'Compte Stripe de la plateforme', $account->charges_enabled ? 'ok' : 'warn',
            ($account->business_profile->name ?? $account->settings->dashboard->display_name ?? 'Compte') . ' · pays ' . ($account->country ?? '?') . ' · paiements ' . ($account->charges_enabled ? 'activés' : 'NON activés (profil à compléter dans Stripe)') . ' · versements vers votre banque ' . ($account->payouts_enabled ? 'activés' : 'NON activés (IBAN à ajouter dans Stripe)') . '.');
        $dev = ($account->details_submitted ?? false) ? 'ok' : 'warn';
        $add($S, 'Profil de l\'entreprise chez Stripe', $dev, $dev === 'ok' ? 'Informations soumises.' : "Pas encore soumises : obligatoire pour passer en production.");
    } catch (Throwable $e) { $add($S, 'Compte Stripe de la plateforme', 'warn', 'Lecture impossible : ' . mb_substr($e->getMessage(), 0, 160)); }
    // Webhooks enregistrés chez Stripe
    $need = ['checkout.session.completed', 'checkout.session.expired', 'charge.refunded', 'charge.dispute.created', 'charge.dispute.closed'];
    $needConnect = ['account.updated', 'payout.failed'];
    try {
        $eps = $client->webhookEndpoints->all(['limit' => 100])->data;
        $mine = array_values(array_filter($eps, fn($e) => strpos((string)$e->url, 'stripe_webhook') !== false));
        if (!$mine) {
            $add($S, 'Webhook vers ' . (defined('BASE_URL') ? BASE_URL : '') . 'api/stripe_webhook.php', 'bad', "Aucun webhook ne pointe vers api/stripe_webhook.php (mode " . ($mode($sk)) . '). Créez-le dans Stripe > Développeurs > Webhooks.');
        } else {
            $events = [];
            foreach ($mine as $e) {
                $ev = (array)$e->enabled_events;
                $events = array_merge($events, in_array('*', $ev, true) ? array_merge($need, $needConnect) : $ev);
                $add($S, 'Webhook enregistré', $e->status === 'enabled' ? 'ok' : 'bad', $e->url . ' · ' . ($e->status === 'enabled' ? 'actif' : 'DÉSACTIVÉ') . ' · ' . (in_array('*', $ev, true) ? 'tous les événements' : count($ev) . ' événement(s)') . '.');
            }
            $miss = array_values(array_diff($need, $events));
            $add($S, 'Événements de paiement écoutés', $miss ? 'bad' : 'ok', $miss ? 'Manquants : ' . implode(', ', $miss) : 'Tous les événements nécessaires sont écoutés.');
            $missC = array_values(array_diff($needConnect, $events));
            $add($S, 'Événements des comptes organismes (Connect)', $missC ? 'warn' : 'ok', $missC ? 'Non écoutés : ' . implode(', ', $missC) . ' (webhook « comptes connectés » à créer).' : 'Écoutés.');
        }
    } catch (Throwable $e) {
        $add($S, 'Liste des webhooks', 'warn', "Lecture impossible avec cette clé : " . mb_substr($e->getMessage(), 0, 160) . " (une clé « restreinte » doit avoir la lecture des webhooks).");
    }
    // Connect activé ?
    try {
        $client->accounts->all(['limit' => 1]);
        $add($S, 'Stripe Connect (comptes d\'organismes)', 'ok', 'Activé : la plateforme peut créer des comptes pour les organismes.');
    } catch (Throwable $e) {
        $add($S, 'Stripe Connect (comptes d\'organismes)', 'bad', "Non activé ou non autorisé : activez Connect dans Stripe (Connect > Premiers pas). Détail : " . mb_substr($e->getMessage(), 0, 140));
    }
}

// ---------------------------------------------------------------- 3. Fonctionnement réel côté site
$S = 'Fonctionnement côté site';
try {
    $n = (int)adm_one("SELECT COUNT(*) FROM stripe_events WHERE created_at > (NOW() - INTERVAL 30 DAY)");
    $last = adm_one("SELECT MAX(created_at) FROM stripe_events");
    $add($S, 'Webhooks reçus de Stripe (30 derniers jours)', $n > 0 ? 'ok' : 'warn', $n > 0 ? $n . " reçus, le dernier : $last." : "Aucun reçu : normal avant le premier paiement de test ; sinon le webhook n'atteint pas le site.");
} catch (Throwable $e) { $add($S, 'Webhooks reçus de Stripe', 'warn', "Table stripe_events absente : elle se crée au premier webhook reçu."); }
try {
    $p = (int)adm_one("SELECT COUNT(*) FROM fin_paiements");
    $add($S, 'Paiements enregistrés (commission et net calculés)', $p > 0 ? 'ok' : 'warn', $p . ' paiement(s) suivi(s). ' . ($p ? '' : 'Faites un paiement de test avec la carte 4242 4242 4242 4242.'));
} catch (Throwable $e) { $add($S, 'Paiements enregistrés', 'warn', 'Table fin_paiements absente : elle se crée au premier usage des finances.'); }
try {
    $last = adm_one("SELECT MAX(created_at) FROM fin_audit WHERE action = 'cron_deblocage'");
    $ok = $last && strtotime((string)$last) > time() - 2 * 3600;
    $add($S, 'Tâche planifiée « versements » (cron_virements)', $ok ? 'ok' : 'warn', $last ? "Dernière exécution : $last" . ($ok ? '.' : ' (plus de 2 h : vérifiez la tâche dans hPanel).') : "Jamais exécutée : créez la tâche horaire dans hPanel.");
} catch (Throwable $e) { $add($S, 'Tâche planifiée « versements »', 'warn', 'Journal absent : la tâche ne s\'est jamais exécutée.'); }
try {
    $c = (int)adm_one("SELECT COUNT(*) FROM stripe_comptes WHERE charges_enabled = 1 AND payouts_enabled = 1");
    $t = (int)adm_one("SELECT COUNT(*) FROM stripe_comptes");
    $add($S, 'Organismes prêts à être payés', $t === 0 ? 'warn' : ($c > 0 ? 'ok' : 'warn'), "$c organisme(s) prêts sur $t inscrit(s) chez Stripe. Sans inscription Stripe terminée, l'argent d'un organisme reste chez vous.");
} catch (Throwable $e) { $add($S, 'Organismes prêts à être payés', 'warn', "Aucun organisme inscrit chez Stripe pour l'instant."); }

// ---------------------------------------------------------------- 4. Tâches planifiées et sécurité
$S = 'Tâches planifiées et sécurité';
$add($S, 'CRON_SECRET (clé des tâches automatiques)', $def('CRON_SECRET') ? (strlen((string)CRON_SECRET) >= 24 ? 'ok' : 'warn') : 'bad', $def('CRON_SECRET') ? 'Définie, ' . strlen((string)CRON_SECRET) . ' caractères' . (strlen((string)CRON_SECRET) >= 24 ? '.' : ' : trop courte, prenez au moins 32 caractères.') : "Absente : les tâches « versements » et « purge RGPD » refusent tout accès.");
$add($S, 'Anti-robot Cloudflare Turnstile', $def('TURNSTILE_SITE_KEY') && $def('TURNSTILE_SECRET') ? 'ok' : 'warn', $def('TURNSTILE_SITE_KEY') && $def('TURNSTILE_SECRET') ? 'Actif sur la connexion et l\'inscription.' : "Inactif : ajoutez TURNSTILE_SITE_KEY et TURNSTILE_SECRET dans config.php.");
$add($S, 'Page en HTTPS', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'ok' : 'bad', 'Les paiements exigent HTTPS.');
$c = session_get_cookie_params();
$add($S, 'Cookie de session protégé (HttpOnly, SameSite)', !empty($c['httponly']) && !empty($c['secure']) && ($c['samesite'] ?? '') !== '' ? 'ok' : 'warn',
    'HttpOnly : ' . (!empty($c['httponly']) ? 'oui' : 'non') . ' · Secure : ' . (!empty($c['secure']) ? 'oui' : 'non') . ' · SameSite : ' . (($c['samesite'] ?? '') ?: 'aucun') . '. Sinon, ajoutez les réglages « ini_set » du guide au début de config.php.');
$add($S, 'Journal des erreurs', function_exists('err_log_db') ? 'ok' : 'warn', function_exists('err_log_db') ? 'Actif (page /admin_erreurs).' : "Inactif : ajoutez require_once __DIR__ . '/errors.php'; à la fin de config.php.");
$cfg = (string)@file_get_contents(__DIR__ . '/api/config.php', false, null, 0, 4000);
$stub = strlen($cfg) < 500 && strpos($cfg, 'DB_PASS') === false;   // simple renvoi vers un fichier hors de la racine web
$add($S, 'Fichier de secrets hors de la racine web', $stub ? 'ok' : 'warn', $stub ? 'api/config.php ne contient plus les secrets.' : "Les secrets sont encore dans api/config.php, accessible dans la racine du site. Recommandé : les déplacer au-dessus de public_html (guide, partie 7). Étape facultative mais conseillée.");

// ---------------------------------------------------------------- Affichage
$count = ['ok' => 0, 'warn' => 0, 'bad' => 0];
foreach ($checks as $c) $count[$c[2]]++;
adm_head('Test de la configuration Stripe', '');
?>
<p class="muted">Contrôle en lecture seule de la configuration des paiements, des webhooks, des versements et de la sécurité. Aucune clé n'est affichée.</p>
<div class="note <?= $count['bad'] ? 'err' : '' ?>">
    <b><?= $count['bad'] ? 'À corriger : ' . $count['bad'] . ' point(s) bloquant(s)' : 'Aucun point bloquant' ?></b>
    · <?= $count['ok'] ?> validé(s) · <?= $count['warn'] ?> à surveiller · mode Stripe : <b><?= $sk !== '' ? h($mode($sk)) : 'inconnu' ?></b>
</div>
<?php $cur = ''; foreach ($checks as $c): if ($c[0] !== $cur): if ($cur !== '') echo '</tbody></table></div>'; $cur = $c[0]; ?>
    <h2 class="h2"><?= h($cur) ?></h2>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Contrôle</th><th>Résultat</th><th>Détail</th></tr></thead><tbody>
<?php endif; ?>
        <tr>
            <td><?= h($c[1]) ?></td>
            <td><span class="pill <?= $c[2] === 'ok' ? 'ok' : 'bad' ?>"><?= $c[2] === 'ok' ? 'OK' : ($c[2] === 'warn' ? 'À surveiller' : 'À corriger') ?></span></td>
            <td><?= h($c[3]) ?></td>
        </tr>
<?php endforeach; if ($cur !== '') echo '</tbody></table></div>'; ?>
<p class="muted">Page de diagnostic : à supprimer ou à protéger une fois la mise en production terminée.</p>
<?php adm_foot(); ?>

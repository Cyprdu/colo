<?php
// tableau_de_controle.php : tableau de contrôle de l'application (administrateurs uniquement).
// Lance une batterie de tests (serveur, base, Stripe, webhooks, versements, Google, anti-robot, pubs, e-mail, sécurité) et affiche ce qui est OK ou à faire.
// Les tests sont en lecture seule (api/controle_lib.php) ; chacun est exécuté à part (?run=<id>) pour qu'un service lent ne bloque pas la page.
// Pas de script ni de style en ligne (CSP stricte de l'administration) : le JS est dans assets/controle.js.
require_once __DIR__ . '/admin_lib.php';
require_once __DIR__ . '/api/controle_lib.php';
$run = (string)($_GET['run'] ?? '');
if ($run !== '' || isset($_GET['list'])) {
    adm_guard(true);
    $T = ctl_tests();
    if (isset($_GET['list'])) adm_json(['tests' => array_map(fn($id, $t) => ['id' => $id, 'g' => $t[0], 'l' => $t[1]], array_keys($T), array_values($T))]);
    if (!isset($T[$run])) adm_json(['error' => 'Test inconnu.'], 404);
    $t0 = microtime(true);
    try { $r = ($T[$run][2])(); } catch (Throwable $e) { error_log('controle ' . $run . ': ' . $e->getMessage()); $r = ctl_r('warn', 'Le test a échoué : ' . mb_substr($e->getMessage(), 0, 160)); }
    adm_json($r + ['ms' => (int)round((microtime(true) - $t0) * 1000)]);
}
adm_guard();
adm_audit('controle_ouvert', 'tableau_de_controle');
$site = ctl_site();
adm_head('Tableau de contrôle', 'tableau_de_controle');
?>
<div class="note" id="ctl-sum"><span>Tests en attente…</span></div>
<div class="bar"><button class="btn" type="button" id="ctl-run">Relancer tous les tests</button>
  <label class="chk"><input type="checkbox" id="ctl-todo"> N'afficher que ce qui est à faire</label></div>
<div id="ctl-body"></div>

<h2 class="sec">Tâche planifiée à créer (hPanel &gt; Avancé &gt; Tâches Cron)</h2>
<div class="card"><p class="muted">Un seul cron suffit, toutes les heures : il lance versements, purge RGPD et alertes animateurs. <b>CRON_SECRET</b> est la chaîne définie dans <code>api/config.php</code>.</p>
<p><code><?= h($site) ?>/api/cron_tout.php?key=VOTRE_CRON_SECRET</code></p></div>

<h2 class="sec">Tutoriel : réparer la connexion Google</h2>
<div class="card"><p class="muted">Lancez d'abord les tests « Connexion Google » ci-dessus : ils disent lequel de ces points casse.</p>
<ol class="steps">
<li><b>Ouvrez Google Cloud Console</b> (console.cloud.google.com), choisissez le projet de moncamp.fr, puis <i>API et services &gt; Identifiants</i>.</li>
<li><b>Client OAuth 2.0 (type « Application Web »)</b> : s'il n'existe plus (« client supprimé »), créez-en un. Sinon ouvrez-le.</li>
<li><b>URI de redirection autorisés</b> : ajoutez exactement <code><?= h($site) ?>/api/google_callback.php</code> (https, sans « / » final). Une différence d'un caractère donne « redirect_uri_mismatch ».</li>
<li><b>Origines JavaScript autorisées</b> : <code><?= h($site) ?></code>.</li>
<li><b>Secret client</b> : s'il est refusé (test « Secret client valide » rouge), cliquez « Ajouter un secret », copiez-le (visible une seule fois) et remplacez <code>$google_client_secret</code> dans <code>api/google_config.php</code>. Vérifiez aussi <code>$google_client_id</code>.</li>
<li><b>Écran de consentement OAuth</b> (<i>API et services &gt; Écran de consentement</i>) : état « En production » (bouton « Publier l'application »). En mode « Test », seuls les testeurs listés se connectent et l'accès expire après 7 jours : c'est la panne la plus fréquente d'une connexion qui « ne marche plus ».</li>
<li><b>Portées</b> : <code>email</code> et <code>profile</code> uniquement (pas de validation Google requise).</li>
<li><b>Redéployez</b> (git push), puis relancez les tests ici : les lignes Google doivent passer au vert. Testez enfin le bouton « Continuer avec Google » sur <a href="login.php">login.php</a>.</li>
</ol></div>
<?php adm_foot(['page' => 'controle', 'js' => 'controle']); ?>

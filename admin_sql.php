<?php
// admin_sql.php : console SQL (réauthentification requise ; confirmation avant toute écriture ; tout est journalisé).
require_once __DIR__ . '/admin_lib.php';
adm_guard(false, 'tech.sql');
adm_head('Console SQL', 'admin_sql.php');
?>
<div id="sql-app">
  <div class="note warn"><?= ic('alert') ?> Une seule requête à la fois, sans commentaires. Les lectures sont limitées à 500 lignes. Les écritures demandent une confirmation et sont journalisées ; le journal de sécurité est protégé. Utilisez <code>?</code> pour les valeurs variables (requête préparée).</div>
  <div id="sql-lock" class="card lockbox" hidden>
    <?= ic('lock', 'ic ic-xl') ?><h2>Accès protégé</h2><p>La console SQL exige votre mot de passe administrateur.</p>
    <button class="btn" id="sql-unlock" type="button"><?= ic('key') ?> Saisir mon mot de passe</button>
  </div>
  <div id="sql-box" class="card" hidden>
    <textarea class="in mono" id="sql-in" rows="6" spellcheck="false" placeholder="SELECT id, nom FROM camps WHERE ville = ? LIMIT 20"></textarea>
    <label class="ds-label">Paramètres (un par ligne, dans l'ordre des <code>?</code>)</label>
    <textarea class="in mono" id="sql-params" rows="2" spellcheck="false" placeholder="Annecy"></textarea>
    <div class="row-l"><button class="btn" id="sql-run" type="button"><?= ic('play') ?> Exécuter</button>
      <button class="btn ghost" id="sql-tables" type="button"><?= ic('list') ?> SHOW TABLES</button><span class="count" id="sql-info"></span></div>
  </div>
  <div id="sql-out"></div>
</div>
<?php adm_foot(['page' => 'sql']); ?>

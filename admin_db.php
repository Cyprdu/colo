<?php
// admin_db.php : éditeur de base de données (liste des tables, parcourir / rechercher / éditer / supprimer).
// Tout passe par admin_api.php (réauthentification requise, journalisé). Cette page n'est qu'une coquille.
require_once __DIR__ . '/admin_lib.php';
adm_guard();
adm_head('Base de données', 'admin_db.php');
?>
<div id="db-app" class="db">
  <aside class="db-tables card">
    <label class="search"><?= ic('search') ?><input class="in" id="db-filter" type="search" name="tblflt" placeholder="Filtrer les tables…" autocomplete="off" data-lpignore="true" data-1p-ignore></label>
    <ul id="db-list" class="tlist"><li class="muted">Chargement…</li></ul>
  </aside>
  <section class="db-main">
    <div id="db-lock" class="card lockbox" hidden>
      <?= ic('lock', 'ic ic-xl') ?><h2>Accès protégé</h2>
      <p>L'éditeur de base de données exige votre mot de passe administrateur.</p>
      <button class="btn" id="db-unlock" type="button"><?= ic('key') ?> Saisir mon mot de passe</button>
    </div>
    <div id="db-view" hidden>
      <div class="bar">
        <h2 id="db-title" class="tname"></h2>
        <label class="search"><?= ic('search') ?><input class="in" id="db-q" type="search" name="rowsrch" placeholder="Rechercher dans la table…" autocomplete="off" data-lpignore="true" data-1p-ignore></label>
        <button class="btn ghost" id="db-new" type="button"><?= ic('plus') ?> Nouvelle ligne</button>
        <span class="count" id="db-count"></span>
      </div>
      <div class="note" id="db-ro" hidden>Table protégée : lecture seule.</div>
      <div class="tbl-wrap"><table class="tbl" id="db-table"></table></div>
      <nav class="pager" id="db-pager"></nav>
    </div>
    <div id="db-empty" class="card lockbox"><?= ic('database', 'ic ic-xl') ?><p>Choisissez une table à gauche.</p></div>
  </section>
</div>
<div class="modal" id="m-row" hidden><div class="dlg wide"><h3 id="row-title"></h3><form id="row-form" autocomplete="off"></form><p class="err" id="row-err"></p>
  <div class="row"><button class="btn ghost" type="button" data-close>Annuler</button><button class="btn" type="button" id="row-save">Enregistrer</button></div></div></div>
<?php adm_foot(['page' => 'db']); ?>

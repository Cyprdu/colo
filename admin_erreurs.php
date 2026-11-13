<?php
// admin_erreurs.php : dernières erreurs du site (fatales / exceptions), pour comprendre un « HTTP 500 ».
require_once __DIR__ . '/admin_lib.php';
adm_guard();
adm_head('Erreurs du site', '');
$rows = []; $fail = false;
try { $rows = adm_rows("SELECT id, created_at, type, message, fichier, ligne, url, methode FROM app_erreurs ORDER BY id DESC LIMIT 150"); }
catch (Throwable $e) { $fail = true; }
?>
<p class="muted">Chaque « HTTP 500 » est enregistré ici avec le fichier et la ligne en cause. Si la liste est vide, aucune erreur n'a été rencontrée depuis la mise en place du journal.</p>
<?php if ($fail || !$rows): ?>
    <div class="note">Aucune erreur enregistrée.</div>
<?php else: ?>
    <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Date</th><th>Type</th><th>Message</th><th>Fichier</th><th>URL</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= h($r['created_at']) ?></td>
                <td><?= h($r['type']) ?></td>
                <td><?= h($r['message']) ?></td>
                <td><?= h($r['fichier']) ?>:<?= (int)$r['ligne'] ?></td>
                <td><?= h($r['methode']) ?> <?= h($r['url']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
<?php adm_foot(); ?>

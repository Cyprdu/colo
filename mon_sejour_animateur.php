<?php
// mon_sejour_animateur.php?t=<jeton d'inscription> : espace de l'animateur inscrit à un séjour (option B « équipe d'animation »).
// Accès : l'animateur concerné, le directeur du séjour et les admins (equipe_acces). Toutes les valeurs viennent de la base.
$body_class = 'bg-white';
require_once 'partials/header.php';
require_once __DIR__ . '/api/adm_core.php';
require_once 'api/animateurs.php';
require_once 'api/equipe.php';
require_once 'api/annulation_lib.php';

$uid = (int)($_SESSION['user']['id'] ?? 0);
$t = (string)($_GET['t'] ?? '');
if (!$uid) { header('Location: login.php?redirect=' . urlencode('mon_sejour_animateur.php?t=' . $t)); exit; }
$h = fn($v) => htmlspecialchars(html_entity_decode((string)$v), ENT_QUOTES, 'UTF-8');
$eur = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', ' '), '0'), ',') . ' €';
$jour = fn($d) => $d ? date('d/m/Y', strtotime($d)) : '';

$acc = null;
if (!sec_blocked($pdo, 'equipe_page', (string)$uid, 120, 1)) { sec_hit($pdo, 'equipe_page', (string)$uid); $acc = equipe_acces($pdo, $t, $uid, adm_can('camps.read')); }
if (!$acc) {
    http_response_code(404);
    echo '<main class="ds-page" style="max-width:640px"><h1 class="ds-h1">Page introuvable</h1><p class="ds-lead">Cette inscription n’existe pas ou ne vous est pas accessible.</p><a class="ds-btn mt-5" href="reservations">Mes réservations</a></main>';
    require_once 'partials/footer.php'; exit;
}
[$e, $camp, $role] = $acc;
$info = equipe_infos($pdo, (int)$camp['id']);
$annule = $e['statut'] === 'Annulé';
$paid = in_array($e['statut_paiement'], ['PAYE', 'GRATUIT'], true);
$jours = fin_jours_avant((string)$camp['date_debut']);
$lieu = trim(implode(', ', array_filter([trim((string)$camp['adresse']), trim($camp['code_postal'] . ' ' . $camp['ville'])])));

$equipe = [];
if ($info['montre_equipe'] || $role !== 'animateur') {
    $st = $pdo->prepare("SELECT prenom, nom FROM equipe_inscriptions WHERE camp_id = ? AND statut = 'Confirmé' AND id <> ? ORDER BY prenom");
    $st->execute([$camp['id'], $e['id']]); $equipe = $st->fetchAll(PDO::FETCH_ASSOC);
}
$jt = (int)($camp['annul_jours_total'] ?? 0) ?: FIN_ANNUL_DEFAUT[0]; $jm = (int)($camp['annul_jours_moitie'] ?? 0) ?: FIN_ANNUL_DEFAUT[1];
$demande = false;
try { $q = $pdo->prepare("SELECT 1 FROM equipe_alertes WHERE equipe_id = ?"); $q->execute([$e['id']]); $demande = (bool)$q->fetchColumn(); } catch (Throwable $x) {}
$statutTxt = $annule ? 'Inscription annulée' : ($paid ? 'Inscription confirmée' : 'Paiement en attente');
?>
<title>Mon séjour d'animateur - moncamp.fr</title>
<meta name="robots" content="noindex, nofollow">
<main class="ds-page" style="max-width:860px">
    <p class="ds-eyebrow">Espace animateur<?= $role !== 'animateur' ? ' · vue ' . ($role === 'directeur' ? 'directeur' : 'admin') : '' ?></p>
    <h1 class="ds-h1 mt-2"><?= $h($camp['nom']) ?></h1>
    <p class="ds-lead"><?= $h(implode(' · ', array_filter([trim((string)$camp['orga_nom']), trim((string)$camp['ville']), $jour($camp['date_debut']) . ' – ' . $jour($camp['date_fin'])]))) ?></p>
    <p class="mt-3"><span class="text-[12px] font-bold px-3 py-1 rounded-full inline-block <?= $annule ? 'bg-[#f3e3e3] text-[#7a2b2b]' : ($paid ? 'bg-[#eef2f4] text-[#26343b]' : 'bg-[#f8f5ea] text-[#5b4a1c]') ?>"><?= $statutTxt ?></span>
        <span class="text-[13px] text-[#6b7780] ml-2"><?= $h($e['prenom'] . ' ' . $e['nom']) ?></span></p>

    <?php if ($info['message']): ?>
    <section class="ds-card mt-6 p-6" style="background:#f8f5ea">
        <p class="ds-eyebrow">Message de l’équipe de direction<?= $info['message_at'] ? ' · ' . $jour($info['message_at']) : '' ?></p>
        <p class="mt-2 text-[15px] text-[#26343b] whitespace-pre-line"><?= $h($info['message']) ?></p>
    </section>
    <?php endif; ?>

    <section class="ds-card mt-6 p-6">
        <h2 class="text-[18px] font-extrabold text-[#26343b]">Infos pratiques</h2>
        <dl class="mt-3 grid sm:grid-cols-2 gap-4 text-[14px]">
            <div><dt class="text-[#6b7780]">Lieu</dt><dd class="font-bold text-[#26343b]"><?= $lieu ? $h($lieu) : 'Non renseigné' ?></dd></div>
            <div><dt class="text-[#6b7780]">Dates</dt><dd class="font-bold text-[#26343b]"><?= $jour($camp['date_debut']) ?> – <?= $jour($camp['date_fin']) ?></dd></div>
        </dl>
        <?php foreach ($info['blocs'] as $b): ?>
            <div class="mt-5 border-t border-[#e5e7eb] pt-4">
                <?php if ($b['titre'] !== ''): ?><h3 class="text-[15px] font-extrabold text-[#26343b]"><?= $h($b['titre']) ?></h3><?php endif; ?>
                <p class="mt-1 text-[15px] text-[#3b4a54] whitespace-pre-line"><?= $h($b['texte']) ?></p>
            </div>
        <?php endforeach; ?>
        <?php if (equipe_infos_vide($info) && !$info['blocs']): ?>
        <div class="mt-5 border-t border-[#e5e7eb] pt-5 text-center">
            <p class="font-bold text-[#26343b]">Aucune information pour le moment</p>
            <p class="text-[13px] text-[#6b7780] mt-1">L’organisateur n’a pas encore renseigné cette page (horaires, programme, consignes…).</p>
            <?php if ($role === 'animateur' && !$annule): ?><?php if ($demande): ?><p class="mt-3 text-[13px] font-semibold text-[#26343b]">✓ Vous serez prévenu dès sa publication.</p><?php else: ?><button type="button" id="prevenir" class="mt-3 text-[13px] font-bold text-[#3d6a8e] underline hover:text-[#26343b]">Me prévenir quand elle est publiée</button><?php endif; ?><?php endif; ?>
        </div>
        <?php endif; ?>
    </section>

    <?php if ($info['docs']): ?>
    <section class="ds-card mt-6 p-6">
        <h2 class="text-[18px] font-extrabold text-[#26343b]">Documents</h2>
        <ul class="mt-3 space-y-2">
        <?php foreach ($info['docs'] as $d): ?>
            <li><a class="font-bold text-[#26343b] underline" target="_blank" rel="noopener" href="api/equipe_infos.php?a=doc&i=<?= (int)$d['id'] ?>&t=<?= $h($t) ?>"><?= $h($d['nom']) ?>.pdf</a> <span class="text-[12px] text-[#6b7780]">(<?= round($d['taille'] / 1024) ?> Ko)</span></li>
        <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <section class="ds-card mt-6 p-6">
        <h2 class="text-[18px] font-extrabold text-[#26343b]">Participation et rémunération</h2>
        <dl class="mt-3 grid sm:grid-cols-2 gap-4 text-[14px]">
            <div><dt class="text-[#6b7780]">Tarif choisi</dt><dd class="font-bold text-[#26343b]"><?= $e['tarif_nom'] ? $h($e['tarif_nom']) : 'Participation gratuite' ?></dd></div>
            <div><dt class="text-[#6b7780]">Montant à votre charge</dt><dd class="font-bold text-[#26343b]"><?= (float)$e['prix_final'] > 0 ? $eur($e['prix_final']) . ($e['statut_paiement'] === 'PAYE' ? ' · payé' : ' · à régler') : 'Gratuit' ?></dd></div>
            <div><dt class="text-[#6b7780]">Rémunération prévue</dt><dd class="font-bold text-[#26343b]"><?= ($camp['anim_remuneration'] ?? null) !== null ? $eur($camp['anim_remuneration']) : 'Non renseignée' ?></dd></div>
            <div><dt class="text-[#6b7780]">Date de versement prévue</dt><dd class="font-bold text-[#26343b]"><?= $info['date_versement'] ? $jour($info['date_versement']) : 'Non renseignée' ?></dd></div>
        </dl>
        <?php if ($paid && !$annule): ?><a class="ds-btn ds-btn-light mt-5" href="generate_pdf_equipe.php?t=<?= $h($t) ?>">Télécharger mon reçu (PDF)</a><?php endif; ?>
    </section>

    <?php if ($equipe): ?>
    <section class="ds-card mt-6 p-6">
        <h2 class="text-[18px] font-extrabold text-[#26343b]">L’équipe d’animation</h2>
        <p class="mt-3 text-[15px] text-[#3b4a54]"><?= $h(implode(' · ', array_map(fn($x) => $x['prenom'] . ' ' . $x['nom'], $equipe))) ?></p>
    </section>
    <?php endif; ?>

    <?php if ($role === 'animateur'): ?>
    <section class="ds-card mt-6 p-6">
        <div class="flex flex-wrap gap-3">
            <button type="button" class="ds-btn" id="contact">Contacter l’organisateur</button>
            <?php if (!$annule && $jours >= 0): ?><button type="button" class="ds-btn ds-btn-light" id="annul">Annuler mon inscription</button><?php endif; ?>
        </div>
        <?php if (!$annule && $jours >= 0 && $e['statut_paiement'] === 'PAYE'): ?><p class="mt-3 text-[13px] text-[#6b7780]"><?= $h(fin_annul_texte($jt, $jm)) ?></p><?php endif; ?>
        <div id="err" class="ds-alert-err hidden mt-4"></div>
    </section>
    <script>
    (function () {
        var T = <?= json_encode($t) ?>, CAMP = <?= (int)$camp['id'] ?>, err = document.getElementById('err');
        var csrf = (document.querySelector('meta[name=csrf]') || {}).content || '';
        function post(url, body) { return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) }).then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.error || 'Erreur'); return d; }); }); }
        function fail(x) { err.textContent = x.message; err.classList.remove('hidden'); }
        document.getElementById('contact').onclick = function () {
            post('api/start_conversation.php', { campId: CAMP }).then(function (d) { location.href = 'messagerie?conv_id=' + d.conversationId; }).catch(fail);
        };
        var pv = document.getElementById('prevenir');
        if (pv) pv.onclick = function () { pv.disabled = true; post('api/equipe_infos.php?a=prevenir', { t: T }).then(function (d) { pv.outerHTML = '<p class="mt-3 text-[13px] font-semibold text-[#26343b]">✓ ' + d.message + '</p>'; }).catch(function (x) { pv.disabled = false; fail(x); }); };
        var a = document.getElementById('annul');
        if (a) a.onclick = async function () {
            if (!(await ColoUI.confirm('Annuler votre inscription ?', { confirmLabel: 'Oui, annuler' }))) return;
            post('api/equipe_infos.php?a=annuler', { t: T }).then(function (d) { return ColoUI.alert(d.message).then(function () { location.reload(); }); }).catch(fail);
        };
    })();
    </script>
    <?php endif; ?>
</main>
<?php require_once 'partials/footer.php'; ?>

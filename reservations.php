<?php
// reservations.php : mes réservations, regroupées par commande (un séjour + une session = une carte,
// même quand plusieurs enfants sont inscrits ensemble). Rendu côté serveur : une seule requête SQL.
require_once 'api/config.php';
require_once 'api/orders.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user']['id'])) { header('Location: login.php?next=reservations'); exit; }
$userId = (int)$_SESSION['user']['id'];

$loadError = false;
try { $rows = loadParentRows($pdo, $userId); }
catch (Throwable $e) { $rows = []; $loadError = true; error_log('reservations: ' . $e->getMessage()); }

// Inscriptions en tant qu'animateur (équipe d'animation, option B du séjour) : table equipe_inscriptions, séparée des inscriptions d'enfants
$equipe = [];
try {
    require_once 'api/equipe.php';
    equipe_ensure($pdo);
    $st = $pdo->prepare("SELECT e.token, e.prenom, e.nom, e.tarif_nom, e.prix_final, e.statut, e.statut_paiement, e.created_at,
            c.token AS camp_token, c.nom AS camp_nom, c.ville, c.image_url, c.date_debut, c.date_fin
        FROM equipe_inscriptions e JOIN camps c ON c.id = e.camp_id WHERE e.user_id = ? AND e.statut <> 'Annulé' ORDER BY c.date_debut ASC");
    $st->execute([$userId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $expire = $e['statut_paiement'] === 'EN_ATTENTE' && strtotime($e['created_at']) < time() - EQUIPE_ATTENTE_MIN * 60;
        if (!$expire) $equipe[] = $e;
    }
} catch (Throwable $e) { error_log('reservations (équipe): ' . $e->getMessage()); }

$today = new DateTime('today');
$upcoming = []; $waiting = []; $past = []; $cancelled = []; $toPay = 0;
foreach (groupOrders($rows) as $members) {
    $status = orderStatus($members);
    if (in_array($status[0], ['cancelled', 'expired'], true)) { $cancelled[] = $members; continue; }
    $end = $members[0]['_end'];
    foreach ($members as $m) if ($m['_end'] > $end) $end = $m['_end'];
    $unpaid = in_array($status[0], ['pending', 'partial'], true);
    if ($end < $today) $past[] = $members;
    elseif ($unpaid) $waiting[] = $members;
    else $upcoming[] = $members;
    if ($unpaid) $toPay++;
}
usort($upcoming, function ($a, $b) { return $a[0]['_start'] <=> $b[0]['_start']; });
usort($waiting, function ($a, $b) { return $a[0]['_start'] <=> $b[0]['_start']; });
usort($past, function ($a, $b) { return $b[0]['_start'] <=> $a[0]['_start']; });

$body_class = 'bg-white';
require_once 'partials/header.php';

$sections = [
    'upcoming'  => ['label' => 'À venir',   'list' => $upcoming],
    'waiting'   => ['label' => 'En attente', 'list' => $waiting],
    'past'      => ['label' => 'Passées',   'list' => $past],
    'cancelled' => ['label' => 'Annulées / expirées', 'list' => $cancelled],
];
$firstTab = $waiting ? 'waiting' : ($upcoming ? 'upcoming' : ($past ? 'past' : ($cancelled ? 'cancelled' : 'upcoming')));

function resaImg($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    $url = str_replace('\\', '/', $url);
    return (preg_match('#^(https?:)?//#', $url) || strpos($url, 'uploads/') === 0) ? $url : 'uploads/camps/' . ltrim($url, '/');
}
?>
<title>Mes réservations - ColoMap</title>
<style>
    .rs-tab { padding:10px 18px; border-radius:9999px; font-size:14px; font-weight:700; color:#3b4a54; border:1.5px solid #e5e7eb; background:#fff; transition:all .15s ease; }
    .rs-tab:hover { background:#f1f3f5; }
    .rs-tab.is-active { background:#2f3d45; color:#fff; border-color:#2f3d45; }
    .rs-card { background:#fff; border:1px solid #e5e7eb; border-radius:24px; overflow:hidden; transition:box-shadow .2s ease; }
    .rs-card:hover { box-shadow:0 10px 32px rgba(38,52,59,.10); }
    .rs-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:11px 18px; border-radius:12px; font-size:14px; font-weight:700; background:#2f3d45; color:#fff; transition:background .2s ease; white-space:nowrap; }
    .rs-btn:hover { background:#1f2a30; }
    .rs-btn-light { background:#fff; color:#26343b; border:1.5px solid #e5e7eb; }
    .rs-btn-light:hover { background:#f1f3f5; }
</style>

<main class="flex-1 w-full max-w-[1100px] mx-auto px-5 sm:px-8 pt-10 pb-16">
    <div class="mb-8">
        <h1 class="text-[30px] md:text-[38px] font-extrabold text-[#26343b] leading-tight">Mes réservations</h1>
        <p class="mt-2 text-[#6b7780]">Retrouvez les séjours de vos enfants, gérez chaque réservation et téléchargez vos reçus.</p>
    </div>

    <?php if ($loadError): ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-semibold text-red-700 mb-6">Impossible de charger vos réservations pour le moment. Réessayez dans un instant.</div>
    <?php endif; ?>

    <?php if ($toPay > 0): ?>
        <div class="mb-6 flex items-center gap-3 rounded-2xl bg-[#f8f5ea] px-5 py-4 text-[#26343b]">
            <span class="w-9 h-9 flex-none rounded-full bg-white flex items-center justify-center font-extrabold"><?= $toPay ?></span>
            <p class="text-[15px] font-semibold"><?= $toPay > 1 ? 'réservations sont en attente de paiement' : 'réservation est en attente de paiement' ?>. Ouvrez-la pour finaliser le règlement.</p>
        </div>
    <?php endif; ?>

    <?php if (($_GET['equipe'] ?? '') === 'ok'): ?>
        <div class="mb-6 rounded-2xl bg-[#eef2f4] px-5 py-4 text-[15px] font-semibold text-[#26343b]">Inscription enregistrée. Le paiement est confirmé dès que votre banque le valide (quelques instants).</div>
    <?php endif; ?>

    <?php if ($equipe): ?>
        <section class="mb-10" aria-label="Mes inscriptions en tant qu'animateur">
            <h2 class="text-[18px] font-extrabold text-[#26343b] mb-3">Mes inscriptions en tant qu'animateur</h2>
            <div class="space-y-4">
            <?php foreach ($equipe as $e):
                $img = resaImg($e['image_url']);
                $paid = in_array($e['statut_paiement'], ['PAYE', 'GRATUIT'], true);
            ?>
                <article class="rs-card flex flex-col md:flex-row">
                    <a href="camp_details?t=<?= urlencode($e['camp_token']) ?>" class="relative block md:w-[200px] h-[140px] md:h-auto flex-none bg-[#e5e7eb] overflow-hidden" aria-label="Voir le séjour">
                        <?php if ($img): ?><img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy" class="absolute inset-0 w-full h-full object-cover" onerror="this.remove()"><?php endif; ?>
                        <span class="absolute top-3 left-3 text-[12px] font-bold px-3 py-1.5 rounded-full bg-[#26343b] text-white">Animateur</span>
                    </a>
                    <div class="flex-1 min-w-0 p-5 flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-[18px] font-extrabold text-[#26343b]"><?= htmlspecialchars(html_entity_decode($e['camp_nom'])) ?></h3>
                            <p class="mt-1 text-[14px] text-[#6b7780]"><?= htmlspecialchars(date('d/m/Y', strtotime($e['date_debut'])) . ' – ' . date('d/m/Y', strtotime($e['date_fin']))) ?> · <?= htmlspecialchars($e['ville'] ?? '') ?></p>
                            <p class="mt-1 text-[13px] text-[#6b7780]"><?= htmlspecialchars($e['prenom'] . ' ' . $e['nom']) ?><?= $e['tarif_nom'] ? ' · ' . htmlspecialchars($e['tarif_nom']) : '' ?></p>
                        </div>
                        <div class="text-right">
                            <p class="text-[20px] font-extrabold text-[#26343b] leading-none"><?= (float)$e['prix_final'] > 0 ? htmlspecialchars(fmtEuro((float)$e['prix_final'])) : 'Gratuit' ?></p>
                            <p class="mt-2 text-[12px] font-bold px-3 py-1 rounded-full inline-block <?= $paid ? 'bg-[#eef2f4] text-[#26343b]' : 'bg-[#f8f5ea] text-[#5b4a1c]' ?>"><?= $paid ? 'Inscription confirmée' : 'Paiement en attente' ?></p>
                            <?php if (!$paid): ?><div class="mt-3"><button type="button" class="rs-btn eq-pay" data-t="<?= htmlspecialchars($e['token']) ?>">Régler maintenant</button></div><?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
        </section>
        <script>
        document.querySelectorAll('.eq-pay').forEach(function (b) {
            b.addEventListener('click', async function () {
                b.disabled = true;
                try {
                    var r = await fetch('api/equipe_inscrire.php?a=payer', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': (document.querySelector('meta[name=csrf]') || {}).content || '' }, body: JSON.stringify({ t: b.dataset.t }) });
                    var d = await r.json(); if (!r.ok) throw new Error(d.error || 'Paiement impossible.'); location.href = d.url;
                } catch (e) { ColoUI.error(e); b.disabled = false; }
            });
        });
        </script>
    <?php endif; ?>

    <?php if (!$rows && !$equipe): ?>
        <div class="text-center py-16 rounded-3xl border border-[#e5e7eb] bg-[#f8f5ea]/50">
            <p class="text-xl font-bold text-[#26343b]">Aucune réservation pour le moment</p>
            <p class="mt-2 text-[#6b7780]">Trouvez le séjour idéal pour vos enfants.</p>
            <a href="recherche" class="rs-btn mt-6">Trouver un séjour</a>
        </div>
    <?php elseif ($rows): ?>
        <div class="flex flex-wrap gap-2 mb-6" role="tablist">
            <?php foreach ($sections as $key => $sec): if (in_array($key, ['cancelled', 'waiting'], true) && !$sec['list']) continue; ?>
                <button type="button" role="tab" class="rs-tab<?= $key === $firstTab ? ' is-active' : '' ?>" data-tab="<?= $key ?>"><?= $sec['label'] ?> (<?= count($sec['list']) ?>)</button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($sections as $key => $sec): ?>
        <div class="space-y-5 <?= $key === $firstTab ? '' : 'hidden' ?>" data-panel="<?= $key ?>">
            <?php if (!$sec['list']): ?>
                <p class="py-10 text-center text-[#6b7780]">Aucune réservation dans cette catégorie.</p>
            <?php endif; ?>

            <?php foreach ($sec['list'] as $members):
                $first = $members[0];
                $status = orderStatus($members);
                $tot = orderTotals($members);
                $live = array_values(array_filter($members, function ($m) { return !isCancelled($m); }));
                if (!$live) $live = $members;
                $token = $first['reservation_token'];
                $img = resaImg($first['image_url']);
                $canPay = in_array($status[0], ['pending', 'partial'], true) && !empty($first['inscription_en_ligne']);
                $hasPaid = false; foreach ($members as $m) if ($m['statut_paiement'] === 'PAYE') $hasPaid = true;
            ?>
            <article class="rs-card flex flex-col md:flex-row">
                <a href="reservation?r=<?= urlencode($token) ?>" class="relative block md:w-[260px] h-[190px] md:h-auto flex-none bg-[#e5e7eb] overflow-hidden" aria-label="Gérer la réservation">
                    <?php if ($img): ?><img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy" class="absolute inset-0 w-full h-full object-cover" onerror="this.remove()"><?php endif; ?>
                    <span class="absolute top-3 left-3 text-[12px] font-bold px-3 py-1.5 rounded-full <?= $status[2] ?>"><?= $status[1] ?></span>
                </a>
                <div class="flex-1 min-w-0 p-5 md:p-6 flex flex-col">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="text-[20px] font-extrabold text-[#26343b] leading-snug"><?= htmlspecialchars($first['camp_nom']) ?></h2>
                            <p class="mt-1 text-[14px] text-[#6b7780]"><?= htmlspecialchars(fmtPlage($first['_start'], $first['_end'])) ?> · <?= htmlspecialchars($first['ville'] ?? '') ?></p>
                            <?php if (!empty($first['orga_nom'])): ?><p class="text-[13px] text-[#6b7780] mt-0.5">par <?= htmlspecialchars($first['orga_nom']) ?></p><?php endif; ?>
                        </div>
                        <div class="text-right">
                            <p class="text-[22px] font-extrabold text-[#26343b] leading-none"><?= htmlspecialchars(fmtEuro($tot['due'])) ?></p>
                            <p class="mt-1 text-[12px] text-[#6b7780]">Réf. <?= htmlspecialchars(orderRef($members)) ?></p>
                        </div>
                    </div>

                    <?php if ($key === 'waiting'):
                        $lim = null; foreach ($members as $m) { $l = attente_limite($m); if ($l && (!$lim || $l < $lim)) $lim = $l; } ?>
                        <?php if ($lim): ?><p class="mt-3 inline-flex items-center gap-2 text-[13px] font-semibold text-[#5b4a1c] bg-[#f8f5ea] rounded-lg px-3 py-1.5">À régler avant le <?= htmlspecialchars(fmtJour($lim) . ' à ' . $lim->format('H\hi')) ?> — passé ce délai, la place est libérée.</p><?php endif; ?>
                    <?php endif; ?>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <?php foreach ($live as $m): ?>
                            <span class="inline-flex items-center gap-2 pl-1.5 pr-3 py-1.5 rounded-full bg-[#f1f3f5] text-[13px] font-semibold text-[#3b4a54]">
                                <span class="w-6 h-6 rounded-full bg-[#3a4852] text-white text-[12px] font-bold flex items-center justify-center"><?= htmlspecialchars(mb_strtoupper(mb_substr($m['enfant_prenom'], 0, 1))) ?></span>
                                <?= htmlspecialchars($m['enfant_prenom']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-auto pt-5 flex flex-wrap items-center gap-2">
                        <a href="reservation?r=<?= urlencode($token) ?>" class="rs-btn">Gérer la réservation</a>
                        <?php if ($hasPaid): ?>
                            <a href="generate_pdf_certificat.php?r=<?= urlencode($token) ?>" target="_blank" rel="noopener" class="rs-btn rs-btn-light">Reçu PDF</a>
                        <?php endif; ?>
                        <?php if ($canPay): ?>
                            <form action="api/create_stripe_session.php" method="POST" class="inline">
                                <input type="hidden" name="r" value="<?= htmlspecialchars($token) ?>">
                                <button type="submit" class="rs-btn rs-btn-light">Payer <?= htmlspecialchars(fmtEuro(max(0, $tot['due'] - $tot['paid']))) ?></button>
                            </form>
                        <?php endif; ?>
                        <a href="camp_details?t=<?= urlencode($first['camp_token']) ?>" class="ml-auto text-[14px] font-semibold text-[#3d6a8e] hover:text-[#2d5170] underline-offset-4 hover:underline">Voir le séjour</a>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

<script>
document.querySelectorAll('.rs-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
        document.querySelectorAll('.rs-tab').forEach(function (t) { t.classList.toggle('is-active', t === tab); });
        document.querySelectorAll('[data-panel]').forEach(function (p) { p.classList.toggle('hidden', p.dataset.panel !== tab.dataset.tab); });
    });
});
</script>

<?php require_once 'partials/footer.php'; ?>

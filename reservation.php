<?php
// reservation.php?r=<jeton> : gérer UNE commande (un séjour/une session, un ou plusieurs enfants).
// Partie « générale » (séjour, paiement, reçu, organisateur) + une ligne par enfant (formule, prix, fiche).
require_once 'api/config.php';
require_once 'api/orders.php';
require_once 'api/enfants.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user']['id'])) { header('Location: login.php?redirect=' . urlencode('reservation?r=' . ($_GET['r'] ?? ''))); exit; }
$userId = (int)$_SESSION['user']['id'];

$members = [];
try { $members = loadOrderByToken($pdo, (string)($_GET['r'] ?? ''), $userId); }
catch (Throwable $e) { error_log('reservation: ' . $e->getMessage()); }
if (!$members) { header('Location: reservations'); exit; }

$first = $members[0];
$status = orderStatus($members);
$tot = orderTotals($members);
$ref = orderRef($members);
$bd = orderBreakdown($members);   // détail : tarif, remise fratrie, code promo
$token = $first['reservation_token'];
$live = array_values(array_filter($members, function ($m) { return !isCancelled($m); }));
$canPay = in_array($status[0], ['pending', 'partial'], true) && !empty($first['inscription_en_ligne']);
$hasPaid = false; foreach ($members as $m) if ($m['statut_paiement'] === 'PAYE') $hasPaid = true;
$img = trim((string)$first['image_url']);
if ($img !== '') { $img = str_replace('\\', '/', $img); if (!preg_match('#^(https?:)?//#', $img) && strpos($img, 'uploads/') !== 0) $img = 'uploads/camps/' . ltrim($img, '/'); }
$lieu = trim(implode(', ', array_filter([trim((string)$first['adresse']), trim($first['code_postal'] . ' ' . $first['ville'])])));
$ended = $first['_end'] < new DateTime('today');
$mode = ['CARTE' => 'Carte bancaire', 'GRATUIT' => 'Gratuit'][$first['mode_paiement']] ?? null;

$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Gérer ma réservation - ColoMap</title>
<style>
    .rv-card { background:#fff; border:1px solid #e5e7eb; border-radius:24px; }
    .rv-btn { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; padding:13px 18px; border-radius:12px; font-size:15px; font-weight:700; background:#2f3d45; color:#fff; transition:background .2s ease; text-align:center; }
    .rv-btn:hover { background:#1f2a30; }
    .rv-btn-light { background:#fff; color:#26343b; border:1.5px solid #e5e7eb; }
    .rv-btn-light:hover { background:#f1f3f5; }
    .rv-label { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#6b7780; }
</style>

<main class="flex-1 w-full max-w-[1100px] mx-auto px-5 sm:px-8 pt-8 pb-16">
    <a href="reservations" class="inline-flex items-center gap-2 text-[14px] font-semibold text-[#3b4a54] hover:text-[#26343b]">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12H4m6-6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Mes réservations
    </a>

    <?php $errs = ['full' => "Il n'y a plus assez de places pour finaliser cette réservation.", 'closed' => "Les inscriptions à ce séjour sont closes.", 'amount' => "Le montant à régler est invalide. Contactez-nous."]; ?>
    <?php if (isset($errs[$_GET['err'] ?? ''])): ?>
        <div class="mt-5 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-semibold text-red-700" role="alert"><?= htmlspecialchars($errs[$_GET['err']]) ?></div>
    <?php endif; ?>

    <div class="mt-5 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <span class="inline-block text-[12px] font-bold px-3 py-1.5 rounded-full <?= $status[2] ?>"><?= $status[1] ?></span>
            <h1 class="mt-3 text-[28px] md:text-[34px] font-extrabold text-[#26343b] leading-tight"><?= htmlspecialchars($first['camp_nom']) ?></h1>
            <p class="mt-2 text-[15px] text-[#6b7780]">Référence <?= htmlspecialchars($ref) ?> · <?= count($live) ?> participant<?= count($live) > 1 ? 's' : '' ?></p>
        </div>
    </div>

    <div class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-8">

        <!-- Colonne gauche : séjour + participants -->
        <div class="lg:col-span-2 space-y-8 min-w-0">

            <section class="rv-card overflow-hidden" aria-labelledby="h-sejour">
                <?php if ($img !== ''): ?>
                    <div class="relative h-[200px] bg-[#e5e7eb]"><img src="<?= htmlspecialchars($img) ?>" alt="" class="absolute inset-0 w-full h-full object-cover" onerror="this.remove()"></div>
                <?php endif; ?>
                <div class="p-6">
                    <h2 id="h-sejour" class="rv-label mb-3">Le séjour</h2>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4 text-[15px]">
                        <div><dt class="text-[#6b7780] text-[13px]">Dates</dt><dd class="font-bold text-[#26343b]"><?= htmlspecialchars(fmtPlage($first['_start'], $first['_end'])) ?></dd></div>
                        <div><dt class="text-[#6b7780] text-[13px]">Durée</dt><dd class="font-bold text-[#26343b]"><?= $first['_nights'] + 1 ?> jours / <?= $first['_nights'] ?> nuit<?= $first['_nights'] > 1 ? 's' : '' ?></dd></div>
                        <div class="sm:col-span-2"><dt class="text-[#6b7780] text-[13px]">Lieu</dt><dd class="font-bold text-[#26343b]"><?= htmlspecialchars($lieu !== '' ? $lieu : (string)$first['ville']) ?></dd></div>
                        <div class="sm:col-span-2"><dt class="text-[#6b7780] text-[13px]">Organisateur</dt><dd class="font-bold text-[#26343b]"><?= htmlspecialchars((string)$first['orga_nom']) ?></dd></div>
                    </dl>
                    <div class="mt-5 flex flex-wrap gap-3">
                        <a href="camp_details?t=<?= urlencode($first['camp_token']) ?>" class="rv-btn rv-btn-light" style="width:auto">Voir la page du séjour</a>
                        <?php if (!empty($first['orga_user_id'])): ?>
                            <button type="button" id="btn-contact" class="rv-btn" style="width:auto">Contacter l'organisateur</button>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section aria-labelledby="h-participants">
                <h2 id="h-participants" class="text-[20px] font-bold text-[#26343b] mb-4">Participants</h2>
                <div class="space-y-4">
                    <?php foreach ($members as $m):
                        $age = ageAnnees($m['date_naissance']);
                        $cancelled = isCancelled($m);
                        $paid = $m['statut_paiement'] === 'PAYE';
                    ?>
                    <article class="rv-card p-5 flex flex-col sm:flex-row sm:items-center gap-4 <?= $cancelled ? 'opacity-60' : '' ?>">
                        <div class="flex items-center gap-4 min-w-0 flex-1">
                            <span class="w-12 h-12 flex-none rounded-full bg-[#3a4852] text-white text-[18px] font-bold flex items-center justify-center"><?= htmlspecialchars(mb_strtoupper(mb_substr($m['enfant_prenom'], 0, 1))) ?></span>
                            <div class="min-w-0">
                                <p class="text-[17px] font-bold text-[#26343b] truncate"><?= htmlspecialchars($m['enfant_prenom'] . ' ' . $m['enfant_nom']) ?></p>
                                <p class="text-[13px] text-[#6b7780]">
                                    <?php $ln = $bd['lines'][$m['inscription_id']] ?? null; ?>
                                    <?= $age !== null ? $age . ' ans · ' : '' ?><?= htmlspecialchars($ln['label'] ?? 'Prix du séjour') ?><?= ($ln && $ln['pct'] > 0) ? ' · remise fratrie −' . $ln['pct'] . ' %' : '' ?> ·
                                    <?= $cancelled ? 'Annulée' : ($paid ? 'Payée' : 'À régler') ?>
                                </p>
                            </div>
                        </div>
                        <p class="text-[18px] font-extrabold text-[#26343b] sm:text-right"><?= htmlspecialchars(fmtEuro((float)$m['prix_final'])) ?></p>
                        <div class="flex flex-wrap gap-2">
                            <a href="info_inscrit?r=<?= urlencode($m['reservation_token']) ?>" class="rv-btn rv-btn-light" style="width:auto;padding:10px 14px;font-size:14px">Fiche &amp; documents</a>
                            <a href="modif_child?id=<?= urlencode(enfant_jeton($pdo, (int)$m['enfant_id'])) ?>" class="rv-btn rv-btn-light" style="width:auto;padding:10px 14px;font-size:14px">Modifier ses infos</a>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <!-- Colonne droite : paiement -->
        <aside class="min-w-0">
            <div class="rv-card p-6 lg:sticky lg:top-[92px]">
                <h2 class="rv-label mb-4">Paiement</h2>
                <ul class="space-y-4 text-[14px]">
                    <?php foreach ($live as $m): $ln = $bd['lines'][$m['inscription_id']] ?? ['label' => 'Prix du séjour', 'base' => (float)$m['prix_final'], 'remise' => 0, 'pct' => 0]; ?>
                        <li>
                            <div class="flex justify-between gap-3"><span class="text-[#26343b] font-semibold min-w-0 truncate"><?= htmlspecialchars($m['enfant_prenom']) ?></span><span class="font-bold text-[#26343b] flex-none"><?= htmlspecialchars(fmtEuro((float)$ln['base'])) ?></span></div>
                            <div class="text-[13px] text-[#6b7780]"><?= htmlspecialchars($ln['label']) ?></div>
                            <?php if ($ln['remise'] > 0.005): ?>
                                <div class="mt-1 flex justify-between text-[13px] text-[#3b4a54]"><span>Remise fratrie −<?= (int)$ln['pct'] ?> %</span><span class="font-semibold">− <?= htmlspecialchars(fmtEuro((float)$ln['remise'])) ?></span></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="mt-4 pt-4 border-t border-[#e5e7eb] space-y-2 text-[14px]">
                    <?php if ($bd['fratrie'] > 0.005): ?>
                        <div class="flex justify-between"><span class="text-[#6b7780]">Sous-total</span><span class="font-semibold text-[#26343b]"><?= htmlspecialchars(fmtEuro($bd['subtotal'])) ?></span></div>
                        <div class="flex justify-between"><span class="text-[#6b7780]">Remise fratrie</span><span class="font-semibold text-[#26343b]">− <?= htmlspecialchars(fmtEuro($bd['fratrie'])) ?></span></div>
                    <?php endif; ?>
                    <div class="flex justify-between"><span class="text-[#6b7780]">Total de la commande</span><span class="font-bold text-[#26343b]"><?= htmlspecialchars(fmtEuro($tot['due'])) ?></span></div>
                    <?php if ($bd['promo'] > 0.005): ?>
                        <div class="flex justify-between"><span class="text-[#6b7780]">Code promo</span><span class="font-semibold text-[#26343b]">− <?= htmlspecialchars(fmtEuro($bd['promo'])) ?></span></div>
                    <?php endif; ?>
                    <div class="flex justify-between text-[16px] pt-1"><span class="font-bold text-[#26343b]">Réglé</span><span class="font-extrabold text-[#26343b]"><?= htmlspecialchars(fmtEuro($tot['paid'])) ?></span></div>
                    <?php if ($mode): ?><div class="flex justify-between"><span class="text-[#6b7780]">Moyen de paiement</span><span class="font-semibold text-[#26343b]"><?= $mode ?></span></div><?php endif; ?>
                    <div class="flex justify-between"><span class="text-[#6b7780]">Réservé le</span><span class="font-semibold text-[#26343b]"><?= htmlspecialchars((new DateTime($first['date_inscription']))->format('d/m/Y')) ?></span></div>
                </div>

                <div class="mt-6 space-y-3">
                    <?php if ($canPay): ?>
                        <form action="api/create_stripe_session.php" method="POST">
                            <input type="hidden" name="r" value="<?= htmlspecialchars($token) ?>">
                            <button type="submit" class="rv-btn">Payer <?= htmlspecialchars(fmtEuro(max(0, $tot['due'] - $tot['paid']))) ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if ($hasPaid): ?>
                        <a href="generate_pdf_certificat.php?r=<?= urlencode($token) ?>" target="_blank" rel="noopener" class="rv-btn rv-btn-light">Télécharger le reçu (PDF)</a>
                    <?php endif; ?>
                </div>
                <p class="mt-4 text-[12px] text-[#6b7780]">Une question sur cette réservation&nbsp;? Contactez l'organisateur ou écrivez-nous depuis la page <a href="contact" class="underline">Contact</a>.</p>
            </div>
        </aside>
    </div>
</main>

<script>
document.getElementById('btn-contact')?.addEventListener('click', async function () {
    const btn = this; btn.disabled = true; btn.style.opacity = '.6';
    try {
        const res = await fetch('api/start_conversation.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ organisateurId: <?= json_encode((int)$first['orga_user_id']) ?>, campId: <?= json_encode((int)$first['camp_id']) ?> }) });
        const data = await res.json();
        if (data.conversationId) window.location.href = 'messagerie?conv_id=' + data.conversationId;
        else { alert(data.error || 'Erreur'); btn.disabled = false; btn.style.opacity = ''; }
    } catch (e) { alert('Erreur réseau'); btn.disabled = false; btn.style.opacity = ''; }
});
</script>

<?php require_once 'partials/footer.php'; ?>

<?php
/**
 * info_inscrit.php?r=<jeton de réservation> : dossier d'UN enfant pour UN séjour.
 * Suivi du dossier, séjour, fiche de l'enfant, documents, paiement (avec détail des réductions) et organisateur.
 * L'inscription doit appartenir à un enfant du parent connecté. (Anciens liens camp_id/child_id : convertis en jeton.)
 */
require_once 'api/config.php';
require_once 'api/orders.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user']['id'])) { header('Location: login.php?redirect=' . urlencode('reservations')); exit; }
$userId = (int)$_SESSION['user']['id'];

// 1. Jeton de la réservation (ou conversion d'un ancien lien camp_id + child_id, avec contrôle du parent)
$token = (string)($_GET['r'] ?? '');
if ($token === '' && !empty($_GET['camp_id']) && !empty($_GET['child_id'])) {
    $st = $pdo->prepare("SELECT i.reservation_token FROM inscriptions i JOIN enfants e ON e.id = i.enfant_id WHERE i.camp_id = ? AND i.enfant_id = ? AND e.parent_id = ?");
    $st->execute([(int)$_GET['camp_id'], (int)$_GET['child_id'], $userId]);
    $token = (string)$st->fetchColumn();
}

// 2. Données : la commande, l'inscription de cet enfant, sa fiche
$members = []; $m = null;
try { $members = loadOrderByToken($pdo, $token, $userId); } catch (Throwable $e) { error_log('info_inscrit: ' . $e->getMessage()); }
foreach ($members as $x) if ($x['reservation_token'] === $token) $m = $x;
if (!$m) { header('Location: reservations'); exit; }

require_once 'api/enfants.php';
$enfantJeton = enfant_jeton($pdo, (int)$m['enfant_id']);   // $m appartient au parent connecté (vérifié par loadOrderByToken)
$child = $pdo->prepare("SELECT infos_sante, allergies, regime_alimentaire, taille, poids, fiche_sanitaire_token, carnet_sante_token FROM enfants WHERE id = ? AND parent_id = ?");
$child->execute([(int)$m['enfant_id'], $userId]);
$child = $child->fetch(PDO::FETCH_ASSOC) ?: [];
$desc = $pdo->prepare("SELECT description FROM camps WHERE id = ?");
$desc->execute([(int)$m['camp_id']]);
$description = (string)$desc->fetchColumn();

$bd = orderBreakdown($members);
$ln = $bd['lines'][$m['inscription_id']] ?? ['label' => 'Prix du séjour', 'base' => (float)$m['prix_final'], 'remise' => 0, 'pct' => 0, 'price' => (float)$m['prix_final']];
$live = array_values(array_filter($members, function ($x) { return !isCancelled($x); }));
$orderToken = $members[0]['reservation_token'];

$cancelled = isCancelled($m);
$isPaid = $m['statut_paiement'] === 'PAYE';
$canPay = !$cancelled && !$isPaid && !empty($m['inscription_en_ligne']);
$age = ageAnnees($m['date_naissance']);
$lieu = trim(implode(', ', array_filter([trim((string)$m['adresse']), trim($m['code_postal'] . ' ' . $m['ville'])])));
$img = trim((string)$m['image_url']);
if ($img !== '') { $img = str_replace('\\', '/', $img); if (!preg_match('#^(https?:)?//#', $img) && strpos($img, 'uploads/') !== 0) $img = 'uploads/camps/' . ltrim($img, '/'); }

// Étapes du dossier : 1 inscrit, 2 validé, 3 payé
$step = 1;
if (in_array($m['statut'], ['Confirmé', 'Validé'], true) || $isPaid) $step = 2;
if ($isPaid) $step = 3;
$steps = [['Inscription', date('d/m/Y', strtotime($m['date_inscription']))], ['Validation', $step >= 2 ? 'Validée' : 'En cours'], ['Paiement', $isPaid ? 'Réglé' : 'À régler']];

$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Dossier de <?= htmlspecialchars($m['enfant_prenom']) ?> - ColoMap</title>
<style>
    .ii-card { background:#fff; border:1px solid #e5e7eb; border-radius:24px; }
    .ii-label { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#6b7780; }
    .ii-btn { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; padding:13px 18px; border-radius:12px; font-size:15px; font-weight:700; background:#2f3d45; color:#fff; transition:background .2s ease; text-align:center; }
    .ii-btn:hover { background:#1f2a30; }
    .ii-btn-light { background:#fff; color:#26343b; border:1.5px solid #e5e7eb; }
    .ii-btn-light:hover { background:#f1f3f5; }
    .ii-doc { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 16px; border:1.5px solid #e5e7eb; border-radius:14px; transition:background .15s ease, border-color .15s ease; }
    a.ii-doc:hover { background:#f6f8fa; border-color:#a9b8c6; }
</style>

<main class="flex-1 w-full max-w-[1100px] mx-auto px-5 sm:px-8 pt-8 pb-16">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-[14px] font-semibold">
        <a href="reservations" class="inline-flex items-center gap-2 text-[#3b4a54] hover:text-[#26343b]">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12H4m6-6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Mes réservations
        </a>
        <?php if (count($live) > 1): ?>
            <span class="text-[#cbd2d9]">/</span>
            <a href="reservation?r=<?= urlencode($orderToken) ?>" class="text-[#3d6a8e] hover:text-[#2d5170]">Toute la commande (<?= count($live) ?> enfants)</a>
        <?php endif; ?>
    </div>

    <div class="mt-5">
        <span class="inline-block text-[12px] font-bold px-3 py-1.5 rounded-full <?= $cancelled ? 'bg-gray-100 text-gray-600' : ($isPaid ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700') ?>">
            <?= $cancelled ? 'Annulée' : ($isPaid ? 'Dossier complet' : 'Paiement en attente') ?>
        </span>
        <h1 class="mt-3 text-[28px] md:text-[34px] font-extrabold text-[#26343b] leading-tight"><?= htmlspecialchars($m['enfant_prenom'] . ' ' . $m['enfant_nom']) ?></h1>
        <p class="mt-2 text-[15px] text-[#6b7780]"><?= htmlspecialchars($m['camp_nom']) ?> · <?= htmlspecialchars(fmtPlage($m['_start'], $m['_end'])) ?> · <?= htmlspecialchars((string)$m['ville']) ?></p>
    </div>

    <!-- Suivi du dossier -->
    <section class="ii-card mt-8 p-6 sm:p-8" aria-label="Suivi du dossier">
        <ol class="relative flex justify-between">
            <span class="absolute top-5 left-[16%] right-[16%] h-[3px] bg-[#e5e7eb] rounded"></span>
            <span class="absolute top-5 left-[16%] h-[3px] bg-[#2f3d45] rounded transition-all duration-700" style="width: <?= ($step - 1) * 34 ?>%"></span>
            <?php foreach ($steps as $i => [$label, $sub]): $done = $i < $step; ?>
                <li class="relative z-10 flex flex-col items-center text-center w-1/3">
                    <span class="w-10 h-10 rounded-full flex items-center justify-center text-[15px] font-bold ring-4 ring-white <?= $done ? 'bg-[#2f3d45] text-white' : 'bg-white border-2 border-[#cbd2d9] text-[#9aa3ab]' ?>">
                        <?php if ($done): ?><svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg><?php else: echo $i + 1; endif; ?>
                    </span>
                    <span class="mt-2 text-[14px] font-bold <?= $done ? 'text-[#26343b]' : 'text-[#9aa3ab]' ?>"><?= $label ?></span>
                    <span class="text-[12.5px] text-[#6b7780]"><?= htmlspecialchars($sub) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <div class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-8">

        <!-- Colonne principale -->
        <div class="lg:col-span-2 space-y-8 min-w-0">

            <section class="ii-card overflow-hidden" aria-labelledby="h-sejour">
                <?php if ($img !== ''): ?><div class="relative h-[190px] bg-[#e5e7eb]"><img src="<?= htmlspecialchars($img) ?>" alt="" class="absolute inset-0 w-full h-full object-cover" onerror="this.remove()"></div><?php endif; ?>
                <div class="p-6">
                    <h2 id="h-sejour" class="ii-label mb-4">Dates et lieu</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="rounded-2xl bg-[#f8f5ea] p-4">
                            <p class="ii-label">Début</p>
                            <p class="mt-1 text-[17px] font-bold text-[#26343b]"><?= $m['_start']->format('d/m/Y') ?></p>
                        </div>
                        <div class="rounded-2xl bg-[#f8f5ea] p-4">
                            <p class="ii-label">Fin</p>
                            <p class="mt-1 text-[17px] font-bold text-[#26343b]"><?= $m['_end']->format('d/m/Y') ?> <span class="text-[13px] font-semibold text-[#6b7780]">· <?= $m['_nights'] + 1 ?> jours / <?= $m['_nights'] ?> nuit<?= $m['_nights'] > 1 ? 's' : '' ?></span></p>
                        </div>
                    </div>
                    <p class="mt-5 text-[13px] text-[#6b7780]">Adresse</p>
                    <p class="text-[15px] font-semibold text-[#26343b]"><?= htmlspecialchars($lieu !== '' ? $lieu : (string)$m['ville']) ?></p>
                    <?php if ($description !== ''): ?>
                        <div class="mt-4 max-h-40 overflow-y-auto pr-2 text-[14.5px] leading-relaxed text-[#3b4a54]"><?= nl2br(htmlspecialchars($description)) ?></div>
                    <?php endif; ?>
                    <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($lieu !== '' ? $lieu : (string)$m['ville']) ?>" target="_blank" rel="noopener" class="mt-5 inline-flex items-center gap-2 text-[14px] font-bold text-[#3d6a8e] hover:text-[#2d5170]">Voir l'itinéraire sur Google Maps →</a>
                </div>
            </section>

            <section class="ii-card p-6" aria-labelledby="h-enfant">
                <div class="flex items-start justify-between gap-4">
                    <h2 id="h-enfant" class="ii-label">Fiche de l'enfant</h2>
                    <a href="modif_child?id=<?= urlencode($enfantJeton) ?>" class="text-[13px] font-bold px-3 py-1.5 rounded-lg border border-[#e5e7eb] text-[#26343b] hover:bg-[#f1f3f5] transition">Modifier</a>
                </div>
                <div class="mt-4 flex items-center gap-4">
                    <span class="w-14 h-14 flex-none rounded-full bg-[#3a4852] text-white text-[22px] font-bold flex items-center justify-center"><?= htmlspecialchars(mb_strtoupper(mb_substr($m['enfant_prenom'], 0, 1))) ?></span>
                    <div>
                        <p class="text-[18px] font-bold text-[#26343b]"><?= htmlspecialchars($m['enfant_prenom'] . ' ' . $m['enfant_nom']) ?></p>
                        <p class="text-[13.5px] text-[#6b7780]">Né(e) le <?= date('d/m/Y', strtotime($m['date_naissance'])) ?><?= $age !== null ? ' · ' . $age . ' ans' : '' ?></p>
                    </div>
                </div>
                <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-3 text-[14.5px]">
                    <div class="rounded-2xl bg-[#f1f3f5] p-4"><p class="ii-label">Santé / allergies</p><p class="mt-1 text-[#26343b]"><?= htmlspecialchars(trim(($child['infos_sante'] ?? '') . ' ' . ($child['allergies'] ?? '')) ?: 'Aucune information') ?></p></div>
                    <div class="rounded-2xl bg-[#f1f3f5] p-4"><p class="ii-label">Taille / poids</p><p class="mt-1 text-[#26343b]"><?= !empty($child['taille']) ? htmlspecialchars($child['taille']) . ' cm' : '?' ?> / <?= !empty($child['poids']) ? htmlspecialchars($child['poids']) . ' kg' : '?' ?></p></div>
                    <?php if (!empty($child['regime_alimentaire'])): ?><div class="rounded-2xl bg-[#f1f3f5] p-4 sm:col-span-2"><p class="ii-label">Régime alimentaire</p><p class="mt-1 text-[#26343b]"><?= htmlspecialchars($child['regime_alimentaire']) ?></p></div><?php endif; ?>
                </div>
            </section>

            <section aria-labelledby="h-docs">
                <h2 id="h-docs" class="text-[20px] font-bold text-[#26343b] mb-4">Documents</h2>
                <div class="space-y-3">
                    <?php if (!$cancelled && ($isPaid || (float)$m['prix_final'] === 0.0)): ?>
                        <a href="generate_pdf_certificat.php?r=<?= urlencode($orderToken) ?>" target="_blank" rel="noopener" class="ii-doc">
                            <span><span class="block text-[15px] font-bold text-[#26343b]">Reçu de paiement</span><span class="block text-[12.5px] text-[#6b7780]">PDF · confirmation d'inscription et détail du paiement</span></span>
                            <span class="text-[#3d6a8e] font-bold text-[14px]">Télécharger</span>
                        </a>
                    <?php endif; ?>
                    <?php foreach ([['carnet_sante_token', 'sante', 'Carnet de santé'], ['fiche_sanitaire_token', 'sanitaire', 'Fiche sanitaire de liaison']] as [$col, $dir, $name]): ?>
                        <?php if (!empty($child[$col])): ?>
                            <a href="api/secure_file.php?type=<?= $dir ?>&amp;file=<?= urlencode($child[$col]) ?>" target="_blank" rel="noopener" class="ii-doc">
                                <span><span class="block text-[15px] font-bold text-[#26343b]"><?= $name ?></span><span class="block text-[12.5px] text-[#6b7780]">Document enregistré</span></span>
                                <span class="text-[#3d6a8e] font-bold text-[14px]">Voir</span>
                            </a>
                        <?php else: ?>
                            <div class="ii-doc bg-[#f8f5ea] border-[#f8f5ea]">
                                <span><span class="block text-[15px] font-bold text-[#26343b]"><?= $name ?></span><span class="block text-[12.5px] text-[#6b7780]">Pas encore ajouté(e). À joindre avant le départ.</span></span>
                                <a href="modif_child?id=<?= urlencode($enfantJeton) ?>" class="text-[#3d6a8e] font-bold text-[14px] whitespace-nowrap">Ajouter</a>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <!-- Colonne droite : paiement + organisateur -->
        <aside class="space-y-6 min-w-0">
            <div class="ii-card p-6">
                <h2 class="ii-label mb-4">Paiement de <?= htmlspecialchars($m['enfant_prenom']) ?></h2>
                <div class="space-y-2 text-[14px]">
                    <div class="flex justify-between gap-3"><span class="text-[#3b4a54] min-w-0 truncate"><?= htmlspecialchars($ln['label']) ?></span><span class="font-bold text-[#26343b] flex-none"><?= htmlspecialchars(fmtEuro((float)$ln['base'])) ?></span></div>
                    <?php if ($ln['remise'] > 0.005): ?>
                        <div class="flex justify-between"><span class="text-[#6b7780]">Remise fratrie −<?= (int)$ln['pct'] ?> %</span><span class="font-semibold text-[#26343b]">− <?= htmlspecialchars(fmtEuro((float)$ln['remise'])) ?></span></div>
                    <?php endif; ?>
                    <div class="flex justify-between pt-3 mt-1 border-t border-[#e5e7eb] text-[16px]"><span class="font-bold text-[#26343b]">Total</span><span class="font-extrabold text-[#26343b]"><?= htmlspecialchars(fmtEuro((float)$m['prix_final'])) ?></span></div>
                </div>
                <div class="mt-4 h-2 rounded-full bg-[#eceef1] overflow-hidden"><div class="h-2 rounded-full bg-[#2f3d45]" style="width: <?= $isPaid ? '100' : '0' ?>%"></div></div>
                <p class="mt-2 text-[12.5px] text-right <?= $isPaid ? 'text-[#3b4a54]' : 'text-[#6b7780]' ?>"><?= $cancelled ? 'Réservation annulée' : ($isPaid ? 'Règlement effectué' : 'Reste à payer : ' . fmtEuro((float)$m['prix_final'])) ?></p>
                <?php if ($bd['promo'] > 0.005 && $isPaid): ?><p class="mt-1 text-[12.5px] text-[#6b7780]">Un code promo a été appliqué sur la commande (− <?= htmlspecialchars(fmtEuro($bd['promo'])) ?>).</p><?php endif; ?>

                <?php if ($canPay): ?>
                    <form action="api/create_stripe_session.php" method="POST" class="mt-5">
                        <input type="hidden" name="r" value="<?= htmlspecialchars($orderToken) ?>">
                        <button type="submit" class="ii-btn">Payer maintenant</button>
                    </form>
                <?php endif; ?>
                <?php if (count($live) > 1): ?>
                    <a href="reservation?r=<?= urlencode($orderToken) ?>" class="ii-btn ii-btn-light mt-3">Gérer toute la commande</a>
                <?php endif; ?>
            </div>

            <div class="ii-card p-6 text-center">
                <div class="w-16 h-16 mx-auto rounded-full bg-[#f1f3f5] overflow-hidden flex items-center justify-center text-[22px] font-bold text-[#26343b]">
                    <?php if (!empty($m['orga_logo'])): ?><img src="<?= htmlspecialchars($m['orga_logo']) ?>" alt="" class="w-full h-full object-contain" onerror="this.parentNode.textContent=<?= htmlspecialchars(json_encode(mb_strtoupper(mb_substr((string)$m['orga_nom'], 0, 1))), ENT_QUOTES) ?>"><?php else: echo htmlspecialchars(mb_strtoupper(mb_substr((string)$m['orga_nom'], 0, 1))); endif; ?>
                </div>
                <p class="mt-3 text-[17px] font-bold text-[#26343b]"><?= htmlspecialchars((string)$m['orga_nom']) ?></p>
                <p class="text-[13px] text-[#6b7780]">Organisateur du séjour</p>
                <div class="mt-5 space-y-3">
                    <?php if (!empty($m['orga_user_id'])): ?><button type="button" id="btn-contact" class="ii-btn">Envoyer un message</button><?php endif; ?>
                    <?php if (!empty($m['orga_tel'])): ?><a href="tel:<?= htmlspecialchars($m['orga_tel']) ?>" class="ii-btn ii-btn-light"><?= htmlspecialchars($m['orga_tel']) ?></a><?php endif; ?>
                </div>
            </div>

            <p class="text-center text-[13px] text-[#6b7780]">Une question ? <a href="aide" class="underline">Consultez l'aide</a> ou <a href="contact" class="underline">contactez-nous</a>.</p>
        </aside>
    </div>
</main>

<script>
document.getElementById('btn-contact')?.addEventListener('click', async function () {
    const btn = this; btn.disabled = true; btn.style.opacity = '.6';
    try {
        const res = await fetch('api/start_conversation.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ organisateurId: <?= json_encode((int)$m['orga_user_id']) ?>, campId: <?= json_encode((int)$m['camp_id']) ?> }) });
        const data = await res.json();
        if (data.conversationId) window.location.href = 'messagerie?conv_id=' + data.conversationId;
        else { alert(data.error || 'Erreur'); btn.disabled = false; btn.style.opacity = ''; }
    } catch (e) { alert('Erreur réseau'); btn.disabled = false; btn.style.opacity = ''; }
});
</script>

<?php require_once 'partials/footer.php'; ?>

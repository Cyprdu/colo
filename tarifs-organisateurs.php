<?php
// tarifs-organisateurs.php : ce que coûte moncamp.fr à un organisme (commission, frais Stripe, versements, hors carte, dette), FAQ et simulateur.
require_once 'api/config.php';
require_once 'api/finance_lib.php';
$R = fin_reglages();
$pct = (float)$R['commission_pct']; $fixe = (int)$R['commission_fixe_cents']; $hors = (float)$R['commission_hors_pct'];
$n = fn($v, $d = 2) => rtrim(rtrim(number_format((float)$v, $d, ',', ' '), '0'), ',');
$ex = 50000;
[$exCom] = fin_commission_split($ex);
$exStripe = fin_frais_estimes($ex);
$exNet = $ex - $exCom - $exStripe;
$e = fn($c) => number_format($c / 100, 2, ',', ' ') . ' €';
$comTxt = $n($pct) . ' %' . ($fixe ? ' + ' . $n($fixe / 100) . ' €' : '');
$page_title = 'Tarifs organisateurs : commission et frais - moncamp.fr';
$page_desc = "Publier ses séjours sur moncamp.fr est gratuit : commission de $comTxt par paiement en ligne, frais Stripe au prix coûtant, versements automatiques. Simulateur et questions fréquentes.";
$body_class = 'bg-white';
require_once 'partials/header.php';
$faq = [
    ['Y a-t-il un abonnement ou des frais fixes ?', "Non. L'inscription, la publication des séjours et l'espace de gestion sont gratuits. moncamp.fr ne gagne de l'argent que lorsqu'une famille paie une inscription."],
    ['Qui paie la commission : la famille ou l\'organisme ?', "L'organisme. La famille paie exactement le prix affiché ; la commission et les frais de paiement sont retenus sur le montant encaissé avant le versement."],
    ['Pourquoi les frais Stripe sont-ils à ma charge ?', "Les paiements sont encaissés directement sur votre compte Stripe : vous êtes le vendeur, Stripe vous facture ses frais comme à tout commerçant. moncamp.fr n'ajoute aucune marge dessus."],
    ['Quand l\'argent arrive-t-il sur mon compte bancaire ?', "Automatiquement, chaque jour ouvré, dès que Stripe a rendu les fonds disponibles (en général 2 à 7 jours ouvrés après le paiement). Le bouton « Virer mon argent maintenant » du tableau de bord accélère un versement."],
    ['Et si une famille est remboursée ?', "Le remboursement est pris sur votre solde Stripe ; la commission correspondante vous est rendue (elle figure en déduction sur la facture du mois). Les frais Stripe du paiement initial ne sont pas rendus par Stripe."],
    ['Qu\'est-ce que la « dette » ?', "Si un remboursement ou un litige dépasse votre solde, moncamp.fr avance la somme à la famille. Votre solde devient négatif et se compense automatiquement sur vos prochains encaissements. Vous pouvez aussi la régler directement depuis le tableau de bord."],
    ['Les paiements par chèque, espèces ou chèques-vacances sont-ils possibles ?', "Oui, si vous les acceptez. La famille réserve sa place en ligne et vous règle directement. Quand vous marquez le paiement « reçu », une commission réduite de " . $n($hors) . " % est retenue sur vos prochains versements par carte."],
    ['Vais-je recevoir une facture ?', "Oui. Chaque début de mois, la facture des commissions du mois écoulé est envoyée par e-mail et reste téléchargeable dans votre tableau de bord (onglet Rapports)."],
];
?>
<style>
.tf-ic { width:44px; height:44px; border-radius:14px; background:#eef2f4; color:#3d6a8e; display:flex; align-items:center; justify-content:center; flex:none; }
.tf-bar { display:flex; height:46px; border-radius:14px; overflow:hidden; font-size:12.5px; font-weight:700; color:#fff; }
.tf-bar > span { display:flex; align-items:center; justify-content:center; white-space:nowrap; overflow:hidden; padding:0 6px; }
.tf-faq summary { cursor:pointer; list-style:none; display:flex; justify-content:space-between; gap:12px; font-weight:700; color:#26343b; padding:16px 0; }
.tf-faq summary::-webkit-details-marker { display:none; }
.tf-faq summary::after { content:'+'; font-size:22px; line-height:1; color:#6b7780; }
.tf-faq details[open] summary::after { content:'–'; }
.tf-out { font-variant-numeric: tabular-nums; }
</style>
<main class="ds-page">
    <div class="max-w-3xl">
        <p class="text-[12px] font-bold uppercase tracking-widest text-[#3d6a8e]">Organisateurs</p>
        <h1 class="ds-h1 mt-1">Des tarifs simples, sans abonnement</h1>
        <p class="ds-lead">Publier vos séjours est gratuit. Vous ne payez que lorsqu'une famille règle une inscription en ligne : la commission de moncamp.fr et les frais du prestataire de paiement, au prix coûtant.</p>
        <div class="mt-6 flex flex-wrap gap-3"><a class="ds-btn" href="devenir-organisateur">Devenir organisateur</a><a class="ds-btn ds-btn-light" href="#simulateur">Simuler mes revenus</a></div>
    </div>

    <section class="grid md:grid-cols-3 gap-4 mt-12" aria-label="Les coûts en bref">
        <div class="ds-card p-6">
            <span class="tf-ic" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M19 5L5 19M7.5 9a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM16.5 18a1.5 1.5 0 100-3 1.5 1.5 0 000 3z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>
            <p class="mt-4 text-[13px] font-bold uppercase tracking-wider text-[#6b7780]">Commission moncamp.fr</p>
            <p class="text-[30px] font-extrabold text-[#26343b] leading-tight"><?= $comTxt ?></p>
            <p class="text-[14px] text-[#4b5964] mt-1">par paiement en ligne, rendue en cas de remboursement.</p>
        </div>
        <div class="ds-card p-6">
            <span class="tf-ic" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="3" y="6" width="18" height="12" rx="2" stroke="currentColor" stroke-width="2"/><path d="M3 10h18M7 15h3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>
            <p class="mt-4 text-[13px] font-bold uppercase tracking-wider text-[#6b7780]">Frais de paiement Stripe</p>
            <p class="text-[30px] font-extrabold text-[#26343b] leading-tight">1,5 % + 0,25 €</p>
            <p class="text-[14px] text-[#4b5964] mt-1">cartes européennes standard, facturés par Stripe à votre compte. <a class="underline" href="https://stripe.com/fr/pricing" target="_blank" rel="noopener">Autres cartes</a>.</p>
        </div>
        <div class="ds-card p-6">
            <span class="tf-ic" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M3 10l9-6 9 6M5 10v8m4-8v8m6-8v8m4-8v8M3 20h18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <p class="mt-4 text-[13px] font-bold uppercase tracking-wider text-[#6b7780]">Paiement hors carte</p>
            <p class="text-[30px] font-extrabold text-[#26343b] leading-tight"><?= $n($hors) ?> %</p>
            <p class="text-[14px] text-[#4b5964] mt-1">chèque, espèces, chèques-vacances : la famille vous paie directement.</p>
        </div>
    </section>

    <section class="mt-14 max-w-3xl">
        <h2 class="text-[24px] font-extrabold text-[#26343b]">Où va l'argent d'une inscription à <?= $e($ex) ?></h2>
        <div class="tf-bar mt-5" role="img" aria-label="Sur <?= $e($ex) ?> : <?= $e($exNet) ?> pour vous, <?= $e($exCom) ?> de commission, <?= $e($exStripe) ?> de frais Stripe">
            <span style="flex:<?= $exNet ?>;background:#3d6a8e">Pour vous · <?= $e($exNet) ?></span>
            <span style="flex:<?= max($exCom, 2600) ?>;background:#8fb3d0;color:#26343b"><?= $e($exCom) ?></span>
            <span style="flex:<?= max($exStripe, 2600) ?>;background:#c9d6df;color:#26343b"><?= $e($exStripe) ?></span>
        </div>
        <ul class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-[13.5px] text-[#4b5964]">
            <li><span class="inline-block w-3 h-3 rounded-sm align-middle mr-1.5" style="background:#3d6a8e"></span>Versé sur votre compte bancaire</li>
            <li><span class="inline-block w-3 h-3 rounded-sm align-middle mr-1.5" style="background:#8fb3d0"></span>Commission moncamp.fr</li>
            <li><span class="inline-block w-3 h-3 rounded-sm align-middle mr-1.5" style="background:#c9d6df"></span>Frais Stripe</li>
        </ul>
        <ol class="mt-8 grid sm:grid-cols-3 gap-4 text-[14.5px] text-[#3b4a54]">
            <li class="ds-card p-5"><b class="block text-[#26343b]">1. La famille paie</b>le prix affiché, par carte, sur votre compte Stripe sécurisé.</li>
            <li class="ds-card p-5"><b class="block text-[#26343b]">2. Retenues automatiques</b>commission moncamp.fr et frais Stripe, détaillés ligne par ligne dans votre tableau de bord.</li>
            <li class="ds-card p-5"><b class="block text-[#26343b]">3. Versement automatique</b>sur votre banque chaque jour ouvré, en général 2 à 7 jours ouvrés après le paiement.</li>
        </ol>
        <div class="mt-6 rounded-2xl bg-[#f8f5ea] border border-[#ebe3c8] p-5 text-[14.5px] text-[#5b4a1c]">
            <b>Remboursements et dette.</b> En cas de remboursement, la commission vous est rendue. Si un remboursement dépasse votre solde, moncamp.fr avance la somme à la famille : votre solde devient négatif (la « dette ») et se compense tout seul sur vos encaissements suivants, sans frais ni intérêts.
        </div>
    </section>

    <section id="simulateur" class="mt-14 ds-card p-6 sm:p-8 max-w-3xl scroll-mt-24">
        <h2 class="text-[24px] font-extrabold text-[#26343b]">Simulateur</h2>
        <p class="text-[14.5px] text-[#6b7780] mt-1">Estimation avec des cartes européennes standard. Les montants réels figurent dans votre tableau de bord.</p>
        <form class="grid sm:grid-cols-3 gap-4 mt-6" id="tf-sim" onsubmit="return false">
            <div><label class="ds-label" for="tf-prix">Prix d'une inscription (€)</label><input class="ds-input" id="tf-prix" type="number" min="1" max="20000" step="1" value="500" inputmode="decimal"></div>
            <div><label class="ds-label" for="tf-nb">Inscriptions par an</label><input class="ds-input" id="tf-nb" type="number" min="1" max="100000" step="1" value="40" inputmode="numeric"></div>
            <div><label class="ds-label" for="tf-carte">Payées par carte (%)</label><input class="ds-input" id="tf-carte" type="number" min="0" max="100" step="5" value="80" inputmode="numeric"></div>
        </form>
        <dl class="mt-6 grid sm:grid-cols-2 gap-x-8 gap-y-2 text-[15px] tf-out" aria-live="polite">
            <div class="flex justify-between border-b border-[#eef0f2] py-2"><dt class="text-[#4b5964]">Chiffre d'affaires</dt><dd class="font-bold" id="tf-ca"></dd></div>
            <div class="flex justify-between border-b border-[#eef0f2] py-2"><dt class="text-[#4b5964]">Commission moncamp.fr (carte)</dt><dd class="font-bold" id="tf-com"></dd></div>
            <div class="flex justify-between border-b border-[#eef0f2] py-2"><dt class="text-[#4b5964]">Frais Stripe</dt><dd class="font-bold" id="tf-stripe"></dd></div>
            <div class="flex justify-between border-b border-[#eef0f2] py-2"><dt class="text-[#4b5964]">Commission hors carte</dt><dd class="font-bold" id="tf-hors"></dd></div>
            <div class="flex justify-between py-3 sm:col-span-2 text-[18px]"><dt class="font-bold text-[#26343b]">Ce que vous gardez</dt><dd class="font-extrabold text-[#26343b]"><span id="tf-net"></span> <span class="text-[14px] font-semibold text-[#6b7780]" id="tf-pct"></span></dd></div>
        </dl>
    </section>

    <section class="mt-14 max-w-3xl tf-faq">
        <h2 class="text-[24px] font-extrabold text-[#26343b] mb-2">Questions fréquentes</h2>
        <?php foreach ($faq as [$q, $r]): ?><details class="border-b border-[#e5e7eb]"><summary><?= htmlspecialchars($q) ?></summary><p class="pb-5 -mt-1 text-[15px] leading-relaxed text-[#3b4a54]"><?= htmlspecialchars($r) ?></p></details><?php endforeach; ?>
    </section>

    <section class="mt-14 rounded-3xl bg-[#26343b] text-white p-8 max-w-3xl">
        <h2 class="text-[22px] font-extrabold">Prêt à publier vos séjours ?</h2>
        <p class="text-white/80 mt-1">Demande vérifiée sous 48 h, sans engagement.</p>
        <a class="ds-btn ds-btn-light mt-5" href="devenir-organisateur">Devenir organisateur</a>
    </section>
</main>
<script>
(function () {
    var PCT = <?= json_encode($pct) ?>, FIXE = <?= json_encode($fixe) ?>, HORS = <?= json_encode($hors) ?>;
    var $ = function (id) { return document.getElementById(id); };
    var eur = function (c) { return (c / 100).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' }); };
    var num = function (id, min, max) { var v = parseFloat(String($(id).value).replace(',', '.')); return isFinite(v) ? Math.min(max, Math.max(min, v)) : min; };
    function calc() {
        var prix = Math.round(num('tf-prix', 0, 20000) * 100), nb = Math.round(num('tf-nb', 0, 100000)), carte = num('tf-carte', 0, 100) / 100;
        var nCarte = Math.round(nb * carte), nHors = nb - nCarte;
        var com = prix > 0 ? Math.min(prix, Math.round(prix * PCT / 100) + FIXE) * nCarte : 0;
        var stripe = prix > 0 ? (Math.round(prix * 0.015) + 25) * nCarte : 0;
        var hors = Math.round(prix * HORS / 100) * nHors, ca = prix * nb, net = ca - com - stripe - hors;
        $('tf-ca').textContent = eur(ca); $('tf-com').textContent = eur(com); $('tf-stripe').textContent = eur(stripe); $('tf-hors').textContent = eur(hors);
        $('tf-net').textContent = eur(net); $('tf-pct').textContent = ca ? '(' + (net / ca * 100).toLocaleString('fr-FR', { maximumFractionDigits: 1 }) + ' %)' : '';
    }
    $('tf-sim').addEventListener('input', calc);
    calc();
})();
</script>
<?php require_once 'partials/footer.php'; ?>

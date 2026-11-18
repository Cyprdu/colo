<?php
// aide_paiements.php : « Comprendre mes paiements » pour les organismes (une cascade illustrée, 6 questions). Page publique, sans données.
require_once 'api/config.php';
require_once 'api/finance_lib.php'; // réglages réels (commission, délai de versement)
require_once 'partials/header3.php';
$n = fin_reglages()['payout_jours'];
$jours = $n . ' jour' . ($n > 1 ? 's' : '');
$com = number_format(fin_commission_pct(), 1, ',', ' ') . ' %' . (fin_commission_fixe_cents() > 0 ? ' + ' . fin_eur(fin_commission_fixe_cents()) : '');
$ex = 10000; [$c] = fin_commission_split($ex);
$faq = [
    'Quand l\'argent arrive-t-il sur mon compte Stripe ?' => 'Dès que la famille paie. Vous le voyez dans « Où va l\'argent » et dans votre espace Stripe, commission déjà déduite.',
    'Quand est-il viré sur ma banque ?' => 'Automatiquement, ' . $jours . ' après le début du séjour. Ce délai permet de rembourser une famille si le séjour est annulé.',
    'Que couvre la commission ?' => 'Commission « tout compris » de ' . $com . ' par inscription : frais de paiement par carte, hébergement du site, support. Aucun autre frais.',
    'Et si une famille annule ?' => 'Elle est remboursée selon les conditions de votre séjour. Vous êtes débité de ce qu\'elle récupère ; la commission moncamp.fr n\'est pas rendue.',
    'Que veut dire « à compenser » ?' => 'Une famille a été remboursée après le virement sur votre banque. Le montant est retenu automatiquement sur vos prochains encaissements.',
    'Et les paiements par chèque ou espèces ?' => 'La famille vous paie directement. Quand vous confirmez l\'avoir reçu, une petite commission (' . number_format(fin_hors_stripe_pct(), 1, ',', ' ') . ' %) est retenue sur vos paiements par carte suivants.',
];
?>
<main class="flex-1 w-full max-w-3xl mx-auto px-6 py-14">
    <h1 class="text-3xl font-extrabold text-[#26343b] mb-2">Comprendre mes paiements</h1>
    <p class="text-[#6b7780] mb-8">Le trajet d'un paiement de <?= fin_eur($ex) ?>, de la famille à votre banque.</p>

    <ol class="space-y-3 mb-12" aria-label="Trajet d'un paiement">
        <?php foreach ([
            ['La famille paie', fin_eur($ex), 'Carte bancaire, en ligne.'],
            ['moncamp.fr garde sa commission', '− ' . fin_eur($c), $com . ' par inscription, frais de carte inclus.'],
            ['Pour vous, sur votre compte Stripe', fin_eur($ex - $c), 'Tout de suite, visible dans votre tableau de bord.'],
            ['Viré sur votre banque', fin_eur($ex - $c), $jours . ' après le début du séjour, automatiquement.'],
        ] as $i => [$t, $m, $s]): ?>
        <li class="flex items-center gap-4 rounded-2xl border border-gray-200 p-4<?= $i === 2 ? ' bg-[#f3f6f8]' : '' ?>">
            <span class="w-9 h-9 flex-none rounded-full bg-[#2f3d45] text-white font-bold flex items-center justify-center"><?= $i + 1 ?></span>
            <span class="flex-1 min-w-0"><b class="text-[#26343b]"><?= htmlspecialchars($t) ?></b><br><span class="text-sm text-[#6b7780]"><?= htmlspecialchars($s) ?></span></span>
            <b class="text-lg text-[#26343b] whitespace-nowrap"><?= htmlspecialchars($m) ?></b>
        </li>
        <?php endforeach; ?>
    </ol>

    <h2 class="text-xl font-bold text-[#26343b] mb-4">Questions fréquentes</h2>
    <div class="space-y-3">
        <?php foreach ($faq as $q => $a): ?>
        <details class="rounded-2xl border border-gray-200 p-4"><summary class="font-semibold text-[#26343b] cursor-pointer"><?= htmlspecialchars($q) ?></summary><p class="mt-2 text-gray-600 leading-relaxed"><?= htmlspecialchars($a) ?></p></details>
        <?php endforeach; ?>
    </div>
    <p class="mt-10 text-sm text-[#6b7780]">Conditions complètes : <a class="text-[#3d6a8e] underline" href="legal.php#cgu">CGU, encaissement et versements</a>.</p>
</main>
<?php require_once 'partials/footer3.php'; ?>

<?php
// dashboard_organisme.php?organisateur_id=N : tableau de bord d'un organisme (versements Stripe, solde, marketing, séjours).
require_once 'api/config.php';
require_once 'api/finance_lib.php';
require_once 'api/marketing_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user']) || empty($_SESSION['user']['is_directeur'])) { header('Location: index.php'); exit; }

$orgaId = (int)($_GET['organisateur_id'] ?? 0);
$userId = (int)$_SESSION['user']['id'];
$error = isset($_GET['error']) ? mb_substr(strip_tags((string)$_GET['error']), 0, 300) : null;
$success = isset($_GET['success']) ? mb_substr(strip_tags((string)$_GET['success']), 0, 300) : null;
$orga = null; $recent = []; $camps = []; $fin = null; $acc = null; $transferts = []; $legacy = []; $mkStats = [];

try {
    fin_ensure($pdo); mk_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM organisateurs WHERE id = ? AND user_id = ?");
    $st->execute([$orgaId, $userId]);
    $orga = $st->fetch(PDO::FETCH_ASSOC);
    if (!$orga) {
        $error = "Organisme introuvable ou vous n'êtes pas autorisé à y accéder.";
    } else {
        // État du compte Stripe : on resynchronise au retour de l'inscription, ou si le dossier n'est pas encore complet
        $acc = fin_account($pdo, $orgaId);
        $stale = !$acc || (!fin_account_ready($acc) && strtotime($acc['maj']) < time() - 60);
        if (!empty($orga['stripe_account_id']) && ($stale || ($_GET['stripe'] ?? '') === 'retour')) {
            try { $acc = fin_sync_account($pdo, $orgaId); } catch (Throwable $e) { error_log('dashboard sync: ' . $e->getMessage()); }
        }
        $fin = fin_overview($pdo, $orgaId);
        $st = $pdo->prepare("SELECT COUNT(*) FROM fin_annulations WHERE organisateur_id = ? AND lu = 0");
        $st->execute([$orgaId]);
        $nbAnnul = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT * FROM fin_transferts WHERE organisateur_id = ? ORDER BY created_at DESC LIMIT 20");
        $st->execute([$orgaId]);
        $transferts = $st->fetchAll(PDO::FETCH_ASSOC);
        try {
            $st = $pdo->prepare("SELECT token, montant_total, montant_apres_commission, date_demande, effectue, date_virement_effectue FROM virements WHERE organisateur_id = ? ORDER BY date_demande DESC LIMIT 20");
            $st->execute([$orgaId]);
            $legacy = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $legacy = []; }
        $recent = fin_recent_payments($pdo, $orgaId, 15);
        $mkStats = mk_camp_stats($pdo, $orgaId, 30);
    }
} catch (Throwable $e) {
    error_log('dashboard_organisme: ' . $e->getMessage());
    $error = 'Une erreur est survenue. Réessayez dans un instant.';
}

$ready = fin_account_ready($acc);
$hasAccount = $orga && !empty($orga['stripe_account_id']);
$due = $acc && !empty($acc['exigences']) ? (json_decode($acc['exigences'], true) ?: []) : [];
$delay = fin_release_delay_days();
$mkTot = ['i' => 0, 'c' => 0, 'n' => 0];
foreach ($mkStats as $c) { $mkTot['i'] += $c['impressions']; $mkTot['c'] += $c['clics']; $mkTot['n'] += $c['inscriptions']; }

$body_class = 'bg-white';
require_once 'partials/header.php';
require_once 'partials/mk_icons.php';
?>
<title>Tableau de bord - <?= htmlspecialchars($orga['nom'] ?? 'Organisme') ?></title>
<link rel="stylesheet" href="assets/mk.css?v=1">

<main class="mk-wrap">
<a href="public_infos.php" class="mk-back"><?= mk_icon('back', 16) ?> Mes organismes</a>

<?php if (!$orga): ?>
    <div class="ds-alert-err"><b>Erreur.</b> <?= htmlspecialchars($error ?? '') ?></div>
<?php else: ?>
    <?php if ($error): ?><div class="ds-alert-err" style="margin-bottom:16px" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="ds-alert-ok" style="margin-bottom:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:14px;min-width:0">
            <?php if (!empty($orga['logo_url'])): ?><img src="<?= htmlspecialchars($orga['logo_url']) ?>" alt="" style="width:56px;height:56px;border-radius:16px;object-fit:cover;border:1px solid var(--ds-line)"><?php endif; ?>
            <div style="min-width:0"><p class="ds-eyebrow">Tableau de bord</p><h1 class="ds-h1" style="overflow-wrap:anywhere"><?= htmlspecialchars($orga['nom']) ?></h1></div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <a class="ds-btn ds-btn-light" href="annulations_organisme.php?o=<?= fin_orga_tok($orgaId) ?>">Annulations<?= !empty($nbAnnul) ? ' (' . $nbAnnul . ')' : '' ?></a>
            <a class="ds-btn ds-btn-light" href="edit_organisateur.php?id=<?= $orgaId ?>">Modifier l'organisme</a>
            <a class="ds-btn" href="marketing.php?id=<?= $orgaId ?>"><?= mk_icon('star', 18) ?> Marketing</a>
        </div>
    </div>

    <!-- Versements Stripe -->
    <section class="mk-section" style="margin-top:28px">
        <div class="ds-card" style="padding:24px;display:flex;gap:18px;flex-wrap:wrap;align-items:center;justify-content:space-between">
            <div style="display:flex;gap:14px;align-items:flex-start;max-width:640px">
                <span class="ds-tile-ico" style="background:<?= $ready ? '#e6efe9' : 'var(--ds-cream)' ?>;color:<?= $ready ? '#2d5a43' : '#6b5518' ?>"><?= mk_icon($ready ? 'check' : 'bank') ?></span>
                <div>
                    <b class="text-[#26343b] text-[17px]">
                        <?php if ($ready): ?>Versements activés<?php elseif ($hasAccount && !empty($acc['details_submitted'])): ?>Vérification en cours chez Stripe<?php elseif ($hasAccount): ?>Finalisez votre inscription pour être payé<?php else: ?>Activez vos versements<?php endif; ?>
                    </b>
                    <p class="mk-note" style="margin-top:4px;font-size:14px">
                        <?php if ($ready): ?>L'argent des inscriptions est versé automatiquement sur votre compte bancaire, <?= $delay === 0 ? 'dès le début' : 'le ' . ($delay === 1 ? 'lendemain' : 'jour J+' . $delay) . ' du début' ?> de chaque séjour.
                        <?php elseif ($hasAccount && !empty($acc['details_submitted'])): ?>Stripe vérifie votre dossier (en général sous 24 à 48 h). Les sommes restent en sécurité et seront versées dès la validation.
                        <?php else: ?>Pour recevoir le paiement des inscriptions, renseignez votre identité et votre IBAN directement chez Stripe, notre prestataire de paiement. ColoMap ne voit ni ne conserve ces données. Compter 5 minutes.<?php endif; ?>
                    </p>
                    <?php if ($hasAccount && !$ready && $due): ?><p class="mk-note" style="margin-top:6px"><?= count($due) ?> information<?= count($due) > 1 ? 's' : '' ?> restant à fournir.</p><?php endif; ?>
                </div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <?php if (!$ready): ?><a class="ds-btn" href="<?= $hasAccount ? 'api/connect_onboarding.php?o=' : 'connect_statut.php?o=' ?><?= fin_orga_tok($orgaId) ?>"><?= $hasAccount ? 'Continuer mon inscription' : 'Activer mes versements' ?> <?= mk_icon('arrow', 16) ?></a>
                <?php elseif (($acc['type'] ?? '') === 'express'): ?><a class="ds-btn ds-btn-light" href="api/connect_dashboard.php?organisateur_id=<?= $orgaId ?>" target="_blank" rel="noopener">Mon espace Stripe</a><?php endif; ?>
            </div>
        </div>
    </section>

    <section class="mk-section" style="margin-top:20px">
        <div class="mk-grid mk-g4">
            <div class="mk-stat dark"><div class="k">À recevoir</div><div class="v"><?= fin_eur(max(0, $fin['solde_cents'])) ?></div><div class="s">net de commission, hors sommes déjà versées</div></div>
            <div class="mk-stat"><div class="k">Prêt à être versé</div><div class="v"><?= fin_eur($fin['deblocable_cents']) ?></div><div class="s"><?= $ready ? 'versé au prochain passage (toutes les heures)' : 'en attente de l\'activation des versements' ?></div></div>
            <div class="mk-stat"><div class="k">Déjà versé</div><div class="v"><?= fin_eur($fin['verse_cents']) ?></div><div class="s"><?= count($transferts) ?> versement<?= count($transferts) > 1 ? 's' : '' ?> récent<?= count($transferts) > 1 ? 's' : '' ?></div></div>
            <div class="mk-stat"><div class="k">Commission ColoMap</div><div class="v"><?= number_format(fin_commission_pct(), 1, ',', ' ') ?> %<?= fin_commission_fixe_cents() > 0 ? ' + ' . fin_eur(fin_commission_fixe_cents()) : '' ?></div><div class="s">tout compris, par inscription : <?= fin_eur($fin['commission_nette_cents']) ?> au total</div></div>
        </div>
        <?php if ($fin['solde_cents'] < 0): ?><div class="ds-alert-err" style="margin-top:14px">Votre solde est négatif de <?= fin_eur(-$fin['solde_cents']) ?> (remboursements après versement). Il sera déduit de vos prochains versements.</div><?php endif; ?>
        <?php if ($fin['historique_cents'] > 0): ?><div class="mk-tip" style="margin-top:14px"><?= mk_icon('info', 20) ?><div>Un solde de <b><?= fin_eur($fin['historique_cents']) ?></b> provient de l'ancien système de virement. L'équipe ColoMap le règle manuellement : <a class="underline" href="contact.php">contactez-nous</a> si vous souhaitez le recevoir.</div></div><?php endif; ?>
    </section>

    <!-- Comprendre mes versements et la commission -->
    <section class="mk-section" style="margin-top:20px">
        <div class="ds-card" style="padding:24px">
            <h2 style="margin:0 0 4px">Comment je suis payé, et où est la commission ?</h2>
            <p class="mk-note" style="margin:0 0 16px;font-size:14px">Un seul prélèvement, affiché ligne par ligne : vous voyez toujours le montant payé par la famille, la commission, puis ce qui vous revient.</p>
            <?php [$exC, $exP, $exF] = fin_commission_split(10000); ?>
            <div class="mk-grid mk-g3">
                <div><b class="text-[#26343b]">1. La famille paie ColoMap</b><p class="mk-note" style="margin-top:4px">Le paiement par carte est encaissé par ColoMap (via Stripe) au moment de l'inscription. La place est réservée aussitôt.</p></div>
                <div><b class="text-[#26343b]">2. La commission est retenue sur chaque inscription</b><p class="mk-note" style="margin-top:4px"><?= number_format(fin_commission_pct(), 1, ',', ' ') ?> %<?= fin_commission_fixe_cents() > 0 ? ' + ' . fin_eur(fin_commission_fixe_cents()) : '' ?> du montant payé. Elle inclut <b>les frais bancaires de paiement par carte</b> : vous n'avez aucun autre frais à payer. Exemple : pour <?= fin_eur(10000) ?> payés, commission <?= fin_eur($exC) ?>, vous recevez <b><?= fin_eur(10000 - $exC) ?></b>.</p></div>
                <div><b class="text-[#26343b]">3. Vous êtes versé après le départ</b><p class="mk-note" style="margin-top:4px">Le net est transféré <?= $delay === 0 ? 'le jour du départ' : ($delay === 1 ? 'le lendemain du départ' : $delay . ' jours après le départ') ?> sur votre compte Stripe, puis Stripe le verse sur votre compte bancaire (en général 2 à 3 jours ouvrés). En cas de remboursement d'une famille, la commission correspondante vous est aussi restituée au prorata.</p></div>
            </div>
            <?php if ($fin['prochain']): ?><div class="mk-tip" style="margin-top:16px"><?= mk_icon('clock', 20) ?><div><b>Prochain versement : <?= fin_eur((int)$fin['prochain']['net']) ?></b> prévu à partir du <b><?= date('d/m/Y', strtotime($fin['prochain']['d'])) ?></b> (<?= (int)$fin['prochain']['n'] ?> inscription<?= $fin['prochain']['n'] > 1 ? 's' : '' ?>)<?= $ready ? '' : ' : activez vos versements ci-dessus pour le recevoir' ?>.</div></div><?php endif; ?>
        </div>
    </section>

    <!-- Derniers paiements -->
    <section class="mk-section">
        <h2>Derniers paiements reçus</h2>
        <p class="lead">Détail de la commission, inscription par inscription.</p>
        <?php if (!$recent): ?><p class="mk-note" style="margin-top:14px">Aucun paiement pour le moment.</p><?php else: ?>
        <div class="mk-table-wrap" style="margin-top:14px"><table class="mk-table" style="min-width:640px">
            <thead><tr><th>Date</th><th>Séjour</th><th class="num">Payé</th><th class="num">Commission</th><th class="num">Net pour vous</th><th>Versement</th></tr></thead><tbody>
            <?php foreach ($recent as $p): $sl = ['attente' => 'prévu ' . date('d/m/Y', strtotime($p['date_deblocage'])), 'bloque' => 'suspendu', 'litige' => 'litige', 'en_cours' => 'en cours', 'verse' => 'versé', 'rembourse' => 'remboursé'][$p['statut']] ?? $p['statut']; ?>
                <tr><td><?= date('d/m/Y', strtotime($p['created_at'])) ?></td><td><b><?= htmlspecialchars($p['camp']) ?></b></td><td class="num"><?= fin_eur((int)$p['brut_cents']) ?></td><td class="num">- <?= fin_eur((int)$p['commission_cents']) ?></td><td class="num"><b><?= fin_eur((int)$p['net_cents'] - (int)$p['net_rembourse_cents']) ?></b><?= $p['rembourse_cents'] ? '<div class="mk-note">remboursé : ' . fin_eur((int)$p['rembourse_cents']) . '</div>' : '' ?></td><td><?= htmlspecialchars($sl) ?></td></tr>
            <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>

    <!-- Marketing -->
    <section class="mk-section" style="margin-top:20px">
        <div class="ds-card" style="padding:22px;display:flex;gap:16px;flex-wrap:wrap;align-items:center;justify-content:space-between">
            <div style="max-width:620px"><b class="text-[#26343b] text-[17px]">Marketing : <?= number_format((int)$fin['points'], 0, ',', ' ') ?> points</b>
                <p class="mk-note" style="margin-top:4px;font-size:14px">Sur 30 jours : <?= number_format($mkTot['i'], 0, ',', ' ') ?> impressions, <?= number_format($mkTot['c'], 0, ',', ' ') ?> visites, <?= $mkTot['n'] ?> inscription<?= $mkTot['n'] > 1 ? 's' : '' ?>.
                <?= $fin['solde_cents'] >= 1000 ? 'Vous pouvez payer vos points avec vos gains, sans carte.' : '' ?></p></div>
            <div style="display:flex;gap:10px;flex-wrap:wrap"><a class="ds-btn ds-btn-light" href="marketing_dashboard.php?id=<?= $orgaId ?>"><?= mk_icon('chart', 18) ?> Statistiques</a><a class="ds-btn" href="marketing.php?id=<?= $orgaId ?>">Booster mes séjours</a></div>
        </div>
    </section>

    <!-- Prochains versements -->
    <section class="mk-section">
        <h2>Prochains versements</h2>
        <p class="lead">Chaque séjour est versé le <?= $delay === 1 ? 'lendemain' : 'jour J+' . $delay ?> de son départ, une fois la période de contestation courante passée.</p>
        <?php if (!$fin['prochains']): ?>
            <p class="mk-note" style="margin-top:14px">Aucune inscription payée en attente de versement.</p>
        <?php else: ?>
            <div class="mk-table-wrap" style="margin-top:14px"><table class="mk-table">
                <thead><tr><th>Séjour</th><th>Départ</th><th>Versement prévu</th><th class="num">Inscr.</th><th class="num">Net</th><th>Statut</th></tr></thead>
                <tbody>
                <?php foreach ($fin['prochains'] as $p): $lbl = ['attente' => ['En attente', 'info'], 'bloque' => ['Suspendu', 'warn'], 'litige' => ['Litige en cours', 'bad'], 'en_cours' => ['En cours d\'envoi', 'info']][$p['statut']] ?? [$p['statut'], '']; ?>
                    <tr><td><b><?= htmlspecialchars($p['nom']) ?></b></td><td><?= date('d/m/Y', strtotime($p['date_debut'])) ?></td><td><?= date('d/m/Y', strtotime($p['date_deblocage'])) ?></td><td class="num"><?= (int)$p['n'] ?></td><td class="num"><b><?= fin_eur((int)$p['net']) ?></b></td><td><span class="mk-pill <?= $lbl[1] ?>"><?= $lbl[0] ?></span></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
        <?php endif; ?>
    </section>

    <!-- Historique -->
    <section class="mk-section">
        <div style="display:flex;justify-content:space-between;align-items:end;gap:12px;flex-wrap:wrap"><div><h2>Historique des versements</h2><p class="lead">Chaque versement a son justificatif, détaillé inscription par inscription.</p></div>
            <?php if ($transferts || $legacy): ?><a class="ds-btn ds-btn-light mk-btn-sm" href="pdf_export_virements_history.php?organisateur_id=<?= $orgaId ?>" target="_blank"><?= mk_icon('doc', 16) ?> Export PDF</a><?php endif; ?></div>
        <?php if (!$transferts && !$legacy): ?>
            <p class="mk-note" style="margin-top:14px">Aucun versement pour le moment.</p>
        <?php else: ?>
            <div class="mk-table-wrap" style="margin-top:14px"><table class="mk-table">
                <thead><tr><th>Date</th><th>Référence</th><th class="num">Montant</th><th>Statut</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($transferts as $t): $s = ['envoye' => ['Envoyé à Stripe', 'ok'], 'en_cours' => ['En cours', 'info'], 'echec' => ['Échec, nouvel essai', 'bad']][$t['statut']] ?? [$t['statut'], '']; ?>
                    <tr><td><?= date('d/m/Y H:i', strtotime($t['created_at'])) ?></td><td class="mk-note"><?= htmlspecialchars(substr($t['token'], 0, 10)) ?> · <?= (int)$t['nb_paiements'] ?> inscr.</td><td class="num"><b><?= fin_eur((int)$t['montant_cents']) ?></b></td><td><span class="mk-pill <?= $s[1] ?>"><?= $s[0] ?></span></td><td class="num"><a class="underline" href="virement.php?t=<?= urlencode($t['token']) ?>">Justificatif</a></td></tr>
                <?php endforeach; ?>
                <?php foreach ($legacy as $v): ?>
                    <tr><td><?= date('d/m/Y', strtotime($v['date_demande'])) ?></td><td class="mk-note">Ancien système</td><td class="num"><b><?= fin_eur((int)round($v['montant_apres_commission'] * 100)) ?></b></td><td><span class="mk-pill <?= $v['effectue'] ? 'ok' : 'warn' ?>"><?= $v['effectue'] ? 'Effectué' : 'En attente' ?></span></td><td class="num"><a class="underline" href="virement.php?t=<?= urlencode($v['token']) ?>">Justificatif</a></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
        <?php endif; ?>
    </section>

    <!-- Séjours -->
    <section class="mk-section">
        <h2>Séjours de <?= htmlspecialchars($orga['nom']) ?></h2>
        <?php if (!$mkStats): ?>
            <p class="mk-note" style="margin-top:14px">Aucun séjour publié. <a class="underline" href="create_camp.php">Créer un séjour</a></p>
        <?php else: ?>
            <div class="mk-grid mk-g3" style="margin-top:16px">
                <?php foreach ($mkStats as $c): $qt = max(1, (int)$c['quota_global']); $pc = min(100, round($c['inscrits_total'] / $qt * 100)); ?>
                    <div class="ds-card" style="padding:18px;display:flex;flex-direction:column">
                        <b class="text-[#26343b]" style="overflow-wrap:anywhere"><?= htmlspecialchars($c['nom']) ?></b>
                        <span class="mk-note"><?= htmlspecialchars($c['ville'] ?? '') ?> · <?= $c['date_debut'] ? date('d/m/Y', strtotime($c['date_debut'])) : '' ?></span>
                        <div style="margin-top:12px;display:flex;justify-content:space-between;font-size:13px;font-weight:700"><span class="mk-note">Inscrits</span><span><?= (int)$c['inscrits_total'] ?> / <?= (int)$c['quota_global'] ?></span></div>
                        <div class="mk-meter" style="margin-top:6px"><span style="width:<?= $pc ?>%"></span></div>
                        <div class="mk-note" style="margin-top:10px"><?= number_format($c['impressions'], 0, ',', ' ') ?> impr. · <?= number_format($c['clics'], 0, ',', ' ') ?> visites (30 j)</div>
                        <div style="margin-top:auto;padding-top:14px;display:flex;gap:8px;flex-wrap:wrap"><a class="ds-btn ds-btn-light mk-btn-sm" href="gestion_camp.php?t=<?= urlencode($c['token']) ?>">Inscrits</a><?php if (!empty($c['valide'])): ?><a class="ds-btn mk-btn-sm" href="boost.php?t=<?= urlencode($c['token']) ?>">Booster</a><?php endif; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
</main>
<?php require_once 'partials/footer.php'; ?>

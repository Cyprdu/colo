<?php
// dashboard_organisme.php?organisateur_id=N : tableau de bord d'un organisme. Ordre : compte Stripe, « où va l'argent » (journal fin_ledger, jamais filtré),
// versements (prévus et passés), activité et liste des paiements (api/dashboard_stats.php, filtres appliqués seulement au clic sur « Appliquer »).
require_once 'api/config.php';
require_once 'api/finance_lib.php';
require_once 'api/marketing_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user']) || empty($_SESSION['user']['is_directeur'])) { header('Location: index.php'); exit; }

$orgaId = (int)($_GET['organisateur_id'] ?? 0);
$userId = (int)$_SESSION['user']['id'];
$error = isset($_GET['error']) ? mb_substr(strip_tags((string)$_GET['error']), 0, 300) : null;
$success = isset($_GET['success']) ? mb_substr(strip_tags((string)$_GET['success']), 0, 300) : null;
// Taux de TVA des séjours (réglage de l'organisme, repris sur les reçus des familles)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'tva') {
    try {
        fin_ensure($pdo);
        if (!fin_csrf_ok()) throw new Exception('Session expirée, rechargez la page.');
        $tx = (string)($_POST['tva'] ?? '');
        if (!array_key_exists($tx, fin_tva_taux_permis())) throw new Exception('Taux invalide.');
        $up = $pdo->prepare("UPDATE organisateurs SET tva_taux = ? WHERE id = ? AND user_id = ?");
        $up->execute([$tx === '' ? null : $tx, $orgaId, $userId]);
        fin_audit($pdo, 'tva_modifiee', 'organisateur', $orgaId, ['taux' => $tx === '' ? 'na' : $tx], 'user:' . $userId);
        header('Location: dashboard_organisme.php?organisateur_id=' . $orgaId . '&success=' . urlencode('Taux de TVA enregistré.')); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$orga = null; $camps = []; $acc = null; $transferts = []; $legacy = []; $tot = null; $prochains = []; $historique = 0;

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
        $tot = fin_ledger_totaux($pdo, $orgaId); // « où va l'argent » : journal comptable, jamais filtré
        $historique = fin_overview($pdo, $orgaId)['historique_cents'];
        $st = $pdo->prepare("SELECT COUNT(*) FROM fin_annulations WHERE organisateur_id = ? AND lu = 0");
        $st->execute([$orgaId]);
        $nbAnnul = (int)$st->fetchColumn();
        // Prochains versements : ce qui n'est pas encore versé, par date prévue (début du séjour + délai)
        $st = $pdo->prepare("SELECT DATE(p.date_deblocage) d, SUM(GREATEST(0, p.net_cents - p.net_rembourse_cents - p.verse_cents)) m, GROUP_CONCAT(DISTINCT c.nom ORDER BY c.nom SEPARATOR ', ') sejours
                             FROM fin_paiements p JOIN camps c ON c.id = p.camp_id WHERE p.organisateur_id = ? AND p.statut = 'attente'
                             GROUP BY DATE(p.date_deblocage) HAVING m > 0 ORDER BY d LIMIT 8");
        $st->execute([$orgaId]);
        $prochains = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $pdo->prepare("SELECT * FROM fin_transferts WHERE organisateur_id = ? ORDER BY created_at DESC LIMIT 12");
        $st->execute([$orgaId]);
        $transferts = $st->fetchAll(PDO::FETCH_ASSOC);
        try {
            $st = $pdo->prepare("SELECT token, montant_total, montant_apres_commission, date_demande, effectue, date_virement_effectue FROM virements WHERE organisateur_id = ? ORDER BY date_demande DESC LIMIT 20");
            $st->execute([$orgaId]);
            $legacy = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $legacy = []; }
    }
} catch (Throwable $e) {
    error_log('dashboard_organisme: ' . $e->getMessage());
    $error = 'Une erreur est survenue. Réessayez dans un instant.';
}

$ready = fin_account_ready($acc);
$hasAccount = $orga && !empty($orga['stripe_account_id']);
$due = $acc && !empty($acc['exigences']) ? (json_decode($acc['exigences'], true) ?: []) : [];
$nJours = fin_reglages()['payout_jours'];
$comTxt = number_format(fin_commission_pct(), 1, ',', ' ') . ' %' . (fin_commission_fixe_cents() > 0 ? ' + ' . fin_eur(fin_commission_fixe_cents()) : '');

$body_class = 'bg-white';
require_once 'partials/header.php';
require_once 'partials/mk_icons.php';
$help = fn(string $t) => '<button type="button" class="dbh" data-help="' . htmlspecialchars($t, ENT_QUOTES) . '" aria-label="Aide" aria-expanded="false">?</button>';
?>
<title>Tableau de bord - <?= htmlspecialchars($orga['nom'] ?? 'Organisme') ?></title>
<link rel="stylesheet" href="assets/mk.css?v=1">
<style>
.dbh { width: 20px; height: 20px; border-radius: 50%; border: 1.5px solid #b8c2ca; color: var(--ds-muted); font-size: 12px; font-weight: 800; line-height: 1; display: inline-flex; align-items: center; justify-content: center; vertical-align: middle; margin-left: 6px; background: #fff; cursor: pointer; flex: none; }
.dbh:hover, .dbh[aria-expanded="true"] { background: var(--ds-btn); border-color: var(--ds-btn); color: #fff; }
.dbh:focus-visible { outline: 2px solid var(--ds-accent); outline-offset: 2px; }
#dbPop { position: absolute; z-index: 60; max-width: 280px; background: var(--ds-btn); color: #fff; font-size: 13px; line-height: 1.45; padding: 12px 14px; border-radius: 12px; box-shadow: 0 12px 30px rgba(31,42,48,.25); display: none; }
#dbPop.on { display: block; animation: dsFade .15s ease-out; }
.db-h { font-size: 14px; font-weight: 800; color: var(--ds-ink); display: flex; align-items: center; margin-bottom: 12px; }
.db-chart { width: 100%; height: 190px; display: block; }
.db-legend { display: flex; flex-wrap: wrap; gap: 12px; font-size: 12px; color: var(--ds-muted); margin-top: 8px; }
.db-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 5px; vertical-align: -1px; }
.db-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 4px 12px; font-size: 13px; margin-bottom: 12px; }
.db-row b { color: var(--ds-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.db-row span { color: var(--ds-muted); font-weight: 700; font-variant-numeric: tabular-nums; }
.db-bar { grid-column: 1 / -1; height: 8px; background: var(--ds-soft); border-radius: 9999px; overflow: hidden; }
.db-bar i { display: block; height: 100%; background: var(--ds-btn); border-radius: 9999px; transition: width .5s ease; }
.db-filt { margin-top: 12px; }
.db-filt summary { cursor: pointer; list-style: none; display: inline-flex; align-items: center; gap: 6px; }
.db-filt summary::-webkit-details-marker { display: none; }
.db-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; align-items: end; padding: 16px 20px; margin-top: 10px; }
.db-filters label span { display: block; font-size: 12px; font-weight: 700; color: var(--ds-muted); margin-bottom: 4px; }
.db-filters .ds-select, .db-filters .ds-input { width: 100%; padding-top: 9px; padding-bottom: 9px; }
.db-presets, .db-acts { display: flex; gap: 6px; flex-wrap: wrap; }
.db-presets .ds-chip { padding: 7px 12px; font-size: 13px; }
.db-empty { color: var(--ds-muted); font-size: 13px; padding: 28px 0; text-align: center; }
.db-tip { fill: var(--ds-muted); font-size: 10px; font-family: Montserrat, sans-serif; }
.cas { padding: 4px 0; }
.cas .cas-r { box-sizing: border-box; width: 100%; display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 20px; cursor: pointer; font-size: 15px; color: var(--ds-ink); }
.cas .cas-r:hover { background: var(--ds-soft); }
.cas .cas-r:focus-visible { outline: 2px solid var(--ds-accent); outline-offset: -2px; }
.cas .amt { font-weight: 800; font-variant-numeric: tabular-nums; white-space: nowrap; }
.cas .sub { color: var(--ds-muted); }
.cas .tot { border-top: 1px solid var(--ds-line); font-size: 17px; }
.cas .tot .amt { font-size: 20px; }
.cas-note { padding: 0 20px 14px; font-size: 13px; color: var(--ds-muted); }
.pay-tr { cursor: pointer; }
.pay-det td { background: var(--ds-soft); font-size: 13px; color: var(--ds-muted); }
@media (prefers-reduced-motion: reduce) { .db-bar i { transition: none; } #dbPop.on { animation: none; } }
</style>

<main class="mk-wrap">
<a href="public_infos.php" class="mk-back"><?= mk_icon('back', 16) ?> Mes organismes</a>

<?php if (!$orga): ?>
    <div class="ds-alert-err" role="alert"><b>Erreur.</b> <?= htmlspecialchars($error ?? '') ?></div>
<?php else: ?>
    <?php if ($error): ?><div class="ds-alert-err" style="margin-bottom:16px" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="ds-alert-ok" style="margin-bottom:16px" role="status"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <!-- 1. En-tête : organisme et état du compte Stripe -->
    <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:14px;min-width:0">
            <?php if (!empty($orga['logo_url'])): ?><img src="<?= htmlspecialchars($orga['logo_url']) ?>" alt="" style="width:52px;height:52px;border-radius:16px;object-fit:cover;border:1px solid var(--ds-line)"><?php endif; ?>
            <div style="min-width:0"><p class="ds-eyebrow">Tableau de bord</p><h1 class="ds-h1" style="overflow-wrap:anywhere"><?= htmlspecialchars($orga['nom']) ?></h1></div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <a class="ds-btn" href="create_camp.php">Créer un séjour</a>
            <a class="ds-btn ds-btn-light" href="mes_camps.php">Inscrits</a>
            <a class="ds-btn ds-btn-light" href="messagerie.php">Messages</a>
            <a class="ds-btn ds-btn-light" href="marketing.php?id=<?= $orgaId ?>">Marketing · <?= number_format((int)$orga['solde_points'], 0, ',', ' ') ?> pts</a>
        </div>
    </div>
    <div class="ds-card" style="margin-top:16px;padding:14px 20px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between">
        <div style="display:flex;align-items:center;gap:10px"><span class="mk-pill <?= $ready ? 'ok' : 'warn' ?>"><?= $ready ? 'Compte prêt' : 'Action requise' ?></span>
            <?= $help($ready ? "Vos paiements arrivent sur votre compte Stripe, puis sur votre banque " . $nJours . " jour" . ($nJours > 1 ? 's' : '') . " après le début de chaque séjour. Stripe vérifie votre identité et garde votre IBAN : moncamp.fr ne les voit pas." : "Pour recevoir l'argent des inscriptions, complétez votre compte chez Stripe (identité, IBAN : 5 minutes). moncamp.fr ne voit ni ne conserve ces données." . ($due ? " Informations restantes : " . count($due) . "." : "")) ?></div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <?php if (!$ready): ?><a class="ds-btn" href="<?= $hasAccount ? 'api/connect_onboarding.php?o=' : 'connect_statut.php?o=' ?><?= fin_orga_tok($orgaId) ?>">Compléter mon compte</a>
            <?php elseif (($acc['type'] ?? '') === 'express'): ?><a class="ds-btn ds-btn-light mk-btn-sm" href="api/connect_dashboard.php?organisateur_id=<?= $orgaId ?>" target="_blank" rel="noopener">Ouvrir mon espace Stripe</a><?php endif; ?>
            <a class="ds-btn ds-btn-light mk-btn-sm" href="annulations_organisme.php?o=<?= fin_orga_tok($orgaId) ?>">Annulations<?= !empty($nbAnnul) ? ' (' . $nbAnnul . ')' : '' ?></a>
            <a class="ds-btn ds-btn-light mk-btn-sm" href="aide_paiements.php">Comprendre mes paiements</a>
        </div>
    </div>

    <!-- 2. Où va l'argent (journal comptable, jamais filtré) -->
    <section class="mk-section" style="margin-top:24px">
        <h2 style="margin:0">Où va l'argent<?= $help("Tous vos paiements par carte depuis le début, sans filtre. Touchez une ligne pour voir les paiements concernés.") ?></h2>
        <div class="ds-card cas" style="margin-top:12px">
            <div class="cas-r" role="button" tabindex="0" data-vue=""><span>Encaissé par les familles</span><span class="amt"><?= fin_eur($tot['encaisse']) ?></span></div>
            <div class="cas-r" role="button" tabindex="0" data-vue="com"><span class="sub">− Commission moncamp.fr<?= $help("Commission « tout compris » : " . $comTxt . " par inscription. Elle couvre les frais de paiement par carte, l'hébergement et le support. Aucun autre frais. Elle n'est pas rendue quand une famille annule elle-même." . " Paiements reçus hors carte (chèque, espèces…) : " . number_format(fin_hors_stripe_pct(), 1, ',', ' ') . " %, retenus sur vos paiements par carte suivants.") ?></span><span class="amt sub">− <?= fin_eur($tot['commission']) ?></span></div>
            <?php if ($tot['rembourse']): ?><div class="cas-r" role="button" tabindex="0" data-vue="remb"><span class="sub">− Remboursé aux familles</span><span class="amt sub">− <?= fin_eur($tot['rembourse']) ?></span></div><?php endif; ?>
            <?php if ($tot['ajustement']): ?><div class="cas-r" role="button" tabindex="0" data-vue=""><span class="sub">− Points marketing payés avec vos gains</span><span class="amt sub">− <?= fin_eur(-$tot['ajustement']) ?></span></div><?php endif; ?>
            <div class="cas-r tot" role="button" tabindex="0" data-vue=""><span><b>Pour vous</b></span><span class="amt"><?= fin_eur($tot['pour_vous']) ?></span></div>
            <div class="cas-r" role="button" tabindex="0" data-vue="verse"><span>Déjà versé sur votre banque</span><span class="amt"><?= fin_eur($tot['verse']) ?></span></div>
            <?php if ($tot['en_attente'] >= 0): ?>
            <div class="cas-r" role="button" tabindex="0" data-vue="attente"><span>En attente de versement<?= $help("Cet argent est déjà sur votre compte Stripe (ou chez moncamp.fr pour les paiements d'avant novembre 2026). Il part sur votre banque " . $nJours . " jour" . ($nJours > 1 ? 's' : '') . " après le début de chaque séjour, pour pouvoir rembourser une famille en cas d'annulation.") ?></span><span class="amt"><?= fin_eur($tot['en_attente']) ?></span></div>
            <?php else: ?>
            <div class="cas-r" role="button" tabindex="0" data-vue="remb"><span style="color:#b42318">À compenser<?= $help("Une famille a été remboursée après le versement sur votre banque. Ce montant sera retenu sur vos prochains encaissements, automatiquement.") ?></span><span class="amt" style="color:#b42318"><?= fin_eur(-$tot['en_attente']) ?></span></div>
            <?php endif; ?>
            <p class="cas-note"><?= $prochains && $tot['en_attente'] > 0 ? 'Prochain versement : <b>' . fin_eur(min((int)$prochains[0]['m'], $tot['en_attente'])) . '</b> le ' . date('d/m/Y', strtotime($prochains[0]['d'])) . '. ' : '' ?>Règle : versé sur votre banque <?= $nJours ?> jour<?= $nJours > 1 ? 's' : '' ?> après le début de chaque séjour.</p>
        </div>
        <?php if ($historique > 0): ?><div class="ds-alert-ok" style="margin-top:12px">Solde de l'ancien système : <?= fin_eur($historique) ?> <?= $help("Réglé par l'équipe moncamp.fr : contactez-nous pour le recevoir.") ?></div><?php endif; ?>
    </section>

    <!-- 3. Versements -->
    <section class="mk-section" id="versements">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><h2 style="margin:0">Versements<?= $help("Les prochains versements sont des prévisions : une annulation peut les réduire. Chaque versement passé a son justificatif, inscription par inscription.") ?></h2>
            <?php if ($transferts || $legacy): ?><a class="ds-btn ds-btn-light mk-btn-sm" href="pdf_export_virements_history.php?organisateur_id=<?= $orgaId ?>" target="_blank"><?= mk_icon('doc', 16) ?> PDF</a><?php endif; ?></div>
        <?php if (!$prochains && !$transferts && !$legacy): ?>
            <p class="db-empty">Aucun versement pour le moment.</p>
        <?php else: ?>
            <div class="mk-table-wrap" style="margin-top:12px"><table class="mk-table">
                <thead><tr><th>Date</th><th class="num">Montant</th><th>Séjours</th><th>Statut</th></tr></thead>
                <tbody>
                <?php foreach ($prochains as $p): ?>
                    <tr><td><?= date('d/m/Y', strtotime($p['d'])) ?></td><td class="num"><b><?= fin_eur((int)$p['m']) ?></b></td><td><?= htmlspecialchars(html_entity_decode((string)$p['sejours'])) ?></td><td><span class="mk-pill info">Prévu</span></td></tr>
                <?php endforeach; ?>
                <?php foreach ($transferts as $t): $po = $t['type'] === 'payout';
                    $s = $po ? (['en_cours' => ['En préparation', 'info'], 'envoye' => ['En cours', 'info'], 'paye' => ['Payé', 'ok'], 'echec' => ['Échoué', 'bad']][$t['statut']] ?? [$t['statut'], ''])
                             : (['envoye' => ['Envoyé sur Stripe', 'ok'], 'en_cours' => ['En cours', 'info'], 'echec' => ['Échec, nouvel essai', 'bad']][$t['statut']] ?? [$t['statut'], '']); ?>
                    <tr><td><?= date('d/m/Y', strtotime($t['created_at'])) ?></td><td class="num"><b><?= fin_eur((int)$t['montant_cents']) ?></b></td><td><a class="underline" href="virement.php?t=<?= urlencode($t['token']) ?>">Justificatif</a></td>
                        <td><span class="mk-pill <?= $s[1] ?>"><?= $s[0] ?></span><?php if ($t['statut'] === 'echec' && $t['erreur']): ?> <?= $help(($po ? "Votre banque a refusé le virement : " : "") . $t['erreur'] . ($po ? " Vérifiez votre IBAN dans votre espace Stripe ; le montant repartira automatiquement." : "")) ?><?php endif; ?></td></tr>
                <?php endforeach; ?>
                <?php foreach ($legacy as $v): ?>
                    <tr><td><?= date('d/m/Y', strtotime($v['date_demande'])) ?></td><td class="num"><b><?= fin_eur((int)round($v['montant_apres_commission'] * 100)) ?></b></td><td><a class="underline" href="virement.php?t=<?= urlencode($v['token']) ?>">Justificatif</a></td><td><span class="mk-pill <?= $v['effectue'] ? 'ok' : 'warn' ?>"><?= $v['effectue'] ? 'Effectué' : 'En attente' ?></span></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
        <?php endif; ?>
    </section>

    <!-- 4-5. Activité : filtres appliqués au clic sur « Appliquer » (graphiques et liste des paiements) -->
    <section class="mk-section" id="activite">
        <h2 style="margin:0">Activité<?= $help("Les filtres s'appliquent aux graphiques et à la liste des paiements, seulement quand vous cliquez sur Appliquer. « Où va l'argent » et les versements montrent toujours tout. Période par défaut : 12 mois.") ?></h2>
        <details class="db-filt" id="fBox">
            <summary class="ds-btn ds-btn-light mk-btn-sm">Filtrer <span id="fCount"></span> ▾</summary>
            <div class="db-filters ds-card" role="group" aria-label="Filtres">
                <label><span>Type</span><select id="fType" class="ds-select"><option value="">Tous</option></select></label>
                <label><span>Séjour</span><select id="fSejour" class="ds-select"><option value="">Tous</option></select></label>
                <label><span>Session</span><select id="fSession" class="ds-select"><option value="">Toutes</option></select></label>
                <label><span>Moyen de paiement</span><select id="fMoyen" class="ds-select"><option value="">Tous</option></select></label>
                <label><span>Du</span><input id="fDu" type="date" class="ds-input"></label>
                <label><span>Au</span><input id="fAu" type="date" class="ds-input"></label>
                <div class="db-presets" role="group" aria-label="Périodes rapides"><button type="button" class="ds-chip" data-p="1">30 j</button><button type="button" class="ds-chip" data-p="3">3 mois</button><button type="button" class="ds-chip" data-p="12">12 mois</button></div>
                <div class="db-acts"><button type="button" id="fApply" class="ds-btn mk-btn-sm">Appliquer</button><button type="button" id="fReset" class="ds-btn ds-btn-light mk-btn-sm">Réinitialiser</button></div>
            </div>
        </details>
        <div class="mk-grid mk-g4" id="kpis" style="margin-top:14px" aria-live="polite">
            <div class="mk-stat"><div class="k">Chiffre d'affaires<?= $help("Montant payé par les familles (carte, chèque, espèces…), annulations déduites.") ?></div><div class="v" id="kCa">–</div></div>
            <div class="mk-stat"><div class="k">Inscrits</div><div class="v" id="kIns">–</div></div>
            <div class="mk-stat"><div class="k">Remplissage<?= $help("Inscrits sur le nombre de places des séjours choisis (toutes périodes).") ?></div><div class="v" id="kRem">–</div></div>
            <div class="mk-stat"><div class="k">À encaisser<?= $help("Règlements hors carte (chèque, espèces…) pas encore marqués comme reçus.") ?></div><div class="v" id="kAr">–</div></div>
        </div>
        <div class="mk-grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));margin-top:14px">
            <div class="ds-card" style="padding:20px"><div class="db-h">Encaissé par mois</div><svg id="chCa" class="db-chart" role="img" aria-label="Courbe du chiffre d'affaires"></svg></div>
            <div class="ds-card" style="padding:20px"><div class="db-h">Inscriptions par mois</div><svg id="chIns" class="db-chart" role="img" aria-label="Courbe des inscriptions"></svg></div>
            <div class="ds-card" style="padding:20px"><div class="db-h">Remplissage par séjour</div><div id="sejList"></div></div>
            <div class="ds-card" style="padding:20px"><div class="db-h">Moyens de paiement</div><div id="payEmpty" class="db-empty" hidden>Aucune inscription sur cette période.</div><div id="payBody" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap"><svg id="chPay" viewBox="0 0 190 190" style="width:150px;height:150px;flex:none" role="img" aria-label="Répartition des moyens de paiement"></svg><div id="payLeg" class="db-legend" style="flex-direction:column;gap:6px;margin:0;flex:1;min-width:150px"></div></div></div>
        </div>
    </section>

    <!-- 6. Liste des paiements (mêmes filtres) -->
    <section class="mk-section" id="paiements">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><h2 style="margin:0">Paiements <span id="pVue" class="mk-pill info" style="display:none"></span><?= $help("Touchez une ligne pour le détail. Pour vous = payé − remboursé − commission.") ?></h2>
            <a id="pCsv" class="ds-btn ds-btn-light mk-btn-sm" href="#"><?= mk_icon('doc', 16) ?> CSV</a></div>
        <div class="mk-table-wrap" style="margin-top:12px"><table class="mk-table" style="min-width:640px">
            <thead><tr><th>Date</th><th>Famille</th><th>Séjour</th><th class="num">Payé</th><th class="num">Commission</th><th class="num">Pour vous</th><th>Statut</th><th></th></tr></thead>
            <tbody id="pBody"><tr><td colspan="8" class="db-empty">Chargement…</td></tr></tbody></table></div>
    </section>

    <!-- TVA -->
    <section class="mk-section" style="margin-top:16px">
        <form method="post" class="ds-card" style="padding:16px 20px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between">
            <b class="text-[#26343b]">TVA<?= $help("Les prix sont TTC : les familles ne paient rien de plus. Si vous êtes assujetti, indiquez votre taux : la TVA incluse figurera sur leur reçu. Associations et micro-entrepreneurs sont souvent non assujettis. Ne remplace pas l'avis d'un comptable.") ?></b>
            <div style="display:flex;gap:10px;align-items:center">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(fin_csrf()) ?>"><input type="hidden" name="act" value="tva">
                <select class="ds-select" name="tva" aria-label="Taux de TVA"><?php foreach (fin_tva_taux_permis() as $k => $l): $cur = $orga['tva_taux'] === null ? '' : rtrim(rtrim((string)$orga['tva_taux'], '0'), '.'); ?><option value="<?= htmlspecialchars((string)$k) ?>"<?= (string)$k === $cur ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?></select>
                <button class="ds-btn ds-btn-light" type="submit">Enregistrer</button>
            </div>
        </form>
    </section>
<?php endif; ?>
</main>
<div id="dbPop" role="tooltip"></div>

<?php if ($orga): ?>
<script>
(function () {
    var NS = 'http://www.w3.org/2000/svg', INK = '#2f3d45', ACC = '#3d6a8e', COLS = ['#2f3d45', '#3d6a8e', '#8aa4b8', '#c9d3db', '#6b7780', '#b9a96b'], ORGA = <?= (int)$orgaId ?>;
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]; }); };
    var eur = function (c) { return (c / 100).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }); };
    var eur2 = function (c) { return (c / 100).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' }); };

    // Courbe SVG légère (aire + ligne + points, axes minimaux)
    function line(id, labels, vals, color, fmt) {
        var s = $(id), W = 360, H = 190, pl = 8, pr = 8, pt = 14, pb = 24, n = vals.length;
        s.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
        if (!n) { s.innerHTML = ''; return; }
        var max = Math.max.apply(null, vals.concat([1]));
        var x = function (i) { return n < 2 ? W / 2 : pl + i * (W - pl - pr) / (n - 1); }, y = function (v) { return H - pb - v / max * (H - pt - pb); };
        var h = '', step = Math.ceil(n / 8);
        [0, .5, 1].forEach(function (t) { var yy = y(max * t); h += '<line x1="' + pl + '" x2="' + (W - pr) + '" y1="' + yy + '" y2="' + yy + '" stroke="#e5e7eb"/>'; });
        var pts = vals.map(function (v, i) { return x(i) + ',' + y(v); }).join(' ');
        h += '<polygon points="' + x(0) + ',' + y(0) + ' ' + pts + ' ' + x(n - 1) + ',' + y(0) + '" fill="' + color + '" opacity=".1"/>';
        h += '<polyline points="' + pts + '" fill="none" stroke="' + color + '" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round"/>';
        vals.forEach(function (v, i) { h += '<circle cx="' + x(i) + '" cy="' + y(v) + '" r="3" fill="#fff" stroke="' + color + '" stroke-width="2"><title>' + esc(labels[i]) + ' : ' + fmt(v) + '</title></circle>'; });
        labels.forEach(function (l, i) { if (i % step === (n - 1) % step) h += '<text class="db-tip" x="' + x(i) + '" y="' + (H - 6) + '" text-anchor="middle">' + esc(l) + '</text>'; });
        h += '<text class="db-tip" x="' + pl + '" y="' + (pt - 4) + '">' + fmt(max) + '</text>';
        s.innerHTML = h;
    }
    function donut(list) {
        var tot = list.reduce(function (a, m) { return a + m.n; }, 0), a0 = -Math.PI / 2, h = '', leg = '';
        list.forEach(function (m, i) {
            var a1 = a0 + m.n / tot * Math.PI * 2, big = a1 - a0 > Math.PI ? 1 : 0, R = 80, r = 50, cx = 95, cy = 95, c = COLS[i % COLS.length];
            var p = function (rad, a) { return (cx + rad * Math.cos(a)) + ' ' + (cy + rad * Math.sin(a)); };
            h += list.length === 1 ? '<circle cx="95" cy="95" r="65" fill="none" stroke="' + c + '" stroke-width="30"/>' : '<path d="M' + p(R, a0) + ' A' + R + ' ' + R + ' 0 ' + big + ' 1 ' + p(R, a1) + ' L' + p(r, a1) + ' A' + r + ' ' + r + ' 0 ' + big + ' 0 ' + p(r, a0) + 'Z" fill="' + c + '"><title>' + esc(m.l) + '</title></path>';
            leg += '<span style="display:flex;justify-content:space-between;gap:10px;width:100%"><span><i style="background:' + c + '"></i>' + esc(m.l) + '</span><b style="color:var(--ds-ink)">' + Math.round(m.n / tot * 100) + ' % <span style="font-weight:600;color:var(--ds-muted)">· ' + m.n + '</span></b></span>';
            a0 = a1;
        });
        $('chPay').innerHTML = h; $('payLeg').innerHTML = leg;
    }

    // Filtres : rien ne se recharge avant « Appliquer » (F = filtres appliqués, les champs ne sont lus qu'au clic)
    var F = { type: '', sejour: '', session: '', moyen: '', du: '', au: '' }, DEF = {}, first = true, seq = 0, SESS = [], ROWS = [], VUE = '';
    var IDS = { type: 'fType', sejour: 'fSejour', session: 'fSession', moyen: 'fMoyen', du: 'fDu', au: 'fAu' };
    function fill(id, rows, val) { var el = $(id), all = el.options[0].outerHTML; el.innerHTML = all + rows.map(function (r) { return '<option value="' + esc(r[0]) + '"' + (r[0] === val ? ' selected' : '') + '>' + esc(r[1]) + '</option>'; }).join(''); el.value = val; }
    function sessions(val) { var sj = $('fSejour').value, ss = SESS.filter(function (s) { return !sj || s.s === sj; }); fill('fSession', ss.map(function (s) { return [s.t, (ss.length > 1 || sj ? '' : s.n + ' · ') + s.l]; }), ss.some(function (s) { return s.t === val; }) ? val : ''); }
    function setFields() { Object.keys(IDS).forEach(function (k) { if (k !== 'session') $(IDS[k]).value = F[k]; }); sessions(F.session); }
    function qs(csv) { var q = new URLSearchParams({ organisateur_id: ORGA }); Object.keys(F).forEach(function (k) { if (F[k]) q.set(k, F[k]); }); if (csv) q.set('csv', '1'); return q; }
    function render(d) {
        var f = d.filtres, k = d.kpi;
        if (first) { first = false; DEF = { du: d.periode.du, au: d.periode.au }; F.du = DEF.du; F.au = DEF.au; SESS = f.sessions;
            fill('fType', f.types.map(function (t) { return [t, t]; }), ''); fill('fSejour', f.sejours.map(function (s) { return [s.t, s.nom]; }), ''); fill('fMoyen', f.moyens.map(function (m) { return [m.k, m.l]; }), ''); setFields(); }
        var n = ['type', 'sejour', 'session', 'moyen'].filter(function (x) { return F[x] !== ''; }).length + (F.du !== DEF.du || F.au !== DEF.au ? 1 : 0);
        $('fCount').textContent = n ? '(' + n + ')' : '';
        $('kCa').textContent = eur(k.ca_cents); $('kIns').textContent = k.inscrits; $('kRem').textContent = k.places ? k.remplissage + ' %' : '–'; $('kAr').textContent = eur(k.a_encaisser_cents);
        line('chCa', d.serie.labels, d.serie.ca, INK, function (v) { return Math.round(v).toLocaleString('fr-FR') + ' €'; });
        line('chIns', d.serie.labels, d.serie.ins, ACC, function (v) { return Math.round(v) + ''; });
        $('sejList').innerHTML = d.sejours.length ? d.sejours.map(function (s) { return '<div class="db-row"><b>' + esc(s.nom) + '</b><span>' + s.ins + '/' + s.quota + '</span><div class="db-bar" role="img" aria-label="' + s.pct + ' % rempli"><i style="width:' + s.pct + '%"></i></div></div>'; }).join('') : '<p class="db-empty">Aucun séjour pour ces filtres.</p>';
        var has = d.moyens.length; $('payBody').hidden = !has; $('payBody').style.display = has ? 'flex' : 'none'; $('payEmpty').hidden = !!has; if (has) donut(d.moyens);
        ROWS = d.paiements || []; list();
        $('pCsv').href = 'api/dashboard_stats.php?' + qs(true);
    }
    function load() {
        var my = ++seq;
        $('kpis').style.opacity = .55;
        fetch('api/dashboard_stats.php?' + qs(false), { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw 0; return r.json(); }).then(function (d) { if (my === seq) { render(d); $('kpis').style.opacity = 1; } })
            .catch(function () { if (my === seq) { $('kpis').style.opacity = 1; $('sejList').innerHTML = '<p class="db-empty">Statistiques indisponibles pour le moment.</p>'; $('pBody').innerHTML = '<tr><td colspan="8" class="db-empty">Liste indisponible pour le moment.</td></tr>'; } });
    }
    $('fSejour').addEventListener('change', function () { sessions(''); }); // seulement la liste des sessions, pas de rechargement
    document.querySelectorAll('[data-p]').forEach(function (b) { b.addEventListener('click', function () {
        var m = +b.dataset.p, e = new Date(), s = new Date(e.getFullYear(), e.getMonth() - (m === 1 ? 0 : m - 1), m === 1 ? e.getDate() - 30 : 1), z = function (n) { return ('0' + n).slice(-2); };
        var f = function (d) { return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate()); };
        $('fDu').value = f(s); $('fAu').value = f(e);
    }); });
    $('fApply').addEventListener('click', function () { Object.keys(IDS).forEach(function (k) { F[k] = $(IDS[k]).value; }); if (!F.du) F.du = DEF.du; if (!F.au) F.au = DEF.au; load(); });
    $('fReset').addEventListener('click', function () { F = { type: '', sejour: '', session: '', moyen: '', du: DEF.du, au: DEF.au }; setFields(); load(); });

    // Liste des paiements ; une ligne de « Où va l'argent » ouvre la liste des paiements concernés
    var VUES = { com: [function (r) { return r.com > 0; }, 'Avec commission'], remb: [function (r) { return r.remb > 0; }, 'Remboursés'], verse: [function (r) { return r.k === 'verse'; }, 'Versés'],
                 attente: [function (r) { return ['attente', 'en_cours', 'litige', 'bloque'].indexOf(r.k) >= 0; }, 'En attente'] };
    var dfr = function (s) { return s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4); };
    function list() {
        var v = VUES[VUE], rows = v ? ROWS.filter(v[0]) : ROWS;
        $('pVue').style.display = v ? '' : 'none'; if (v) $('pVue').textContent = v[1] + ' ×';
        $('pBody').innerHTML = rows.length ? rows.map(function (r) { return '<tr class="pay-tr" tabindex="0" aria-expanded="false" data-det="' + esc('Moyen : ' + r.moy + ' · payé ' + eur2(r.brut) + (r.remb ? ' · remboursé ' + eur2(r.remb) : '') + ' · commission ' + eur2(r.com) + ' · pour vous ' + eur2(r.pv) + ' · ' + r.st) + '">'
            + '<td>' + dfr(r.d) + '</td><td><b>' + esc(r.fam) + '</b></td><td>' + esc(r.camp) + '</td><td class="num">' + eur2(r.brut) + '</td><td class="num">' + (r.com ? '− ' + eur2(r.com) : '–') + '</td><td class="num"><b>' + eur2(r.pv) + '</b></td><td>' + esc(r.st) + '</td>'
            + '<td class="num">' + (r.tok ? '<a class="underline" href="generate_pdf_certificat.php?r=' + encodeURIComponent(r.tok) + '" target="_blank" rel="noopener">Reçu</a>' : '') + '</td></tr>'; }).join('')
            : '<tr><td colspan="8" class="db-empty">Aucun paiement' + (v ? ' de ce type' : '') + ' sur cette période.</td></tr>';
    }
    function toggle(tr) {
        var nx = tr.nextElementSibling;
        if (nx && nx.classList.contains('pay-det')) { nx.remove(); tr.setAttribute('aria-expanded', 'false'); return; }
        var d = document.createElement('tr'); d.className = 'pay-det'; d.innerHTML = '<td colspan="8"></td>'; d.firstChild.textContent = tr.dataset.det; tr.after(d); tr.setAttribute('aria-expanded', 'true');
    }
    $('pBody').addEventListener('click', function (e) { var tr = e.target.closest('.pay-tr'); if (tr && !e.target.closest('a')) toggle(tr); });
    $('pBody').addEventListener('keydown', function (e) { var tr = e.target.closest('.pay-tr'); if (tr && e.key === 'Enter') toggle(tr); });
    document.querySelectorAll('.cas [data-vue]').forEach(function (b) {
        b.addEventListener('click', function () { VUE = b.dataset.vue; list(); $('paiements').scrollIntoView({ behavior: 'smooth', block: 'start' }); });
        b.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && !e.target.closest('.dbh')) { e.preventDefault(); b.click(); } });
    });
    $('pVue').addEventListener('click', function () { VUE = ''; list(); });
    $('pVue').style.cursor = 'pointer';
    load();

    // Bulles d'aide « ? » (clic)
    var pop = document.getElementById('dbPop'), cur = null;
    function close() { pop.classList.remove('on'); if (cur) cur.setAttribute('aria-expanded', 'false'); cur = null; }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.dbh');
        if (!b) { if (!e.target.closest('#dbPop')) close(); return; }
        e.stopPropagation();
        if (cur === b) { close(); return; }
        close(); cur = b; b.setAttribute('aria-expanded', 'true'); pop.textContent = b.dataset.help; pop.classList.add('on');
        var r = b.getBoundingClientRect(), w = pop.offsetWidth;
        pop.style.top = (window.scrollY + r.bottom + 8) + 'px';
        pop.style.left = Math.max(8, Math.min(window.scrollX + r.left - 8, window.scrollX + document.documentElement.clientWidth - w - 8)) + 'px';
    }, true);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    window.addEventListener('scroll', close, { passive: true });
})();
</script>
<?php endif; ?>
<?php require_once 'partials/footer.php'; ?>

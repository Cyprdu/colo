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


// ---- Données des graphiques (12 derniers mois) ----
$serie = [];
for ($i = 11; $i >= 0; $i--) { $k = date('Y-m', strtotime("first day of -$i month")); $serie[$k] = ['ca' => 0, 'ins' => 0]; }
$nbInscrits = 0; $nbPlaces = 0;
if ($orga) {
    try {
        require_once 'api/compta_lib.php';
        foreach (compta_by_month($pdo, date('Y-m-01 00:00:00', strtotime('first day of -11 month')), date('Y-m-d 23:59:59'), $orgaId) as $r) {
            if (isset($serie[$r['m']])) { $serie[$r['m']]['ca'] = round((int)$r['ca'] / 100, 2); }
        }
        $q = $pdo->prepare("SELECT DATE_FORMAT(i.date_inscription, '%Y-%m') m, COUNT(*) n FROM inscriptions i JOIN camps c ON c.id = i.camp_id
                            WHERE c.organisateur_id = ? AND i.statut_paiement = 'PAYE' AND COALESCE(i.statut,'') NOT IN ('Annulé','annule','Annule')
                              AND i.date_inscription >= ? GROUP BY m");
        $q->execute([$orgaId, date('Y-m-01', strtotime('first day of -11 month'))]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($serie[$r['m']])) $serie[$r['m']]['ins'] = (int)$r['n'];
    } catch (Throwable $e) { error_log('dashboard series: ' . $e->getMessage()); }
}
$moisCourt = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
$chart = ['labels' => [], 'ca' => [], 'ins' => []];
foreach ($serie as $k => $v) { $chart['labels'][] = $moisCourt[(int)substr($k, 5, 2) - 1]; $chart['ca'][] = $v['ca']; $chart['ins'][] = $v['ins']; }
$sejours = [];
foreach ($mkStats as $c) {
    $qt = max(1, (int)$c['quota_global']); $in = (int)$c['inscrits_total'];
    $nbInscrits += $in; $nbPlaces += $qt;
    $sejours[] = ['nom' => $c['nom'], 'ins' => $in, 'quota' => $qt, 'pct' => min(100, (int)round($in / $qt * 100))];
}
usort($sejours, fn($a, $b) => $b['ins'] <=> $a['ins']);
$remplissage = $nbPlaces ? (int)round($nbInscrits / $nbPlaces * 100) : 0;
$caTotal = $fin ? (int)$fin['brut_total_cents'] : 0;

$body_class = 'bg-white';
require_once 'partials/header.php';
require_once 'partials/mk_icons.php';
$statusTxt = $ready ? 'Versements activés' : ($hasAccount && !empty($acc['details_submitted']) ? 'Vérification Stripe en cours' : ($hasAccount ? 'Inscription Stripe à finaliser' : 'Versements non activés'));
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
.db-empty { color: var(--ds-muted); font-size: 13px; padding: 28px 0; text-align: center; }
.db-tip { fill: var(--ds-muted); font-size: 10px; font-family: Montserrat, sans-serif; }
@media (prefers-reduced-motion: reduce) { .db-bar i { transition: none; } #dbPop.on { animation: none; } }
</style>

<main class="mk-wrap">
<a href="public_infos.php" class="mk-back"><?= mk_icon('back', 16) ?> Mes organismes</a>

<?php if (!$orga): ?>
    <div class="ds-alert-err" role="alert"><b>Erreur.</b> <?= htmlspecialchars($error ?? '') ?></div>
<?php else: ?>
    <?php if ($error): ?><div class="ds-alert-err" style="margin-bottom:16px" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="ds-alert-ok" style="margin-bottom:16px" role="status"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:14px;min-width:0">
            <?php if (!empty($orga['logo_url'])): ?><img src="<?= htmlspecialchars($orga['logo_url']) ?>" alt="" style="width:52px;height:52px;border-radius:16px;object-fit:cover;border:1px solid var(--ds-line)"><?php endif; ?>
            <div style="min-width:0"><p class="ds-eyebrow">Tableau de bord</p><h1 class="ds-h1" style="overflow-wrap:anywhere"><?= htmlspecialchars($orga['nom']) ?></h1></div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <a class="ds-btn" href="create_camp.php">Créer un séjour</a>
            <a class="ds-btn ds-btn-light" href="mes_camps.php">Inscrits</a>
            <a class="ds-btn ds-btn-light" href="messagerie.php">Messages</a>
            <a class="ds-btn ds-btn-light" href="marketing.php?id=<?= $orgaId ?>">Marketing</a>
        </div>
    </div>

    <!-- Indicateurs -->
    <section class="mk-section" style="margin-top:24px">
        <div class="mk-grid mk-g4">
            <div class="mk-stat dark"><div class="k">Prochain versement<?= $help("Somme versée sur votre compte bancaire au prochain jour de versement. Elle comprend les inscriptions payées depuis au moins " . (int)$fin['delai_jours'] . " jours, hors réserve. Le jour est fixe chaque semaine" . ($fin['jour_nom'] ? " (" . $fin['jour_nom'] . ")" : "") . ".") ?></div><div class="v"><?= fin_eur($fin['prochain']['cents'] ?? 0) ?></div><div class="s"><?= $fin['prochain'] ? date('d/m/Y', strtotime($fin['prochain']['d'])) : 'date à venir' ?></div></div>
            <div class="mk-stat"><div class="k">Réserve<?= $help(number_format($fin['reserve_pct'], 0, ',', ' ') . " % de chaque paiement est gardé de côté tant qu'une famille peut annuler et être remboursée. Il vous est versé automatiquement ensuite : ce n'est pas un frais.") ?></div><div class="v"><?= fin_eur($fin['reserve_cents']) ?></div></div>
            <div class="mk-stat"><div class="k">Déjà versé<?= $help("Total des versements envoyés sur votre compte bancaire via Stripe.") ?></div><div class="v"><?= fin_eur($fin['verse_cents']) ?></div></div>
            <div class="mk-stat"><div class="k">Commission<?= $help("Commission ColoMap : " . number_format(fin_commission_pct(), 1, ',', ' ') . " %" . (fin_commission_fixe_cents() > 0 ? " + " . fin_eur(fin_commission_fixe_cents()) : "") . " du montant payé par inscription, frais bancaires de paiement par carte inclus. Aucun autre frais.") ?></div><div class="v"><?= fin_eur($fin['commission_nette_cents']) ?></div></div>
            <div class="mk-stat"><div class="k">Chiffre d'affaires</div><div class="v"><?= fin_eur($caTotal) ?></div></div>
            <div class="mk-stat"><div class="k">Inscrits</div><div class="v"><?= $nbInscrits ?></div></div>
            <div class="mk-stat"><div class="k">Remplissage<?= $help("Inscrits sur le nombre total de places de tous vos séjours.") ?></div><div class="v"><?= $remplissage ?> %</div></div>
            <div class="mk-stat"><div class="k">Points marketing</div><div class="v"><?= number_format((int)$fin['points'], 0, ',', ' ') ?></div></div>
        </div>
        <?php if ($fin['solde_cents'] < 0): ?><div class="ds-alert-err" style="margin-top:14px">Solde négatif : <?= fin_eur(-$fin['solde_cents']) ?> seront retirés de vos prochains versements. <?= $help("Une famille a été remboursée après que vous avez reçu l'argent.") ?></div><?php endif; ?>
        <?php if ($fin['historique_cents'] > 0): ?><div class="ds-alert-ok" style="margin-top:14px">Solde de l'ancien système : <?= fin_eur($fin['historique_cents']) ?> <?= $help("Réglé manuellement par l'équipe ColoMap : contactez-nous pour le recevoir.") ?></div><?php endif; ?>
    </section>

    <!-- Versements -->
    <section class="mk-section" style="margin-top:16px">
        <div class="ds-card" style="padding:16px 20px;display:flex;gap:14px;flex-wrap:wrap;align-items:center;justify-content:space-between">
            <div style="display:flex;align-items:center;gap:12px"><span class="ds-tile-ico" style="width:40px;height:40px"><?= mk_icon($ready ? 'check' : 'bank', 20) ?></span>
                <b class="text-[#26343b]"><?= $statusTxt ?></b><?= $help($ready ? "L'argent des inscriptions est versé automatiquement sur votre compte bancaire, chaque semaine, sans attendre le séjour." : "Pour être payé, renseignez votre identité et votre IBAN directement chez Stripe (5 minutes). ColoMap ne voit ni ne conserve ces données." . ($due ? " Informations restantes : " . count($due) . "." : "")) ?></div>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <?php if (!$ready): ?><a class="ds-btn" href="<?= $hasAccount ? 'api/connect_onboarding.php?o=' : 'connect_statut.php?o=' ?><?= fin_orga_tok($orgaId) ?>"><?= $hasAccount ? 'Continuer' : 'Activer mes versements' ?></a>
                <?php elseif (($acc['type'] ?? '') === 'express'): ?><a class="ds-btn ds-btn-light" href="api/connect_dashboard.php?organisateur_id=<?= $orgaId ?>" target="_blank" rel="noopener">Espace Stripe</a><?php endif; ?>
                <a class="ds-btn ds-btn-light" href="#versements">Versements</a>
                <a class="ds-btn ds-btn-light" href="annulations_organisme.php?o=<?= fin_orga_tok($orgaId) ?>">Annulations<?= !empty($nbAnnul) ? ' (' . $nbAnnul . ')' : '' ?></a>
                <a class="ds-btn ds-btn-light" href="edit_organisateur.php?id=<?= $orgaId ?>">Modifier</a>
            </div>
        </div>
    </section>

    <!-- Graphiques -->
    <section class="mk-section" style="margin-top:16px">
        <div class="mk-grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
            <div class="ds-card" style="padding:20px"><div class="db-h">Chiffre d'affaires (12 mois)<?= $help("Montant payé par les familles, remboursements déduits, par mois.") ?></div><svg id="chCa" class="db-chart" role="img" aria-label="Courbe du chiffre d'affaires sur 12 mois"></svg></div>
            <div class="ds-card" style="padding:20px"><div class="db-h">Inscriptions (12 mois)<?= $help("Nombre d'inscriptions payées et non annulées, par mois.") ?></div><svg id="chIns" class="db-chart" role="img" aria-label="Courbe des inscriptions sur 12 mois"></svg></div>
            <div class="ds-card" style="padding:20px"><div class="db-h">Remplissage par séjour<?= $help("Inscrits sur places disponibles, séjour par séjour.") ?></div>
                <?php if (!$sejours): ?><p class="db-empty">Aucun séjour. <a class="underline" href="create_camp.php">Créer un séjour</a></p><?php else: foreach (array_slice($sejours, 0, 8) as $s): ?>
                    <div class="db-row"><b><?= htmlspecialchars($s['nom']) ?></b><span><?= $s['ins'] ?>/<?= $s['quota'] ?></span><div class="db-bar" role="img" aria-label="<?= $s['pct'] ?> % rempli"><i style="width:<?= $s['pct'] ?>%"></i></div></div>
                <?php endforeach; endif; ?></div>
            <div class="ds-card" id="payCard" style="padding:20px;display:none"><div class="db-h">Moyens de paiement<?= $help("Répartition des paiements par moyen utilisé (carte, etc.).") ?></div><svg id="chPay" class="db-chart" role="img" aria-label="Répartition des moyens de paiement"></svg><div id="payLeg" class="db-legend"></div></div>
        </div>
    </section>

    <!-- Historique -->
    <section class="mk-section" id="versements">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><h2 style="margin:0">Versements<?= $help("Chaque versement a son justificatif, détaillé inscription par inscription.") ?></h2>
            <?php if ($transferts || $legacy): ?><a class="ds-btn ds-btn-light mk-btn-sm" href="pdf_export_virements_history.php?organisateur_id=<?= $orgaId ?>" target="_blank"><?= mk_icon('doc', 16) ?> PDF</a><?php endif; ?></div>
        <?php if (!$transferts && !$legacy): ?>
            <p class="db-empty">Aucun versement pour le moment.</p>
        <?php else: ?>
            <div class="mk-table-wrap" style="margin-top:12px"><table class="mk-table">
                <thead><tr><th>Date</th><th class="num">Montant</th><th>Statut</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($transferts as $t): $s = ['envoye' => ['Envoyé', 'ok'], 'en_cours' => ['En cours', 'info'], 'echec' => ['Échec, nouvel essai', 'bad']][$t['statut']] ?? [$t['statut'], '']; ?>
                    <tr><td><?= date('d/m/Y', strtotime($t['created_at'])) ?></td><td class="num"><b><?= fin_eur((int)$t['montant_cents']) ?></b></td><td><span class="mk-pill <?= $s[1] ?>"><?= $s[0] ?></span></td><td class="num"><a class="underline" href="virement.php?t=<?= urlencode($t['token']) ?>">Justificatif</a></td></tr>
                <?php endforeach; ?>
                <?php foreach ($legacy as $v): ?>
                    <tr><td><?= date('d/m/Y', strtotime($v['date_demande'])) ?></td><td class="num"><b><?= fin_eur((int)round($v['montant_apres_commission'] * 100)) ?></b></td><td><span class="mk-pill <?= $v['effectue'] ? 'ok' : 'warn' ?>"><?= $v['effectue'] ? 'Effectué' : 'En attente' ?></span></td><td class="num"><a class="underline" href="virement.php?t=<?= urlencode($v['token']) ?>">Justificatif</a></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
        <?php endif; ?>
    </section>

    <!-- Derniers paiements -->
    <?php if ($recent): ?>
    <section class="mk-section">
        <h2 style="margin:0">Derniers paiements<?= $help("Payé par la famille, commission ColoMap, puis net pour vous.") ?></h2>
        <div class="mk-table-wrap" style="margin-top:12px"><table class="mk-table" style="min-width:560px">
            <thead><tr><th>Date</th><th>Séjour</th><th class="num">Payé</th><th class="num">Commission</th><th class="num">Net</th></tr></thead><tbody>
            <?php foreach (array_slice($recent, 0, 8) as $p): ?>
                <tr><td><?= date('d/m/Y', strtotime($p['created_at'])) ?></td><td><b><?= htmlspecialchars($p['camp']) ?></b></td><td class="num"><?= fin_eur((int)$p['brut_cents']) ?></td><td class="num">- <?= fin_eur((int)$p['commission_cents']) ?></td><td class="num"><b><?= fin_eur((int)$p['net_cents'] - (int)$p['net_rembourse_cents']) ?></b></td></tr>
            <?php endforeach; ?></tbody></table></div>
    </section>
    <?php endif; ?>

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
    var D = <?= json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var NS = 'http://www.w3.org/2000/svg', INK = '#2f3d45', ACC = '#3d6a8e';

    // Courbe SVG légère (aire + ligne + points, axes minimaux)
    function line(id, vals, color, fmt) {
        var s = document.getElementById(id), W = 360, H = 190, pl = 8, pr = 8, pt = 14, pb = 24;
        s.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
        var max = Math.max.apply(null, vals.concat([1])), n = vals.length;
        var x = function (i) { return pl + i * (W - pl - pr) / (n - 1); }, y = function (v) { return H - pb - v / max * (H - pt - pb); };
        var h = '';
        [0, .5, 1].forEach(function (t) { var yy = y(max * t); h += '<line x1="' + pl + '" x2="' + (W - pr) + '" y1="' + yy + '" y2="' + yy + '" stroke="#e5e7eb"/>'; });
        var pts = vals.map(function (v, i) { return x(i) + ',' + y(v); }).join(' ');
        h += '<polygon points="' + pl + ',' + y(0) + ' ' + pts + ' ' + (W - pr) + ',' + y(0) + '" fill="' + color + '" opacity=".1"/>';
        h += '<polyline points="' + pts + '" fill="none" stroke="' + color + '" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round"/>';
        vals.forEach(function (v, i) { h += '<circle cx="' + x(i) + '" cy="' + y(v) + '" r="3" fill="#fff" stroke="' + color + '" stroke-width="2"><title>' + D.labels[i] + ' : ' + fmt(v) + '</title></circle>'; });
        D.labels.forEach(function (l, i) { if (i % 2 === (n - 1) % 2) h += '<text class="db-tip" x="' + x(i) + '" y="' + (H - 6) + '" text-anchor="middle">' + l + '</text>'; });
        h += '<text class="db-tip" x="' + pl + '" y="' + (pt - 4) + '">' + fmt(max) + '</text>';
        s.innerHTML = h;
    }
    line('chCa', D.ca, INK, function (v) { return Math.round(v).toLocaleString('fr-FR') + ' €'; });
    line('chIns', D.ins, ACC, function (v) { return Math.round(v) + ''; });

    // Moyens de paiement : alimenté par api/moyens_paiement_stats.php (masqué si absent ou vide)
    fetch('api/moyens_paiement_stats.php?organisateur_id=<?= $orgaId ?>', { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw 0; return r.json(); }).then(function (d) {
        var list = (d && (d.moyens || d.data || d)) || [];
        if (!Array.isArray(list)) list = Object.keys(list).map(function (k) { return { label: k, n: list[k] }; });
        list = list.map(function (m) { return { l: String(m.label || m.moyen || m.nom || ''), n: Number(m.n != null ? m.n : (m.total != null ? m.total : m.montant)) || 0 }; }).filter(function (m) { return m.n > 0; });
        if (!list.length) return;
        var tot = list.reduce(function (a, m) { return a + m.n; }, 0), cols = ['#2f3d45', '#3d6a8e', '#8aa4b8', '#c9d3db', '#6b7780', '#b9a96b'], a0 = -Math.PI / 2, h = '', leg = '';
        list.forEach(function (m, i) {
            var a1 = a0 + m.n / tot * Math.PI * 2, big = a1 - a0 > Math.PI ? 1 : 0, R = 70, r = 42, cx = 95, cy = 95;
            var p = function (rad, a) { return (cx + rad * Math.cos(a)) + ' ' + (cy + rad * Math.sin(a)); };
            h += list.length === 1 ? '<circle cx="95" cy="95" r="56" fill="none" stroke="' + cols[0] + '" stroke-width="28"/>' : '<path d="M' + p(R, a0) + ' A' + R + ' ' + R + ' 0 ' + big + ' 1 ' + p(R, a1) + ' L' + p(r, a1) + ' A' + r + ' ' + r + ' 0 ' + big + ' 0 ' + p(r, a0) + 'Z" fill="' + cols[i % cols.length] + '"><title>' + m.l + '</title></path>';
            leg += '<span><i style="background:' + cols[i % cols.length] + '"></i>' + m.l.replace(/[<&]/g, '') + ' ' + Math.round(m.n / tot * 100) + ' %</span>';
            a0 = a1;
        });
        document.getElementById('chPay').setAttribute('viewBox', '0 0 190 190'); document.getElementById('chPay').innerHTML = h;
        document.getElementById('payLeg').innerHTML = leg; document.getElementById('payCard').style.display = '';
    }).catch(function () {});

    // Bulles d'aide « ? » (clic)
    var pop = document.getElementById('dbPop'), cur = null;
    function close() { pop.classList.remove('on'); if (cur) cur.setAttribute('aria-expanded', 'false'); cur = null; }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.dbh');
        if (!b) { if (!e.target.closest('#dbPop')) close(); return; }
        if (cur === b) { close(); return; }
        close(); cur = b; b.setAttribute('aria-expanded', 'true'); pop.textContent = b.dataset.help; pop.classList.add('on');
        var r = b.getBoundingClientRect(), w = pop.offsetWidth;
        pop.style.top = (window.scrollY + r.bottom + 8) + 'px';
        pop.style.left = Math.max(8, Math.min(window.scrollX + r.left - 8, window.scrollX + document.documentElement.clientWidth - w - 8)) + 'px';
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    window.addEventListener('scroll', close, { passive: true });
})();
</script>
<?php endif; ?>
<?php require_once 'partials/footer.php'; ?>

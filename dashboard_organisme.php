<?php
// dashboard_organisme.php?o=<jeton organisme> : outil de gestion financière de l'organisme (usage quotidien).
// Onglets : Vue d'ensemble (indicateurs vs période précédente, « où va l'argent », graphiques), Transactions (recherche, tri, filtres au clic
// sur « Appliquer », pagination, sélection, exports, panneau détail), Versements, Remboursements & litiges, Dette, Hors carte, Rapports (+ TVA).
// Données : api/dashboard_stats.php (fin_ledger / fin_paiements, centimes). Aucune règle financière n'est calculée ici.
require_once 'api/config.php';
require_once 'api/finance_lib.php';
require_once 'api/marketing_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user']) || (empty($_SESSION['user']['is_directeur']) && empty($_SESSION['user']['is_admin']))) { header('Location: index.php'); exit; }

$orgaId = fin_orga_request($pdo); // propriétaire ou admin, sinon 404 ; ancienne URL numérique : redirection admin
$orgaTok = fin_orga_tok($orgaId);
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
        $pdo->prepare("UPDATE organisateurs SET tva_taux = ? WHERE id = ?")->execute([$tx === '' ? null : $tx, $orgaId]);
        fin_audit($pdo, 'tva_modifiee', 'organisateur', $orgaId, ['taux' => $tx === '' ? 'na' : $tx], 'user:' . $userId);
        header('Location: dashboard_organisme.php?o=' . $orgaTok . '&success=' . urlencode('Taux de TVA enregistré.') . '#rapports'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$orga = null; $acc = null; $historique = 0; $nbAnnul = 0;
try {
    fin_ensure($pdo); mk_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM organisateurs WHERE id = ?");
    $st->execute([$orgaId]);
    $orga = $st->fetch(PDO::FETCH_ASSOC);
    // État du compte Stripe : resynchronisé au retour de l'inscription, ou si le dossier n'est pas encore complet
    $acc = fin_account($pdo, $orgaId);
    $stale = !$acc || (!fin_account_ready($acc) && strtotime($acc['maj']) < time() - 60);
    if (!empty($orga['stripe_account_id']) && ($stale || ($_GET['stripe'] ?? '') === 'retour')) {
        try { $acc = fin_sync_account($pdo, $orgaId); } catch (Throwable $e) { error_log('dashboard sync: ' . $e->getMessage()); }
    }
    $historique = fin_overview($pdo, $orgaId)['historique_cents'];
    if (!empty($acc['account_id'])) { try { fin_payouts_sync($pdo, $orgaId); } catch (Throwable $e) { error_log('dashboard payouts: ' . $e->getMessage()); } } // dates d'arrivée Stripe
    $st = $pdo->prepare("SELECT COUNT(*) FROM fin_annulations WHERE organisateur_id = ? AND lu = 0");
    $st->execute([$orgaId]);
    $nbAnnul = (int)$st->fetchColumn();
} catch (Throwable $e) {
    error_log('dashboard_organisme: ' . $e->getMessage());
    $error = 'Une erreur est survenue. Réessayez dans un instant.';
}
if (!$orga) fin_orga_404();

$ready = fin_account_ready($acc);
$hasAccount = !empty($orga['stripe_account_id']);
$due = $acc && !empty($acc['exigences']) ? (json_decode($acc['exigences'], true) ?: []) : [];
$autoTxt = 'versé automatiquement par Stripe dès que les fonds sont disponibles, en général sous 2 à 7 jours ouvrés';
$comTxt = number_format(fin_commission_pct(), 1, ',', ' ') . ' %' . (fin_commission_fixe_cents() > 0 ? ' + ' . fin_eur(fin_commission_fixe_cents()) : '');
$tvaCur = $orga['tva_taux'] === null ? '' : rtrim(rtrim((string)$orga['tva_taux'], '0'), '.');

$body_class = 'bg-white';
require_once 'partials/header.php';
require_once 'partials/mk_icons.php';
$help = fn(string $t) => '<button type="button" class="dbh" data-help="' . htmlspecialchars($t, ENT_QUOTES) . '" aria-label="Aide : ' . htmlspecialchars(mb_substr($t, 0, 60), ENT_QUOTES) . '…" aria-expanded="false">?</button>';
$tabs = ['apercu' => "Vue d'ensemble", 'transactions' => 'Transactions', 'versements' => 'Versements', 'litiges' => 'Remboursements & litiges', 'dette' => 'Dette', 'hors' => 'Hors carte', 'rapports' => 'Rapports'];
?>
<title>Finances - <?= htmlspecialchars($orga['nom']) ?></title>
<link rel="stylesheet" href="assets/mk.css?v=1">
<style>
.fd { max-width: 1240px; margin: 0 auto; padding: 20px 16px 64px; }
.fd-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.fd-id { display: flex; align-items: center; gap: 14px; min-width: 0; }
.fd-id img { width: 48px; height: 48px; border-radius: 14px; object-fit: cover; border: 1px solid var(--ds-line); }
.fd-id h1 { font-size: 24px; font-weight: 800; color: var(--ds-ink); margin: 0; overflow-wrap: anywhere; line-height: 1.2; }
.fd-acct { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 6px; font-size: 13px; color: var(--ds-muted); }
.fd-bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 16px; padding: 10px; border: 1px solid var(--ds-line); border-radius: 14px; background: #fff; position: sticky; top: 0; z-index: 30; }
.fd-bar .ds-select, .fd-bar .ds-input { padding: 8px 12px; font-size: 14px; width: auto; }
.fd-bar .grow { flex: 1; }
.fd-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 10px; font-size: 14px; font-weight: 700; border: 1.5px solid var(--ds-line); background: #fff; color: var(--ds-ink); cursor: pointer; white-space: nowrap; }
.fd-btn:hover { border-color: var(--ds-btn); }
.fd-btn.pri { background: var(--ds-btn); border-color: var(--ds-btn); color: #fff; }
.fd-btn.pri:hover { background: var(--ds-btn-hover); }
.fd-btn:disabled { opacity: .45; cursor: not-allowed; }
.fd-btn:focus-visible, .fd-tab:focus-visible, .fd-th:focus-visible, .fd-tr:focus-visible { outline: 2px solid var(--ds-accent); outline-offset: 2px; }
.fd-menu { position: relative; }
.fd-menu > div { position: absolute; right: 0; top: calc(100% + 6px); background: #fff; border: 1px solid var(--ds-line); border-radius: 12px; box-shadow: 0 14px 34px rgba(31,42,48,.16); padding: 6px; min-width: 230px; z-index: 40; display: none; }
.fd-menu.open > div { display: block; }
.fd-menu a, .fd-menu button.it { display: block; width: 100%; text-align: left; padding: 9px 12px; border-radius: 8px; font-size: 14px; color: var(--ds-ink); background: none; border: 0; cursor: pointer; }
.fd-menu a:hover, .fd-menu button.it:hover, .fd-menu button.it:focus-visible { background: var(--ds-soft); }
.fd-tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--ds-line); margin-top: 18px; overflow-x: auto; scrollbar-width: none; }
.fd-tab { padding: 11px 14px; font-size: 14px; font-weight: 700; color: var(--ds-muted); border: 0; background: none; border-bottom: 2.5px solid transparent; cursor: pointer; white-space: nowrap; }
.fd-tab[aria-selected="true"] { color: var(--ds-ink); border-bottom-color: var(--ds-btn); }
.fd-tab .ct { display: inline-block; min-width: 18px; padding: 0 6px; margin-left: 6px; border-radius: 9px; background: #fde68a; color: #7a4b00; font-size: 11px; line-height: 18px; }
.fd-panel { padding-top: 18px; }
.fd-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
.fd-kpi { border: 1px solid var(--ds-line); border-radius: 14px; padding: 14px 16px; background: #fff; min-width: 0; }
.fd-kpi .k { font-size: 12.5px; font-weight: 700; color: var(--ds-muted); display: flex; align-items: center; gap: 2px; }
.fd-kpi .v { font-size: 22px; font-weight: 800; color: var(--ds-ink); font-variant-numeric: tabular-nums; margin-top: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fd-kpi .s { font-size: 12px; color: var(--ds-muted); margin-top: 3px; min-height: 16px; }
.fd-kpi.dark { background: var(--ds-btn); border-color: var(--ds-btn); }
.fd-kpi.dark .k, .fd-kpi.dark .s { color: #c9d3db; } .fd-kpi.dark .v { color: #fff; }
.fd-kpi.alert { border-color: #f3c2bd; background: #fff7f6; } .fd-kpi.alert .v { color: #b42318; }
.fd-d { font-weight: 800; font-variant-numeric: tabular-nums; }
.fd-d.up { color: #087443; } .fd-d.down { color: #b42318; } .fd-d.flat { color: var(--ds-muted); }
.fd-grid2 { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px; margin-top: 12px; }
.fd-card { border: 1px solid var(--ds-line); border-radius: 14px; background: #fff; padding: 16px 18px; min-width: 0; }
.fd-h { font-size: 14px; font-weight: 800; color: var(--ds-ink); display: flex; align-items: center; justify-content: space-between; gap: 8px; margin: 0 0 12px; }
.fd-seg { display: inline-flex; border: 1px solid var(--ds-line); border-radius: 9px; overflow: hidden; }
.fd-seg button { border: 0; background: #fff; padding: 5px 10px; font-size: 12.5px; font-weight: 700; color: var(--ds-muted); cursor: pointer; }
.fd-seg button[aria-pressed="true"] { background: var(--ds-btn); color: #fff; }
.fd-wf { display: grid; grid-template-columns: minmax(0, 1fr) auto; font-size: 14px; }
.fd-wf > div { padding: 8px 0; border-bottom: 1px solid var(--ds-soft); display: contents; }
.fd-wf .l { padding: 8px 0; border-bottom: 1px solid var(--ds-soft); color: var(--ds-ink); display: flex; align-items: center; gap: 8px; min-width: 0; }
.fd-wf .a { padding: 8px 0; border-bottom: 1px solid var(--ds-soft); text-align: right; font-weight: 800; font-variant-numeric: tabular-nums; white-space: nowrap; }
.fd-wf .neg { color: var(--ds-muted); }
.fd-wf .tot { font-size: 16px; border-bottom: 2px solid var(--ds-ink); }
.fd-wf .bar { flex: none; height: 8px; border-radius: 4px; background: var(--ds-btn); }
.fd-wf .bar.n { background: #c9d3db; } .fd-wf .bar.r { background: #e7b0aa; }
.fd-chart { width: 100%; height: 200px; display: block; }
.fd-ax { fill: var(--ds-muted); font-size: 10px; font-family: Montserrat, sans-serif; }
.fd-leg { display: flex; gap: 14px; font-size: 12px; color: var(--ds-muted); margin-top: 6px; flex-wrap: wrap; }
.fd-leg i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 5px; vertical-align: -1px; }
.fd-fill { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 3px 12px; font-size: 13px; margin-bottom: 10px; }
.fd-fill b { color: var(--ds-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .fd-fill small { color: var(--ds-muted); grid-column: 1; }
.fd-fill span { color: var(--ds-muted); font-weight: 700; font-variant-numeric: tabular-nums; grid-row: 1 / 3; grid-column: 2; align-self: center; }
.fd-fill .tr { grid-column: 1 / -1; height: 7px; background: var(--ds-soft); border-radius: 9999px; overflow: hidden; } .fd-fill .tr i { display: block; height: 100%; background: var(--ds-accent); border-radius: 9999px; }
.fd-filters { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)) auto; gap: 8px; align-items: end; margin-top: 10px; }
.fd-filters label span { display: block; font-size: 11.5px; font-weight: 700; color: var(--ds-muted); margin-bottom: 3px; }
.fd-filters .ds-select, .fd-filters .ds-input { width: 100%; padding: 8px 10px; font-size: 13.5px; }
.fd-tw { overflow-x: auto; border: 1px solid var(--ds-line); border-radius: 14px; margin-top: 10px; background: #fff; }
.fd-t { width: 100%; border-collapse: collapse; font-size: 13.5px; min-width: 920px; }
.fd-t th { position: sticky; top: 0; background: #f8fafb; text-align: left; font-size: 12px; font-weight: 800; color: var(--ds-muted); padding: 0; border-bottom: 1px solid var(--ds-line); white-space: nowrap; }
.fd-th { width: 100%; text-align: inherit; border: 0; background: none; font: inherit; color: inherit; padding: 10px 10px; cursor: pointer; }
.fd-th::after { content: ' ↕'; opacity: .35; } th[aria-sort="ascending"] .fd-th::after { content: ' ↑'; opacity: 1; } th[aria-sort="descending"] .fd-th::after { content: ' ↓'; opacity: 1; }
.fd-t td { padding: 9px 10px; border-bottom: 1px solid var(--ds-soft); color: var(--ds-ink); vertical-align: middle; }
.fd-t .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.fd-t th.num { text-align: right; }
.fd-t .mut { color: var(--ds-muted); }
.fd-tr { cursor: pointer; } .fd-tr:hover td { background: #f8fafb; } .fd-tr.sel td { background: #eef4f9; }
.fd-t input[type=checkbox] { width: 16px; height: 16px; accent-color: var(--ds-btn); cursor: pointer; }
.fd-pg { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 10px; font-size: 13px; color: var(--ds-muted); }
.fd-empty { text-align: center; color: var(--ds-muted); font-size: 14px; padding: 36px 12px; }
.fd-empty b { display: block; color: var(--ds-ink); font-size: 15px; margin-bottom: 4px; }
.sk { display: inline-block; height: 14px; width: 70%; border-radius: 6px; background: linear-gradient(90deg, #eef1f3 25%, #f6f8f9 50%, #eef1f3 75%); background-size: 200% 100%; animation: skl 1.2s infinite linear; }
@keyframes skl { to { background-position: -200% 0; } }
.fd-dl { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 8px 16px; font-size: 14px; margin: 0; }
.fd-dl dt { color: var(--ds-muted); } .fd-dl dd { margin: 0; text-align: right; font-weight: 700; color: var(--ds-ink); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.fd-dl .sep { grid-column: 1 / -1; border-top: 1px solid var(--ds-line); }
.fd-sub { font-size: 12px; font-weight: 800; color: var(--ds-muted); text-transform: uppercase; letter-spacing: .04em; margin: 18px 0 8px; }
.fd-code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; background: var(--ds-soft); padding: 2px 6px; border-radius: 6px; }
.dbh { width: 18px; height: 18px; border-radius: 50%; border: 1.5px solid #b8c2ca; color: var(--ds-muted); font-size: 11px; font-weight: 800; line-height: 1; display: inline-flex; align-items: center; justify-content: center; margin-left: 5px; background: #fff; cursor: pointer; flex: none; padding: 0; }
.dbh:hover, .dbh[aria-expanded="true"] { background: var(--ds-btn); border-color: var(--ds-btn); color: #fff; }
.fd-kpi.dark .dbh { background: transparent; border-color: #8aa4b8; color: #c9d3db; }
#dbPop { position: absolute; z-index: 90; max-width: 290px; background: var(--ds-btn); color: #fff; font-size: 13px; line-height: 1.45; padding: 11px 13px; border-radius: 11px; box-shadow: 0 12px 30px rgba(31,42,48,.25); display: none; }
#dbPop.on { display: block; }
.fd-filters[hidden], #drawer[hidden] { display: none; }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
@media (max-width: 1024px) { .fd-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } .fd-filters { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 760px) { .fd-grid2 { grid-template-columns: minmax(0, 1fr); } .fd-bar { position: static; } .fd-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); } .fd-kpi .v { font-size: 19px; } }
@media (max-width: 420px) { .fd-kpis { grid-template-columns: minmax(0, 1fr); } }
@media (prefers-reduced-motion: reduce) { .sk { animation: none; } }
</style>

<main class="fd" id="fd">
    <a href="public_infos.php" class="mk-back"><?= mk_icon('back', 16) ?> Mes organismes</a>
    <?php if ($error): ?><div class="ds-alert-err" style="margin:12px 0" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="ds-alert-ok" style="margin:12px 0" role="status"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <header class="fd-top" style="margin-top:10px">
        <div class="fd-id">
            <?php if (!empty($orga['logo_url'])): ?><img src="<?= htmlspecialchars($orga['logo_url']) ?>" alt=""><?php endif; ?>
            <div style="min-width:0"><p class="ds-eyebrow" style="margin:0">Finances</p><h1><?= htmlspecialchars($orga['nom']) ?></h1>
                <div class="fd-acct"><span class="mk-pill <?= $ready ? 'ok' : 'warn' ?>"><?= $ready ? 'Compte Stripe actif' : 'Compte Stripe à compléter' ?></span>
                    <?= $help($ready ? "Les paiements arrivent sur votre compte Stripe, puis sur votre banque : " . $autoTxt . ". Stripe garde votre IBAN : moncamp.fr ne le voit pas." : "Pour recevoir l'argent des inscriptions, complétez votre compte chez Stripe (identité, IBAN : 5 minutes)." . ($due ? " Informations restantes : " . count($due) . "." : "")) ?>
                    <?php if (!$ready): ?><a class="underline font-semibold" href="<?= $hasAccount ? 'api/connect_onboarding.php?o=' : 'connect_statut.php?o=' ?><?= $orgaTok ?>">Compléter</a>
                    <?php elseif (($acc['type'] ?? '') === 'express'): ?><a class="underline" href="api/connect_dashboard.php?o=<?= $orgaTok ?>" target="_blank" rel="noopener">Espace Stripe ↗</a><?php endif; ?>
                    <span>· Commission <?= htmlspecialchars($comTxt) ?></span></div>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a class="fd-btn" href="mes_camps.php">Séjours et inscrits</a>
            <a class="fd-btn" href="edit_organisateur.php?o=<?= $orgaTok ?>">Mon organisme</a>
            <a class="fd-btn" href="messagerie.php">Messages</a>
            <a class="fd-btn" href="annulations_organisme.php?o=<?= $orgaTok ?>">Annulations<?= $nbAnnul ? ' (' . $nbAnnul . ')' : '' ?></a>
            <a class="fd-btn" href="marketing.php?o=<?= $orgaTok ?>">Marketing</a>
            <a class="fd-btn" href="aide_paiements.php">Aide</a>
        </div>
    </header>

    <div class="fd-bar" role="group" aria-label="Période et exports">
        <label class="sr" for="pPreset">Période</label>
        <select id="pPreset" class="ds-select">
            <option value="7">7 derniers jours</option><option value="30">30 derniers jours</option><option value="mois">Ce mois-ci</option><option value="mprec">Mois dernier</option>
            <option value="90">90 derniers jours</option><option value="annee">Année en cours</option><option value="365" selected>12 derniers mois</option><option value="tout">Depuis le début</option><option value="perso">Personnalisée…</option>
        </select>
        <label class="sr" for="pDu">Du</label><input id="pDu" type="date" class="ds-input">
        <label class="sr" for="pAu">Au</label><input id="pAu" type="date" class="ds-input">
        <button type="button" id="pGo" class="fd-btn pri">Afficher</button>
        <span class="grow mut" id="pCmp" style="font-size:12.5px;color:var(--ds-muted)"></span>
        <div class="fd-menu" id="expMenu"><button type="button" class="fd-btn" aria-haspopup="true" aria-expanded="false"><?= mk_icon('doc', 16) ?> Exporter ▾</button>
            <div role="menu">
                <button type="button" class="it" role="menuitem" data-exp="tx" data-fmt="xlsx">Transactions · Excel</button>
                <button type="button" class="it" role="menuitem" data-exp="tx" data-fmt="csv">Transactions · CSV</button>
                <button type="button" class="it" role="menuitem" data-exp="mois" data-fmt="xlsx">Rapport mensuel · Excel</button>
                <button type="button" class="it" role="menuitem" data-exp="mois" data-fmt="csv">Rapport mensuel · CSV</button>
                <a role="menuitem" href="pdf_export_virements_history.php?o=<?= $orgaTok ?>" target="_blank" rel="noopener">Historique des versements · PDF</a>
            </div></div>
    </div>

    <nav class="fd-tabs" role="tablist" aria-label="Sections">
        <?php foreach ($tabs as $k => $l): ?><button type="button" class="fd-tab" role="tab" id="tab-<?= $k ?>" aria-controls="pan-<?= $k ?>" aria-selected="false" tabindex="-1" data-tab="<?= $k ?>"><?= $l ?><?php if ($k === 'hors'): ?><span class="ct" id="ctHors" hidden></span><?php endif; ?></button><?php endforeach; ?>
    </nav>

    <!-- Vue d'ensemble -->
    <section class="fd-panel" role="tabpanel" id="pan-apercu" aria-labelledby="tab-apercu" hidden>
        <div class="fd-kpis" id="kpis" aria-live="polite">
            <?php foreach ([['brut', 'Encaissé brut', 'Paiements par carte reçus sur la période (avant commission, frais et remboursements).'], ['net', 'Net pour vous', 'Encaissé − commission − frais Stripe − remboursements (± ajustements).', 'dark'],
                            ['com', 'Commission moncamp.fr', "Commission : $comTxt par inscription. Jamais rendue en cas d'annulation."], ['frais', 'Frais Stripe', "Frais réels de Stripe pour chaque paiement (et litige), débités par Stripe sur votre compte."],
                            ['remb', 'Remboursé', 'Montants rendus aux familles sur la période.'], ['attente', 'En attente de versement', "Déjà sur votre compte Stripe (ou chez moncamp.fr avant l'activation de votre compte), $autoTxt. Montant à ce jour."],
                            ['prochain', 'Prochain versement', "Versement en route vers votre banque, avec la date d'arrivée annoncée par Stripe."], ['dette', 'Dette compensée', "Remboursement avancé par moncamp.fr quand votre solde Stripe ne suffisait pas. Retenu automatiquement sur vos prochains paiements."]] as $k): ?>
                <div class="fd-kpi <?= $k[3] ?? '' ?>" id="kp-<?= $k[0] ?>"><div class="k"><?= $k[1] ?><?= $help($k[2]) ?></div><div class="v"><span class="sk" style="width:60%"></span></div><div class="s"></div></div>
            <?php endforeach; ?>
        </div>
        <div class="fd-grid2">
            <div class="fd-card"><h2 class="fd-h"><span>Où va l'argent<?= $help("Sur la période choisie. Cliquez une ligne pour voir les transactions concernées.") ?></span></h2><div class="fd-wf" id="wf"><span class="sk"></span></div>
                <p id="wfNote" style="font-size:12.5px;color:var(--ds-muted);margin:10px 0 0"></p></div>
            <div class="fd-card"><h2 class="fd-h"><span>Encaissements</span><span class="fd-seg" role="group" aria-label="Regroupement" id="gran"><button type="button" data-g="j" aria-pressed="false">Jour</button><button type="button" data-g="s" aria-pressed="false">Semaine</button><button type="button" data-g="m" aria-pressed="false">Mois</button></span></h2>
                <svg id="chCa" class="fd-chart" role="img" aria-label="Encaissements bruts et nets par période"></svg><div class="fd-leg"><span><i style="background:#2f3d45"></i>Brut</span><span><i style="background:#8aa4b8"></i>Net pour vous</span></div></div>
            <div class="fd-card"><h2 class="fd-h">Inscriptions</h2><svg id="chIns" class="fd-chart" role="img" aria-label="Inscriptions par période"></svg></div>
            <div class="fd-card"><h2 class="fd-h">Moyens de paiement<?= $help("Inscriptions de la période par moyen de paiement (montant payé).") ?></h2><div id="moy" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap"><span class="sk"></span></div></div>
            <div class="fd-card" style="grid-column:1/-1"><h2 class="fd-h"><span>Remplissage par séjour et session<?= $help("Sessions en cours, à venir, ou de la période : inscrits sur places.") ?></span></h2><div id="fill" style="columns:2 320px;column-gap:28px"><span class="sk"></span></div></div>
        </div>
        <?php if ($historique > 0): ?><div class="ds-alert-ok" style="margin-top:12px">Solde de l'ancien système : <?= fin_eur($historique) ?> <?= $help("Réglé par l'équipe moncamp.fr : contactez-nous pour le recevoir.") ?></div><?php endif; ?>
    </section>

    <!-- Transactions -->
    <section class="fd-panel" role="tabpanel" id="pan-transactions" aria-labelledby="tab-transactions" hidden>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <label class="sr" for="tQ">Rechercher</label><input id="tQ" type="search" class="ds-input" placeholder="Rechercher famille, enfant, séjour, référence…" style="flex:1;min-width:220px;padding:9px 12px;font-size:14px">
            <button type="button" class="fd-btn" id="tFiltBtn" aria-expanded="false" aria-controls="tFilt">Filtres <span id="tFiltN"></span></button>
            <span id="tSelInfo" style="font-size:13px;color:var(--ds-muted)"></span>
            <button type="button" class="fd-btn" id="tExpSel" data-fmt="xlsx" disabled>Exporter la sélection · Excel</button>
            <button type="button" class="fd-btn" id="tExpSelCsv" data-fmt="csv" disabled>CSV</button>
        </div>
        <div id="tFilt" class="fd-filters" hidden>
            <label><span>Séjour</span><select id="fSej" class="ds-select"><option value="">Tous</option></select></label>
            <label><span>Session</span><select id="fSes" class="ds-select"><option value="">Toutes</option></select></label>
            <label><span>Statut</span><select id="fSt" class="ds-select"><option value="">Tous</option><option value="attente">En attente de versement</option><option value="en_cours">Versement en cours</option><option value="verse">Versé</option><option value="partiel">Remboursé en partie</option><option value="rembourse">Remboursé</option><option value="annule">Annulé</option><option value="litige">Litige</option><option value="bloque">Suspendu</option><option value="PAYE">Reçu hors carte</option><option value="A_REGLER">À encaisser</option></select></label>
            <label><span>Moyen</span><select id="fMoy" class="ds-select"><option value="">Tous</option></select></label>
            <label><span>Du</span><input id="fDu" type="date" class="ds-input"></label>
            <label><span>Au</span><input id="fAu" type="date" class="ds-input"></label>
            <div style="display:flex;gap:6px"><button type="button" class="fd-btn pri" id="fApply">Appliquer</button><button type="button" class="fd-btn" id="fReset">Effacer</button></div>
        </div>
        <div class="fd-tw"><table class="fd-t" aria-describedby="tCount">
            <thead><tr><th style="width:36px;padding-left:12px"><input type="checkbox" id="tAll" aria-label="Tout sélectionner (page)"></th>
                <?php foreach ([['d', 'Date', ''], ['fam', 'Famille', ''], ['camp', 'Séjour', ''], ['moyl', 'Moyen', ''], ['brut', 'Payé', 'num'], ['com', 'Commission', 'num'], ['frais', 'Frais Stripe', 'num'], ['remb', 'Remboursé', 'num'], ['pv', 'Net', 'num'], ['stl', 'Statut', '']] as $c): ?>
                <th class="<?= $c[2] ?>" aria-sort="none" data-col="<?= $c[0] ?>"><button type="button" class="fd-th"><?= $c[1] ?></button></th><?php endforeach; ?></tr></thead>
            <tbody id="tBody"><?php for ($i = 0; $i < 6; $i++): ?><tr><td colspan="11"><span class="sk" style="width:<?= 90 - $i * 7 ?>%"></span></td></tr><?php endfor; ?></tbody>
            <tfoot id="tFoot"></tfoot>
        </table></div>
        <div class="fd-pg"><span id="tCount" aria-live="polite"></span>
            <span style="display:flex;gap:6px;align-items:center"><label for="tPer" class="sr">Lignes par page</label><select id="tPer" class="ds-select" style="width:auto;padding:6px 10px;font-size:13px"><option>25</option><option selected>50</option><option>100</option></select>
                <button type="button" class="fd-btn" id="tPrev" aria-label="Page précédente">‹</button><span id="tPage"></span><button type="button" class="fd-btn" id="tNext" aria-label="Page suivante">›</button></span></div>
    </section>

    <!-- Versements -->
    <section class="fd-panel" role="tabpanel" id="pan-versements" aria-labelledby="tab-versements" hidden>
        <div class="fd-grid2" style="margin-top:0">
            <div class="fd-card"><h2 class="fd-h"><span>Versements en route<?= $help("Versements automatiques Stripe : " . $autoTxt . ". Date d'arrivée annoncée par Stripe.") ?></span></h2><div id="vNext"><span class="sk"></span></div></div>
            <div class="fd-card"><h2 class="fd-h">Versé sur votre banque</h2><dl class="fd-dl" id="vTot"><dt><span class="sk"></span></dt><dd></dd></dl></div>
        </div>
        <h2 class="fd-sub">Historique</h2>
        <div class="fd-tw"><table class="fd-t" style="min-width:720px"><thead><tr><th><span class="fd-th" style="cursor:default">Date</span></th><th class="num"><span class="fd-th" style="cursor:default">Montant</span></th><th><span class="fd-th" style="cursor:default">Statut</span></th><th><span class="fd-th" style="cursor:default">Arrivée</span></th><th class="num"><span class="fd-th" style="cursor:default">Transactions</span></th><th></th></tr></thead><tbody id="vBody"></tbody></table></div>
    </section>

    <!-- Remboursements & litiges -->
    <section class="fd-panel" role="tabpanel" id="pan-litiges" aria-labelledby="tab-litiges" hidden>
        <div class="fd-kpis" id="lKpi"></div>
        <h2 class="fd-sub">Transactions remboursées ou en litige (période)</h2>
        <div class="fd-tw"><table class="fd-t" style="min-width:720px"><thead><tr><th><span class="fd-th" style="cursor:default">Date</span></th><th><span class="fd-th" style="cursor:default">Famille</span></th><th><span class="fd-th" style="cursor:default">Séjour</span></th><th class="num"><span class="fd-th" style="cursor:default">Payé</span></th><th class="num"><span class="fd-th" style="cursor:default">Remboursé</span></th><th><span class="fd-th" style="cursor:default">Statut</span></th></tr></thead><tbody id="lBody"></tbody></table></div>
        <h2 class="fd-sub">Annulations des familles<?= $help("Les conditions d'annulation se règlent séjour par séjour. La commission moncamp.fr n'est pas rendue.") ?></h2>
        <div class="fd-tw"><table class="fd-t" style="min-width:720px"><thead><tr><th><span class="fd-th" style="cursor:default">Date</span></th><th><span class="fd-th" style="cursor:default">Famille</span></th><th><span class="fd-th" style="cursor:default">Séjour</span></th><th class="num"><span class="fd-th" style="cursor:default">Part remboursée</span></th><th class="num"><span class="fd-th" style="cursor:default">Remboursé</span></th><th class="num"><span class="fd-th" style="cursor:default">Commission conservée</span></th></tr></thead><tbody id="aBody"></tbody></table></div>
        <p style="margin-top:10px"><a class="underline" href="annulations_organisme.php?o=<?= $orgaTok ?>">Conditions d'annulation par séjour →</a></p>
    </section>

    <!-- Dette -->
    <section class="fd-panel" role="tabpanel" id="pan-dette" aria-labelledby="tab-dette" hidden>
        <div class="fd-card" style="max-width:720px"><h2 class="fd-h"><span>Dette et compensations<?= $help("Quand une famille est remboursée alors que votre solde Stripe ne suffit pas, moncamp.fr avance la différence. Elle est retenue avec la commission sur vos paiements par carte suivants, jamais plus que leur montant. Aucune démarche.") ?></span></h2>
            <dl class="fd-dl" id="dTot"></dl>
            <div class="fd-tw" style="margin-top:14px"><table class="fd-t" style="min-width:480px"><thead><tr><th><span class="fd-th" style="cursor:default">Date</span></th><th><span class="fd-th" style="cursor:default">Opération</span></th><th class="num"><span class="fd-th" style="cursor:default">Montant</span></th></tr></thead><tbody id="dBody"></tbody></table></div></div>
    </section>

    <!-- Hors carte -->
    <section class="fd-panel" role="tabpanel" id="pan-hors" aria-labelledby="tab-hors" hidden>
        <p style="font-size:14px;color:var(--ds-muted);margin:0 0 10px">Règlements choisis par les familles hors carte (chèque, virement, espèces, ANCV…), pas encore reçus. Marquez-les reçus à l'encaissement<?= $help("Commission moncamp.fr sur les paiements hors carte : " . number_format(fin_hors_stripe_pct(), 1, ',', ' ') . " %, retenue sur vos prochains versements par carte.") ?></p>
        <div class="fd-tw"><table class="fd-t" style="min-width:720px"><thead><tr><th><span class="fd-th" style="cursor:default">Séjour</span></th><th><span class="fd-th" style="cursor:default">Famille</span></th><th><span class="fd-th" style="cursor:default">Moyen</span></th><th class="num"><span class="fd-th" style="cursor:default">Montant</span></th><th><span class="fd-th" style="cursor:default">Inscrit le</span></th><th></th></tr></thead><tbody id="hBody"></tbody></table></div>
    </section>

    <!-- Rapports -->
    <section class="fd-panel" role="tabpanel" id="pan-rapports" aria-labelledby="tab-rapports" hidden>
        <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><h2 class="fd-h" style="margin:0">Rapport mensuel (période)<?= $help("Pour votre comptable : une ligne par mois, issue du journal comptable. TVA incluse calculée si un taux est renseigné.") ?></h2>
            <span style="display:flex;gap:6px"><button type="button" class="fd-btn" data-exp="mois" data-fmt="xlsx">Excel</button><button type="button" class="fd-btn" data-exp="mois" data-fmt="csv">CSV</button></span></div>
        <div class="fd-tw"><table class="fd-t" style="min-width:980px"><thead><tr><?php foreach (['Mois', 'Encaissé', 'Commission', 'Frais Stripe', 'Remboursé', 'Net', 'Versé', 'Dette compensée', 'Hors carte reçu', 'TVA incluse'] as $i => $h): ?><th class="<?= $i ? 'num' : '' ?>"><span class="fd-th" style="cursor:default"><?= $h ?></span></th><?php endforeach; ?></tr></thead><tbody id="rBody"></tbody><tfoot id="rFoot"></tfoot></table></div>
        <form method="post" class="fd-card" style="margin-top:14px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between">
            <b style="color:var(--ds-ink)">TVA de vos séjours<?= $help("Prix TTC : les familles ne paient rien de plus. Si vous êtes assujetti, indiquez votre taux : la TVA incluse figurera sur leurs reçus et dans ce rapport. Ne remplace pas l'avis d'un comptable.") ?></b>
            <span style="display:flex;gap:8px;align-items:center">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(fin_csrf()) ?>"><input type="hidden" name="act" value="tva">
                <select class="ds-select" name="tva" aria-label="Taux de TVA" style="width:auto"><?php foreach (fin_tva_taux_permis() as $k => $l): ?><option value="<?= htmlspecialchars((string)$k) ?>"<?= (string)$k === $tvaCur ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?></select>
                <button class="fd-btn" type="submit">Enregistrer</button></span>
        </form>
    </section>
    <p id="loadErr" class="ds-alert-err" role="alert" hidden style="margin-top:14px">Données indisponibles pour le moment. <button type="button" class="underline" id="retry">Réessayer</button></p>
</main>

<div class="ds-scrim" id="dScrim"></div>
<aside class="ds-drawer" id="drawer" role="dialog" aria-modal="true" aria-labelledby="drTitle" style="width:min(480px,100%)" hidden>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--ds-line)"><h2 id="drTitle" style="font-size:17px;font-weight:800;color:var(--ds-ink);margin:0">Transaction</h2><button type="button" class="fd-btn" id="drClose" aria-label="Fermer">✕</button></div>
    <div id="drBody" style="padding:18px 20px;overflow-y:auto;flex:1"></div>
</aside>
<div id="dbPop" role="tooltip"></div>
<form id="expForm" method="post" action="api/dashboard_stats.php" target="_blank" hidden><input name="o" value="<?= $orgaTok ?>"><input name="du"><input name="au"><input name="export"><input name="fmt"><input name="keys"></form>

<script>
(function () {
    'use strict';
    var ORGA = <?= json_encode($orgaTok) ?>, CSRF = <?= json_encode(fin_csrf()) ?>, EXPRESS = <?= json_encode(($acc['type'] ?? '') === 'express') ?>;
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]; }); };
    var eur = function (c) { return ((c || 0) / 100).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' }); };
    var eur0 = function (c) { return ((c || 0) / 100).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }); };
    var z = function (n) { return ('0' + n).slice(-2); };
    var iso = function (d) { return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate()); };
    var dfr = function (s) { return s ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4) : ''; };
    var MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    var PILL = { attente: 'info', en_cours: 'info', verse: 'ok', partiel: 'warn', rembourse: 'bad', annule: 'bad', litige: 'bad', bloque: 'warn', PAYE: 'ok', A_REGLER: 'warn' };
    var D = null, GRAN = '', seq = 0;

    // ---------- période
    function range(p) {
        var e = new Date(), s = new Date();
        if (p === 'mois') s = new Date(e.getFullYear(), e.getMonth(), 1);
        else if (p === 'mprec') { s = new Date(e.getFullYear(), e.getMonth() - 1, 1); e = new Date(e.getFullYear(), e.getMonth(), 0); }
        else if (p === 'annee') s = new Date(e.getFullYear(), 0, 1);
        else if (p === 'tout') s = new Date(2020, 0, 1);
        else s.setDate(s.getDate() - (+p - 1));
        return [iso(s), iso(e)];
    }
    function setPreset() { var p = $('pPreset').value; if (p !== 'perso') { var r = range(p); $('pDu').value = r[0]; $('pAu').value = r[1]; } }
    $('pPreset').addEventListener('change', function () { setPreset(); if (this.value !== 'perso') load(); else $('pDu').focus(); });
    $('pGo').addEventListener('click', function () { $('pPreset').value = 'perso'; load(); });
    [$('pDu'), $('pAu')].forEach(function (el) { el.addEventListener('keydown', function (e) { if (e.key === 'Enter') load(); }); });

    function load() {
        var my = ++seq, q = new URLSearchParams({ o: ORGA, du: $('pDu').value, au: $('pAu').value });
        $('loadErr').hidden = true; document.body.style.cursor = 'progress';
        fetch('api/dashboard_stats.php?' + q, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw 0; return r.json(); })
            .then(function (d) { if (my !== seq) return; document.body.style.cursor = ''; if (d.error) throw 0; D = d; $('pDu').value = d.periode.du; $('pAu').value = d.periode.au; renderAll(); })
            .catch(function () { if (my === seq) { document.body.style.cursor = ''; $('loadErr').hidden = false; } });
    }
    $('retry').addEventListener('click', load);

    // ---------- onglets (#hash, flèches)
    var TABS = [].slice.call(document.querySelectorAll('.fd-tab'));
    function showTab(k, focus) {
        if (!$('pan-' + k)) k = 'apercu';
        TABS.forEach(function (t) { var on = t.dataset.tab === k; t.setAttribute('aria-selected', on); t.tabIndex = on ? 0 : -1; $('pan-' + t.dataset.tab).hidden = !on; if (on && focus) t.focus(); });
        if (location.hash !== '#' + k) history.replaceState(null, '', '#' + k);
    }
    TABS.forEach(function (t, i) {
        t.addEventListener('click', function () { showTab(t.dataset.tab); });
        t.addEventListener('keydown', function (e) { var j = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : e.key === 'Home' ? 0 : e.key === 'End' ? TABS.length - 1 : null; if (j === null) return; e.preventDefault(); showTab(TABS[(j + TABS.length) % TABS.length].dataset.tab, true); });
    });

    // ---------- vue d'ensemble
    function delta(c, p, goodUp) {
        if (!p && !c) return '<span class="fd-d flat">=</span> vs période préc.';
        if (!p) return '<span class="fd-d flat">nouveau</span> vs période préc.';
        var v = Math.round((c - p) / Math.abs(p) * 100), cls = v === 0 ? 'flat' : ((v > 0) === goodUp ? 'up' : 'down');
        if (goodUp === null) cls = 'flat';
        return '<span class="fd-d ' + cls + '">' + (v > 0 ? '▲ +' : v < 0 ? '▼ ' : '') + v + ' %</span> vs ' + eur0(p);
    }
    function kpi(k, v, s) { var el = $('kp-' + k); el.querySelector('.v').textContent = v; el.querySelector('.s').innerHTML = s || ''; }
    function renderKpis() {
        var c = D.kpi.cur, p = D.kpi.prev;
        kpi('brut', eur(c.brut), delta(c.brut, p.brut, true));
        kpi('net', eur(c.net), delta(c.net, p.net, true));
        kpi('com', eur(c.com), delta(c.com, p.com, null));
        kpi('frais', eur(c.frais), delta(c.frais, p.frais, false));
        kpi('remb', eur(c.remb), delta(c.remb, p.remb, false));
        kpi('attente', eur(D.kpi.en_attente), 'à ce jour');
        kpi('prochain', D.kpi.prochain ? eur(D.kpi.prochain.m) : '–', D.kpi.prochain ? 'arrivée prévue le ' + dfr(D.kpi.prochain.d) : 'automatique, dès que disponible');
        kpi('dette', eur(c.compense), D.kpi.dette > 0 ? '<b style="color:#b42318">Reste dû : ' + eur(D.kpi.dette) + '</b>' : 'aucune dette en cours');
        $('kp-dette').classList.toggle('alert', D.kpi.dette > 0);
        $('pCmp').textContent = 'Comparé au ' + dfr(D.periode.prev_du) + ' – ' + dfr(D.periode.prev_au);
    }
    function renderWf() {
        var c = D.kpi.cur, max = Math.max(c.brut, 1), rows = [
            ['Encaissé par les familles', c.brut, '', ''], ['Commission moncamp.fr', -c.com, 'n', 'com'], ['Frais Stripe', -c.frais, 'n', 'frais'], ['Remboursements', -c.remb, 'r', 'remb']];
        if (c.ajust) rows.push(['Points marketing payés avec vos gains', c.ajust, 'n', '']);
        rows.push(['Net pour vous', c.net, 'tot', '']);
        rows.push(['Versé sur votre banque', -c.verse, 'n', 'verse'], ['En attente de versement (à ce jour)', D.kpi.en_attente, 'n', 'attente']);
        if (c.compense || D.kpi.dette) rows.push(['Dette compensée', -c.compense, 'r', 'dette']);
        $('wf').innerHTML = rows.map(function (r) {
            var neg = r[1] < 0, w = Math.max(2, Math.round(Math.abs(r[1]) / max * 90)), tot = r[2] === 'tot';
            var act = r[3] ? ' role="button" tabindex="0" data-wf="' + r[3] + '" style="cursor:pointer"' : '';
            return '<div class="l' + (tot ? ' tot' : '') + '"' + act + '><span class="bar ' + (r[2] === 'tot' ? '' : r[2]) + '" style="width:' + w + 'px"></span><span' + (neg ? ' class="neg"' : '') + '>' + (tot ? '<b>' + r[0] + '</b>' : esc(r[0])) + '</span></div><div class="a' + (tot ? ' tot' : '') + (neg ? ' neg' : '') + '">' + (neg ? '− ' + eur(-r[1]) : eur(r[1])) + '</div>';
        }).join('');
        $('wfNote').textContent = (D.prochains.length ? 'Versement en route : ' + eur(D.prochains[0].m) + ', arrivée prévue le ' + dfr(D.prochains[0].d) + '. ' : '') + 'Versé automatiquement par Stripe dès que les fonds sont disponibles (2 à 7 jours ouvrés).';
    }
    $('wf').addEventListener('click', function (e) { var b = e.target.closest('[data-wf]'); if (!b) return; wfGo(b.dataset.wf); });
    $('wf').addEventListener('keydown', function (e) { var b = e.target.closest('[data-wf]'); if (b && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); wfGo(b.dataset.wf); } });
    function wfGo(k) {
        if (k === 'verse' || k === 'attente') { showTab('versements'); return; }
        if (k === 'dette') { showTab('dette'); return; }
        resetFilters(); T.pre = k; T.page = 0; table(); showTab('transactions');
    }

    function buckets(g) {
        var out = [], idx = {};
        D.jours.forEach(function (j) {
            var d = new Date(j.d + 'T12:00:00'), key, lab;
            if (g === 'j') { key = j.d; lab = d.getDate() + ' ' + MOIS[d.getMonth()]; }
            else if (g === 's') { var m = new Date(d); m.setDate(d.getDate() - (d.getDay() + 6) % 7); key = iso(m); lab = 'sem. ' + m.getDate() + ' ' + MOIS[m.getMonth()]; }
            else { key = j.d.slice(0, 7); lab = MOIS[d.getMonth()] + ' ' + String(d.getFullYear()).slice(2); }
            if (!(key in idx)) { idx[key] = out.length; out.push({ l: lab, brut: 0, net: 0, ins: 0 }); }
            var b = out[idx[key]]; b.brut += j.brut; b.net += j.net; b.ins += j.ins;
        });
        return out;
    }
    var NS = 'http://www.w3.org/2000/svg';
    function bars(id, data, keys, cols, fmt) {
        var s = $(id), W = 560, H = 200, pl = 6, pr = 6, pt = 16, pb = 22, n = data.length;
        s.setAttribute('viewBox', '0 0 ' + W + ' ' + H); s.setAttribute('preserveAspectRatio', 'none');
        var max = 1; data.forEach(function (b) { keys.forEach(function (k) { max = Math.max(max, b[k]); }); });
        if (!data.some(function (b) { return keys.some(function (k) { return b[k]; }); })) { s.innerHTML = '<text class="fd-ax" x="' + W / 2 + '" y="' + H / 2 + '" text-anchor="middle" style="font-size:13px">Rien sur cette période</text>'; return; }
        var bw = (W - pl - pr) / n, gap = Math.min(4, bw * .2), y = function (v) { return H - pb - Math.max(0, v) / max * (H - pt - pb); }, h = '', step = Math.ceil(n / 8);
        [.5, 1].forEach(function (t) { h += '<line x1="' + pl + '" x2="' + (W - pr) + '" y1="' + y(max * t) + '" y2="' + y(max * t) + '" stroke="#eef1f3"/>'; });
        h += '<line x1="' + pl + '" x2="' + (W - pr) + '" y1="' + y(0) + '" y2="' + y(0) + '" stroke="#d3dbe0"/>';
        data.forEach(function (b, i) {
            var x0 = pl + i * bw + gap / 2, w = (bw - gap) / keys.length;
            keys.forEach(function (k, j) { h += '<rect x="' + (x0 + j * w) + '" y="' + y(b[k]) + '" width="' + Math.max(1, w - (keys.length > 1 ? 1 : 0)) + '" height="' + (y(0) - y(b[k])) + '" rx="' + Math.min(3, w / 3) + '" fill="' + cols[j] + '"><title>' + esc(b.l) + ' : ' + keys.map(function (kk) { return fmt(b[kk]); }).join(' / ') + '</title></rect>'; });
            if (i % step === (n - 1) % step) h += '<text class="fd-ax" x="' + (x0 + (bw - gap) / 2) + '" y="' + (H - 6) + '" text-anchor="middle">' + esc(b.l) + '</text>';
        });
        h += '<text class="fd-ax" x="' + pl + '" y="' + (pt - 5) + '">' + fmt(max) + '</text>';
        s.innerHTML = h;
    }
    function renderCharts() {
        if (!GRAN) GRAN = D.jours.length <= 45 ? 'j' : D.jours.length <= 200 ? 's' : 'm';
        [].forEach.call($('gran').children, function (b) { b.setAttribute('aria-pressed', b.dataset.g === GRAN); });
        var b = buckets(GRAN);
        bars('chCa', b, ['brut', 'net'], ['#2f3d45', '#8aa4b8'], function (v) { return eur0(v); });
        bars('chIns', b, ['ins'], ['#3d6a8e'], function (v) { return Math.round(v) + ' inscr.'; });
        var tot = D.moyens.reduce(function (a, m) { return a + m.n; }, 0), C = ['#2f3d45', '#3d6a8e', '#8aa4b8', '#c9d3db', '#6b7780', '#b9a96b', '#d9c99a'];
        if (!tot) { $('moy').innerHTML = '<div class="fd-empty" style="width:100%"><b>Aucune inscription</b>sur cette période.</div>'; }
        else {
            var a0 = -Math.PI / 2, svg = '', leg = '';
            D.moyens.forEach(function (m, i) {
                var a1 = a0 + m.n / tot * Math.PI * 2, big = a1 - a0 > Math.PI ? 1 : 0, c = C[i % C.length], p = function (r, a) { return (60 + r * Math.cos(a)) + ' ' + (60 + r * Math.sin(a)); };
                svg += D.moyens.length === 1 ? '<circle cx="60" cy="60" r="42" fill="none" stroke="' + c + '" stroke-width="20"/>' : '<path d="M' + p(52, a0) + ' A52 52 0 ' + big + ' 1 ' + p(52, a1) + ' L' + p(32, a1) + ' A32 32 0 ' + big + ' 0 ' + p(32, a0) + 'Z" fill="' + c + '"><title>' + esc(m.l) + '</title></path>';
                leg += '<div style="display:flex;justify-content:space-between;gap:10px;font-size:13px;padding:3px 0"><span><i style="display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;background:' + c + '"></i>' + esc(m.l) + '</span><b style="font-variant-numeric:tabular-nums">' + Math.round(m.n / tot * 100) + ' % · ' + m.n + (m.montant ? ' · ' + eur0(m.montant) : '') + '</b></div>';
                a0 = a1;
            });
            $('moy').innerHTML = '<svg viewBox="0 0 120 120" style="width:120px;height:120px;flex:none" role="img" aria-label="Répartition des moyens de paiement">' + svg + '</svg><div style="flex:1;min-width:200px">' + leg + '</div>';
        }
        $('fill').innerHTML = D.remplissage.length ? D.remplissage.map(function (s) {
            return '<div class="fd-fill" style="break-inside:avoid"><b>' + esc(s.nom) + '</b><span>' + s.ins + (s.quota ? '/' + s.quota : '') + '</span><small>' + esc(s.session) + '</small><div class="tr" role="img" aria-label="' + (s.pct === null ? 'places non limitées' : s.pct + ' % rempli') + '"><i style="width:' + (s.pct === null ? 0 : s.pct) + '%"></i></div></div>';
        }).join('') : '<div class="fd-empty"><b>Aucun séjour en cours ou à venir</b><a class="underline" href="create_camp.php">Créer un séjour</a></div>';
    }
    $('gran').addEventListener('click', function (e) { var b = e.target.closest('[data-g]'); if (b && D) { GRAN = b.dataset.g; renderCharts(); } });

    // ---------- transactions
    var T = { q: '', sort: 'd', dir: -1, page: 0, per: 50, sel: {}, f: {}, pre: '' }, VIEW = [];
    var PRE = { com: [function (r) { return r.com > 0; }, 'avec commission'], frais: [function (r) { return r.frais > 0; }, 'avec frais Stripe'], remb: [function (r) { return r.remb > 0; }, 'remboursées'] };
    function fill(id, rows, val) { var el = $(id), first = el.options[0].outerHTML; el.innerHTML = first + rows.map(function (r) { return '<option value="' + esc(r[0]) + '">' + esc(r[1]) + '</option>'; }).join(''); el.value = val || ''; }
    function sesOpts() { var sj = $('fSej').value; fill('fSes', D.filtres.sessions.filter(function (s) { return !sj || s.s === sj; }).map(function (s) { return [s.t, s.l]; }), ''); }
    $('fSej').addEventListener('change', sesOpts);
    function readFilters() { T.f = { sej: $('fSej').value, ses: $('fSes').value, st: $('fSt').value, moy: $('fMoy').value, du: $('fDu').value, au: $('fAu').value }; }
    function resetFilters() { ['fSej', 'fSt', 'fMoy', 'fDu', 'fAu'].forEach(function (i) { $(i).value = ''; }); sesOpts(); T.f = {}; T.pre = ''; }
    $('fApply').addEventListener('click', function () { readFilters(); T.pre = ''; T.page = 0; table(); });
    $('fReset').addEventListener('click', function () { resetFilters(); T.page = 0; table(); });
    $('tFiltBtn').addEventListener('click', function () { var o = $('tFilt').hidden; $('tFilt').hidden = !o; this.setAttribute('aria-expanded', o); });
    var qt; $('tQ').addEventListener('input', function () { clearTimeout(qt); qt = setTimeout(function () { T.q = $('tQ').value.trim().toLowerCase(); T.page = 0; table(); }, 150); });
    document.querySelectorAll('.fd-t th[data-col] .fd-th').forEach(function (b) { b.addEventListener('click', function () {
        var c = b.parentNode.dataset.col; T.dir = T.sort === c ? -T.dir : (['d', 'brut', 'com', 'frais', 'remb', 'pv'].indexOf(c) >= 0 ? -1 : 1); T.sort = c; table();
    }); });
    $('tPer').addEventListener('change', function () { T.per = +this.value; T.page = 0; table(); });
    $('tPrev').addEventListener('click', function () { if (T.page > 0) { T.page--; table(); } });
    $('tNext').addEventListener('click', function () { if ((T.page + 1) * T.per < VIEW.length) { T.page++; table(); } });
    function match(r) {
        var f = T.f;
        if (T.pre && !PRE[T.pre][0](r)) return false;
        if (f.sej && r.sej !== f.sej) return false; if (f.ses && r.ses !== f.ses) return false;
        if (f.st && r.st !== f.st) return false; if (f.moy && r.moy !== f.moy) return false;
        if (f.du && r.d.slice(0, 10) < f.du) return false; if (f.au && r.d.slice(0, 10) > f.au) return false;
        if (T.q && (r.fam + ' ' + r.enf + ' ' + r.parent + ' ' + r.camp + ' ' + r.sesl + ' ' + (r.pi || '') + ' ' + r.stl + ' ' + r.moyl).toLowerCase().indexOf(T.q) < 0) return false;
        return true;
    }
    function table() {
        if (!D) return;
        VIEW = D.tx.filter(match);
        VIEW.sort(function (a, b) { var x = a[T.sort], y = b[T.sort]; return (typeof x === 'number' ? x - y : String(x).localeCompare(String(y), 'fr')) * T.dir; });
        document.querySelectorAll('.fd-t th[data-col]').forEach(function (th) { th.setAttribute('aria-sort', th.dataset.col === T.sort ? (T.dir > 0 ? 'ascending' : 'descending') : 'none'); });
        var nf = Object.keys(T.f).filter(function (k) { return T.f[k]; }).length + (T.pre ? 1 : 0);
        $('tFiltN').textContent = nf ? '(' + nf + ')' : '';
        var pages = Math.max(1, Math.ceil(VIEW.length / T.per)); if (T.page >= pages) T.page = pages - 1;
        var rows = VIEW.slice(T.page * T.per, (T.page + 1) * T.per);
        $('tBody').innerHTML = rows.length ? rows.map(function (r) {
            var on = !!T.sel[r.k];
            return '<tr class="fd-tr' + (on ? ' sel' : '') + '" tabindex="0" data-k="' + r.k + '"><td style="padding-left:12px"><input type="checkbox" data-sel="' + r.k + '"' + (on ? ' checked' : '') + ' aria-label="Sélectionner ' + esc(r.fam) + ' ' + dfr(r.d) + '"></td>'
                + '<td class="mut" style="white-space:nowrap">' + dfr(r.d) + '</td><td><b>' + esc(r.fam) + '</b>' + (r.enf ? '<div class="mut" style="font-size:12px">' + esc(r.enf) + '</div>' : '') + '</td>'
                + '<td style="max-width:240px"><div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(r.camp) + '</div><div class="mut" style="font-size:12px">' + esc(r.sesl) + '</div></td><td class="mut">' + esc(r.moyl) + '</td>'
                + '<td class="num"><b>' + eur(r.brut) + '</b></td><td class="num mut">' + (r.com ? '− ' + eur(r.com) : '–') + '</td><td class="num mut">' + (r.frais ? '− ' + eur(r.frais) : '–') + '</td><td class="num mut">' + (r.remb ? '− ' + eur(r.remb) : '–') + '</td>'
                + '<td class="num"><b>' + eur(r.pv) + '</b></td><td><span class="mk-pill ' + (PILL[r.st] || '') + '" style="white-space:nowrap">' + esc(r.stl) + '</span></td></tr>';
        }).join('') : '<tr><td colspan="11"><div class="fd-empty"><b>' + (D.tx.length ? 'Aucune transaction ne correspond' : 'Aucune transaction sur cette période') + '</b>' + (D.tx.length ? 'Modifiez la recherche ou les filtres.' : 'Elles apparaîtront ici dès le premier paiement.') + '</div></td></tr>';
        var s = { brut: 0, com: 0, frais: 0, remb: 0, pv: 0 }; VIEW.forEach(function (r) { for (var k in s) s[k] += r[k]; });
        $('tFoot').innerHTML = VIEW.length ? '<tr style="font-weight:800;background:#f8fafb"><td></td><td colspan="4">Total · ' + VIEW.length + ' transaction' + (VIEW.length > 1 ? 's' : '') + '</td><td class="num">' + eur(s.brut) + '</td><td class="num">− ' + eur(s.com) + '</td><td class="num">− ' + eur(s.frais) + '</td><td class="num">− ' + eur(s.remb) + '</td><td class="num">' + eur(s.pv) + '</td><td></td></tr>' : '';
        $('tCount').textContent = VIEW.length ? (T.page * T.per + 1) + '–' + Math.min(VIEW.length, (T.page + 1) * T.per) + ' sur ' + VIEW.length + (T.pre ? ' (' + PRE[T.pre][1] + ')' : '') + (D.tx_limite ? ' · limité aux 5 000 plus récentes, réduisez la période' : '') : '';
        $('tPage').textContent = (T.page + 1) + ' / ' + pages; $('tPrev').disabled = T.page === 0; $('tNext').disabled = T.page + 1 >= pages;
        $('tAll').checked = rows.length > 0 && rows.every(function (r) { return T.sel[r.k]; });
        selInfo();
    }
    function selInfo() { var n = Object.keys(T.sel).length; $('tSelInfo').textContent = n ? n + ' sélectionnée' + (n > 1 ? 's' : '') : ''; $('tExpSel').disabled = $('tExpSelCsv').disabled = !n; }
    $('tAll').addEventListener('change', function () { var on = this.checked; VIEW.slice(T.page * T.per, (T.page + 1) * T.per).forEach(function (r) { if (on) T.sel[r.k] = 1; else delete T.sel[r.k]; }); table(); });
    $('tBody').addEventListener('change', function (e) { var c = e.target.closest('[data-sel]'); if (!c) return; if (c.checked) T.sel[c.dataset.sel] = 1; else delete T.sel[c.dataset.sel]; c.closest('tr').classList.toggle('sel', c.checked); selInfo(); });
    $('tBody').addEventListener('click', function (e) { if (e.target.closest('input,a')) return; var tr = e.target.closest('.fd-tr'); if (tr) detail(tr.dataset.k, tr); });
    $('tBody').addEventListener('keydown', function (e) { var tr = e.target.closest('.fd-tr'); if (tr && e.key === 'Enter' && e.target === tr) detail(tr.dataset.k, tr); });

    // ---------- panneau détail
    var back = null;
    function detail(k, from) {
        var r = D.tx.filter(function (x) { return x.k === k; })[0]; if (!r) return;
        back = from || document.activeElement;
        var card = r.mode !== 'hors', row = function (a, b) { return '<dt>' + a + '</dt><dd>' + b + '</dd>'; };
        var h = '<p style="margin:0 0 4px;font-size:13px;color:var(--ds-muted)">' + dfr(r.d) + ' à ' + r.d.slice(11, 16) + '</p>'
            + '<p style="margin:0 0 14px"><span class="mk-pill ' + (PILL[r.st] || '') + '">' + esc(r.stl) + '</span></p>'
            + '<div class="fd-sub" style="margin-top:0">Famille</div><dl class="fd-dl">' + row('Famille', esc(r.fam)) + (r.enf ? row('Enfant', esc(r.enf)) : '') + (r.parent ? row('Parent', esc(r.parent)) : '') + '</dl>'
            + '<div class="fd-sub">Séjour</div><dl class="fd-dl">' + row('Séjour', esc(r.camp)) + row('Session', esc(r.sesl)) + row('Moyen', esc(r.moyl)) + '</dl>'
            + '<div class="fd-sub">Montants</div><dl class="fd-dl">' + row('Payé par la famille', eur(r.brut)) + row('Commission moncamp.fr', '− ' + eur(r.com)) + (card ? row('Frais Stripe', r.frais ? '− ' + eur(r.frais) : (r.mode === 'direct' ? 'en attente de Stripe' : 'inclus dans la commission')) : '')
            + (r.remb ? row('Remboursé', '− ' + eur(r.remb)) : '') + '<span class="sep"></span>' + row('<b>Net pour vous</b>', '<b>' + eur(r.pv) + '</b>')
            + (r.comp ? row('Dette compensée sur ce paiement', eur(r.comp)) : '') + (r.tva ? row('dont TVA incluse', eur(r.tva)) : '') + '</dl>';
        if (card) h += '<div class="fd-sub">Versement</div><dl class="fd-dl">' + row('Déjà versé', eur(r.verse)) + (r.st === 'attente' ? row('Versement', 'automatique, dès que disponible') : '') + (r.vtok ? row('Justificatif', '<a class="underline" href="virement.php?t=' + encodeURIComponent(r.vtok) + '">Voir le versement</a>') : '') + '</dl>';
        h += '<div class="fd-sub">Documents</div><div style="display:flex;gap:8px;flex-wrap:wrap">'
            + (r.tok ? '<a class="fd-btn" href="generate_pdf_certificat.php?r=' + encodeURIComponent(r.tok) + '" target="_blank" rel="noopener">Reçu PDF</a>' : '')
            + (card && EXPRESS ? '<a class="fd-btn" href="api/connect_dashboard.php?o=' + encodeURIComponent(ORGA) + '" target="_blank" rel="noopener">Ouvrir dans Stripe ↗</a>' : '')
            + (r.st === 'A_REGLER' ? '<button type="button" class="fd-btn pri" data-recu="' + esc(r.tok) + '">Marquer reçu</button>' : '') + '</div>'
            + (r.pi ? '<p style="font-size:12.5px;color:var(--ds-muted);margin-top:12px">Référence Stripe : <span class="fd-code">' + esc(r.pi) + '</span></p>' : '');
        $('drTitle').textContent = r.fam + ' · ' + eur(r.brut); $('drBody').innerHTML = h;
        $('drawer').hidden = false; requestAnimationFrame(function () { $('drawer').classList.add('open'); $('dScrim').classList.add('open'); $('drClose').focus(); });
    }
    function closeDr() { $('drawer').classList.remove('open'); $('dScrim').classList.remove('open'); setTimeout(function () { $('drawer').hidden = true; }, 280); if (back && back.focus) back.focus(); }
    $('drClose').addEventListener('click', closeDr); $('dScrim').addEventListener('click', closeDr);
    $('drawer').addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.stopPropagation(); closeDr(); }
        if (e.key === 'Tab') { var f = $('drawer').querySelectorAll('a,button'), a = f[0], z = f[f.length - 1]; if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); } }
    });

    // ---------- versements, litiges, dette, hors carte, rapports
    var VST = { prepa: ['En préparation', 'info'], route: ['En route vers la banque', 'info'], arrive: ['Arrivé', 'ok'], echec: ['Échec', 'bad'], stripe: ['Envoyé sur Stripe', 'ok'] };
    var empty = function (cols, t, s) { return '<tr><td colspan="' + cols + '"><div class="fd-empty"><b>' + t + '</b>' + (s || '') + '</div></td></tr>'; };
    function renderOthers() {
        $('vNext').innerHTML = D.prochains.length ? '<dl class="fd-dl">' + D.prochains.map(function (p) { return '<dt>Arrivée prévue le ' + dfr(p.d) + '</dt><dd>' + eur(p.m) + '</dd>'; }).join('') + '</dl>' : '<div class="fd-empty"><b>Aucun versement en route</b>' + (D.kpi.en_attente ? eur(D.kpi.en_attente) + ' seront versés automatiquement par Stripe dès que disponibles.' : 'Les prochains paiements seront versés automatiquement.') + '</div>';
        $('vTot').innerHTML = '<dt>Total versé (depuis le début)</dt><dd>' + eur(D.kpi.verse_total) + '</dd><dt>Versé sur la période</dt><dd>' + eur(D.kpi.cur.verse) + '</dd><dt>En attente à ce jour</dt><dd>' + eur(D.kpi.en_attente) + '</dd>';
        $('vBody').innerHTML = D.versements.length ? D.versements.map(function (v, i) {
            var s = VST[v.st] || [v.st, ''];
            return '<tr><td>' + dfr(v.d) + '</td><td class="num"><b>' + eur(v.m) + '</b></td><td><span class="mk-pill ' + s[1] + '">' + s[0] + '</span>' + (v.err ? ' <span class="mut" style="font-size:12px">' + esc(v.err) + '</span>' : '') + '</td>'
                + '<td>' + (v.arr ? (v.arr_est ? 'vers le ' : '') + dfr(v.arr) : '<span class="mut">–</span>') + '</td><td class="num">' + (v.lignes.length ? '<button type="button" class="underline" data-vl="' + i + '" aria-expanded="false">' + v.lignes.length + ' ▾</button>' : (v.n || '–')) + '</td>'
                + '<td class="num">' + (v.tok ? '<a class="underline" href="virement.php?t=' + encodeURIComponent(v.tok) + '">Justificatif</a>' : '') + '</td></tr>';
        }).join('') : empty(6, 'Aucun versement pour le moment', <?= json_encode(ucfirst($autoTxt) . '.') ?>);
        var L = D.tx.filter(function (r) { return r.remb > 0 || r.st === 'litige' || r.st === 'annule'; }), lit = D.tx.filter(function (r) { return r.st === 'litige'; });
        $('lKpi').innerHTML = '<div class="fd-kpi"><div class="k">Remboursé (période)</div><div class="v">' + eur(D.kpi.cur.remb) + '</div></div><div class="fd-kpi"><div class="k">Transactions remboursées</div><div class="v">' + D.tx.filter(function (r) { return r.remb > 0; }).length + '</div></div>'
            + '<div class="fd-kpi' + (lit.length ? ' alert' : '') + '"><div class="k">Litiges en cours</div><div class="v">' + lit.length + '</div></div><div class="fd-kpi"><div class="k">Annulations (total)</div><div class="v">' + D.annulations.length + '</div></div>';
        $('lBody').innerHTML = L.length ? L.map(function (r) { return '<tr class="fd-tr" tabindex="0" data-k="' + r.k + '"><td>' + dfr(r.d) + '</td><td><b>' + esc(r.fam) + '</b></td><td>' + esc(r.camp) + '</td><td class="num">' + eur(r.brut) + '</td><td class="num">' + eur(r.remb) + '</td><td><span class="mk-pill ' + (PILL[r.st] || '') + '">' + esc(r.stl) + '</span></td></tr>'; }).join('') : empty(6, 'Aucun remboursement ni litige', 'sur cette période.');
        var MOT = { maladie: 'Maladie', force: 'Force majeure', autre: 'Autre' };
        $('aBody').innerHTML = D.annulations.length ? D.annulations.map(function (a) { return '<tr><td>' + dfr(a.d) + '</td><td><b>' + esc(a.fam) + '</b><div class="mut" style="font-size:12px">' + esc(MOT[a.motif] || a.motif) + '</div></td><td>' + esc(a.camp) + '</td><td class="num">' + a.pct + ' %</td><td class="num">' + eur(a.r) + '</td><td class="num mut">' + eur(a.c) + '</td></tr>'; }).join('') : empty(6, 'Aucune annulation');
        var av = 0, co = 0; D.dettes.forEach(function (d) { if (d.m > 0) av += d.m; else co -= d.m; });
        $('dTot').innerHTML = '<dt>Avancé par moncamp.fr</dt><dd>' + eur(av) + '</dd><dt>Déjà compensé</dt><dd>' + eur(co) + '</dd><span class="sep"></span><dt><b>Reste dû</b></dt><dd style="color:' + (D.kpi.dette ? '#b42318' : 'inherit') + '"><b>' + eur(D.kpi.dette) + '</b></dd>';
        $('dBody').innerHTML = D.dettes.length ? D.dettes.map(function (d) { return '<tr><td>' + dfr(d.d) + '</td><td>' + esc(d.l || (d.m > 0 ? 'Avance' : 'Compensation')) + '</td><td class="num"' + (d.m > 0 ? ' style="color:#b42318"' : '') + '>' + (d.m > 0 ? '+ ' : '− ') + eur(Math.abs(d.m)) + '</td></tr>'; }).join('') : empty(3, 'Aucune dette', 'Tout est en ordre.');
        $('ctHors').hidden = !D.hors.length; $('ctHors').textContent = D.hors.length;
        $('hBody').innerHTML = D.hors.length ? D.hors.map(function (h) { return '<tr><td><b>' + esc(h.camp) + '</b><div class="mut" style="font-size:12px">début ' + dfr(h.debut) + '</div></td><td>' + esc(h.fam) + (h.enf ? ' <span class="mut">· ' + esc(h.enf) + '</span>' : '') + '</td><td>' + esc(h.moyl) + '</td><td class="num"><b>' + eur(h.m) + '</b></td><td class="mut">' + dfr(h.d) + '</td><td class="num"><button type="button" class="fd-btn" data-recu="' + esc(h.tok) + '">Marquer reçu</button></td></tr>'; }).join('')
            + '<tr style="font-weight:800;background:#f8fafb"><td colspan="3">Total à encaisser</td><td class="num">' + eur(D.kpi.a_encaisser) + '</td><td colspan="2"></td></tr>' : empty(6, 'Rien à encaisser', 'Tous les règlements hors carte sont reçus.');
        var t = { brut: 0, com: 0, frais: 0, remb: 0, net: 0, verse: 0, compense: 0, hors: 0, tva: 0 }, K = Object.keys(t);
        $('rBody').innerHTML = D.mois.length ? D.mois.map(function (m) { K.forEach(function (k) { t[k] += m[k]; }); var d = new Date(m.m + '-15'); return '<tr><td><b>' + MOIS[d.getMonth()] + ' ' + d.getFullYear() + '</b></td>' + K.map(function (k) { return '<td class="num' + (k === 'net' ? '' : ' mut') + '">' + (k === 'tva' && !D.tva && !m.tva ? '–' : eur(m[k])) + '</td>'; }).join('') + '</tr>'; }).join('') : empty(10, 'Aucun mouvement', 'sur cette période.');
        $('rFoot').innerHTML = D.mois.length ? '<tr style="font-weight:800;background:#f8fafb"><td>Total</td>' + K.map(function (k) { return '<td class="num">' + eur(t[k]) + '</td>'; }).join('') + '</tr>' : '';
    }
    $('vBody').addEventListener('click', function (e) {
        var b = e.target.closest('[data-vl]'); if (!b) return; var tr = b.closest('tr'), nx = tr.nextElementSibling;
        if (nx && nx.classList.contains('vl')) { nx.remove(); b.setAttribute('aria-expanded', 'false'); return; }
        var v = D.versements[+b.dataset.vl], x = document.createElement('tr'); x.className = 'vl';
        x.innerHTML = '<td colspan="6" style="background:#f8fafb"><table style="width:100%;font-size:13px">' + v.lignes.map(function (l) { return '<tr><td class="mut" style="padding:4px 8px">' + dfr(l.d) + '</td><td style="padding:4px 8px">' + esc(l.fam) + '</td><td style="padding:4px 8px">' + esc(l.camp) + '</td><td class="num" style="padding:4px 8px">' + eur(l.m) + '</td></tr>'; }).join('') + '</table></td>';
        tr.after(x); b.setAttribute('aria-expanded', 'true');
    });
    $('lBody').addEventListener('click', function (e) { var tr = e.target.closest('.fd-tr'); if (tr) detail(tr.dataset.k, tr); });
    $('lBody').addEventListener('keydown', function (e) { var tr = e.target.closest('.fd-tr'); if (tr && e.key === 'Enter') detail(tr.dataset.k, tr); });
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-recu]'); if (!b) return;
        if (!confirm('Confirmer la réception de ce règlement ? La commission hors carte sera retenue sur vos prochains versements par carte.')) return;
        b.disabled = true; var fd = new FormData(); fd.append('csrf', CSRF); fd.append('t', b.dataset.recu);
        fetch('api/marquer_recu.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); })
            .then(function (d) { if (d.error) { alert(d.error); b.disabled = false; return; } if (!$('drawer').hidden) closeDr(); load(); })
            .catch(function () { alert('Réseau indisponible, réessayez.'); b.disabled = false; });
    });

    // ---------- exports (POST : la sélection peut être longue)
    function exp(kind, fmt, keys) { var f = $('expForm'); f.du.value = $('pDu').value; f.au.value = $('pAu').value; f.export.value = kind; f.fmt.value = fmt; f.keys.value = keys || ''; f.submit(); }
    document.addEventListener('click', function (e) { var b = e.target.closest('[data-exp]'); if (b) { exp(b.dataset.exp, b.dataset.fmt); $('expMenu').classList.remove('open'); } });
    [$('tExpSel'), $('tExpSelCsv')].forEach(function (b) { b.addEventListener('click', function () { exp('tx', b.dataset.fmt, Object.keys(T.sel).join(',')); }); });
    var mb = $('expMenu').firstElementChild;
    mb.addEventListener('click', function (e) { e.stopPropagation(); var o = $('expMenu').classList.toggle('open'); mb.setAttribute('aria-expanded', o); if (o) $('expMenu').querySelector('[role=menuitem]').focus(); });
    document.addEventListener('click', function (e) { if (!e.target.closest('#expMenu')) { $('expMenu').classList.remove('open'); mb.setAttribute('aria-expanded', 'false'); } });
    $('expMenu').addEventListener('keydown', function (e) { if (e.key === 'Escape') { $('expMenu').classList.remove('open'); mb.focus(); } });

    function renderAll() {
        renderKpis(); renderWf(); renderCharts(); renderOthers();
        var sj = $('fSej').value, mo = $('fMoy').value;
        fill('fSej', D.filtres.sejours.map(function (s) { return [s.t, s.nom]; }), sj); sesOpts(); fill('fMoy', D.filtres.moyens.map(function (m) { return [m.k, m.l]; }), mo);
        T.sel = {}; table();
    }

    // ---------- bulles d'aide « ? »
    var pop = $('dbPop'), cur = null;
    function closePop() { pop.classList.remove('on'); if (cur) cur.setAttribute('aria-expanded', 'false'); cur = null; }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.dbh');
        if (!b) { if (!e.target.closest('#dbPop')) closePop(); return; }
        e.stopPropagation(); e.preventDefault();
        if (cur === b) { closePop(); return; }
        closePop(); cur = b; b.setAttribute('aria-expanded', 'true'); pop.textContent = b.dataset.help; pop.classList.add('on');
        var r = b.getBoundingClientRect(), w = pop.offsetWidth;
        pop.style.top = (window.scrollY + r.bottom + 8) + 'px';
        pop.style.left = Math.max(8, Math.min(window.scrollX + r.left - 8, window.scrollX + document.documentElement.clientWidth - w - 8)) + 'px';
    }, true);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closePop(); });
    window.addEventListener('scroll', closePop, { passive: true });

    showTab((location.hash || '#apercu').slice(1));
    window.addEventListener('hashchange', function () { showTab(location.hash.slice(1)); });
    setPreset(); load();
})();
</script>
<?php require_once 'partials/footer.php'; ?>

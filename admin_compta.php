<?php
// admin_compta.php : section « Comptabilité » du back-office (chiffre d'affaires, commissions, frais Stripe, marge nette,
// montants dus / versés aux organismes, rapprochement avec Stripe, exports CSV, réglage de la commission).
// Garde, CSRF (champ _csrf) et en-têtes : ceux de admin_lib.php. Pas de script ni de style en ligne (CSP stricte).
// Le réglage de la commission et le déverrouillage des exports exigent le mot de passe administrateur (adm_reauth).
require_once __DIR__ . '/admin_lib.php';
require_once __DIR__ . '/api/compta_lib.php';
require_once __DIR__ . '/api/gains_lib.php';
adm_guard(false, 'compta.read');
fin_ensure($pdo);
try { fin_frais_rattrapage($pdo); } catch (Throwable $e) { error_log('admin rattrapage frais: ' . $e->getMessage()); } // une fois, puis ne refait rien

$v = in_array($_GET['v'] ?? '', ['synthese', 'revenus', 'journal', 'grandlivre', 'paiements', 'remboursements', 'dettes', 'commission', 'organismes', 'versements', 'rapprochement', 'tva', 'cloture', 'recherche', 'export', 'reglages'], true) ? $_GET['v'] : 'synthese';
[$from, $to, $periodLabel, $pk] = compta_period($_GET);
$orgaTok = preg_match('/^[A-Za-z0-9]{22,24}$/', (string)($_GET['o'] ?? '')) ? (string)$_GET['o'] : ''; // jeton public (22 car.) ou ancien jeton HMAC
$orgaId = $orgaTok ? fin_orga_from_tok($pdo, $orgaTok) : 0;
if ($orgaTok && !$orgaId) $orgaTok = '';
$uid = (int)$_SESSION['user']['id'];
if ($v === 'revenus' && ($_GET['csv'] ?? '') === 'revenus') { // export comptable mensuel de « Mes revenus »
    adm_audit('export_revenus', 'fin_ledger', substr($from, 0, 10) . ' / ' . substr($to, 0, 10));
    compta_csv('revenus-moncamp-' . substr($from, 0, 10) . '-' . substr($to, 0, 10) . '.csv',
        ['Mois', 'Paiements carte', 'Commissions carte', 'Commissions hors carte', 'Frais Stripe payés par moncamp.fr', 'Marge nette', 'Dettes avancées', 'Dettes recouvrées'],
        (function () use ($pdo, $from, $to) { foreach (compta_revenus_mois($pdo, $from, $to) as $r) yield [$r['m'], $r['nb'], compta_eur($r['com_carte']), compta_eur($r['com_hors']), compta_eur($r['frais']), compta_eur($r['marge']), compta_eur($r['avance']), compta_eur($r['recouvre'])]; })());
}

/** URL de la page avec la période / l'organisme courants (aucun identifiant numérique) */
function cu(array $o = []): string {
    global $v, $pk, $orgaTok;
    $keep = (!isset($o['v']) || $o['v'] === $v) ? array_intersect_key($_GET, array_flip(['q', 'ty', 'mo', 'min', 'max', 'f', 'tri', 'sens', 'page'])) : [];
    $q = array_merge(['v' => $v, 'p' => $pk, 'o' => $orgaTok, 'du' => $_GET['du'] ?? '', 'au' => $_GET['au'] ?? ''], $keep, $o);
    if (($q['p'] ?? '') !== 'perso') unset($q['du'], $q['au']);
    return 'admin_compta.php?' . http_build_query(array_filter($q, fn($x) => $x !== '' && $x !== null));
}
function flash_go(string $kind, string $msg, string $to): void { $_SESSION['adm_flash'] = [$kind, $msg]; header('Location: ' . $to); exit; }

/* ------------------------------------------------------------------ actions (POST : CSRF vérifié par adm_guard) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $need = ['ajustement' => 'paiements.write', 'commission' => 'compta.settings', 'direct_charges' => 'compta.settings', 'versement_now' => 'versements.write',
             'retenter' => 'versements.write', 'unlock' => 'compta.export', 'frais' => 'paiements.write', 'payouts' => 'compta.read', 'tva' => 'compta.settings'];
    if (isset($need[$act])) adm_need($need[$act], false);
    try {
        if ($act === 'ajustement') {   // ajustement / dette manuelle d'un organisme (mot de passe, motif obligatoire, journalisé)
            [$ok, $msg] = adm_reauth((string)($_POST['password'] ?? ''));
            if (!$ok) flash_go('err', $msg, 'admin_compta.php?v=versements');
            $oid = fin_orga_from_tok($pdo, (string)($_POST['orga'] ?? ''));
            $m = str_replace([' ', ','], ['', '.'], (string)($_POST['montant'] ?? ''));
            $motif = trim((string)($_POST['motif'] ?? ''));
            if (!$oid || !preg_match('/^\d+(\.\d{1,2})?$/', $m) || (float)$m <= 0 || (float)$m > 100000 || mb_strlen($motif) < 3)
                flash_go('err', 'Organisme, montant positif et motif (3 caractères minimum) obligatoires.', 'admin_compta.php?v=versements');
            $sens = ($_POST['sens'] ?? '') === 'credit' ? 'credit' : 'dette';
            $ref = fin_ajustement_manuel($pdo, $oid, $sens, (int)round((float)$m * 100), $motif, 'admin:' . $uid);
            adm_audit('ajustement_organisme', 'organisateur', $sens . ' ' . $m . ' EUR · ' . mb_substr($motif, 0, 120));
            flash_go('ok', ($sens === 'dette' ? 'Dette' : 'Crédit') . ' de ' . fin_eur((int)round((float)$m * 100)) . ' enregistré (réf. ' . substr($ref, 7) . ').', 'admin_compta.php?v=versements');
        }
        if ($act === 'commission') {
            [$ok, $msg] = adm_reauth((string)($_POST['password'] ?? ''));
            if (!$ok) flash_go('err', $msg, 'admin_compta.php?v=reglages');
            $pct = (float)str_replace(',', '.', (string)($_POST['pct'] ?? ''));
            $fixe = ctype_digit((string)($_POST['fixe'] ?? '')) ? (int)$_POST['fixe'] : -1;
            if (!is_numeric(str_replace(',', '.', (string)($_POST['pct'] ?? ''))) || $pct < 0 || $pct > 30 || $fixe < 0 || $fixe > 500)
                flash_go('err', 'Valeurs invalides : pourcentage entre 0 et 30, part fixe entre 0 et 500 centimes.', 'admin_compta.php?v=reglages');
            $hors = str_replace(',', '.', (string)($_POST['hors'] ?? ''));
            if (!is_numeric($hors) || (float)$hors < 0 || (float)$hors > 30) flash_go('err', 'Commission hors carte invalide (0 à 30 %).', 'admin_compta.php?v=reglages');
            fin_set_commission($pdo, $pct, $fixe, 'admin:' . $uid, (float)$hors);
            adm_audit('commission_modifiee', 'fin_reglages', number_format($pct, 2, '.', '') . ' % + ' . $fixe . ' c');
            flash_go('ok', 'Commission enregistrée : ' . number_format($pct, 2, ',', ' ') . ' % + ' . compta_eur($fixe) . ' €. Elle s\'applique aux prochains paiements uniquement.', 'admin_compta.php?v=reglages');
        }
        if ($act === 'direct_charges') {
            [$ok, $msg] = adm_reauth((string)($_POST['password'] ?? ''));
            if (!$ok) flash_go('err', $msg, 'admin_compta.php?v=versements');
            $on = ($_POST['direct'] ?? '') === '1' ? 1 : 0;
            fin_put_reglage($pdo, 'direct_charges', (string)$on, 'admin:' . $uid);
            fin_audit($pdo, 'direct_charges', 'reglage', 'paiements', ['actif' => $on], 'admin:' . $uid);
            adm_audit('direct_charges', 'fin_reglages', $on ? 'actif' : 'repli destination');
            flash_go('ok', $on ? 'Paiements créés sur le compte de l\'organisme (direct charges) pour les comptes complets.' : 'Repli activé : paiements encaissés par moncamp.fr et transférés aussitôt (destination charges).', 'admin_compta.php?v=versements');
        }
        if ($act === 'versement_now') {   // lance le versement d'UN organisme tout de suite (même logique que api/cron_virements.php)
            [$ok, $msg] = adm_reauth((string)($_POST['password'] ?? ''));
            if (!$ok) flash_go('err', $msg, 'admin_compta.php?v=versements');
            $oid = fin_orga_from_tok($pdo, (string)($_POST['orga'] ?? ''));
            if (!$oid) flash_go('err', 'Organisme introuvable.', 'admin_compta.php?v=versements');
            $force = !empty($_POST['force']);   // test : ignore la date de versement (et, pour les anciens paiements, délai et réserve)
            $r = fin_release_due($pdo, 'admin:' . $uid, $oid, false, true); // anciens paiements : tout le net, tout de suite
            $p = ['payouts' => 0, 'montant_cents' => 0, 'erreurs' => 0];
            try { $p['payouts'] = fin_payouts_sync($pdo, $oid); } catch (Throwable $e) { $p['erreurs']++; error_log('admin versement_now sync: ' . $e->getMessage()); }
            adm_audit($force ? 'versement_force_test' : 'versement_immediat', 'organisateur', (string)$oid);
            if ($r['transferts']) flash_go('ok', fin_eur($r['montant_cents']) . ' d\'anciens paiements transférés sur son compte Stripe (versés ensuite automatiquement par Stripe). ' . $p['payouts'] . ' versement(s) Stripe relevé(s).', 'admin_compta.php?v=versements');
            $acc = fin_account($pdo, $oid);
            flash_go('err', 'Rien à transférer (' . $p['payouts'] . ' versement(s) Stripe relevé(s)) · compte Stripe ' . (fin_account_ready($acc) ? 'prêt' : 'NON prêt (onboarding incomplet)') . ($p['erreurs'] + $r['erreurs'] ? ' · ' . ($p['erreurs'] + $r['erreurs']) . ' erreur(s), voir le journal' : '') . '.', 'admin_compta.php?v=versements');
        }
        if ($act === 'retenter') {
            if (!adm_reauth_ok()) flash_go('err', 'Confirmez votre mot de passe (onglet Exports CSV, « Déverrouiller ») puis réessayez.', 'admin_compta.php?v=versements');
            $_SESSION['adm_reauth'] = time();
            $revId = adm_untok('rev', (string)($_POST['rev'] ?? ''));
            $ok = $revId > 0 && fin_reversal_retenter($pdo, $revId, 'admin:' . $uid);
            if ($ok) { $r = []; fin_process_reversals($pdo, $r); }
            flash_go($ok ? 'ok' : 'err', $ok ? 'Reversement retenté (voir le résultat ci-dessous).' : 'Demande introuvable ou déjà traitée.', 'admin_compta.php?v=versements');
        }
        if ($act === 'unlock') {
            [$ok, $msg] = adm_reauth((string)($_POST['password'] ?? ''));
            flash_go($ok ? 'ok' : 'err', $ok ? 'Exports déverrouillés pendant 10 minutes.' : $msg, 'admin_compta.php?v=export');
        }
        if ($act === 'tva') {
            $t = str_replace(',', '.', (string)($_POST['taux'] ?? ''));
            $siren = preg_replace('/\D/', '', (string)($_POST['siren'] ?? ''));
            if (!is_numeric($t) || (float)$t < 0 || (float)$t > 30 || ($siren !== '' && strlen($siren) !== 9)) flash_go('err', 'Taux entre 0 et 30 %, SIREN à 9 chiffres.', 'admin_compta.php?v=tva');
            $old = ['taux' => adm_one("SELECT valeur FROM fin_reglages WHERE cle = 'tva_commission_pct'"), 'siren' => adm_one("SELECT valeur FROM fin_reglages WHERE cle = 'siren_plateforme'")];
            fin_put_reglage($pdo, 'tva_commission_pct', (string)(float)$t, 'admin:' . $uid);
            fin_put_reglage($pdo, 'siren_plateforme', $siren, 'admin:' . $uid);
            adm_audit('compta_tva_reglage', 'fin_reglages', null, $old, ['taux' => (string)(float)$t, 'siren' => $siren]);
            flash_go('ok', 'Paramètres enregistrés.', 'admin_compta.php?v=tva');
        }
        if ($act === 'frais') {
            [$ok, $ko] = fin_backfill_fees($pdo, 60);
            adm_audit('compta_frais_releves', null, "$ok relevés, $ko en échec");
            flash_go($ko && !$ok ? 'err' : 'ok', "$ok paiement(s) mis à jour avec les frais Stripe réels" . ($ko ? ", $ko non disponible(s) chez Stripe (réessayez plus tard)." : '.'), cu());
        }
        if ($act === 'payouts') {
            $n = compta_sync_payouts($pdo);
            adm_audit('compta_payouts_sync', null, "$n virements");
            flash_go('ok', "$n virement(s) Stripe synchronisé(s).", 'admin_compta.php?v=rapprochement');
        }
    } catch (Throwable $e) {
        error_log('admin_compta: ' . $e->getMessage());
        flash_go('err', 'Opération impossible (voir les journaux serveur). Stripe est peut-être injoignable ou la clé non configurée.', 'admin_compta.php?v=' . urlencode($v));
    }
    flash_go('err', 'Action inconnue.', 'admin_compta.php');
}

/* ------------------------------------------------------------------ données */
$sum = compta_summary($pdo, $from, $to, $orgaId);
$hs = compta_hors_stripe($pdo, $from, $to, $orgaId);
$negN = compta_negative_count($pdo);
$orgList = $pdo->query("SELECT DISTINCT o.id, o.nom FROM fin_paiements p JOIN organisateurs o ON o.id = p.organisateur_id ORDER BY o.nom")->fetchAll(PDO::FETCH_ASSOC);
$e = fn($c) => h(fin_eur((int)$c));

adm_head('Comptabilité');
$tabs = ['synthese' => ['Synthèse', ''], 'revenus' => ['Mes revenus', ''], 'journal' => ['Journal', ''], 'grandlivre' => ['Grand livre', ''], 'paiements' => ['Paiements', 'paiements.read'],
         'remboursements' => ['Remboursements', 'paiements.read'], 'dettes' => ['Dettes', ''], 'versements' => ['Versements', 'versements.read'], 'rapprochement' => ['Rapprochement Stripe', ''],
         'tva' => ['TVA', ''], 'cloture' => ['Clôtures', ''], 'commission' => ['Commission', ''], 'organismes' => ['Par organisme', ''], 'export' => ['Exports', 'compta.export'], 'reglages' => ['Réglages', 'compta.settings']];
$tabs = array_filter($tabs, fn($t) => $t[1] === '' || adm_can($t[1]));
if (!isset($tabs[$v]) && $v !== 'recherche') adm_forbidden(false, 'compta');
?>
<div class="row-l wrap-row compta-top">
<nav class="tabs grow" aria-label="Sections de la comptabilité">
<?php foreach ($tabs as $k => [$l]): ?><a class="tab<?= $k === $v ? ' on' : '' ?>" href="<?= h(cu(['v' => $k, 'page' => ''])) ?>"><?= h($l) ?></a><?php endforeach; ?>
</nav>
<form class="tsearch" method="get" action="admin_compta.php" role="search"><input type="hidden" name="v" value="recherche"><label class="search"><?= ic('search') ?><input class="in" type="search" name="q" value="<?= h($v === 'recherche' ? (string)($_GET['q'] ?? '') : '') ?>" placeholder="Rechercher une transaction" maxlength="80"></label></form>
</div>

<?php if ($negN > 0): ?>
<div class="note err"><?= ic('alert') ?><div><b><?= $negN ?> paiement<?= $negN > 1 ? 's' : '' ?> à marge négative.</b> La commission ne couvre pas les frais Stripe réels (carte hors Europe, remboursement, litige...). <a class="lnk" href="<?= h(cu(['v' => 'synthese'])) ?>#negatives">Voir le détail</a> · envisagez d'augmenter la part fixe ou le pourcentage (onglet Commission).</div></div>
<?php endif; ?>

<?php if (in_array($v, ['synthese', 'revenus', 'commission', 'organismes', 'export', 'journal', 'grandlivre', 'paiements', 'remboursements', 'tva'], true)): ?>
<form class="bar" method="get" action="admin_compta.php">
  <input type="hidden" name="v" value="<?= h($v) ?>"><input type="hidden" name="p" value="perso">
  <?php foreach (['mois' => 'Ce mois', 'trimestre' => 'Trimestre', 'annee' => 'Année', 'tout' => 'Tout'] as $k => $l): ?><a class="btn ghost<?= $pk === $k ? ' sel-on' : '' ?>" href="<?= h(cu(['p' => $k])) ?>"><?= h($l) ?></a><?php endforeach; ?>
  <label class="fld-inline">Du <input class="in dt" type="date" name="du" value="<?= h(substr($from, 0, 10)) ?>"></label>
  <label class="fld-inline">au <input class="in dt" type="date" name="au" value="<?= h(substr($to, 0, 10)) ?>"></label>
  <?php if ($v === 'revenus'): ?><input type="hidden" name="g" value="<?= h((string)($_GET['g'] ?? 'm')) ?>"><?php endif; ?>
  <?php foreach (['q', 'ty', 'mo', 'min', 'max', 'f'] as $kk) if (($_GET[$kk] ?? '') !== '') echo '<input type="hidden" name="' . $kk . '" value="' . h((string)$_GET[$kk]) . '">'; ?>
  <?php if ($v !== 'organismes' && $v !== 'revenus' && $v !== 'tva'): ?><select class="in sel" name="o" aria-label="Organisme"><option value="">Tous les organismes</option>
    <?php foreach ($orgList as $o): $t = fin_orga_tok((int)$o['id']); ?><option value="<?= h($t) ?>"<?= $t === $orgaTok ? ' selected' : '' ?>><?= h($o['nom']) ?></option><?php endforeach; ?></select><?php endif; ?>
  <button class="btn" type="submit"><?= ic('search') ?> Appliquer</button>
  <span class="count"><?= h($periodLabel) ?></span>
</form>
<?php endif; ?>

<?php if ($v === 'synthese'): $ps = compta_plateforme_solde(); ?>
<section class="card"><h2 class="sec"><?= ic('bank') ?> Vos commissions <span class="muted small">· solde Stripe de moncamp.fr</span></h2>
  <?php if ($ps): ?>
  <div class="kv"><span>Disponible (prêt à être viré sur votre banque)</span><b><?= $e($ps['disponible']) ?></b></div>
  <div class="kv"><span>À venir (paiements récents en cours de disponibilité)</span><b><?= $e($ps['a_venir']) ?></b></div>
  <div class="kv"><span>Dernier virement vers votre banque</span><b><?= $ps['dernier'] ? $e($ps['dernier']['m']) . ' · arrivé le ' . h(date('d/m/Y', strtotime($ps['dernier']['arrivee']))) : '–' ?></b></div>
  <div class="kv"><span>Prochain virement</span><b><?= $ps['prochain'] ? $e($ps['prochain']['m']) . ' · arrivée prévue le ' . h(date('d/m/Y', strtotime($ps['prochain']['arrivee']))) : 'aucun annoncé' ?></b></div>
  <?php else: ?><p class="muted">Solde Stripe indisponible (Stripe injoignable ou clé non configurée).</p><?php endif; ?>
  <p class="muted small"><a class="underline" href="https://dashboard.stripe.com/balance/overview" target="_blank" rel="noopener">Ouvrir la page Soldes de Stripe ↗</a> · le solde peut aussi contenir l'argent d'anciens paiements pas encore transférés aux organismes.</p>
</section>
<?php if ($sum['frais_inconnus'] > 0): ?>
<div class="note warn"><?= ic('clock') ?><div><?= $sum['frais_inconnus'] ?> paiement<?= $sum['frais_inconnus'] > 1 ? 's' : '' ?> de la période sans frais Stripe relevés (la marge ci-dessous ne les inclut pas). Le relevé est automatique (webhook + cron) ;
  <form class="inl" method="post" action="admin_compta.php"><input type="hidden" name="_csrf" value="<?= h(adm_csrf()) ?>"><input type="hidden" name="act" value="frais"><button class="btn ghost sm" type="submit">relever maintenant</button></form></div></div>
<?php endif; ?>
<section class="kpis">
  <div class="kpi"><span class="ico"><?= ic('euro') ?></span><div><b><?= $e($sum['ca']) ?></b><span>Chiffre d'affaires encaissé</span><small><?= $sum['nb'] ?> paiement<?= $sum['nb'] > 1 ? 's' : '' ?> · remboursé : <?= $e($sum['rembourse']) ?></small></div></div>
  <div class="kpi"><span class="ico"><?= ic('chart') ?></span><div><b><?= $e($sum['commission']) ?></b><span>Commissions moncamp.fr</span><small>nettes des remboursements</small></div></div>
  <div class="kpi"><span class="ico"><?= ic('card') ?></span><div><b><?= $e($sum['frais'] + $sum['litige']) ?></b><span>Frais Stripe réels</span><small>dont litiges : <?= $e($sum['litige']) ?></small></div></div>
  <div class="kpi<?= $sum['marge'] < 0 ? ' bad' : '' ?>"><span class="ico"><?= ic('star') ?></span><div><b><?= $e($sum['marge']) ?></b><span>Marge nette moncamp.fr</span><small>commission − frais Stripe (paiements relevés)</small></div></div>
  <div class="kpi"><span class="ico"><?= ic('ticket') ?></span><div><b><?= $e($hs['commission']) ?></b><span>Commissions hors carte</span><small><?= $hs['nb'] ?> règlement<?= $hs['nb'] > 1 ? 's' : '' ?> (<?= $e($hs['brut']) ?>) · retenues sur les versements par carte</small></div></div>
  <div class="kpi"><span class="ico"><?= ic('building') ?></span><div><b><?= $e($sum['du_total']) ?></b><span>Dû aux organismes</span><small>net total de la période</small></div></div>
  <div class="kpi"><span class="ico"><?= ic('check') ?></span><div><b><?= $e($sum['verse']) ?></b><span>Déjà versé</span><small>transferts exécutés</small></div></div>
  <div class="kpi"><span class="ico"><?= ic('clock') ?></span><div><b><?= $e($sum['attente']) ?></b><span>En attente de versement</span><small>avant la date de déblocage, suspendu ou en litige</small></div></div>
</section>

<h2 class="sec"><?= ic('list') ?> Par mois</h2>
<?php $months = compta_by_month($pdo, $from, $to, $orgaId); ?>
<div class="tbl-wrap"><table class="tbl">
  <thead><tr><th>Mois</th><th class="num">Paiements</th><th class="num">CA</th><th class="num">Commissions</th><th class="num">Frais Stripe</th><th class="num">Marge nette</th></tr></thead><tbody>
  <?php if (!$months): ?><tr><td colspan="6" class="empty"><p>Aucun paiement sur cette période.</p></td></tr><?php endif; ?>
  <?php foreach ($months as $m): ?><tr><td data-l="Mois"><?= h(substr($m['m'], 5, 2) . '/' . substr($m['m'], 0, 4)) ?></td><td class="num" data-l="Paiements"><?= (int)$m['nb'] ?></td><td class="num" data-l="CA"><?= $e($m['ca']) ?></td><td class="num" data-l="Commissions"><?= $e($m['commission']) ?></td><td class="num" data-l="Frais Stripe"><?= $e($m['frais'] + $m['litige']) ?></td><td class="num<?= $m['marge'] < 0 ? ' neg' : '' ?>" data-l="Marge nette"><b><?= $e($m['marge']) ?></b></td></tr><?php endforeach; ?>
  </tbody></table></div>

<?php $gj = gains_history($pdo, 0, 30); $gc = gains_verify_chain($pdo); ?>
<h2 class="sec"><?= ic('lock') ?> Paiements avec les gains (journal en ajout seulement)</h2>
<p class="muted small">Chaque achat de points payé avec le solde « à recevoir » : qui, quand, IP, montant, solde avant/après. Chaîne d'empreintes : <b><?= $gc['ok'] ? 'intacte (' . (int)$gc['n'] . ' lignes)' : 'ALTÉRÉE à la ligne n° ' . (int)$gc['rupture'] ?></b>.</p>
<div class="tbl-wrap"><table class="tbl">
  <thead><tr><th>Date</th><th>Organisme</th><th>Achat</th><th class="num">Montant</th><th class="num">Solde avant</th><th class="num">Solde après</th><th>IP</th><th>Statut</th><th>Réf.</th></tr></thead><tbody>
  <?php if (!$gj): ?><tr><td colspan="9" class="empty"><p>Aucun paiement avec les gains pour le moment.</p></td></tr><?php endif; ?>
  <?php foreach ($gj as $g): ?><tr><td data-l="Date"><?= h(date('d/m/Y H:i', strtotime($g['created_at']))) ?></td><td data-l="Organisme"><?= h($g['orga_nom'] ?? '') ?></td><td data-l="Achat"><?= h($g['libelle']) ?></td><td class="num" data-l="Montant"><?= h(fin_eur((int)$g['montant_cents'])) ?></td><td class="num" data-l="Solde avant"><?= h(fin_eur((int)$g['solde_avant_cents'])) ?></td><td class="num" data-l="Solde après"><?= h(fin_eur((int)$g['solde_apres_cents'])) ?></td><td data-l="IP"><?= h($g['ip']) ?></td><td data-l="Statut"><?= $g['statut'] === 'ok' ? 'Payé' : 'Refusé (solde)' ?></td><td data-l="Réf.">G-<?= (int)$g['id'] ?></td></tr><?php endforeach; ?>
  </tbody></table></div>

<?php if ($negN > 0): $neg = compta_negative($pdo); ?>
<h2 class="sec" id="negatives"><?= ic('alert') ?> Paiements à marge négative</h2>
<div class="tbl-wrap"><table class="tbl">
  <thead><tr><th>Date</th><th>Organisme · séjour</th><th class="num">Payé</th><th class="num">Commission</th><th class="num">Frais Stripe</th><th class="num">Marge</th><th>Statut</th></tr></thead><tbody>
  <?php foreach ($neg as $n): ?><tr><td data-l="Date"><?= h(adm_date($n['created_at'])) ?></td><td data-l="Séjour"><?= h($n['orga']) ?> · <?= h($n['camp']) ?></td><td class="num" data-l="Payé"><?= $e($n['brut_cents']) ?></td><td class="num" data-l="Commission"><?= $e($n['commission']) ?></td><td class="num" data-l="Frais"><?= $e($n['frais'] + $n['litige']) ?><?= $n['litige'] > 0 ? ' <span class="pill bad">litige</span>' : '' ?></td><td class="num neg" data-l="Marge"><b><?= $e($n['marge']) ?></b></td><td data-l="Statut"><?= h($n['statut']) ?><?= $n['rembourse_cents'] > 0 ? ' · remboursé ' . $e($n['rembourse_cents']) : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
<p class="muted small">Un remboursement ne restitue pas les frais Stripe : la commission rendue à la famille augmente la perte. Cas normaux à surveiller : cartes hors Europe, litiges (frais fixes élevés).</p>
<?php endif; ?>

<?php elseif ($v === 'revenus'): $g = in_array($_GET['g'] ?? '', ['j', 's', 'm'], true) ? $_GET['g'] : 'm'; $rv = compta_revenus($pdo, $from, $to, $g); $ps = compta_plateforme_solde();
  $dl = function (int $c, int $p, bool $up = true) { if (!$p) return $c ? 'nouveau' : '='; $x = (int)round(($c - $p) / abs($p) * 100); return ($x > 0 ? '+' : '') . $x . ' % vs ' . fin_eur($p); };
  $c = $rv['cur']; $pv = $rv['prev']; ?>
<p class="muted">Ce que gagne moncamp.fr, lu dans le journal comptable <code>fin_ledger</code>. Comparaison : du <?= h(date('d/m/Y', strtotime($rv['prev_du']))) ?> au <?= h(date('d/m/Y', strtotime($rv['prev_au']))) ?>.</p>
<?php $rr = compta_revenus_rapprochement($pdo, $ps); ?>
<section class="card"><h2 class="sec"><?= ic('bank') ?> Ce que gagne moncamp.fr <span class="muted small">· depuis le début</span></h2>
  <div class="kv"><span>Commission</span><b><?= $e($rr['com']) ?></b></div>
  <div class="kv"><span>− frais Stripe payés par moncamp.fr <small class="muted">(anciens paiements seulement : depuis la règle « frais à la charge de l'organisme », tous les nouveaux paiements, y compris reçus avant l'activation ou en repli destination, sont payés par l'organisme)</small></span><b>− <?= $e($rr['frais']) ?></b></div>
  <div class="kv"><span><b>= net pour moncamp.fr</b></span><b><?= $e($rr['net']) ?></b></div>
  <?php if ($rr['hors']): ?><div class="kv"><span>− commissions hors carte pas encore encaissées <small class="muted">(retenues sur les paiements suivants des organismes)</small></span><b>− <?= $e($rr['hors']) ?></b></div><?php endif; ?>
  <?php if ($rr['points']): ?><div class="kv"><span>+ achats de points par carte</span><b>+ <?= $e($rr['points']) ?></b></div><?php endif; ?>
  <?php if ($rr['detenu']): ?><div class="kv"><span>+ argent d'organismes encore détenu <small class="muted">(paiements reçus avant l'activation, à leur transférer)</small></span><b>+ <?= $e($rr['detenu']) ?></b></div><?php endif; ?>
  <?php if ($rr['dette']): ?><div class="kv"><span><?= $rr['dette'] > 0 ? '− dettes d\'organismes avancées, pas encore recouvrées' : '+ dettes et commissions hors carte recouvrées' ?></span><b><?= $rr['dette'] > 0 ? '− ' : '+ ' ?><?= $e(abs($rr['dette'])) ?></b></div><?php endif; ?>
  <?php if ($rr['payouts']): ?><div class="kv"><span>− déjà viré sur votre banque</span><b>− <?= $e($rr['payouts']) ?></b></div><?php endif; ?>
  <?php if ($rr['solde'] === null): ?><p class="muted">Solde Stripe indisponible : rapprochement impossible pour le moment.</p>
  <?php elseif (abs($rr['ecart']) <= 1): ?><div class="note ok"><?= ic('check') ?><div><b>= solde Stripe de la plateforme : <?= $e($rr['solde']) ?></b> (disponible + à venir). Tout est rapproché.</div></div>
  <?php else: ?><div class="note err"><?= ic('alert') ?><div><b>Solde attendu <?= $e($rr['attendu']) ?> · solde Stripe <?= $e($rr['solde']) ?> · écart <?= $e($rr['ecart']) ?></b><br>
    Causes possibles : frais Stripe pas encore relevés sur un paiement récent, frais Connect ou de virement facturés par Stripe, virements vers votre banque pas encore synchronisés (onglet Rapprochement Stripe), paiements antérieurs au journal, mouvements en cours.</div></div><?php endif; ?>
</section>
<section class="kpis">
  <div class="kpi"><span class="ico"><?= ic('chart') ?></span><div><b><?= $e($c['com']) ?></b><span>Commissions encaissées</span><small><?= h($dl($c['com'], $pv['com'])) ?> · dont hors carte <?= $e($c['hors']) ?></small></div></div>
  <div class="kpi"><span class="ico"><?= ic('card') ?></span><div><b><?= $e($c['frais']) ?></b><span>Frais Stripe payés par moncamp.fr</span><small><?= h($dl($c['frais'], $pv['frais'])) ?> · paiements en repli destination / anciens</small></div></div>
  <div class="kpi<?= $c['marge'] < 0 ? ' bad' : '' ?>"><span class="ico"><?= ic('star') ?></span><div><b><?= $e($c['marge']) ?></b><span>Marge nette</span><small><?= h($dl($c['marge'], $pv['marge'])) ?></small></div></div>
  <div class="kpi"><span class="ico"><?= ic('ticket') ?></span><div><b><?= (int)$c['nb'] ?></b><span>Paiements par carte</span><small><?= $c['nb'] ? 'commission moyenne ' . $e(intdiv($c['com'] - $c['hors'], max(1, $c['nb']))) : '' ?></small></div></div>
</section>
<div class="bar">
  <?php foreach (['j' => 'Par jour', 's' => 'Par semaine', 'm' => 'Par mois'] as $k => $l): ?><a class="btn ghost<?= $g === $k ? ' sel-on' : '' ?>" href="<?= h(cu(['g' => $k])) ?>"><?= h($l) ?></a><?php endforeach; ?>
  <a class="btn" href="<?= h(cu(['csv' => 'revenus'])) ?>"><?= ic('list') ?> Export CSV par mois</a>
</div>
<?php $sr = $rv['serie']; $mx = max(1, ...array_map(fn($x) => max($x['com'], $x['frais']), $sr ?: [['com' => 1, 'frais' => 0]])); $n = max(1, count($sr)); $W = 760; $H = 220; $bw = ($W - 20) / $n; ?>
<section class="card"><h2 class="sec"><?= ic('chart') ?> Commissions encaissées<span class="muted small"> · barres foncées : commissions, claires : frais Stripe</span></h2>
<?php if (!$sr): ?><p class="muted">Aucune commission sur cette période.</p><?php else: ?>
<svg viewBox="0 0 <?= $W ?> <?= $H + 24 ?>" width="100%" role="img" aria-label="Commissions encaissées et frais Stripe par période">
  <line x1="10" x2="<?= $W - 10 ?>" y1="<?= $H ?>" y2="<?= $H ?>" stroke="#d3dbe0"/>
  <text x="10" y="12" font-size="11" fill="#6b7780"><?= h(fin_eur($mx)) ?></text>
  <?php foreach ($sr as $i => $x): $x0 = 10 + $i * $bw; $w = max(1, $bw * .42); $hc = max(0, $x['com']) / $mx * ($H - 20); $hf = max(0, $x['frais']) / $mx * ($H - 20); ?>
  <rect x="<?= round($x0 + $bw * .08, 1) ?>" y="<?= round($H - $hc, 1) ?>" width="<?= round($w, 1) ?>" height="<?= round($hc, 1) ?>" fill="#2f3d45"><title><?= h($x['k'] . ' : ' . fin_eur($x['com'])) ?></title></rect>
  <rect x="<?= round($x0 + $bw * .08 + $w, 1) ?>" y="<?= round($H - $hf, 1) ?>" width="<?= round($w, 1) ?>" height="<?= round($hf, 1) ?>" fill="#8aa4b8"><title><?= h($x['k'] . ' : frais ' . fin_eur($x['frais'])) ?></title></rect>
  <?php if ($i % (int)ceil($n / 10) === 0): ?><text x="<?= round($x0 + $bw / 2, 1) ?>" y="<?= $H + 16 ?>" font-size="10" fill="#6b7780" text-anchor="middle"><?= h($g === 'm' ? substr($x['k'], 5, 2) . '/' . substr($x['k'], 2, 2) : ($g === 'j' ? substr($x['k'], 8, 2) . '/' . substr($x['k'], 5, 2) : substr($x['k'], 5))) ?></text><?php endif; ?>
  <?php endforeach; ?>
</svg><?php endif; ?>
</section>
<div class="cols">
  <section class="card"><h2 class="sec"><?= ic('building') ?> Meilleurs organismes</h2>
    <?php if (!$rv['orgas']): ?><p class="muted">Aucun.</p><?php endif; ?>
    <?php foreach (array_slice($rv['orgas'], 0, 10) as $o): ?><div class="kv"><span><?= h($o['nom']) ?> <small class="muted">· <?= (int)$o['nb'] ?> paiement<?= $o['nb'] > 1 ? 's' : '' ?><?= $c['com'] ? ' · ' . round($o['com'] / $c['com'] * 100) . ' %' : '' ?></small></span><b><?= $e($o['com']) ?></b></div><?php endforeach; ?>
  </section>
  <section class="card"><h2 class="sec"><?= ic('bank') ?> Solde Stripe de moncamp.fr</h2>
    <?php if ($ps): ?>
    <div class="kv"><span>Disponible</span><b><?= $e($ps['disponible']) ?></b></div>
    <div class="kv"><span>À venir</span><b><?= $e($ps['a_venir']) ?></b></div>
    <div class="kv"><span>Prochain virement vers votre banque</span><b><?= $ps['prochain'] ? $e($ps['prochain']['m']) . ' · le ' . h(date('d/m/Y', strtotime($ps['prochain']['arrivee']))) : 'aucun annoncé' ?></b></div>
    <?php else: ?><p class="muted">Solde Stripe indisponible (Stripe injoignable ou clé non configurée).</p><?php endif; ?>
    <p class="muted small"><a class="underline" href="https://dashboard.stripe.com/balance/overview" target="_blank" rel="noopener">Page Soldes de Stripe ↗</a> · le solde peut contenir l'argent d'anciens paiements pas encore transférés aux organismes.</p>
  </section>
</div>
<h2 class="sec"><?= ic('list') ?> Par organisme</h2>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Organisme</th><th class="num">Paiements</th><th class="num">Commissions</th><th class="num">Frais Stripe plateforme</th><th class="num">Marge nette</th></tr></thead><tbody>
  <?php if (!$rv['orgas']): ?><tr><td colspan="5" class="empty"><p>Aucune commission sur cette période.</p></td></tr><?php endif; ?>
  <?php foreach ($rv['orgas'] as $o): ?><tr><td data-l="Organisme"><?= h($o['nom']) ?></td><td class="num" data-l="Paiements"><?= (int)$o['nb'] ?></td><td class="num" data-l="Commissions"><?= $e($o['com']) ?></td><td class="num" data-l="Frais"><?= $e($o['frais']) ?></td><td class="num<?= $o['marge'] < 0 ? ' neg' : '' ?>" data-l="Marge"><?= $e($o['marge']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<h2 class="sec"><?= ic('list') ?> Par séjour (20 premiers)</h2>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Séjour</th><th>Organisme</th><th class="num">Paiements</th><th class="num">Commissions</th></tr></thead><tbody>
  <?php if (!$rv['sejours']): ?><tr><td colspan="4" class="empty"><p>Aucun séjour sur cette période.</p></td></tr><?php endif; ?>
  <?php foreach ($rv['sejours'] as $s): ?><tr><td data-l="Séjour"><?= h($s['camp']) ?></td><td data-l="Organisme"><?= h($s['orga']) ?></td><td class="num" data-l="Paiements"><?= (int)$s['nb'] ?></td><td class="num" data-l="Commissions"><?= $e($s['com']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="cols">
  <section class="card"><h2 class="sec"><?= ic('alert') ?> Dettes d'organismes</h2>
    <div class="kv"><span>Avancées sur la période</span><b><?= $e($c['avance']) ?></b></div>
    <div class="kv"><span>Recouvrées sur la période</span><b><?= $e($c['recouvre']) ?></b></div>
    <div class="kv"><span><b>En cours (tous organismes)</b></span><b><?= $e(array_sum(array_column($rv['dettes'], 'du'))) ?></b></div>
    <?php foreach ($rv['dettes'] as $d): ?><div class="kv"><span><?= h($d['nom']) ?> <small class="muted">· depuis le <?= h(date('d/m/Y', strtotime($d['depuis']))) ?></small></span><b><?= $e($d['du']) ?></b></div><?php endforeach; ?>
  </section>
  <section class="card"><h2 class="sec"><?= ic('bank') ?> Virements vers votre banque</h2>
    <?php if (!$ps || !$ps['historique']): ?><p class="muted">Aucun virement connu.</p><?php else: foreach ($ps['historique'] as $p): ?>
    <div class="kv"><span><?= $p['arrivee'] ? h(date('d/m/Y', strtotime($p['arrivee']))) : '–' ?> <small class="muted">· <?= h(['paid' => 'arrivé', 'in_transit' => 'en route', 'pending' => 'en préparation', 'failed' => 'échec', 'canceled' => 'annulé'][$p['statut']] ?? $p['statut']) ?></small></span><b><?= $e($p['m']) ?></b></div>
    <?php endforeach; endif; ?>
  </section>
</div>

<?php elseif ($v === 'commission'): $lc = compta_ledger_commission($pdo, $from, $to, $orgaId); $rc = null; $rcErr = null;
  if (!empty($_GET['rp'])) { try { $rc = compta_fees_reconcile($pdo, $from, $to); } catch (Throwable $ex) { $rcErr = 'Stripe injoignable : ' . mb_substr($ex->getMessage(), 0, 160); } } ?>
<p class="muted">Lu dans le journal comptable unique <code>fin_ledger</code> (ajout seulement). Commission nette des commissions rendues ; frais Stripe réels relevés sur chaque paiement.</p>
<section class="kpis">
  <div class="kpi"><span class="ico"><?= ic('euro') ?></span><div><b><?= $e($lc['tot']['encaisse']) ?></b><span>Encaissé par carte</span></div></div>
  <div class="kpi"><span class="ico"><?= ic('chart') ?></span><div><b><?= $e($lc['tot']['com']) ?></b><span>Commission</span><small>y compris hors carte</small></div></div>
  <div class="kpi"><span class="ico"><?= ic('card') ?></span><div><b><?= $e($lc['tot']['frais']) ?></b><span>Frais Stripe réels</span><small>dont litiges</small></div></div>
  <div class="kpi<?= $lc['tot']['marge'] < 0 ? ' bad' : '' ?>"><span class="ico"><?= ic('star') ?></span><div><b><?= $e($lc['tot']['marge']) ?></b><span>Marge nette</span></div></div>
</section>
<section class="card"><h2 class="sec"><?= ic('check') ?> Rapprochement avec Stripe</h2>
  <?php if ($rcErr): ?><div class="note warn"><?= ic('alert') ?><div><?= h($rcErr) ?></div></div><?php endif; ?>
  <?php if ($rc): ?><p>Commissions attendues (paiements destination de la période) : <b><?= $e($rc['attendu']) ?></b> · relevées chez Stripe (<?= (int)$rc['n'] ?> commissions) : <b><?= $e($rc['stripe']) ?></b> ·
    écart : <b<?= $rc['ecart'] !== 0 ? ' class="neg"' : '' ?>><?= $e($rc['ecart']) ?></b></p>
    <?php if ($rc['ecart'] !== 0): ?><p class="muted small">Un écart vient en général d'un litige perdu (Stripe garde la commission, nos comptes la rendent), d'un remboursement fait dans Stripe sans « refund application fee », ou d'un paiement de fin de période enregistré le lendemain.</p><?php endif; ?>
  <?php else: ?><a class="btn ghost" href="<?= h(cu(['rp' => 1])) ?>"><?= ic('search') ?> Comparer avec Stripe</a><?php endif; ?>
</section>
<div class="cols">
<section><h2 class="sec"><?= ic('building') ?> Par organisme</h2>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Organisme</th><th class="num">Commission</th><th class="num">Frais</th><th class="num">Marge</th></tr></thead><tbody>
  <?php if (!$lc['orgas']): ?><tr><td colspan="4" class="empty"><p>Rien sur cette période.</p></td></tr><?php endif; ?>
  <?php foreach ($lc['orgas'] as $r): ?><tr><td data-l="Organisme"><?= h($r['k'] ?? '-') ?></td><td class="num" data-l="Commission"><?= $e($r['com']) ?></td><td class="num" data-l="Frais"><?= $e($r['frais']) ?></td><td class="num<?= $r['marge'] < 0 ? ' neg' : '' ?>" data-l="Marge"><b><?= $e($r['marge']) ?></b></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section><h2 class="sec"><?= ic('list') ?> Par mois</h2>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Mois</th><th class="num">Commission</th><th class="num">Frais</th><th class="num">Marge</th></tr></thead><tbody>
  <?php if (!$lc['mois']): ?><tr><td colspan="4" class="empty"><p>Rien sur cette période.</p></td></tr><?php endif; ?>
  <?php foreach ($lc['mois'] as $r): ?><tr><td data-l="Mois"><?= h(substr($r['k'], 5, 2) . '/' . substr($r['k'], 0, 4)) ?></td><td class="num" data-l="Commission"><?= $e($r['com']) ?></td><td class="num" data-l="Frais"><?= $e($r['frais']) ?></td><td class="num<?= $r['marge'] < 0 ? ' neg' : '' ?>" data-l="Marge"><b><?= $e($r['marge']) ?></b></td></tr><?php endforeach; ?>
</tbody></table></div></section>
</div>
<h2 class="sec"><?= ic('alert') ?> Marge réelle par paiement (les plus faibles d'abord)</h2>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Date</th><th>Organisme</th><th class="num">Payé</th><th class="num">Commission</th><th class="num">Frais Stripe</th><th class="num">Marge</th></tr></thead><tbody>
  <?php $mg = compta_marges($pdo, $from, $to, $orgaId); if (!$mg): ?><tr><td colspan="6" class="empty"><p>Aucun paiement avec frais relevés.</p></td></tr><?php endif; ?>
  <?php foreach ($mg as $r): ?><tr><td data-l="Date"><?= h(adm_date($r['created_at'])) ?></td><td data-l="Organisme"><?= h($r['orga']) ?></td><td class="num" data-l="Payé"><?= $e($r['brut_cents']) ?></td><td class="num" data-l="Commission"><?= $e($r['com']) ?></td><td class="num" data-l="Frais"><?= $e((int)$r['frais'] + (int)$r['litige']) ?></td><td class="num<?= $r['marge'] < 0 ? ' neg' : '' ?>" data-l="Marge"><b><?= $e($r['marge']) ?></b></td></tr><?php endforeach; ?>
</tbody></table></div>
<h2 class="sec"><?= ic('lock') ?> Journal comptable (50 dernières lignes)</h2>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Date</th><th>Type</th><th>Organisme</th><th class="num">Montant</th><th>Référence</th><th>Par</th></tr></thead><tbody>
  <?php $jl = compta_journal($pdo, $from, $to, $orgaId); if (!$jl): ?><tr><td colspan="6" class="empty"><p>Aucune écriture sur cette période.</p></td></tr><?php endif; ?>
  <?php foreach ($jl as $r): ?><tr><td data-l="Date"><?= h(adm_date($r['created_at'])) ?></td><td data-l="Type"><?= h($r['libelle'] ?: $r['type']) ?></td><td data-l="Organisme"><?= h($r['orga'] ?? '-') ?></td><td class="num<?= $r['montant_cents'] < 0 ? ' neg' : '' ?>" data-l="Montant"><?= $e($r['montant_cents']) ?></td><td data-l="Référence"><code><?= h($r['reference']) ?></code></td><td data-l="Par"><?= h($r['acteur']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>

<?php elseif ($v === 'organismes'): $rows = compta_by_orga($pdo, $from, $to); ?>
<div class="bar"><span class="count"><?= count($rows) ?> organisme<?= count($rows) > 1 ? 's' : '' ?></span><a class="btn ghost" href="<?= h('admin_compta_export.php?' . http_build_query(['t' => 'organismes', 'p' => $pk] + ($pk === 'perso' ? ['du' => substr($from, 0, 10), 'au' => substr($to, 0, 10)] : []))) ?>"><?= ic('list') ?> Exporter (CSV)</a></div>
<div class="tbl-wrap"><table class="tbl">
  <thead><tr><th>Organisme</th><th class="num">Paiements</th><th class="num">CA</th><th class="num">Commission</th><th class="num">Frais Stripe</th><th class="num">Marge</th><th class="num">Dû</th><th class="num">Versé</th><th class="num">En attente</th></tr></thead><tbody>
  <?php if (!$rows): ?><tr><td colspan="9" class="empty"><p>Aucun paiement sur cette période.</p></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?><tr><td data-l="Organisme"><b><?= h($r['nom']) ?></b></td><td class="num" data-l="Paiements"><?= (int)$r['nb'] ?></td><td class="num" data-l="CA"><?= $e($r['ca']) ?></td><td class="num" data-l="Commission"><?= $e($r['commission']) ?></td><td class="num" data-l="Frais Stripe"><?= $e($r['frais'] + $r['litige']) ?></td><td class="num<?= $r['marge'] < 0 ? ' neg' : '' ?>" data-l="Marge"><b><?= $e($r['marge']) ?></b></td><td class="num" data-l="Dû"><?= $e($r['du']) ?></td><td class="num" data-l="Versé"><?= $e($r['verse']) ?></td><td class="num" data-l="En attente"><?= $e($r['attente']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>

<?php elseif ($v === 'versements'): $vs = compta_versements($pdo); $rg = fin_reglages(true); ?>
<?php if ($vs['dettes'] || $vs['negatifs']): ?><div class="note err"><?= ic('alert') ?><div><b>Dettes d'organismes.</b> Un remboursement a eu lieu après un versement et n'a pas pu être repris. La dette est prélevée automatiquement sur la commission de ses prochains paiements par carte.</div></div><?php endif; ?>
<div class="cols">
  <section class="card"><h2 class="sec"><?= ic('clock') ?> Règle de versement</h2>
    <p class="muted">L'argent de chaque paiement arrive sur le <b>compte Stripe de l'organisme</b> dès le paiement (commission déduite). Il est <b>viré chaque jour sur sa banque</b> par moncamp.fr dès que les fonds sont disponibles (cron quotidien, ou bouton « Virer mon argent maintenant » de l'organisme ; en général 2 à 7 jours ouvrés après le paiement), <b>sans attendre le séjour</b>. Chaque versement est relevé avec sa date d'arrivée. Un remboursement après versement crée une dette, compensée sur ses paiements suivants.</p>
    <form class="mt" method="post" action="admin_compta.php?v=versements"><input type="hidden" name="_csrf" value="<?= h(adm_csrf()) ?>"><input type="hidden" name="act" value="direct_charges">
      <label class="fld"><span class="fl">Paiements par carte</span><select class="in" name="direct"><option value="1"<?= $rg['direct_charges'] ? ' selected' : '' ?>>Sur le compte de l'organisme (direct charges : frais Stripe à sa charge)</option><option value="0"<?= $rg['direct_charges'] ? '' : ' selected' ?>>Repli : encaissés par moncamp.fr puis transférés (frais Stripe à notre charge)</option></select></label>
      <p class="muted small">Direct charges seulement pour les comptes complets (aucune information demandée par Stripe). Repli à utiliser si la page de paiement Stripe affiche une erreur.</p>
      <label class="fld"><span class="fl">Votre mot de passe administrateur</span><input class="in" type="password" name="password" autocomplete="current-password" required></label>
      <div class="row-l"><button class="btn ghost" type="submit"><?= ic('check') ?> Enregistrer</button></div></form>
    <?php if ($vs['legacy']): ?><p class="muted small">Anciens paiements encore gardés par moncamp.fr : <?= $e($vs['legacy']) ?> (règles d'origine : <?= (int)$rg['delai_securite_jours'] ?> j après le paiement, réserve <?= h(number_format($rg['reserve_pct'], 0, ',', ' ')) ?> %), transférés automatiquement puis versés avec le reste.</p><?php endif; ?>
  </section>
  <section class="card"><h2 class="sec"><?= ic('clock') ?> Rattraper un organisme maintenant</h2>
    <p class="muted">Transfère tout de suite sur son compte Stripe le net des paiements reçus avant l'activation de son compte, et relève ses versements Stripe (dates d'arrivée). Les versements vers sa banque restent automatiques.</p>
    <form method="post" action="admin_compta.php?v=versements"><input type="hidden" name="_csrf" value="<?= h(adm_csrf()) ?>"><input type="hidden" name="act" value="versement_now">
      <label class="fld"><span class="fl">Organisme</span><select class="in" name="orga" required><?php foreach ($vs['orgas'] as $o): ?><option value="<?= h(fin_orga_tok((int)$o['id'])) ?>"><?= h($o['nom']) ?></option><?php endforeach; ?></select></label>
      <label class="fld"><span class="fl">Votre mot de passe administrateur</span><input class="in" type="password" name="password" autocomplete="current-password" required></label>
      <div class="row-l"><button class="btn" type="submit"><?= ic('check') ?> Lancer maintenant</button></div></form>
  </section>
</div>

<?php if (adm_can('paiements.write')): ?><section class="card" id="ajustement"><h2 class="sec"><?= ic('edit') ?> Ajouter un ajustement / une dette <?= adm_help('Mot de passe obligatoire. L’écriture est ajoutée au journal et ne peut pas être supprimée : une erreur se corrige par un ajustement inverse.') ?></h2>
  <p class="muted">Dette : l'organisme doit ce montant à moncamp.fr (compensée sur ses prochains paiements, visible dans son onglet Dette). Crédit : moncamp.fr lui doit ce montant. Journalisé avec le motif.</p>
  <form method="post" action="admin_compta.php?v=versements"><input type="hidden" name="_csrf" value="<?= h(adm_csrf()) ?>"><input type="hidden" name="act" value="ajustement">
    <label class="fld"><span class="fl">Organisme</span><select class="in" name="orga" required><?php foreach ($pdo->query("SELECT id, nom FROM organisateurs ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC) as $o): ?><option value="<?= h(fin_orga_tok((int)$o['id'])) ?>"><?= h(html_entity_decode((string)$o['nom'], ENT_QUOTES, 'UTF-8')) ?></option><?php endforeach; ?></select></label>
    <label class="fld"><span class="fl">Type</span><select class="in" name="sens"><option value="dette">Dette de l'organisme</option><option value="credit">Crédit à l'organisme</option></select></label>
    <label class="fld"><span class="fl">Montant (EUR)</span><input class="in" name="montant" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" required></label>
    <label class="fld"><span class="fl">Motif (obligatoire, visible par l'organisme)</span><input class="in" name="motif" minlength="3" maxlength="110" required></label>
    <label class="fld"><span class="fl">Votre mot de passe administrateur</span><input class="in" type="password" name="password" autocomplete="current-password" required></label>
    <div class="row-l"><button class="btn" type="submit"><?= ic('check') ?> Enregistrer</button></div></form>
</section><?php endif; ?>

<h2 class="sec"><?= ic('building') ?> Situation par organisme</h2>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Organisme</th><th class="num">À verser maintenant</th><th class="num">Séjours à venir</th><th class="num">Litige</th><th class="num">Anciens paiements</th><th class="num">Solde moncamp.fr</th></tr></thead><tbody>
  <?php $any = false; foreach ($vs['orgas'] as $o): if (!($o['ok'] || $o['futur'] || $o['litige'] || $o['legacy'] || abs((float)$o['portefeuille']) > 0.004)) continue; $any = true; ?>
  <tr><td data-l="Organisme"><b><?= h($o['nom']) ?></b></td><td class="num" data-l="À verser"><b><?= $e($o['ok']) ?></b></td><td class="num" data-l="À venir"><?= $e($o['futur']) ?></td><td class="num" data-l="Litige"><?= $e($o['litige']) ?></td><td class="num" data-l="Anciens"><?= $e($o['legacy']) ?></td><td class="num<?= $o['portefeuille'] < 0 ? ' neg' : '' ?>" data-l="Solde"><?= $e((int)round($o['portefeuille'] * 100)) ?></td></tr>
  <?php endforeach; if (!$any): ?><tr><td colspan="6" class="empty"><p>Aucun fonds en attente.</p></td></tr><?php endif; ?>
  </tbody></table></div>
<p class="muted small">Solde moncamp.fr négatif : dette de l'organisme (commission d'un paiement hors carte, remboursement non repris), prélevée sur ses prochains paiements par carte.</p>

<h2 class="sec"><?= ic('list') ?> Derniers versements bancaires</h2>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Date</th><th>Organisme</th><th class="num">Montant</th><th>Statut</th></tr></thead><tbody>
  <?php if (!$vs['payouts']): ?><tr><td colspan="4" class="empty"><p>Aucun versement pour l'instant.</p></td></tr><?php endif; ?>
  <?php foreach ($vs['payouts'] as $pp): $lab = ['en_cours' => 'En préparation', 'envoye' => 'En cours', 'paye' => 'Payé', 'echec' => 'Échoué'][$pp['statut']] ?? $pp['statut']; ?>
  <tr><td data-l="Date"><?= h(adm_date($pp['created_at'])) ?></td><td data-l="Organisme"><?= h($pp['orga']) ?></td><td class="num" data-l="Montant"><b><?= $e($pp['montant_cents']) ?></b></td><td data-l="Statut"><?= h($lab) ?><?= $pp['statut'] === 'echec' && $pp['erreur'] ? ' <span class="muted small">' . h($pp['erreur']) . '</span>' : '' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>

<h2 class="sec" id="dettes"><?= ic('alert') ?> Dettes d'organismes (reversements impossibles)</h2>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Date</th><th>Organisme</th><th>Inscription</th><th class="num">Reste dû</th><th></th></tr></thead><tbody>
  <?php if (!$vs['dettes']): ?><tr><td colspan="5" class="empty"><p>Aucune dette.</p></td></tr><?php endif; ?>
  <?php foreach ($vs['dettes'] as $d): ?><tr><td data-l="Date"><?= h(adm_date($d['created_at'])) ?></td><td data-l="Organisme"><?= h($d['orga']) ?></td><td data-l="Inscription"><?php if (adm_can('paiements.read')): ?><a class="lnk-q" href="<?= h(cu(['v' => 'dettes'])) ?>">Voir</a><?php endif; ?></td><td class="num neg" data-l="Reste dû"><b><?= $e($d['montant_cents'] - $d['recupere_cents']) ?></b></td>
    <td class="acts"><form class="inl" method="post" action="admin_compta.php?v=versements"><input type="hidden" name="_csrf" value="<?= h(adm_csrf()) ?>"><input type="hidden" name="act" value="retenter"><input type="hidden" name="rev" value="<?= h(adm_tok('rev', (int)$d['id'])) ?>"><button class="btn ghost sm" type="submit">Retenter le reversement</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
<p class="muted small">Un reversement échoue si le solde Stripe de l'organisme est insuffisant (il a déjà été viré sur sa banque). La dette reste alors en solde négatif et se compense toute seule sur ses prochains versements.</p>

<?php elseif ($v === 'rapprochement'):
    $stripeBal = null; $schedule = null; $stripeErr = '';
    try {
        $b = fin_stripe()->balance->retrieve();
        $sum_ = ['disponible' => 0, 'en_attente' => 0];
        foreach ($b->available as $x) if (strtolower($x->currency) === 'eur') $sum_['disponible'] += (int)$x->amount;
        foreach ($b->pending as $x) if (strtolower($x->currency) === 'eur') $sum_['en_attente'] += (int)$x->amount;
        $stripeBal = $sum_;
        try { $acct = fin_stripe()->accounts->retrieve(); $schedule = $acct->settings->payouts->schedule->interval ?? null; } catch (Throwable $e2) { $schedule = null; }
    } catch (Throwable $e1) { error_log('compta rapprochement: ' . $e1->getMessage()); $stripeErr = 'Stripe injoignable ou clé non configurée : seule la partie « base de données » est affichée.'; }
    $rc = compta_reconcile($pdo, $stripeBal);
    $pays = $pdo->query("SELECT * FROM fin_payouts ORDER BY date_creation DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
?>
<?php if ($stripeErr): ?><div class="note warn"><?= ic('alert') ?><div><?= h($stripeErr) ?></div></div><?php endif; ?>
<?php if ($schedule !== null && $schedule !== 'manual'): ?>
<div class="note err"><?= ic('alert') ?><div><b>Les virements Stripe vers votre banque sont en mode « <?= h($schedule) ?> ».</b> Ils vident le solde Stripe, y compris l'argent dû aux organismes : les transferts vers eux échoueraient faute de fonds. Passez les virements en <b>manuel</b> (Stripe &gt; Paramètres &gt; Compte bancaire et devises) et ne virez que le montant « versable » ci-dessous.</div></div>
<?php endif; ?>
<div class="bar"><form class="inl" method="post" action="admin_compta.php"><input type="hidden" name="_csrf" value="<?= h(adm_csrf()) ?>"><input type="hidden" name="act" value="payouts"><button class="btn" type="submit"><?= ic('refresh') ?> Synchroniser les virements Stripe</button></form><span class="muted small">Lecture seule : aucune action financière n'est déclenchée chez Stripe.</span></div>
<div class="cols">
  <section class="card"><h2 class="sec"><?= ic('database') ?> D'après la base (théorique)</h2>
    <div class="kv"><span>Encaissements d'inscriptions (nets des remboursements)</span><b><?= $e($rc['ca']) ?></b></div>
    <div class="kv"><span>+ Achats de points par carte</span><b><?= $e($rc['points']) ?></b></div>
    <div class="kv"><span>− Frais Stripe relevés</span><b><?= $e($rc['frais']) ?></b></div>
    <div class="kv"><span>− Frais de litige</span><b><?= $e($rc['litige']) ?></b></div>
    <div class="kv"><span>dont commissions hors carte (retenues sur les versements : l'argent reste chez vous)</span><b><?= $e($rc['hors']) ?></b></div>
    <div class="kv"><span>− Transferts envoyés aux organismes</span><b><?= $e($rc['transferts']) ?></b></div>
    <div class="kv"><span><b>= Fonds moncamp.fr attendus (Stripe + banque)</b></span><b><?= $e($rc['theorique']) ?></b></div>
    <?php if ($rc['frais_inconnus'] > 0): ?><p class="muted small"><?= $rc['frais_inconnus'] ?> paiement(s) sans frais relevés : le théorique est surévalué d'autant.</p><?php endif; ?>
  </section>
  <section class="card"><h2 class="sec"><?= ic('bank') ?> D'après Stripe (réel)</h2>
    <?php if ($stripeBal): ?>
    <div class="kv"><span>Solde Stripe disponible</span><b><?= $e($stripeBal['disponible']) ?></b></div>
    <div class="kv"><span>Solde Stripe en attente</span><b><?= $e($stripeBal['en_attente']) ?></b></div>
    <div class="kv"><span>+ Virements déjà faits vers votre banque (<?= $rc['nb_payouts'] ?>)</span><b><?= $e($rc['payouts']) ?></b></div>
    <div class="kv"><span><b>= Fonds réellement constatés</b></span><b><?= $e($rc['reel']) ?></b></div>
    <div class="kv"><span>Écart (réel − théorique)</span><b class="<?= abs($rc['ecart']) > 100 ? 'neg' : '' ?>"><?= $e($rc['ecart']) ?></b></div>
    <p class="muted small">Un petit écart est normal : frais de virement/Connect facturés par Stripe, paiements antérieurs à ce module, boosts payés par carte, mouvements en cours. Un écart important ou qui grandit est à investiguer (journal d'audit, export des paiements).</p>
    <?php else: ?><p class="muted">Solde Stripe indisponible.</p><?php endif; ?>
  </section>
</div>
<?php if ($stripeBal): ?>
<section class="card hl"><h2 class="sec"><?= ic('key') ?> Combien pouvez-vous virer sur votre banque ?</h2>
  <div class="kv"><span>Solde Stripe disponible</span><b><?= $e($stripeBal['disponible']) ?></b></div>
  <div class="kv"><span>− Dû aux organismes (net non encore transféré)</span><b><?= $e($rc['du_orgas']) ?></b></div>
  <div class="kv"><span><b>= Montant versable sur votre banque sans toucher aux fonds des organismes</b></span><b class="big"><?= $e($rc['versable']) ?></b></div>
  <p class="muted small">C'est votre commission accumulée (marge + frais Stripe déjà payés). Le virement se fait dans Stripe (Soldes &gt; Virement) : moncamp.fr ne déclenche jamais de virement vers votre banque.</p>
</section>
<?php endif; ?>
<h2 class="sec"><?= ic('bank') ?> Virements Stripe vers la banque de la plateforme</h2>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Créé le</th><th>Arrivée</th><th class="num">Montant</th><th>Statut</th><th>Référence</th></tr></thead><tbody>
  <?php if (!$pays): ?><tr><td colspan="5" class="empty"><p>Aucun virement synchronisé. Utilisez le bouton ci-dessus.</p></td></tr><?php endif; ?>
  <?php foreach ($pays as $p): ?><tr><td data-l="Créé"><?= h(adm_date($p['date_creation'])) ?></td><td data-l="Arrivée"><?= h(adm_date($p['date_arrivee'])) ?></td><td class="num" data-l="Montant"><b><?= $e($p['montant_cents']) ?></b></td><td data-l="Statut"><span class="pill <?= $p['statut'] === 'paid' ? 'ok' : ($p['statut'] === 'failed' ? 'bad' : 'warn') ?>"><?= h($p['statut']) ?></span></td><td class="mono small" data-l="Référence"><?= h($p['payout_id']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>

<?php elseif ($v === 'export'): ?>
<?php if (!adm_reauth_ok()): ?>
<section class="card"><h2 class="sec"><?= ic('lock') ?> Exports verrouillés</h2>
  <p class="muted">Les exports contiennent toutes les écritures financières. Confirmez votre mot de passe administrateur pour 10 minutes.</p>
  <form method="post" action="admin_compta.php?v=export"><input type="hidden" name="_csrf" value="<?= h(adm_csrf()) ?>"><input type="hidden" name="act" value="unlock">
    <input class="in pw" type="password" name="password" autocomplete="current-password" placeholder="Mot de passe" required> <button class="btn" type="submit"><?= ic('lock') ?> Déverrouiller</button></form>
</section>
<?php else:
    $qs = ['p' => $pk] + ($pk === 'perso' ? ['du' => substr($from, 0, 10), 'au' => substr($to, 0, 10)] : []) + ($orgaTok ? ['o' => $orgaTok] : []);
    $exp = [
        ['paiements', 'Écritures de paiement', 'Une ligne par inscription payée : brut, commission, frais Stripe réels, marge, net dû, statut.'],
        ['transferts', 'Versements aux organismes', 'Transferts Stripe Connect envoyés (montant, statut, référence).'],
        ['organismes', 'Synthèse par organisme', 'CA, commissions, frais, marge, dû / versé / en attente (ignore le filtre organisme).'],
        ['hors_stripe', 'Paiements hors carte et commissions', 'Chèques, espèces, virements... marqués reçus par les organisateurs, avec la commission retenue.'],
        ['payouts', 'Virements Stripe vers la banque', 'Pour le rapprochement avec votre relevé bancaire (synchroniser d\'abord).'],
        ['gains', 'Paiements avec les gains', 'Achats de points payés avec le solde à recevoir : qui, IP, montant, solde avant/après, statut, empreinte.'],
        ['ledger', 'Journal comptable', 'Toutes les écritures de fin_ledger de la période (débit, crédit, référence, acteur).'],
        ['grandlivre', 'Grand livre de l’organisme', 'Écritures et solde cumulé d’un organisme (choisissez l’organisme dans le filtre).'],
        ['journal', 'Journal d\'audit financier', 'Toutes les opérations (paiements, remboursements, transferts, suspensions).'],
    ]; ?>
<p class="muted">Période : <b><?= h($periodLabel) ?></b><?= $orgaTok ? ' · un seul organisme' : '' ?>. Format Excel français (séparateur « ; », UTF-8, virgule décimale).</p>
<div class="cols">
<?php foreach ($exp as [$t, $l, $d]): if ($t === 'grandlivre' && !$orgaTok) continue; ?><section class="card"><h2 class="sec"><?= ic('list') ?> <?= h($l) ?></h2><p class="muted"><?= h($d) ?></p><div class="row-l wrap-row"><a class="btn" href="<?= h('admin_compta_export.php?' . http_build_query(['t' => $t] + $qs)) ?>"><?= ic('download') ?> CSV</a><a class="btn ghost" href="<?= h('admin_compta_export.php?' . http_build_query(['t' => $t, 'f' => 'xlsx'] + $qs)) ?>"><?= ic('download') ?> Excel</a></div></section><?php endforeach; ?>
<section class="card"><h2 class="sec"><?= ic('list') ?> Fichier des écritures comptables (FEC) <?= adm_help('Format texte tabulé de l’administration fiscale. Seules les écritures propres à moncamp.fr (commissions, frais Stripe à sa charge, dettes, ajustements) sont exportées, avec un plan de comptes proposé : à valider par votre expert-comptable. Renseignez le SIREN dans l’onglet TVA.') ?></h2><p class="muted">Période choisie ci-dessus, fichier SIRENFECAAAAMMJJ.txt.</p><a class="btn" href="<?= h('admin_compta_export.php?' . http_build_query(['t' => 'fec'] + $qs)) ?>"><?= ic('download') ?> FEC</a></section>
</div>
<?php endif; ?>

<?php elseif (in_array($v, ['journal', 'grandlivre', 'paiements', 'remboursements', 'dettes', 'tva', 'cloture', 'recherche'], true)): require __DIR__ . '/admin_compta_plus.php'; ?>

<?php else: /* réglages */
    $rg = fin_reglages(true); $stripePct = 1.5; $stripeFixe = 25;
    $sims = [1500, 2500, 10000, 50000, 200000];
?>
<div class="cols">
  <section class="card"><h2 class="sec"><?= ic('euro') ?> Commission moncamp.fr</h2>
    <p class="muted">Prélevée sur chaque inscription : <b>pourcentage + part fixe</b>. Les frais de paiement Stripe sont en plus, débités par Stripe à l'organisme (direct charges) : la marge ne peut plus être négative. Anciens paiements et comptes pas encore ouverts aux cartes : frais payés par moncamp.fr. Le changement ne s'applique qu'aux <b>prochains paiements</b> ; chaque paiement conserve le taux de sa vente.</p>
    <form method="post" action="admin_compta.php">
      <input type="hidden" name="_csrf" value="<?= h(adm_csrf()) ?>"><input type="hidden" name="act" value="commission">
      <label class="fld"><span class="fl">Pourcentage du montant payé <small>(0 à 30 %)</small></span><input class="in" name="pct" inputmode="decimal" value="<?= h(number_format($rg['commission_pct'], 2, ',', '')) ?>" required></label>
      <label class="fld"><span class="fl">Part fixe par inscription, en centimes <small>(0 à 500)</small></span><input class="in" name="fixe" type="number" min="0" max="500" step="1" value="<?= (int)$rg['commission_fixe_cents'] ?>" required></label>
      <label class="fld"><span class="fl">Commission sur les paiements hors carte (chèque, espèces...), en % <small>(0 à 30)</small></span><input class="in" name="hors" inputmode="decimal" value="<?= h(number_format($rg['commission_hors_pct'], 2, ',', '')) ?>" required></label>
      <label class="fld"><span class="fl">Votre mot de passe administrateur</span><input class="in" type="password" name="password" autocomplete="current-password" required></label>
      <div class="row-l"><button class="btn" type="submit"><?= ic('check') ?> Enregistrer</button></div>
    </form>
  </section>
  <section class="card"><h2 class="sec"><?= ic('chart') ?> Simulation (frais Stripe estimés : <?= str_replace('.', ',', (string)$stripePct) ?> % + <?= compta_eur($stripeFixe) ?> €)</h2>
    <div class="tbl-wrap flat"><table class="tbl"><thead><tr><th class="num">Payé</th><th class="num">Commission</th><th class="num">Net organisme</th><th class="num">Frais Stripe</th><th class="num">Marge</th></tr></thead><tbody>
    <?php foreach ($sims as $s): [$c] = fin_commission_split($s); $f = (int)round($s * $stripePct / 100) + $stripeFixe; $m = $c - $f; ?>
      <tr><td class="num"><?= $e($s) ?></td><td class="num"><?= $e($c) ?></td><td class="num"><?= $e($s - $c) ?></td><td class="num"><?= $e($f) ?></td><td class="num<?= $m < 0 ? ' neg' : '' ?>"><b><?= $e($m) ?></b></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <p class="muted small">Hypothèses à vérifier dans votre tarif Stripe (Paramètres &gt; Tarification) : carte européenne standard 1,5 % + 0,25 € ; cartes britanniques ≈ 2,5 % + 0,25 € ; cartes hors Europe ≈ 3,25 % + 0,25 € (+ conversion). Les frais réels de chaque paiement sont relevés dans la Synthèse ; une marge négative déclenche l'alerte. Hors simulation : Connect Express peut ajouter un abonnement par compte actif et des frais par virement vers l'organisme, à contrôler dans votre facture Stripe.</p>
    <p class="muted small">Seuil de rentabilité (carte européenne) : une commission de <?= number_format($rg['commission_pct'], 2, ',', ' ') ?> % + <?= compta_eur($rg['commission_fixe_cents']) ?> € couvre les frais Stripe tant que le taux Stripe reste inférieur à <?= number_format($rg['commission_pct'] + 100 * ($rg['commission_fixe_cents'] - $stripeFixe) / 5000, 2, ',', ' ') ?> % sur un panier de 50 €.</p>
  </section>
</div>
<?php endif; ?>
<?php adm_foot(['page' => 'compta']); ?>

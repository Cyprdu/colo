<?php
// admin_demande_virement.php : supervision des versements aux organismes (administrateurs).
// Les versements sont automatiques (api/cron_virements.php) ; cette page permet de surveiller, forcer, suspendre et auditer.
require_once 'api/config.php';
require_once 'api/finance_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user']) || empty($_SESSION['user']['is_admin'])) { header('Location: alerte.html'); exit; }

$adminId = (int)$_SESSION['user']['id'];
$acteur = 'admin:' . $adminId;
$message = null; $error = null;
fin_ensure($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!fin_csrf_ok()) throw new Exception('Session expirée, rechargez la page.');
        $action = (string)($_POST['action'] ?? '');
        $orgaId = (int)($_POST['organisateur_id'] ?? 0);
        if ($action === 'release_now') {
            $r = fin_release_due($pdo, $acteur);
            $message = $r['transferts'] . ' transfert(s) envoyé(s) (' . fin_eur($r['montant_cents']) . '), ' . $r['reportes'] . ' reporté(s), ' . $r['erreurs'] . ' erreur(s).';
        } elseif ($action === 'release_orga' && $orgaId) {
            $r = fin_release_due($pdo, $acteur, $orgaId);
            $message = $r['transferts'] ? 'Transfert envoyé.' : 'Rien à transférer pour cet organisme (compte non prêt, solde nul ou date de déblocage non atteinte).';
        } elseif (($action === 'hold' || $action === 'unhold') && $orgaId) {
            $from = $action === 'hold' ? 'attente' : 'bloque'; $to = $action === 'hold' ? 'bloque' : 'attente';
            $st = $pdo->prepare("UPDATE fin_paiements SET statut = ? WHERE organisateur_id = ? AND statut = ?");
            $st->execute([$to, $orgaId, $from]);
            fin_audit($pdo, $action === 'hold' ? 'versements_suspendus' : 'versements_repris', 'organisateur', $orgaId, ['lignes' => $st->rowCount()], $acteur);
            $message = $action === 'hold' ? 'Versements suspendus pour cet organisme.' : 'Versements repris.';
        } elseif ($action === 'legacy_pay' && $orgaId) {
            $tid = fin_transfer_legacy($pdo, $orgaId, $acteur);
            if (!$tid) throw new Exception("Solde historique non versable (compte Stripe non prêt ou montant trop faible).");
            $res = fin_execute_transfer($pdo, $tid, $acteur);
            $message = $res === 'envoye' ? 'Solde historique versé.' : 'Transfert ' . $res . ' : voir le journal.';
        } elseif ($action === 'valider_virement') { // ancienne file de demandes manuelles
            $tk = (string)($_POST['token'] ?? '');
            $st = $pdo->prepare("UPDATE virements SET effectue = 1, date_virement_effectue = NOW() WHERE token = ? AND effectue = 0");
            $st->execute([$tk]);
            if (!$st->rowCount()) throw new Exception('Demande introuvable ou déjà traitée.');
            fin_audit($pdo, 'ancien_virement_valide', 'virement', substr($tk, 0, 12), [], $acteur);
            $message = 'Ancienne demande marquée comme effectuée.';
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$orgs = $pdo->query("SELECT o.id, o.nom, o.portefeuille, o.stripe_account_id,
        COALESCE(SUM(CASE WHEN p.statut IN ('attente','bloque','litige','en_cours') THEN GREATEST(0, p.net_cents - p.net_rembourse_cents - p.verse_cents) END), 0) a_recevoir,
        COALESCE(SUM(CASE WHEN p.statut = 'attente' AND p.date_deblocage <= NOW() THEN GREATEST(0, p.net_cents - p.net_rembourse_cents - p.verse_cents) END), 0) deblocable,
        COALESCE(SUM(CASE WHEN p.statut = 'bloque' THEN 1 END), 0) nb_bloques, COALESCE(SUM(CASE WHEN p.statut = 'litige' THEN 1 END), 0) nb_litiges,
        MIN(CASE WHEN p.statut = 'attente' THEN p.date_deblocage END) prochaine
    FROM organisateurs o LEFT JOIN fin_paiements p ON p.organisateur_id = o.id
    GROUP BY o.id, o.nom, o.portefeuille, o.stripe_account_id
    HAVING a_recevoir <> 0 OR ABS(o.portefeuille) > 0.004 ORDER BY deblocable DESC, a_recevoir DESC")->fetchAll(PDO::FETCH_ASSOC);
$comptes = [];
foreach ($pdo->query("SELECT * FROM stripe_comptes")->fetchAll(PDO::FETCH_ASSOC) as $c) $comptes[(int)$c['organisateur_id']] = $c;

$tr = $pdo->query("SELECT t.*, o.nom FROM fin_transferts t JOIN organisateurs o ON o.id = t.organisateur_id ORDER BY t.created_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
$audit = $pdo->query("SELECT * FROM fin_audit ORDER BY id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
try { $anciens = $pdo->query("SELECT v.*, o.nom AS organisateur_nom FROM virements v JOIN organisateurs o ON o.id = v.organisateur_id WHERE v.effectue = 0 ORDER BY v.date_demande ASC")->fetchAll(PDO::FETCH_ASSOC); }
catch (Throwable $e) { $anciens = []; }

$tot = ['a_recevoir' => 0, 'deblocable' => 0];
foreach ($orgs as $o) { $tot['a_recevoir'] += (int)$o['a_recevoir']; $tot['deblocable'] += (int)$o['deblocable']; }
$nbEchec = 0; $nbCours = 0; foreach ($tr as $t) { if ($t['statut'] === 'echec') $nbEchec++; if ($t['statut'] === 'en_cours') $nbCours++; }

$body_class = 'bg-white';
require_once 'partials/header.php';
require_once 'partials/mk_icons.php';
$csrf = fin_csrf();
function postbtn($label, $action, $orgaId, $csrf, $confirm = '', $light = true) {
    return '<form method="post" style="display:inline"' . ($confirm ? ' data-confirm="' . htmlspecialchars($confirm, ENT_QUOTES) . '"' : '') . '><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="organisateur_id" value="' . (int)$orgaId . '"><button class="ds-btn ' . ($light ? 'ds-btn-light ' : '') . 'mk-btn-sm" type="submit">' . $label . '</button></form>';
}
?>
<title>Admin - Versements aux organismes</title>
<link rel="stylesheet" href="assets/mk.css?v=1">
<main class="mk-wrap" style="max-width:1200px">
    <a href="admin.php" class="mk-back"><?= mk_icon('back', 16) ?> Administration</a>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap">
        <div><p class="ds-eyebrow">Finances</p><h1 class="ds-h1">Versements aux organismes</h1><p class="ds-lead">Automatiques : chaque organisme est versé un jour fixe par semaine, <?= fin_release_delay_days() ?> jours après le paiement, avec une réserve de <?= number_format(fin_reserve_pct(), 0, ',', ' ') ?> % tant qu'une annulation est possible (réglages : Comptabilité &gt; Versements). Commission : <?= number_format(fin_commission_pct(), 2, ',', ' ') ?> %.</p></div>
        <div style="display:flex;gap:10px;flex-wrap:wrap"><a class="ds-btn ds-btn-light" href="admin_commission_stats.php">Commissions</a><?= postbtn('Lancer les déblocages maintenant', 'release_now', 0, $csrf, 'Transférer maintenant tous les fonds dont la date est passée ?', false) ?></div>
    </div>

    <?php if ($message): ?><div class="ds-alert-ok" style="margin-top:16px"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="ds-alert-err" style="margin-top:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="mk-grid mk-g4" style="margin-top:22px">
        <div class="mk-stat dark"><div class="k">Dû aux organismes</div><div class="v"><?= fin_eur($tot['a_recevoir']) ?></div><div class="s">net, non encore transféré</div></div>
        <div class="mk-stat"><div class="k">Déblocable maintenant</div><div class="v"><?= fin_eur($tot['deblocable']) ?></div><div class="s">délai de sécurité passé, avant réserve et jour de versement</div></div>
        <div class="mk-stat"><div class="k">Transferts en échec</div><div class="v"><?= $nbEchec ?></div><div class="s">sur les 30 derniers</div></div>
        <div class="mk-stat"><div class="k">En cours d'envoi</div><div class="v"><?= $nbCours ?></div><div class="s">rejoués automatiquement</div></div>
    </div>

    <section class="mk-section"><h2>Organismes</h2>
        <div class="mk-table-wrap" style="margin-top:14px"><table class="mk-table" style="min-width:900px">
            <thead><tr><th>Organisme</th><th>Compte Stripe</th><th class="num">Dû (net)</th><th class="num">Déblocable</th><th>Prochaine date</th><th class="num">Solde portefeuille</th><th></th></tr></thead><tbody>
            <?php foreach ($orgs as $o): $oid = (int)$o['id']; $acc = $comptes[$oid] ?? null; $ready = fin_account_ready($acc); $hist = max(0, (int)round($o['portefeuille'] * 100) - (int)$o['a_recevoir']); ?>
                <tr>
                    <td><b><?= htmlspecialchars($o['nom']) ?></b><?= $o['nb_litiges'] ? ' <span class="mk-pill bad">litige</span>' : '' ?><?= $o['nb_bloques'] ? ' <span class="mk-pill warn">suspendu</span>' : '' ?></td>
                    <td><?php if ($ready): ?><span class="mk-pill ok">Prêt</span><?php elseif ($acc): ?><span class="mk-pill warn">Incomplet</span><?php elseif ($o['stripe_account_id']): ?><span class="mk-pill warn">Non synchronisé</span><?php else: ?><span class="mk-pill bad">Aucun</span><?php endif; ?></td>
                    <td class="num"><?= fin_eur((int)$o['a_recevoir']) ?></td>
                    <td class="num"><b><?= fin_eur((int)$o['deblocable']) ?></b></td>
                    <td><?= $o['prochaine'] ? date('d/m/Y', strtotime($o['prochaine'])) : '-' ?></td>
                    <td class="num"><?= fin_eur((int)round($o['portefeuille'] * 100)) ?><?= $hist > 0 ? '<div class="mk-note">dont historique : ' . fin_eur($hist) . '</div>' : '' ?></td>
                    <td class="num" style="white-space:normal">
                        <?php if ((int)$o['deblocable'] > 0 && $ready): echo postbtn('Verser', 'release_orga', $oid, $csrf, 'Verser maintenant ' . fin_eur((int)$o['deblocable']) . ' (max.) à ' . $o['nom'] . ' ?'); endif; ?>
                        <?php if ($hist >= 100 && $ready): echo postbtn('Solde historique', 'legacy_pay', $oid, $csrf, 'Verser le solde historique net de commission ?'); endif; ?>
                        <?= $o['nb_bloques'] ? postbtn('Reprendre', 'unhold', $oid, $csrf) : postbtn('Suspendre', 'hold', $oid, $csrf, 'Suspendre les versements de cet organisme ?') ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$orgs): ?><tr><td colspan="7" class="mk-note" style="text-align:center;padding:26px">Aucun fonds en attente.</td></tr><?php endif; ?>
            </tbody></table></div>
    </section>

    <section class="mk-section"><h2>Derniers transferts</h2>
        <div class="mk-table-wrap" style="margin-top:14px"><table class="mk-table" style="min-width:820px">
            <thead><tr><th>Date</th><th>Organisme</th><th class="num">Montant</th><th>Type</th><th>Statut</th><th>Stripe / erreur</th><th></th></tr></thead><tbody>
            <?php foreach ($tr as $t): $s = ['envoye' => ['Envoyé', 'ok'], 'en_cours' => ['En cours', 'info'], 'echec' => ['Échec', 'bad']][$t['statut']] ?? [$t['statut'], '']; ?>
                <tr><td><?= date('d/m/Y H:i', strtotime($t['created_at'])) ?></td><td><?= htmlspecialchars($t['nom']) ?></td><td class="num"><b><?= fin_eur((int)$t['montant_cents']) ?></b></td><td><?= htmlspecialchars($t['type']) ?></td><td><span class="mk-pill <?= $s[1] ?>"><?= $s[0] ?></span></td><td class="mk-note" style="max-width:260px;overflow-wrap:anywhere"><?= htmlspecialchars($t['stripe_transfer_id'] ?: ($t['erreur'] ?? '')) ?></td><td class="num"><a class="underline" target="_blank" href="virement.php?t=<?= urlencode($t['token']) ?>">Justificatif</a></td></tr>
            <?php endforeach; ?>
            <?php if (!$tr): ?><tr><td colspan="7" class="mk-note" style="text-align:center;padding:26px">Aucun transfert.</td></tr><?php endif; ?>
            </tbody></table></div>
    </section>

    <?php if ($anciens): ?>
    <section class="mk-section"><h2>Anciennes demandes manuelles en attente (<?= count($anciens) ?>)</h2>
        <p class="lead">Demandes faites avant la mise en place des versements automatiques : à régler à la main puis à marquer comme effectuées.</p>
        <div class="mk-table-wrap" style="margin-top:14px"><table class="mk-table"><thead><tr><th>Demande du</th><th>Organisme</th><th class="num">Net</th><th>IBAN</th><th></th></tr></thead><tbody>
            <?php foreach ($anciens as $v): ?><tr><td><?= date('d/m/Y', strtotime($v['date_demande'])) ?></td><td><?= htmlspecialchars($v['organisateur_nom']) ?></td><td class="num"><b><?= number_format($v['montant_apres_commission'], 2, ',', ' ') ?> €</b></td><td class="mk-note" style="font-family:monospace;overflow-wrap:anywhere"><?= htmlspecialchars($v['iban']) ?></td>
                <td class="num"><form method="post" style="display:inline" data-confirm="Confirmer que ce virement a bien été effectué ?"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="valider_virement"><input type="hidden" name="token" value="<?= htmlspecialchars($v['token']) ?>"><button class="ds-btn mk-btn-sm" type="submit">Marquer effectué</button></form></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
    <?php endif; ?>

    <section class="mk-section"><h2>Journal d'audit</h2>
        <p class="lead">Toute opération financière (paiement reçu, remboursement, transfert, suspension) est tracée ici.</p>
        <div class="mk-table-wrap" style="margin-top:14px"><table class="mk-table" style="min-width:780px"><thead><tr><th>Date</th><th>Acteur</th><th>Action</th><th>Objet</th><th>Détail</th></tr></thead><tbody>
            <?php foreach ($audit as $a): ?><tr><td style="white-space:nowrap"><?= date('d/m H:i:s', strtotime($a['created_at'])) ?></td><td class="mk-note"><?= htmlspecialchars($a['acteur']) ?></td><td><b><?= htmlspecialchars($a['action']) ?></b></td><td class="mk-note"><?= htmlspecialchars($a['objet_type'] . ' ' . $a['objet_id']) ?></td><td class="mk-note" style="max-width:300px;overflow-wrap:anywhere"><?= htmlspecialchars(mb_substr((string)$a['donnees'], 0, 200)) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</main>
<?php require_once 'partials/footer.php'; ?>

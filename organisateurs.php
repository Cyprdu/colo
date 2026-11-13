<?php
// organisateurs.php : page d'accueil de l'espace organisateur (accès rapides + chiffres clés).
require_once 'api/config.php';

if (!isset($_SESSION['user']) || empty($_SESSION['user']['is_directeur'])) {
    header('Location: index.php');
    exit;
}
$userId = $_SESSION['user']['id'];
$prenom = trim((string)($_SESSION['user']['prenom'] ?? ''));

// Chiffres clés (en cas d'erreur la page reste utilisable, sans chiffres)
$st = ['orgas' => 0, 'avenir' => 0, 'inscrits' => 0, 'solde' => 0.0, 'attente' => 0];
$orgaIds = [];
$statsOk = true;
try {
    $q = $pdo->prepare("SELECT id, portefeuille FROM organisateurs WHERE user_id = ?");
    $q->execute([$userId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) { $orgaIds[] = (int)$o['id']; $st['solde'] += (float)$o['portefeuille']; }
    $st['orgas'] = count($orgaIds);
    if ($orgaIds) {
        $in = implode(',', $orgaIds);
        $r = $pdo->query("SELECT SUM(date_fin >= CURDATE() AND valide = 1) av, SUM(valide = 0 AND COALESCE(refuse,0) = 0) att FROM camps WHERE organisateur_id IN ($in) AND COALESCE(supprime,0) = 0")->fetch(PDO::FETCH_ASSOC);
        $st['avenir'] = (int)$r['av']; $st['attente'] = (int)$r['att'];
        $st['inscrits'] = (int)$pdo->query("SELECT COUNT(*) FROM inscriptions i JOIN camps c ON c.id = i.camp_id WHERE c.organisateur_id IN ($in) AND COALESCE(c.supprime,0) = 0 AND c.date_fin >= CURDATE() AND COALESCE(i.statut,'') NOT IN ('annule','Annulé','Annule')")->fetchColumn();
    }
} catch (Throwable $e) {
    error_log('organisateurs: ' . $e->getMessage());
    $statsOk = false;
}
$financeHref = $st['orgas'] === 1 ? 'dashboard_organisme.php?organisateur_id=' . $orgaIds[0] : 'public_infos.php';

$ico = [
    'sejours'  => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/>',
    'plus'     => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
    'orga'     => '<path d="M4 21V8l8-5 8 5v13M9 21v-6h6v6M8 11h.01M16 11h.01"/>',
    'anim'     => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6M16 5.2a3 3 0 010 5.6M18 14.3c1.8.8 3 2.6 3 4.7"/>',
    'mail'     => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 8l9 6 9-6"/>',
    'bolt'     => '<path d="M13 3L5 14h6l-1 7 8-11h-6l1-7z"/>',
    'euro'     => '<path d="M17 7a6 6 0 100 10M5 10h8M5 14h8"/>',
];
$tiles = [
    ['mes_camps.php', 'sejours', 'Mes séjours', 'Modifier, dupliquer, suivre les inscrits.'],
    ['create_camp.php', 'plus', 'Créer un séjour', 'Ajouter une nouvelle offre au catalogue.'],
    ['public_infos.php', 'orga', 'Mes organismes', 'Informations publiques, contacts, tableau de bord.'],
    ['demandes-animation.php', 'anim', 'Animateurs', 'Candidatures et inscription de votre équipe.'],
    ['messagerie.php', 'mail', 'Messagerie', 'Les questions des familles.'],
    ['marketing.php', 'bolt', 'Marketing et boost', 'Points, mise en avant de vos séjours.'],
    [$financeHref, 'euro', 'Finances et versements', 'Solde, versements et commissions.'],
];
$kpis = [
    ['Séjours à venir', (string)$st['avenir'], 'mes_camps.php'],
    ['Inscrits à venir', (string)$st['inscrits'], 'mes_camps.php'],
    ['Solde à recevoir', number_format($st['solde'], 2, ',', ' ') . ' €', $financeHref],
    ['En validation', (string)$st['attente'], 'mes_camps.php'],
];

$body_class = 'bg-white';
require_once 'partials/header.php';
$initial = mb_strtoupper(mb_substr($prenom, 0, 1)) ?: 'O';
$photo = trim((string)($_SESSION['user']['photo_url'] ?? ''));
?>
<title>Espace organisateur - ColoMap</title>

<main class="ds-page" style="max-width:1000px">
    <header class="flex items-center gap-4 ds-fade">
        <span class="w-14 h-14 flex-none rounded-full bg-[#eef2f4] border border-[#e5e7eb] flex items-center justify-center overflow-hidden text-[#3a4852] font-extrabold text-xl">
            <?php if ($photo): ?><img src="<?= htmlspecialchars($photo) ?>" alt="" class="w-full h-full object-cover" onerror="this.remove()"><?php else: ?><?= htmlspecialchars($initial) ?><?php endif; ?>
        </span>
        <div class="min-w-0">
            <p class="ds-eyebrow">Espace organisateur</p>
            <h1 class="ds-h1"><?= $prenom !== '' ? 'Bonjour ' . htmlspecialchars($prenom) : 'Bonjour' ?></h1>
        </div>
    </header>
    <p class="ds-lead">Gérez vos séjours, vos inscriptions et votre visibilité depuis un seul endroit.</p>

    <?php if (!$statsOk): ?>
        <div class="ds-alert-err mt-6" role="alert">Les chiffres clés sont momentanément indisponibles. Vos accès restent utilisables.</div>
    <?php elseif ($st['orgas'] === 0): ?>
        <div class="ds-alert-err mt-6 flex flex-wrap items-center justify-between gap-3" role="status">
            <span>Aucun organisme n'est encore rattaché à votre compte.</span>
            <a href="create_organisateur.php" class="ds-btn ds-btn-sm">Créer mon organisme</a>
        </div>
    <?php else: ?>
        <section class="mt-8 grid grid-cols-2 lg:grid-cols-4 gap-4" aria-label="Chiffres clés">
            <?php foreach ($kpis as $k): ?>
                <a href="<?= htmlspecialchars($k[2]) ?>" class="ds-card p-5 block transition hover:shadow-lg ds-fade">
                    <span class="block text-[13px] text-[#6b7780] font-semibold"><?= htmlspecialchars($k[0]) ?></span>
                    <span class="block mt-1 text-[26px] font-extrabold text-[#26343b] leading-tight"><?= htmlspecialchars($k[1]) ?></span>
                </a>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <nav class="mt-10" aria-label="Accès rapides">
        <h2 class="ds-eyebrow mb-4">Accès rapides</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($tiles as $t): ?>
                <a href="<?= htmlspecialchars($t[0]) ?>" class="ds-tile relative ds-fade">
                    <span class="ds-tile-ico"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $ico[$t[1]] ?></svg></span>
                    <span class="min-w-0">
                        <span class="block font-extrabold text-[16px] text-[#26343b]"><?= htmlspecialchars($t[2]) ?></span>
                        <span class="block text-[13px] text-[#6b7780] mt-0.5"><?= htmlspecialchars($t[3]) ?></span>
                    </span>
                    <?php if ($t[0] === 'messagerie.php'): ?><span id="unread-badge" class="hidden absolute top-3 right-3 min-w-[24px] h-6 px-1.5 flex items-center justify-center bg-[#26343b] text-white text-xs font-bold rounded-full" aria-label="Messages non lus"></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>
</main>

<script>
(async function () {
    var badge = document.getElementById('unread-badge');
    if (!badge) return;
    try {
        var d = await (await fetch('api/get_unread_messages_count.php')).json();
        if (d.count > 0) { badge.textContent = d.count; badge.classList.remove('hidden'); }
    } catch (e) {}
})();
</script>
<?php require_once 'partials/footer.php'; ?>

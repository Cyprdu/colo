<?php
// public_infos.php : « Mes organismes » : choix de l'organisme à gérer (directeur de plusieurs structures).
require_once 'api/config.php';
require_once 'api/orga_lib.php';

if (!isset($_SESSION['user']) || empty($_SESSION['user']['is_directeur'])) {
    header('Location: index.php');
    exit;
}

$userId = $_SESSION['user']['id'];
$organisateurs = [];
$error = false;

try {
    $st = $pdo->prepare("SELECT id, nom, email, tel, web, portefeuille, logo_url FROM organisateurs WHERE user_id = ? ORDER BY nom ASC");
    $st->execute([$userId]);
    $organisateurs = $st->fetchAll(PDO::FETCH_ASSOC);
    // nombre de séjours par organisme
    $nb = [];
    if ($organisateurs) {
        $ids = implode(',', array_map('intval', array_column($organisateurs, 'id')));
        foreach ($pdo->query("SELECT organisateur_id, COUNT(*) n FROM camps WHERE organisateur_id IN ($ids) AND COALESCE(supprime, 0) = 0 GROUP BY organisateur_id")->fetchAll(PDO::FETCH_ASSOC) as $r) $nb[(int)$r['organisateur_id']] = (int)$r['n'];
    }
} catch (Throwable $e) {
    error_log('public_infos: ' . $e->getMessage());
    $error = true;
}

function pi_logo($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    $url = str_replace('\\', '/', $url);
    return (preg_match('#^(https?:)?//#', $url) || strpos($url, 'uploads/') === 0) ? $url : $url;
}

$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Mes organismes - moncamp.fr</title>

<main class="ds-page" style="max-width:920px">
    <a href="organisateurs" class="inline-flex items-center gap-2 text-[14px] font-semibold text-[#3b4a54] hover:text-[#26343b]">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12H4m6-6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Espace organisateur
    </a>

    <p class="ds-eyebrow mt-6">Mes organismes</p>
    <h1 class="ds-h1">Quel organisme souhaitez-vous gérer ?</h1>
    <p class="ds-lead">Vous dirigez plusieurs structures : choisissez celle dont vous voulez voir le tableau de bord, les séjours et les finances.</p>

    <?php if ($error): ?>
        <div class="ds-alert-err mt-6" role="alert"><p class="font-semibold">Impossible de charger vos organismes pour le moment. Réessayez dans un instant.</p></div>
    <?php endif; ?>

    <div class="mt-8 grid gap-5 sm:grid-cols-2">
        <?php foreach ($organisateurs as $o):
            $logo = pi_logo($o['logo_url']);
            $oid = (int)$o['id']; $otok = htmlspecialchars(fin_orga_tok($oid));
            $nbSej = $nb[$oid] ?? 0;
            $init = mb_strtoupper(mb_substr(trim((string)$o['nom']), 0, 1)) ?: 'O';
        ?>
        <article class="ds-card p-0 overflow-hidden flex flex-col transition hover:shadow-lg">
            <a href="dashboard_organisme.php?o=<?= $otok ?>" class="block p-6 flex-1" aria-label="Ouvrir le tableau de bord de <?= htmlspecialchars($o['nom']) ?>">
                <div class="flex items-start gap-4">
                    <span class="w-14 h-14 flex-none rounded-2xl bg-[#eef2f4] border border-[#e5e7eb] flex items-center justify-center overflow-hidden text-[#3a4852] font-extrabold text-[22px]">
                        <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="" class="w-full h-full object-cover" loading="lazy" onerror="this.remove()"><?php else: ?><?= htmlspecialchars($init) ?><?php endif; ?>
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-[19px] font-extrabold text-[#26343b] leading-snug truncate"><?= htmlspecialchars($o['nom']) ?></h2>
                        <p class="mt-0.5 text-[13.5px] text-[#6b7780] truncate"><?= htmlspecialchars((string)$o['email']) ?></p>
                    </div>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-3 text-[13px]">
                    <div class="rounded-xl bg-[#f8f5ea] px-4 py-3">
                        <dt class="text-[#6b7780]">À recevoir</dt>
                        <dd class="mt-0.5 text-[18px] font-extrabold text-[#26343b]"><?= number_format((float)$o['portefeuille'], 2, ',', ' ') ?> €</dd>
                    </div>
                    <div class="rounded-xl bg-[#f1f3f5] px-4 py-3">
                        <dt class="text-[#6b7780]">Séjours</dt>
                        <dd class="mt-0.5 text-[18px] font-extrabold text-[#26343b]"><?= $nbSej ?></dd>
                    </div>
                </dl>

                <?php if (!empty($o['tel']) || !empty($o['web'])): ?>
                <ul class="mt-4 space-y-1.5 text-[13px] text-[#4b5964]">
                    <?php if (!empty($o['tel'])): ?><li class="flex items-center gap-2"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6.6 10.8a15 15 0 006.6 6.6l2.2-2.2a1 1 0 011-.25 11 11 0 003.5.56 1 1 0 011 1V20a1 1 0 01-1 1A17 17 0 013 4a1 1 0 011-1h3.5a1 1 0 011 1c0 1.2.2 2.4.56 3.5a1 1 0 01-.25 1z" fill="#9aa3ab"/></svg><?= htmlspecialchars($o['tel']) ?></li><?php endif; ?>
                    <?php if (!empty($o['web'])): ?><li class="flex items-center gap-2 truncate"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="#9aa3ab" stroke-width="2"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18" stroke="#9aa3ab" stroke-width="2"/></svg><span class="truncate"><?= htmlspecialchars($o['web']) ?></span></li><?php endif; ?>
                </ul>
                <?php endif; ?>
            </a>

            <div class="border-t border-[#e5e7eb] bg-[#fbfbfa] px-4 py-3 flex flex-wrap gap-2">
                <a href="dashboard_organisme.php?o=<?= $otok ?>" class="ds-btn" style="padding:9px 16px;font-size:13.5px">Tableau de bord</a>
                <a href="marketing_dashboard.php?o=<?= $otok ?>" class="ds-btn ds-btn-light" style="padding:9px 16px;font-size:13.5px">Marketing</a>
                <a href="mes_camps" class="ds-btn ds-btn-light" style="padding:9px 16px;font-size:13.5px">Mes séjours</a>
            </div>
        </article>
        <?php endforeach; ?>

        <a href="create_organisateur.php" class="rounded-3xl border-2 border-dashed border-[#cfd6dc] hover:border-[#2f3d45] hover:bg-[#f6f8fa] transition p-6 flex flex-col items-center justify-center text-center min-h-[190px]">
            <span class="w-12 h-12 rounded-full bg-[#eef2f4] flex items-center justify-center text-[#26343b]"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg></span>
            <span class="mt-3 font-extrabold text-[#26343b]">Ajouter un organisme</span>
            <span class="mt-1 text-[13px] text-[#6b7780]">Une autre structure, une autre association</span>
        </a>
    </div>

    <?php if (!$organisateurs && !$error): ?>
        <p class="mt-6 text-[14px] text-[#6b7780]">Votre profil est marqué comme directeur, mais aucun organisme ne vous est encore rattaché : créez le premier ci-dessus.</p>
    <?php endif; ?>
</main>

<?php require_once 'partials/footer.php'; ?>

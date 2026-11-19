<?php
// children.php : liste de mes enfants. Les liens utilisent le jeton public de l'enfant, jamais son id numérique.
require_once 'api/config.php';
require_once 'api/enfants.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user']['id'])) { header('Location: login.php?next=children'); exit; }

enfants_ensure($pdo);
$st = $pdo->prepare("SELECT public_id, photo_token, prenom, nom, sexe, date_naissance, infos_sante, allergies FROM enfants WHERE parent_id = ? ORDER BY prenom ASC");
$st->execute([(int)$_SESSION['user']['id']]);
$enfants = $st->fetchAll(PDO::FETCH_ASSOC);

$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Mes enfants - moncamp.fr</title>

<main class="ds-page ds-fade" style="max-width:860px">
    <a href="profile" class="inline-flex items-center gap-2 text-[14px] font-semibold text-[#3b4a54] hover:text-[#26343b]">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12H4m6-6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Mon espace
    </a>

    <div class="mt-5 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="ds-h1">Mes enfants</h1>
            <p class="ds-lead">Les fiches servent à vous inscrire plus vite et sont transmises aux organisateurs.</p>
        </div>
        <a href="add_child" class="ds-btn">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
            Ajouter un enfant
        </a>
    </div>

    <?php if (isset($_GET['success'])): ?><div class="ds-alert-ok mt-6 font-semibold" role="status">Enfant ajouté.</div><?php endif; ?>

    <?php if (!$enfants): ?>
        <div class="ds-card mt-8 p-10 text-center" style="border-style:dashed">
            <p class="text-[17px] font-bold text-[#26343b]">Aucun enfant enregistré</p>
            <p class="text-[14px] text-[#6b7780] mt-1">Ajoutez une première fiche pour réserver un séjour.</p>
        </div>
    <?php else: ?>
        <ul class="mt-8 space-y-4">
            <?php foreach ($enfants as $e):
                $age = null;
                if (!empty($e['date_naissance'])) { try { $age = (new DateTime($e['date_naissance']))->diff(new DateTime())->y; } catch (Throwable $x) {} }
                $sante = trim((string)$e['infos_sante'] . (string)$e['allergies']) !== '';
            ?>
            <li class="ds-card p-5 flex flex-wrap items-center gap-4">
                <?= enfant_avatar($e['photo_token'] ?? null, (string)$e['prenom'], 'w-12 h-12 text-[19px]') ?>
                <div class="min-w-0 flex-1">
                    <p class="text-[17px] font-bold text-[#26343b] truncate"><?= htmlspecialchars($e['prenom'] . ' ' . $e['nom']) ?></p>
                    <p class="text-[13.5px] text-[#6b7780]"><?= $age !== null ? $age . ' ans' : 'Âge non renseigné' ?><?= $sante ? ' · infos santé renseignées' : '' ?></p>
                </div>
                <a href="modif_child?id=<?= urlencode($e['public_id']) ?>" class="ds-btn ds-btn-light" style="padding:10px 18px;font-size:14px">Modifier</a>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>

<?php require_once 'partials/footer.php'; ?>

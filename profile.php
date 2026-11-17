<?php
// profile.php : mon espace personnel
require_once 'api/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user'])) { header('Location: login.php?next=profile'); exit; }

$user = $_SESSION['user'];
$is_admin = $user['is_admin'] ?? false;
$is_director = $user['is_directeur'] ?? false;
$prenom = (string)($user['prenom'] ?? '');
$nom = (string)($user['nom'] ?? '');

$body_class = 'bg-white';
require_once 'partials/header.php';

// Raccourcis : [lien, icône FontAwesome, titre, sous-titre]
$tiles = [
    ['edit_profile.php', 'fa-user-pen', 'Mes informations', 'Modifier mon profil'],
    ['children.php', 'fa-children', 'Mes enfants', 'Gérer les fiches enfants'],
    ['reservations', 'fa-ticket', 'Mes réservations', 'Suivre et gérer mes séjours'],
    ['favorites', 'fa-heart', 'Mes favoris', 'Les séjours sauvegardés'],
    ['messagerie', 'fa-comments', 'Mes messages', 'Échanger avec les organisateurs'],
];
if ($is_director) {
    $tiles[] = ['organisateurs.php', 'fa-briefcase', 'Espace organisateur', 'Gérer mes camps et mes organismes'];
    $tiles[] = ['mes_camps.php', 'fa-campground', 'Mes séjours', 'Sessions, inscrits et visibilité'];
}
?>
<title>Mon espace - ColoMap</title>

<main class="ds-page">
    <section class="flex flex-wrap items-center gap-5">
        <span class="w-20 h-20 flex-none rounded-full bg-[#3a4852] text-white text-[32px] font-extrabold flex items-center justify-center shadow-lg"><?= htmlspecialchars(mb_strtoupper(mb_substr($prenom ?: 'U', 0, 1))) ?></span>
        <div class="min-w-0">
            <h1 class="ds-h1">Bonjour <?= htmlspecialchars($prenom) ?></h1>
            <p class="ds-lead truncate"><?= htmlspecialchars((string)($user['email'] ?? '')) ?>
                <?php if ($is_admin): ?><span class="ds-badge ml-2">Administrateur</span><?php elseif ($is_director): ?><span class="ds-badge ml-2">Organisateur</span><?php endif; ?>
            </p>
        </div>
    </section>

    <section class="mt-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5" aria-label="Raccourcis">
        <?php foreach ($tiles as [$href, $icon, $title, $sub]): ?>
            <a href="<?= $href ?>" class="ds-tile">
                <span class="ds-tile-ico"><i class="fa-solid <?= $icon ?>"></i></span>
                <span class="min-w-0"><span class="block text-[17px] font-bold text-[#26343b]"><?= htmlspecialchars($title) ?></span><span class="block text-[13.5px] text-[#6b7780]"><?= htmlspecialchars($sub) ?></span></span>
            </a>
        <?php endforeach; ?>

        <?php if (!$is_director && !$is_admin): ?>
            <button type="button" onclick="requestDirectorAccess(this)" class="ds-tile text-left w-full border-dashed" style="background:#f8f5ea">
                <span class="ds-tile-ico" style="background:#fff"><i class="fa-solid fa-building-user"></i></span>
                <span class="min-w-0"><span class="block text-[17px] font-bold text-[#26343b]">Devenir organisateur</span><span class="block text-[13.5px] text-[#6b7780]">Demander un accès directeur</span></span>
            </button>
        <?php endif; ?>

        <?php if ($is_admin): ?>
            <a href="admin" class="ds-tile relative">
                <span class="ds-tile-ico"><i class="fa-solid fa-gauge-high"></i></span>
                <span class="min-w-0"><span class="block text-[17px] font-bold text-[#26343b]">Administration</span><span class="block text-[13.5px] text-[#6b7780]">Gérer le site</span></span>
                <span id="admin-notif-badge-profile" class="absolute top-3 right-3 min-w-[24px] h-6 px-1.5 flex items-center justify-center rounded-full bg-[#26343b] text-white text-xs font-bold hidden"></span>
            </a>
        <?php endif; ?>
    </section>

    <section class="mt-12 flex flex-wrap items-center justify-between gap-4 rounded-3xl bg-[#f8f5ea] px-6 py-6">
        <div>
            <p class="font-bold text-[#26343b]">Besoin d'aide ?</p>
            <p class="text-[14px] text-[#6b7780]">Consultez le centre d'aide ou écrivez-nous.</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <a href="aide" class="ds-btn ds-btn-light">Centre d'aide</a>
            <a href="api/logout.php" class="ds-btn"><i class="fa-solid fa-right-from-bracket"></i> Me déconnecter</a>
        </div>
    </section>
</main>

<script>
// Demande d'accès organisateur (directeur)
async function requestDirectorAccess(element) {
    if (!(await ColoUI.confirm("Vous êtes sur le point de demander un accès Organisateur (Directeur). Confirmer ?", { confirmLabel: "Envoyer la demande" }))) return;
    const originalContent = element.innerHTML;
    element.innerHTML = '<span class="flex items-center justify-center h-12 w-full text-[#2f3d45]"><i class="fa-solid fa-circle-notch fa-spin text-2xl"></i></span>';
    element.style.pointerEvents = 'none';
    try {
        const response = await fetch('api/request_director_access.php', { method: 'POST' });
        const data = await response.json();
        if (data.success) {
            await ColoUI.alert("Votre demande a bien été envoyée. Elle sera examinée par un administrateur.", { tone: "ok" });
            element.remove();
        } else {
            ColoUI.error(data.message);
            element.innerHTML = originalContent;
            element.style.pointerEvents = 'auto';
        }
    } catch (error) {
        console.error(error);
        ColoUI.error('Failed to fetch');
        element.innerHTML = originalContent;
        element.style.pointerEvents = 'auto';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    <?php if ($is_admin): ?>
    async function getAdminNotifCount() {
        try {
            const [requestResponse, campResponse] = await Promise.all([fetch('api/get_request_count.php'), fetch('api/get_camp_request_count.php')]);
            if (!requestResponse.ok || !campResponse.ok) return;
            const requestData = await requestResponse.json(), campData = await campResponse.json();
            const totalCount = (requestData.count || 0) + (campData.count || 0);
            const badge = document.getElementById('admin-notif-badge-profile');
            if (badge && totalCount > 0) { badge.textContent = totalCount; badge.classList.remove('hidden'); }
        } catch (error) { console.error(error); }
    }
    getAdminNotifCount();
    <?php endif; ?>
});
</script>

<?php require_once 'partials/footer.php'; ?>

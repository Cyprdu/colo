<?php
$body_class = 'bg-white';
require_once 'partials/header.php';

// Sécurité
if (!isset($_SESSION['user']) || !$_SESSION['user']['is_directeur']) {
    header('Location: index.php');
    exit;
}
?>

<title>Créer un organisme - moncamp.fr</title>

<main class="ds-page" style="max-width:680px">
    <a href="public_infos.php" class="inline-flex items-center gap-2 text-[14px] font-semibold text-[#3b4a54] hover:text-[#26343b]">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12H4m6-6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Mes organismes
    </a>
    <p class="ds-eyebrow mt-6">Nouvel organisme</p>
    <h1 class="ds-h1">Créer un organisme</h1>
    <p class="ds-lead">Vous pourrez compléter ses informations (logo, adresse, versements) ensuite.</p>

    <form id="create-org-form" class="ds-card p-6 sm:p-8 mt-8 space-y-5">
        <div><label for="nom_organisme" class="ds-label">Nom de l'organisme</label><input type="text" id="nom_organisme" required class="ds-input"></div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="tel" class="ds-label">Téléphone</label><input type="tel" id="tel" required class="ds-input"></div>
            <div><label for="mail" class="ds-label">Email de contact</label><input type="email" id="mail" required class="ds-input"></div>
        </div>
        <div><label for="web" class="ds-label">Site web <span class="font-normal text-[#6b7780]">(facultatif)</span></label><input type="url" id="web" placeholder="https://..." class="ds-input"></div>
        <div><label for="orgaKwInput" class="ds-label">Alias de l'organisme <span class="font-normal text-[#6b7780]">(facultatif, 8 maximum)</span></label><div id="orgaKw"></div>
            <p class="ds-hint">Les noms sous lesquels on vous connaît (« PAJE » pour la Pastorale des Jeunes de l'Ain) : <b>ces mots sont ceux que les familles tapent ; ils améliorent la visibilité de vos séjours.</b></p></div>
        <div id="form-message" class="text-sm font-semibold min-h-[20px]" role="status" aria-live="polite"></div>
        <div class="flex justify-end"><button type="submit" class="ds-btn">Enregistrer l'organisme</button></div>
    </form>
</main>

<link rel="stylesheet" href="assets/css/colomap-search.css">
<script src="assets/js/motscles.js"></script>
<script>
var KW = MotsCles.mount(document.getElementById('orgaKw'), { max: 8, inputId: 'orgaKwInput', placeholder: 'Ex : PAJE, puis Entrée' });
document.getElementById('create-org-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formMessage = document.getElementById('form-message');
    formMessage.textContent = 'Enregistrement en cours...';

    const formData = {
        nom: document.getElementById('nom_organisme').value,
        tel: document.getElementById('tel').value,
        mail: document.getElementById('mail').value,
        web: document.getElementById('web').value,
        motscles: KW.get()
    };

    try {
        const response = await fetch('api/create_organisateur.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(formData)
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error);
        
        var refus = (result.motscles_refuses || []).map(function (r) { return '« ' + r.label + ' » (' + r.raison + ')'; });
        formMessage.className = 'text-sm font-semibold text-[#26343b]'; formMessage.textContent = 'Organisme créé.' + (refus.length ? ' Alias refusés : ' + refus.join(' ; ') : '');
        setTimeout(() => window.location.href = 'public_infos.php', refus.length ? 4500 : 1500);

    } catch (error) {
        formMessage.className = 'text-sm font-semibold text-[#8a3b32]'; formMessage.textContent = error.message;
    }
});
</script>
<?php require_once 'partials/footer.php'; ?>

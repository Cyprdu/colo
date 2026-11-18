<?php
http_response_code(404);
$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Page introuvable - moncamp.fr</title>
<main class="ds-page flex items-center justify-center" style="max-width:640px">
    <div class="text-center ds-fade">
        <p class="text-[88px] sm:text-[110px] font-extrabold leading-none text-[#e1e6ea]" aria-hidden="true">404</p>
        <h1 class="ds-h1 -mt-2">Page introuvable</h1>
        <p class="ds-lead">La page que vous cherchez n'existe pas ou a été déplacée.</p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="/" class="ds-btn">Retour à l'accueil</a>
            <a href="recherche.php" class="ds-btn ds-btn-light">Rechercher un séjour</a>
        </div>
    </div>
</main>
<?php require_once 'partials/footer.php'; ?>

<?php
http_response_code(410);
$body_class = 'bg-white';
$page_title = 'Contenu retiré - moncamp.fr';
require_once 'partials/header.php';
?>
<main class="ds-page flex items-center justify-center" style="max-width:640px">
    <div class="text-center ds-fade">
        <p class="text-[88px] sm:text-[110px] font-extrabold leading-none text-[#e1e6ea]" aria-hidden="true">410</p>
        <h1 class="ds-h1 -mt-2">Ce contenu a été retiré</h1>
        <p class="ds-lead">Ce séjour ou cette page n'est plus en ligne. D'autres séjours vous attendent.</p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="/" class="ds-btn">Retour à l'accueil</a>
            <a href="recherche.php" class="ds-btn ds-btn-light">Rechercher un séjour</a>
        </div>
    </div>
</main>
<?php require_once 'partials/footer.php'; ?>

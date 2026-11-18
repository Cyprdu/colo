<?php
// child_details.php?r=<jeton de réservation>&t=<jeton de session> : dossier complet d'un enfant inscrit (organisateur du séjour ou administrateur).
// Les données viennent de api/dossier_inscrit.php (accès vérifié et journalisé) et le rendu est partagé avec le panneau de gestion_camp.php (assets/js/dossier.js).
require_once 'api/config.php';
require_once 'api/options.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user']['id']) || (empty($_SESSION['user']['is_directeur']) && empty($_SESSION['user']['is_admin']))) { header('Location: index.php'); exit; }
$resToken = (string)($_GET['r'] ?? '');
$tokenCamp = (string)($_GET['t'] ?? '');
if ($resToken === '') { header('Location: mes_camps.php'); exit; }
$retour = $tokenCamp !== '' ? 'gestion_camp.php?t=' . rawurlencode($tokenCamp) . '&s=' . rawurlencode($tokenCamp) : 'mes_camps.php';

$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Dossier de l'inscrit - moncamp.fr</title>
<meta name="robots" content="noindex, nofollow">
<main class="ds-page" style="max-width:1000px">
    <a href="<?= htmlspecialchars($retour) ?>" class="text-sm font-semibold text-[#6b7780] hover:text-[#26343b]">&larr; Retour aux inscrits</a>
    <div id="state" class="ds-lead py-20 text-center" role="status">Chargement du dossier…</div>
    <div id="content" class="hidden mt-3">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
            <div id="head" class="min-w-0"></div>
            <div class="flex flex-wrap gap-2 no-print">
                <?php $pdfUrl = 'api/dossier_inscrit.php?r=' . rawurlencode($resToken) . '&format=pdf'; ?>
                <a href="<?= htmlspecialchars($pdfUrl . '&dl=1') ?>" class="ds-btn ds-btn-light">Exporter en PDF</a>
                <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank" rel="noopener" class="ds-btn ds-btn-light">Imprimer</a>
            </div>
        </div>
        <div class="ds-card p-5 sm:p-7"><div id="tabs"></div></div>
        <p class="ds-hint mt-4">Document confidentiel. Chaque ouverture de ce dossier est enregistrée dans le journal d'accès aux données de santé.</p>
    </div>
</main>
<script src="assets/js/dossier.js"></script>
<script>
window.DOSSIER_MOYENS = <?= json_encode(array_map(fn($m) => $m[0], options_paiements()), JSON_UNESCAPED_UNICODE) ?>;
const $ = id => document.getElementById(id);
let D = null;
(async function () {
    try {
        const r = await fetch('api/dossier_inscrit.php?r=' + encodeURIComponent(<?= json_encode($resToken) ?>)), d = await r.json();
        if (!r.ok) throw new Error(d.error || 'Dossier indisponible.');
        D = d; $('head').innerHTML = Dossier.head(d); Dossier.tabs(d, $('tabs'), { recu: false });
        $('state').classList.add('hidden'); $('content').classList.remove('hidden');
        document.title = d.enfant.prenom + ' ' + d.enfant.nom + ' - Dossier - moncamp.fr';
        const h = location.hash.slice(1); if (h) { const b = document.querySelector('[data-dtab="' + h + '"]'); if (b) b.click(); }
    } catch (e) { $('state').textContent = ColoUI.friendly(e, 'Impossible de charger ce dossier.'); }
})();
</script>
<?php require_once 'partials/footer.php'; ?>

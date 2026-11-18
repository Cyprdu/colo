<?php
// child_details.php?r=<jeton de réservation>&t=<jeton de session> : dossier complet d'un enfant inscrit (organisateur du séjour ou administrateur).
// Les données viennent de api/dossier_inscrit.php (accès vérifié et journalisé) et le rendu est partagé avec le panneau de gestion_camp.php (assets/js/dossier.js).
require_once 'api/config.php';
require_once __DIR__ . '/api/adm_core.php';
require_once 'api/options.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user']['id']) || (empty($_SESSION['user']['is_directeur']) && !adm_can('orgas.read'))) { header('Location: index.php'); exit; }
$resToken = (string)($_GET['r'] ?? '');
$tokenCamp = (string)($_GET['t'] ?? '');
if ($resToken === '') { header('Location: mes_camps.php'); exit; }
$retour = $tokenCamp !== '' ? 'gestion_camp.php?t=' . rawurlencode($tokenCamp) . '&s=' . rawurlencode($tokenCamp) : 'mes_camps.php';

$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Dossier de l'inscrit - moncamp.fr</title>
<meta name="robots" content="noindex, nofollow">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<main class="ds-page" style="max-width:1000px">
    <a href="<?= htmlspecialchars($retour) ?>" class="text-sm font-semibold text-[#6b7780] hover:text-[#26343b]">&larr; Retour aux inscrits</a>
    <div id="state" class="ds-lead py-20 text-center" role="status">Chargement du dossier…</div>
    <div id="content" class="hidden mt-3">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
            <div id="head" class="min-w-0"></div>
            <div class="flex flex-wrap gap-2 no-print">
                <button type="button" id="btnPdf" class="ds-btn ds-btn-light">Exporter en PDF</button>
                <button type="button" id="btnPrint" class="ds-btn ds-btn-light">Imprimer</button>
            </div>
        </div>
        <div class="ds-card p-5 sm:p-7"><div id="tabs"></div></div>
        <p class="ds-hint mt-4">Document confidentiel. Chaque ouverture de ce dossier est enregistrée dans le journal d'accès aux données de santé.</p>
    </div>
    <div id="pdfZone" style="position:absolute;left:-9999px;top:0;width:760px" aria-hidden="true"></div>
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
$('btnPrint').addEventListener('click', () => { $('pdfZone').style.cssText = ''; $('pdfZone').innerHTML = '<div class="p-6">' + Dossier.head(D) + '<div class="h-4"></div>' + Dossier.stack(D) + '</div>'; document.body.classList.add('print-dossier'); window.print(); document.body.classList.remove('print-dossier'); $('pdfZone').innerHTML = ''; $('pdfZone').style.cssText = 'position:absolute;left:-9999px;top:0;width:760px'; });
$('btnPdf').addEventListener('click', async () => {
    const z = $('pdfZone'); z.innerHTML = '<div style="padding:16px;background:#fff">' + Dossier.head(D) + '<div style="height:16px"></div>' + Dossier.stack(D) + '</div>';
    try { await html2pdf().set({ margin: 8, filename: 'Dossier_' + D.enfant.prenom + '_' + D.enfant.nom + '.pdf', image: { type: 'jpeg', quality: .96 }, html2canvas: { scale: 2 }, jsPDF: { unit: 'mm', format: 'a4' } }).from(z.firstElementChild).save(); }
    catch (e) { ColoUI.error(e); }
    z.innerHTML = '';
});
</script>
<style>@media print { body.print-dossier > *:not(main), body.print-dossier main > *:not(#pdfZone) { display: none !important; } body.print-dossier #pdfZone { position: static !important; width: auto !important; } }</style>
<?php require_once 'partials/footer.php'; ?>

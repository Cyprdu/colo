<?php
$body_class = 'bg-white';
require_once 'partials/header.php';

if (!isset($_SESSION['user']) || !$_SESSION['user']['is_directeur']) {
    header('Location: index');
    exit;
}

$resToken = (string)($_GET['r'] ?? '');
$inscriptionId = $resToken === '' ? (int)($_GET['id'] ?? 0) : 0; // l'adresse a jeton (r) remplace l'identifiant numerique
$tokenCamp = $_GET['t'] ?? '';
?>

<title>Dossier Enfant - ColoMap</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<main class="container mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 py-8">
    
    <div class="flex flex-col md:flex-row justify-between items-center mb-6 no-print">
        <a href="gestion_camp?t=<?= htmlspecialchars($tokenCamp) ?>" class="text-[#3b4a54] hover:text-[#26343b] font-semibold mb-4 md:mb-0">
            &larr; Retour au Dashboard
        </a>
        <div class="flex gap-3">
            <button onclick="window.open('api/secure_file.php?file='+window.carnetToken, '_blank')" id="btn-carnet" class="ds-btn ds-btn-light" style="display:none">
                Voir le carnet de santé
            </button>
            <button onclick="generatePDF()" class="ds-btn">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Exporter Fiche PDF
            </button>
        </div>
    </div>

    <div id="fiche-enfant" class="bg-white p-10 rounded-3xl shadow-sm border border-[#e5e7eb] max-w-4xl mx-auto">
        
        <div class="flex justify-between items-start border-b-2 border-gray-800 pb-6 mb-8">
            <div>
                <h1 class="text-4xl font-extrabold text-[#26343b] uppercase tracking-tight" id="child-name">Chargement...</h1>
                <p class="text-xl text-[#6b7780] mt-1" id="child-age"></p>
                <div class="mt-3 inline-block bg-[#e5eaef] text-[#26343b] px-3 py-1 rounded-full text-sm font-bold" id="camp-name"></div>
            </div>
            <div class="text-right">
                <div class="bg-gray-100 p-4 rounded-lg border border-[#e5e7eb]">
                    <p class="text-xs text-[#6b7780] uppercase font-bold">Dossier N°</p>
                    <p class="text-2xl font-mono font-bold text-[#26343b]">#<?= htmlspecialchars(strtoupper(substr($resToken !== "" ? $resToken : (string)$inscriptionId, 0, 8))) ?></p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
            
            <div class="space-y-8">
                
                <section>
                    <h3 class="text-lg font-bold text-[#2d5170] uppercase border-b border-[#d3dbe0] pb-1 mb-3">Identité</h3>
                    <ul class="space-y-2 text-sm text-gray-700">
                        <li><span class="font-bold w-32 inline-block">Date Naissance:</span> <span id="child-dob"></span></li>
                        <li><span class="font-bold w-32 inline-block">Sexe:</span> <span id="child-sex"></span></li>
                        <li><span class="font-bold w-32 inline-block">Adresse:</span> <span id="child-address"></span></li>
                        <li><span class="font-bold w-32 inline-block">Pays:</span> <span id="child-country"></span></li>
                    </ul>
                </section>

                <section class="bg-green-50 p-4 rounded-lg border border-green-100">
                    <h3 class="text-lg font-bold text-green-800 uppercase border-b border-green-200 pb-1 mb-3">Responsable 1 (Principal)</h3>
                    <ul class="space-y-2 text-sm text-[#26343b]">
                        <li><span class="font-bold">Nom:</span> <span id="r1-name"></span> (<span id="r1-statut"></span>)</li>
                        <li><span class="font-bold">Tel:</span> <span id="r1-tel"></span></li>
                        <li><span class="font-bold">Email:</span> <span id="r1-email"></span></li>
                        <li><span class="font-bold">Profession:</span> <span id="r1-job"></span></li>
                    </ul>
                </section>

                <section id="sec-r2" class="hidden">
                    <h3 class="text-lg font-bold text-[#6b7780] uppercase border-b border-[#e5e7eb] pb-1 mb-3">Responsable 2</h3>
                    <ul class="space-y-2 text-sm text-gray-700">
                        <li><span class="font-bold">Nom:</span> <span id="r2-name"></span> (<span id="r2-statut"></span>)</li>
                        <li><span class="font-bold">Tel:</span> <span id="r2-tel"></span></li>
                        <li><span class="font-bold">Email:</span> <span id="r2-email"></span></li>
                    </ul>
                </section>

            </div>

            <div class="space-y-8">
                
                <section class="bg-red-50 p-5 rounded-3xl border-l-4 border-red-500">
                    <h3 class="text-lg font-bold text-red-800 uppercase mb-4 flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 016.364 0L12 7.5l1.318-1.182a4.5 4.5 0 116.364 6.364L12 21l-7.682-7.682a4.5 4.5 0 010-6.364z"></path></svg>
                        Santé & Alim.
                    </h3>
                    
                    <div class="mb-4">
                        <p class="text-xs font-bold text-red-600 uppercase">Infos Médicales / Allergies</p>
                        <p class="text-sm font-medium text-[#26343b] mt-1" id="health-infos">RAS</p>
                    </div>
                    
                    <div class="mb-4">
                        <p class="text-xs font-bold text-red-600 uppercase">Régime Alimentaire</p>
                        <p class="text-sm font-medium text-[#26343b] mt-1" id="health-diet">Standard</p>
                    </div>

                    <div class="mt-4 pt-4 border-t border-red-200">
                        <p class="text-xs font-bold text-[#6b7780]">Carnet de santé</p>
                        <p class="text-sm text-[#3d6a8e] underline cursor-pointer" onclick="window.open('api/secure_file.php?file='+window.carnetToken, '_blank')">
                            Consulter le document numérisé
                        </p>
                    </div>
                </section>

                <section>
                    <h3 class="text-lg font-bold text-gray-700 uppercase border-b border-[#e5e7eb] pb-1 mb-3">Autorisations</h3>
                    <ul class="space-y-2 text-sm">
                        <li class="flex justify-between">
                            <span>Droit à l'image</span>
                            <span id="auth-img" class="font-bold"></span>
                        </li>
                        <li class="flex justify-between">
                            <span>Hospitalisation / Soins</span>
                            <span class="font-bold text-green-600">OUI (Accord Parental)</span>
                        </li>
                        <li class="flex justify-between">
                            <span>Transport / Sorties</span>
                            <span class="font-bold text-green-600">OUI</span>
                        </li>
                    </ul>
                </section>

                <section id="reponses-section" class="hidden">
                    <h3 class="text-lg font-bold text-gray-700 uppercase border-b border-[#e5e7eb] pb-1 mb-3">Réponses au formulaire</h3>
                    <dl id="reponses-list" class="space-y-2 text-sm"></dl>
                </section>

                <section class="bg-white p-4 rounded border border-[#e5e7eb]">
                    <h3 class="text-xs font-bold text-[#6b7780] uppercase mb-2">Commentaires Parents</h3>
                    <p class="text-sm italic text-[#6b7780]" id="comments">Aucun commentaire.</p>
                </section>

            </div>
        </div>

        <div class="mt-10 pt-6 border-t-2 border-[#e5e7eb] text-center text-xs text-gray-400">
            Document généré le <?= date('d/m/Y') ?> via ColoMap - Confidentiel
        </div>
    </div>

    <div class="mt-8">
        <h3 class="text-xl font-bold text-[#26343b] mb-4">Aperçu du Carnet de Santé</h3>
        <iframe id="pdf-viewer" class="w-full h-[600px] border-2 border-[#e5e7eb] rounded-lg bg-gray-100" src=""></iframe>
    </div>

</main>

<script>
window.carnetToken = null;

document.addEventListener('DOMContentLoaded', async function() {
    const refParam = <?= json_encode($resToken !== '' ? 'r=' . rawurlencode($resToken) : 'id=' . (int)$inscriptionId) ?>;
    
    try {
        const response = await fetch(`api/get_child_full_details.php?${refParam}`);
        const data = await response.json();
        
        if(data.error) throw new Error(data.error);

        // --- Remplissage des champs ---
        
        // Header
        document.getElementById('child-name').textContent = `${data.prenom} ${data.nom}`;
        document.getElementById('camp-name').textContent = data.camp_nom;
        
        const age = new Date().getFullYear() - new Date(data.date_naissance).getFullYear();
        document.getElementById('child-age').textContent = `${age} ans`;

        // Identité
        document.getElementById('child-dob').textContent = new Date(data.date_naissance).toLocaleDateString('fr-FR');
        document.getElementById('child-sex').textContent = data.sexe;
        document.getElementById('child-address').textContent = `${data.adresse}, ${data.code_postal} ${data.ville}`;
        document.getElementById('child-country').textContent = data.pays;

        // Responsable 1
        document.getElementById('r1-name').textContent = `${data.resp1_civilite} ${data.resp1_prenom} ${data.resp1_nom}`;
        document.getElementById('r1-statut').textContent = data.resp1_statut;
        document.getElementById('r1-tel').textContent = data.resp1_tel;
        document.getElementById('r1-email').textContent = data.resp1_email;
        document.getElementById('r1-job').textContent = data.resp1_profession || 'NC';

        // Responsable 2 (Si existe)
        if(data.resp2_nom) {
            document.getElementById('sec-r2').classList.remove('hidden');
            document.getElementById('r2-name').textContent = `${data.resp2_civilite} ${data.resp2_prenom} ${data.resp2_nom}`;
            document.getElementById('r2-statut').textContent = data.resp2_statut;
            document.getElementById('r2-tel').textContent = data.resp2_tel;
            document.getElementById('r2-email').textContent = data.resp2_email;
        }

        // Santé
        if(data.infos_sante) {
            const el = document.getElementById('health-infos');
            el.textContent = data.infos_sante;
            el.className = "text-sm font-bold text-red-600 mt-1 bg-white p-2 rounded border border-red-200";
        }
        if(data.regime_alimentaire) document.getElementById('health-diet').textContent = data.regime_alimentaire;

        // Fichier Carnet
        window.carnetToken = data.carnet_sante_token;
        if(window.carnetToken) {
            document.getElementById('btn-carnet').style.display = '';
            // Charger dans l'iframe
            document.getElementById('pdf-viewer').src = `api/secure_file.php?file=${window.carnetToken}`;
        } else {
            document.getElementById('pdf-viewer').parentElement.innerHTML = '<p class="text-[#6b7780] italic">Aucun carnet de santé numérisé.</p>';
        }

        // Autorisations
        const imgOk = data.droit_image == 1 ? '<span class="text-green-600 font-bold">OUI</span>' : '<span class="text-red-600 font-bold">NON</span>';
        document.getElementById('auth-img').innerHTML = imgOk;

        // Réponses aux questions personnalisées du directeur
        if (data.reponses && data.reponses.length) {
            const list = document.getElementById('reponses-list');
            data.reponses.forEach(r => {
                const row = document.createElement('div');
                const dt = document.createElement('dt'); dt.className = 'font-bold'; dt.textContent = r.libelle;
                const dd = document.createElement('dd'); dd.className = 'text-gray-700'; dd.textContent = r.valeur;
                row.append(dt, dd); list.appendChild(row);
            });
            document.getElementById('reponses-section').classList.remove('hidden');
        }

        // Commentaires
        if(data.commentaires) document.getElementById('comments').textContent = data.commentaires;

    } catch (e) {
        alert("Erreur : " + e.message);
    }
});

function generatePDF() {
    const element = document.getElementById('fiche-enfant');
    const opt = {
        margin:       0.5,
        filename:     `Fiche_${document.getElementById('child-name').textContent}.pdf`,
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2 },
        jsPDF:        { unit: 'in', format: 'a4', orientation: 'portrait' }
    };
    
    // On génère le PDF
    html2pdf().set(opt).from(element).save().then(() => {
        // Optionnel : Message pour prévenir que le carnet de santé est à part
        alert("La fiche récapitulative a été téléchargée.\n\nLe carnet de santé est un document séparé (pour des raisons de format), vous pouvez le télécharger via le bouton rouge 'Voir Carnet'.");
    });
}
</script>

<?php require_once 'partials/footer.php'; ?>
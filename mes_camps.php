<?php
// 1. CONFIGURATION
require_once 'api/config.php';
require_once 'api/sessions.php';

// 2. SÉCURITÉ
if (!isset($_SESSION['user']) || empty($_SESSION['user']['is_directeur'])) {
    header('Location: index.php');
    exit;
}

$userId = $_SESSION['user']['id'];
$camps = [];
$error = null;

try {
    $stmtOrga = $pdo->prepare("SELECT id FROM organisateurs WHERE user_id = ?");
    $stmtOrga->execute([$userId]);
    $organisateurIds = $stmtOrga->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($organisateurIds)) {
        $placeholders = implode(',', array_fill(0, count($organisateurIds), '?'));
        $sql = "
            SELECT 
                c.*,
                COUNT(i.id) as nb_inscrits,
                GROUP_CONCAT(CONCAT(e.prenom, ' ', e.nom) SEPARATOR '|||') as liste_inscrits
            FROM camps c
            LEFT JOIN inscriptions i ON c.id = i.camp_id AND i.statut != 'annule'
            LEFT JOIN enfants e ON i.enfant_id = e.id
            WHERE c.organisateur_id IN ($placeholders)
            AND c.supprime = 0  -- <--- AJOUT IMPORTANT ICI
            GROUP BY c.id
            ORDER BY c.date_debut DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($organisateurIds); 
        $camps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $error = "Erreur technique : " . $e->getMessage();
}
$sessCount = [];
foreach ($camps as $cc) { $k = sessionGroupKey($cc); $sessCount[$k] = ($sessCount[$k] ?? 0) + 1; }
require_once 'partials/header.php';
?>
<title>Mes Séjours - Gestion</title>
<style>
.toggle-checkbox:checked {
    right: 0;
    border-color: #68D391;
}
.toggle-checkbox:checked + .toggle-label {
    background-color: #68D391;
}
.tooltip { position: relative; }
</style>
<div class="min-h-screen bg-gray-50 py-10 font-sans">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-10">
            <div>
                <h1 class="text-3xl font-extrabold text-[#0A112F]">Gestion de vos séjours</h1>
                <p class="text-gray-500 mt-1">Gérez la visibilité, les inscriptions et modifiez vos séjours.</p>
            </div>
            <a href="create_camp.php" class="inline-flex items-center justify-center gap-2 bg-[#0A112F] hover:bg-blue-900 text-white font-bold py-3 px-6 rounded-xl shadow-lg transition transform hover:-translate-y-0.5">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Créer un nouveau séjour
            </a>
        </div>
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded shadow-sm">
                <p class="font-bold">Erreur</p>
                <p><?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>
        <?php if (empty($camps)): ?>
            <div class="bg-white rounded-3xl shadow-sm p-12 text-center border border-gray-100">
                <div class="mx-auto h-24 w-24 bg-blue-50 rounded-full flex items-center justify-center mb-6">
                    <svg class="w-10 h-10 text-[#0A112F]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                </div>
                <h3 class="text-xl font-bold text-gray-900 mb-2">Aucun séjour pour le moment</h3>
                <p class="text-gray-500 mb-6">Commencez par publier votre premier séjour.</p>
                <a href="create_camp.php" class="text-[#0A112F] font-bold hover:underline">Créer mon premier séjour &rarr;</a>
            </div>
        <?php else: ?>
            
            <?php
// ---- Regroupement : un camp = une carte ; ses sessions (dates) et formules (durée/prix) sont dans la carte ----
$todayYmd = date('Y-m-d');
$mois = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
$fmtDay  = function (DateTime $d) use ($mois) { return $d->format('j') . ' ' . $mois[(int)$d->format('n') - 1]; };
$fmtFull = function (DateTime $d) use ($fmtDay) { return $fmtDay($d) . ' ' . $d->format('Y'); };

$groupsRaw = [];
foreach ($camps as $c) {
    [$n1, $n2] = normalizeSessionDates($c['date_debut'], $c['date_fin']);
    $c['_start'] = $n1; $c['_end'] = $n2;
    $c['_nights'] = max(0, (int)$n1->diff($n2)->days);
    $groupsRaw[sessionGroupKey($c)][] = $c;
}
$cards = [];
foreach ($groupsRaw as $list) {
    usort($list, function ($a, $b) { return ($a['_start'] <=> $b['_start']) ?: ((float)$a['prix'] <=> (float)$b['prix']); });
    $rep = null;
    foreach ($list as $s) { if ($s['_end']->format('Y-m-d') >= $todayYmd) { $rep = $s; break; } }
    $upcoming = $rep !== null;
    if (!$rep) $rep = end($list);
    $byDate = [];
    foreach ($list as $s) $byDate[$s['_start']->format('Y-m-d')][] = $s;
    $prices = array_filter(array_map(function ($s) { return (float)$s['prix']; }, $list), function ($p) { return $p > 0; });
    $tot = 0; $quotaTot = 0; $pub = 0;
    foreach ($list as $s) { $tot += (int)$s['nb_inscrits']; $quotaTot += max(1, (int)$s['quota_global']); if (empty($s['prive'])) $pub++; }
    $cards[] = [
        'rep' => $rep, 'list' => $list, 'byDate' => $byDate, 'upcoming' => $upcoming,
        'sortKey' => $upcoming ? $rep['_start']->format('Y-m-d') : $list[count($list) - 1]['_end']->format('Y-m-d'),
        'minPrice' => $prices ? min($prices) : (float)$rep['prix'],
        'inscrits' => $tot, 'quota' => $quotaTot, 'pub' => $pub,
    ];
}
// À venir d'abord (le plus proche en premier), puis les camps terminés (les plus récents en premier)
usort($cards, function ($a, $b) {
    if ($a['upcoming'] !== $b['upcoming']) return $a['upcoming'] ? -1 : 1;
    return $a['upcoming'] ? strcmp($a['sortKey'], $b['sortKey']) : strcmp($b['sortKey'], $a['sortKey']);
});
?>
            <p class="text-sm text-gray-500 mb-6"><?= count($cards) ?> camp<?= count($cards) > 1 ? 's' : '' ?> · <?= count($camps) ?> session<?= count($camps) > 1 ? 's' : '' ?></p>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 items-start">
                <?php foreach ($cards as $card):
                    $camp = $card['rep']; $list = $card['list'];
                    $nbSessions = count($list); $nbDates = count($card['byDate']);
                    $percent = min(100, $card['quota'] > 0 ? round($card['inscrits'] / $card['quota'] * 100) : 0);
                    $img = !empty($camp['image_url']) ? $camp['image_url'] : 'assets/default_camp.jpg';
                    $token = $camp['token'];
                    $visLabel = $card['pub'] === $nbSessions ? 'Public' : ($card['pub'] === 0 ? 'Privé' : 'Public en partie');
                ?>
                <div class="bg-white rounded-2xl shadow-sm hover:shadow-md transition border border-gray-200 overflow-hidden flex flex-col group relative">

                    <a href="gestion_camp.php?t=<?= $token ?>" class="block relative h-48 w-full overflow-hidden cursor-pointer">
                        <img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy" class="w-full h-full object-cover transition duration-700 group-hover:scale-105">
                        <div class="absolute inset-0 bg-gradient-to-t from-black/70 to-transparent"></div>
                        <div class="absolute bottom-4 left-4 right-4">
                            <h3 class="text-white font-bold text-xl leading-tight truncate"><?= htmlspecialchars($camp['nom']) ?></h3>
                            <p class="text-gray-200 text-sm mt-1"><?= htmlspecialchars($camp['ville']) ?></p>
                        </div>
                    </a>
                    <span class="absolute top-4 right-4 z-10 text-xs font-bold text-white bg-black/50 px-2 py-1 rounded"><?= $visLabel ?></span>

                    <div class="p-5 flex-1 flex flex-col">
                        <div class="flex justify-between items-center mb-4">
                            <span class="inline-block text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-100 rounded-lg px-3 py-1.5">
                                <?= $nbSessions ?> session<?= $nbSessions > 1 ? 's' : '' ?><?= $nbDates !== $nbSessions ? ' · ' . $nbDates . ' date' . ($nbDates > 1 ? 's' : '') : '' ?>
                            </span>
                            <span class="font-bold text-[#26343b] text-lg"><?= $nbSessions > 1 ? 'dès ' : '' ?><?= number_format($card['minPrice'], 0, ',', ' ') ?>€</span>
                        </div>

                        <div class="mb-5">
                            <div class="flex justify-between text-xs font-semibold mb-1.5">
                                <span class="text-gray-500">Remplissage</span>
                                <span class="text-[#26343b]"><?= $card['inscrits'] ?> / <?= $card['quota'] ?></span>
                            </div>
                            <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden"><div class="bg-[#26343b] h-2 rounded-full" style="width: <?= $percent ?>%"></div></div>
                        </div>

                        <div class="grid grid-cols-3 gap-2 border-t border-gray-100 pt-4">
                            <a href="camp_details.php?t=<?= $token ?>" target="_blank" class="flex items-center justify-center gap-2 p-2 rounded-lg text-sm font-semibold text-gray-600 hover:text-blue-600 hover:bg-blue-50 transition" title="Voir la page publique">Voir</a>
                            <button type="button" onclick="openSessionModal('<?= $token ?>', '<?= addslashes(htmlspecialchars($camp['nom'])) ?>', '<?= $list[count($list) - 1]['_start']->format('Y-m-d') ?>', '<?= $list[count($list) - 1]['_end']->format('Y-m-d') ?>')"
                                    class="flex items-center justify-center gap-2 p-2 rounded-lg text-sm font-semibold text-gray-600 hover:text-emerald-700 hover:bg-emerald-50 transition" title="Ajouter une session">+ Session</button>
                            <button onclick="shareLink('<?= $token ?>')" class="flex items-center justify-center gap-2 p-2 rounded-lg text-sm font-semibold text-gray-600 hover:text-indigo-600 hover:bg-indigo-50 transition" title="Copier le lien">Partager</button>
                        </div>

                        <details class="mt-4 rounded-xl border border-gray-100 bg-gray-50/60">
                            <summary class="cursor-pointer select-none px-4 py-3 text-sm font-bold text-[#26343b]">Gérer les <?= $nbSessions ?> session<?= $nbSessions > 1 ? 's' : '' ?></summary>
                            <div class="px-4 pb-3 max-h-80 overflow-y-auto">
                            <?php foreach ($card['byDate'] as $ymd => $rows): $first = $rows[0]; ?>
                                <p class="mt-3 mb-1 text-xs font-extrabold uppercase tracking-wider text-gray-400"><?= $fmtFull($first['_start']) ?><?= count($rows) > 1 ? ' · ' . count($rows) . ' formules' : '' ?></p>
                                <?php foreach ($rows as $s):
                                    $sTok = $s['token'];
                                    $sList = $s['liste_inscrits'] ? str_replace('|||', ',', $s['liste_inscrits']) : '';
                                    $sQuota = max(1, (int)$s['quota_global']);
                                ?>
                                <div class="flex items-center gap-3 py-2 border-b border-gray-100 last:border-0">
                                    <div class="relative inline-block w-10 h-6 flex-none select-none" title="Visible par les familles">
                                        <input type="checkbox" id="toggle_<?= $s['id'] ?>"
                                               class="toggle-checkbox absolute block w-6 h-6 rounded-full bg-white border-4 appearance-none cursor-pointer transition-all duration-300 <?= empty($s['prive']) ? 'right-0 border-green-400' : 'left-0 border-gray-300' ?>"
                                               <?= empty($s['prive']) ? 'checked' : '' ?> onchange="toggleCampStatus('<?= $sTok ?>', this)">
                                        <label for="toggle_<?= $s['id'] ?>" class="toggle-label block overflow-hidden h-6 rounded-full bg-gray-300 cursor-pointer <?= empty($s['prive']) ? 'bg-green-400' : '' ?>"></label>
                                    </div>
                                    <span id="status_text_<?= $s['id'] ?>" class="sr-only"><?= empty($s['prive']) ? 'Public' : 'Privé' ?></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-gray-800"><?= $fmtDay($s['_start']) ?> → <?= $fmtDay($s['_end']) ?> <?= $s['_end']->format('Y') ?></p>
                                        <p class="text-xs text-gray-500"><?= $s['_nights'] ?> nuit<?= $s['_nights'] > 1 ? 's' : '' ?> · <?= number_format($s['prix'], 0, ',', ' ') ?>€ · <?= (int)$s['nb_inscrits'] ?>/<?= $sQuota ?> inscrits</p>
                                    </div>
                                    <div class="flex items-center flex-none">
                                        <a href="edit_camp.php?t=<?= $sTok ?>" class="p-1.5 rounded-lg text-gray-400 hover:text-orange-600 hover:bg-orange-50 transition" title="Modifier cette session" aria-label="Modifier">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536M9 13l6.768-6.768a2.5 2.5 0 113.536 3.536L12.536 16.5 8 18l1-5z"/></svg></a>
                                        <a href="boost.php?t=<?= $sTok ?>" class="p-1.5 rounded-lg text-gray-400 hover:text-purple-600 hover:bg-purple-50 transition" title="Booster" aria-label="Booster">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></a>
                                        <button type="button" onclick="openDeleteModal('<?= $sTok ?>', '<?= addslashes(htmlspecialchars($s['nom'])) ?>', '<?= addslashes($sList) ?>')" class="p-1.5 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition" title="Supprimer cette session" aria-label="Supprimer">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            </div>
                        </details>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </div>
</div>

<div id="deleteModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-80 backdrop-blur-sm overflow-y-auto h-full w-full z-50 flex items-center justify-center">
    <div class="relative bg-white p-8 rounded-2xl shadow-2xl w-full max-w-lg border border-red-100">
        
        <div class="text-center mb-6">
            <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-4">
                <svg class="h-8 w-8 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">Suppression du Séjour</h3>
            <p class="text-sm text-gray-500 mt-2">Cette action est <span class="font-bold text-red-600 uppercase">irréversible</span>.</p>
        </div>

        <form id="deleteForm" action="api/delete_camp.php" method="POST" class="space-y-6">
            <input type="hidden" name="token" id="modalToken">

            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 text-sm max-h-40 overflow-y-auto">
                <p class="font-bold text-gray-700 mb-2">Inscrits à ce séjour :</p>
                <div id="modalInscritsList" class="text-gray-600">
                    </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Veuillez écrire <span class="font-bold select-all" id="modalCampNameDisplay"></span> ci-dessous pour confirmer :
                </label>
                <input type="text" id="confirmNameInput" class="w-full border-2 border-gray-300 rounded-lg p-2 focus:border-red-500 focus:outline-none" placeholder="Nom du séjour">
            </div>

            <div class="flex items-start">
                <div class="flex items-center h-5">
                    <input id="refundCheck" type="checkbox" required class="w-5 h-5 text-red-600 border-gray-300 rounded focus:ring-red-500">
                </div>
                <div class="ml-3 text-sm">
                    <label for="refundCheck" class="font-medium text-gray-700">Je m'engage sur l'honneur à rembourser intégralement les familles inscrites si le séjour est annulé.</label>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 pt-2">
                <button type="button" onclick="closeDeleteModal()" class="w-full px-4 py-3 bg-gray-100 text-gray-700 rounded-lg font-bold hover:bg-gray-200 transition">
                    Annuler
                </button>
                <button type="submit" id="finalDeleteBtn" class="w-full px-4 py-3 bg-red-600 text-white rounded-lg font-bold hover:bg-red-700 transition opacity-50 cursor-not-allowed" disabled>
                    Supprimer définitivement
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// --- 1. Gestion du Toggle Public/Privé ---
function toggleCampStatus(token, checkbox) {
    const isPublic = checkbox.checked;
    const statusText = document.getElementById('status_text_' + checkbox.id.split('_')[1]);
    const originalState = !isPublic; // Pour rollback si erreur

    // Update UI immédiate
    statusText.textContent = isPublic ? 'Public' : 'Privé';
    checkbox.classList.toggle('right-0', isPublic);
    checkbox.classList.toggle('left-0', !isPublic);
    checkbox.classList.toggle('border-green-400', isPublic);
    checkbox.classList.toggle('border-gray-300', !isPublic);
    checkbox.nextElementSibling.classList.toggle('bg-green-400', isPublic);

    // Appel API
    // Assurez-vous d'avoir créé api/update_camp_visibility.php
    fetch('api/update_camp_visibility.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `token=${token}&prive=${isPublic ? 0 : 1}` 
    })
    .then(res => res.json())
    .then(data => {
        if(!data.success) {
            alert("Erreur lors de la mise à jour.");
            // Rollback UI
            checkbox.checked = originalState;
            statusText.textContent = originalState ? 'Public' : 'Privé';
        }
    })
    .catch(err => {
        console.error(err);
        checkbox.checked = originalState;
    });
}

// --- 2. Gestion de la Modale de Suppression ---
let targetCampName = "";

function openDeleteModal(token, campName, inscritsStr) {
    // Reset
    document.getElementById('deleteForm').reset();
    document.getElementById('finalDeleteBtn').disabled = true;
    document.getElementById('finalDeleteBtn').classList.add('opacity-50', 'cursor-not-allowed');
    
    // Set Data
    document.getElementById('modalToken').value = token;
    document.getElementById('modalCampNameDisplay').textContent = campName;
    targetCampName = campName; 

    // Gestion liste inscrits
    const listDiv = document.getElementById('modalInscritsList');
    if (inscritsStr && inscritsStr.trim() !== "") {
        const names = inscritsStr.split('|||');
        let html = '<ul class="list-disc pl-5 mt-1 space-y-1">';
        names.forEach(name => {
            html += `<li class="text-red-600 font-medium">${name}</li>`;
        });
        html += '</ul>';
        listDiv.innerHTML = html;
    } else {
        listDiv.innerHTML = '<span class="text-green-600 italic">Aucun inscrit pour le moment.</span>';
    }

    // Show Modal
    document.getElementById('deleteModal').classList.remove('hidden');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}

// Vérification de la saisie pour activer le bouton
const nameInput = document.getElementById('confirmNameInput');
const checkInput = document.getElementById('refundCheck');
const btn = document.getElementById('finalDeleteBtn');

function checkDeleteForm() {
    const isNameOk = nameInput.value.trim() === targetCampName;
    const isChecked = checkInput.checked;

    if (isNameOk && isChecked) {
        btn.disabled = false;
        btn.classList.remove('opacity-50', 'cursor-not-allowed');
    } else {
        btn.disabled = true;
        btn.classList.add('opacity-50', 'cursor-not-allowed');
    }
}

nameInput.addEventListener('input', checkDeleteForm);
checkInput.addEventListener('change', checkDeleteForm);

// --- 3. Partage de lien ---
function shareLink(token) {
    if (!token) return alert("Pas de lien.");
    const fullUrl = "https://moncamp.fr/camp_details.php?t=" + token;
    
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(fullUrl).then(() => {
            alert("Lien copié !");
        });
    } else {
        prompt("Copiez le lien :", fullUrl);
    }
}
</script>


<div id="sessionModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/70 backdrop-blur-sm p-4" role="dialog" aria-modal="true" aria-labelledby="sessionTitle">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">
        <h3 id="sessionTitle" class="text-xl font-extrabold text-[#26343b] mb-1">Ajouter une session</h3>
        <p class="text-sm text-gray-500 mb-5">Nouvelles dates pour <b id="sessionCampName"></b>. Tout le reste (description, âges, tarifs, images) est repris du séjour. Les familles verront un seul camp avec plusieurs sessions.</p>
        <form id="sessionForm" class="space-y-4">
            <input type="hidden" id="sessionToken">
            <div class="grid grid-cols-2 gap-3">
                <label class="block text-sm font-semibold text-gray-700">Début<input type="date" id="sessionDebut" required class="mt-1 w-full border border-gray-300 rounded-lg px-3 py-2"></label>
                <label class="block text-sm font-semibold text-gray-700">Fin<input type="date" id="sessionFin" required class="mt-1 w-full border border-gray-300 rounded-lg px-3 py-2"></label>
            </div>
            <label class="block text-sm font-semibold text-gray-700">Date limite d'inscription <span class="font-normal text-gray-400">(facultatif)</span>
                <input type="date" id="sessionLimite" class="mt-1 w-full border border-gray-300 rounded-lg px-3 py-2"></label>
            <label class="block text-sm font-semibold text-gray-700">Prix de cette session <span class="font-normal text-gray-400">(facultatif, sinon identique)</span>
                <input type="number" id="sessionPrix" min="0" step="0.01" class="mt-1 w-full border border-gray-300 rounded-lg px-3 py-2"></label>
            <p id="sessionError" class="hidden text-sm text-red-600 font-semibold"></p>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeSessionModal()" class="flex-1 px-4 py-3 bg-gray-100 text-gray-700 rounded-lg font-bold hover:bg-gray-200 transition">Annuler</button>
                <button type="submit" id="sessionSubmit" class="flex-1 px-4 py-3 bg-[#26343b] text-white rounded-lg font-bold hover:bg-black transition">Ajouter</button>
            </div>
        </form>
    </div>
</div>
<script>
function openSessionModal(token, name, debut, fin) {
    document.getElementById('sessionToken').value = token;
    document.getElementById('sessionCampName').textContent = name;
    // propose la même durée, une semaine après la dernière session connue (jamais dans le passé)
    const d = new Date(debut), f = new Date(fin);
    const days = Math.max(0, Math.round((f - d) / 86400000));
    let start = new Date(f); start.setDate(start.getDate() + 7);
    if (isNaN(start) || start < new Date()) start = new Date();
    const end = new Date(start); end.setDate(end.getDate() + days);
    const iso = x => x.toISOString().slice(0, 10);
    document.getElementById('sessionDebut').value = iso(start);
    document.getElementById('sessionFin').value = iso(end);
    document.getElementById('sessionLimite').value = '';
    document.getElementById('sessionPrix').value = '';
    document.getElementById('sessionError').classList.add('hidden');
    document.getElementById('sessionModal').classList.remove('hidden');
}
function closeSessionModal() { document.getElementById('sessionModal').classList.add('hidden'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSessionModal(); });
document.getElementById('sessionForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('sessionSubmit'), err = document.getElementById('sessionError');
    btn.disabled = true; btn.textContent = 'Ajout...'; err.classList.add('hidden');
    try {
        const res = await fetch('api/add_session.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                token: document.getElementById('sessionToken').value,
                date_debut: document.getElementById('sessionDebut').value,
                date_fin: document.getElementById('sessionFin').value,
                date_limite_inscription: document.getElementById('sessionLimite').value,
                prix: document.getElementById('sessionPrix').value
            })
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.error || 'Erreur');
        window.location.reload();
    } catch (ex) {
        err.textContent = ex.message; err.classList.remove('hidden');
        btn.disabled = false; btn.textContent = 'Ajouter';
    }
});
</script>

<?php require_once 'partials/footer.php'; ?>
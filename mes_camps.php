<?php
// mes_camps.php : « Mes séjours » du directeur. Un clic sur un séjour ouvre son panneau (modifier, inscrits, partager, sessions).
require_once 'api/config.php';
require_once 'api/sessions.php';
require_once 'api/options.php';

if (!isset($_SESSION['user']) || empty($_SESSION['user']['is_directeur'])) { header('Location: index.php'); exit; }

$userId = $_SESSION['user']['id'];
$camps = [];
$error = null;

try {
    options_ensure($pdo);
    $stmtOrga = $pdo->prepare("SELECT id FROM organisateurs WHERE user_id = ?");
    $stmtOrga->execute([$userId]);
    $organisateurIds = $stmtOrga->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($organisateurIds)) {
        $placeholders = implode(',', array_fill(0, count($organisateurIds), '?'));
        $stmt = $pdo->prepare("
            SELECT c.*,
                   COUNT(i.id) AS nb_inscrits,
                   GROUP_CONCAT(CONCAT(e.prenom, ' ', e.nom) SEPARATOR '|||') AS liste_inscrits
            FROM camps c
            LEFT JOIN inscriptions i ON c.id = i.camp_id AND COALESCE(i.statut, '') NOT IN ('annule', 'Annulé', 'Annule')
            LEFT JOIN enfants e ON i.enfant_id = e.id
            WHERE c.organisateur_id IN ($placeholders) AND c.supprime = 0
            GROUP BY c.id
            ORDER BY c.date_debut DESC");
        $stmt->execute($organisateurIds);
        $camps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log('mes_camps: ' . $e->getMessage());
    $error = "Impossible de charger vos séjours pour le moment.";
}

// ---- Un camp = une carte ; ses sessions (dates) et formules (durée/prix) sont dans son panneau ----
$todayYmd = date('Y-m-d');
$mois = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
$fmtDay = fn(DateTime $d) => $d->format('j') . ' ' . $mois[(int)$d->format('n') - 1];

$groupsRaw = [];
foreach ($camps as $c) {
    [$n1, $n2] = normalizeSessionDates($c['date_debut'], $c['date_fin']);
    $c['_start'] = $n1; $c['_end'] = $n2;
    $groupsRaw[sessionGroupKey($c)][] = $c;
}
$cards = [];
foreach ($groupsRaw as $list) {
    usort($list, fn($a, $b) => ($a['_start'] <=> $b['_start']) ?: ((float)$a['prix'] <=> (float)$b['prix']));
    $rep = null;
    foreach ($list as $s) { if ($s['_end']->format('Y-m-d') >= $todayYmd) { $rep = $s; break; } }
    $upcoming = $rep !== null;
    if (!$rep) $rep = end($list);
    $prices = array_filter(array_map(fn($s) => (float)$s['prix'], $list), fn($p) => $p > 0);
    $tot = 0; $quotaTot = 0;
    $sessions = [];
    foreach ($list as $s) {
        $tot += (int)$s['nb_inscrits']; $quotaTot += max(1, (int)$s['quota_global']);
        $sessions[] = [
            'token' => $s['token'], 'nom' => $s['nom'],
            'label' => $fmtDay($s['_start']) . ' → ' . $fmtDay($s['_end']) . ' ' . $s['_end']->format('Y'),
            'nuits' => max(0, (int)$s['_start']->diff($s['_end'])->days),
            'prix' => (float)$s['prix'], 'inscrits' => (int)$s['nb_inscrits'], 'quota' => max(1, (int)$s['quota_global']),
            'prive' => !empty($s['prive']), 'fini' => $s['_end']->format('Y-m-d') < $todayYmd,
            'valide' => !empty($s['valide']), 'refuse' => !empty($s['refuse']),
            'liste' => $s['liste_inscrits'] ? explode('|||', $s['liste_inscrits']) : [],
        ];
    }
    $cards[] = [
        'upcoming' => $upcoming,
        'sortKey' => $upcoming ? $rep['_start']->format('Y-m-d') : $list[count($list) - 1]['_end']->format('Y-m-d'),
        'token' => $rep['token'], 'nom' => $rep['nom'], 'ville' => $rep['ville'],
        'img' => !empty($rep['image_url']) ? $rep['image_url'] : null,
        'prix' => $prices ? min($prices) : (float)$rep['prix'], 'multi' => count($list) > 1,
        'inscrits' => $tot, 'quota' => $quotaTot,
        'reserve' => $rep['reserve_sexe'] ?? null,
        'online' => !empty($rep['inscription_en_ligne']),
        'lastStart' => $list[count($list) - 1]['_start']->format('Y-m-d'), 'lastEnd' => $list[count($list) - 1]['_end']->format('Y-m-d'),
        'sessions' => $sessions,
    ];
}
usort($cards, function ($a, $b) {
    if ($a['upcoming'] !== $b['upcoming']) return $a['upcoming'] ? -1 : 1;
    return $a['upcoming'] ? strcmp($a['sortKey'], $b['sortKey']) : strcmp($b['sortKey'], $a['sortKey']);
});
$nbUp = count(array_filter($cards, fn($c) => $c['upcoming']));

$body_class = 'bg-white';
require_once 'partials/header.php';
$flash = ($_GET['ok'] ?? $_GET['success'] ?? '');
?>
<title>Mes séjours - ColoMap</title>
<main class="ds-page" style="max-width:1200px">
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
        <div>
            <h1 class="ds-h1">Mes séjours</h1>
            <p class="ds-lead">Cliquez sur un séjour pour le modifier, voir les inscrits, le partager ou ajouter une session.</p>
        </div>
        <a href="create_camp.php" class="ds-btn"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> Publier un séjour</a>
    </div>

    <?php if ($flash === 'created' || $flash === 'updated'): ?>
        <div class="ds-alert-ok mb-6 font-semibold" role="status"><?= $flash === 'created' ? 'Séjour publié.' : 'Séjour mis à jour. Il repasse en validation avant d\'être de nouveau visible.' ?></div>
    <?php endif; ?>
    <?php if ($error): ?><div class="ds-alert-err mb-6" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (!$cards): ?>
        <div class="ds-card p-12 text-center">
            <h2 class="text-xl font-extrabold text-[#26343b]">Aucun séjour pour le moment</h2>
            <p class="ds-lead mb-6">Commencez par publier votre premier séjour.</p>
            <a href="create_camp.php" class="ds-btn">Publier mon premier séjour</a>
        </div>
    <?php else: ?>
        <div class="flex flex-wrap items-center gap-3 mb-6">
            <div class="flex gap-2" role="group" aria-label="Filtrer les séjours">
                <button type="button" class="ds-chip" data-f="up" aria-pressed="true">À venir (<?= $nbUp ?>)</button>
                <button type="button" class="ds-chip" data-f="past" aria-pressed="false">Terminés (<?= count($cards) - $nbUp ?>)</button>
                <button type="button" class="ds-chip" data-f="all" aria-pressed="false">Tous</button>
            </div>
            <input id="q" type="search" class="ds-input !w-auto flex-1 min-w-[180px] max-w-xs ml-auto" placeholder="Rechercher un séjour" aria-label="Rechercher un séjour">
        </div>
        <div id="grid" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($cards as $i => $c):
                $pct = min(100, $c['quota'] > 0 ? round($c['inscrits'] / $c['quota'] * 100) : 0);
                $nb = count($c['sessions']);
            ?>
            <button type="button" class="camp-card ds-card text-left overflow-hidden flex flex-col transition hover:shadow-[0_14px_34px_rgba(38,52,59,.12)] hover:-translate-y-0.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#3d6a8e]"
                    data-i="<?= $i ?>" data-up="<?= $c['upcoming'] ? 1 : 0 ?>" data-name="<?= htmlspecialchars(mb_strtolower($c['nom'] . ' ' . $c['ville'])) ?>">
                <span class="block relative h-44 w-full bg-[#e6ebf0]">
                    <?php if ($c['img']): ?><img src="<?= htmlspecialchars($c['img']) ?>" alt="" loading="lazy" class="w-full h-full object-cover"><?php endif; ?>
                    <span class="absolute inset-0 bg-gradient-to-t from-black/55 to-transparent"></span>
                    <span class="absolute top-3 left-3 flex gap-2">
                        <span class="ds-badge !bg-white/90"><?= $c['sessions'][0]['prive'] && $nb === 1 ? 'Privé' : 'Public' ?></span>
                        <?php if ($c['reserve']): ?><span class="ds-badge !bg-white/90"><?= $c['reserve'] === SEXE_FILLE ? 'Filles' : 'Garçons' ?></span><?php endif; ?>
                    </span>
                </span>
                <span class="block p-5 flex-1">
                    <span class="block text-lg font-extrabold text-[#26343b] leading-tight truncate"><?= htmlspecialchars($c['nom']) ?></span>
                    <span class="block text-sm text-[#6b7780] mt-0.5"><?= htmlspecialchars($c['ville']) ?> · <?= $nb ?> session<?= $nb > 1 ? 's' : '' ?></span>
                    <span class="flex justify-between text-xs font-semibold mt-4 mb-1.5"><span class="text-[#6b7780]">Inscrits</span><span class="text-[#26343b]"><?= $c['inscrits'] ?> / <?= $c['quota'] ?></span></span>
                    <span class="block w-full bg-[#f1f3f5] rounded-full h-2 overflow-hidden"><span class="block bg-[#2f3d45] h-2 rounded-full" style="width:<?= $pct ?>%"></span></span>
                    <span class="block mt-4 font-bold text-[#26343b]"><?= $c['multi'] ? 'dès ' : '' ?><?= number_format($c['prix'], 0, ',', ' ') ?> €</span>
                </span>
            </button>
            <?php endforeach; ?>
        </div>
        <p id="none" class="hidden ds-lead text-center py-10">Aucun séjour ne correspond.</p>
    <?php endif; ?>
</main>

<div id="scrim" class="ds-scrim"></div>
<aside id="drawer" class="ds-drawer" role="dialog" aria-modal="true" aria-labelledby="dTitle" aria-hidden="true">
    <div class="flex items-start justify-between gap-3 p-6 border-b border-[#e5e7eb]">
        <div class="min-w-0"><p class="ds-eyebrow">Séjour</p><h2 id="dTitle" class="text-xl font-extrabold text-[#26343b] leading-tight"></h2><p id="dSub" class="text-sm text-[#6b7780] mt-0.5"></p></div>
        <button type="button" class="ds-iconbtn" id="dClose" aria-label="Fermer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
    </div>
    <div class="flex-1 overflow-y-auto p-6 space-y-6">
        <div class="grid grid-cols-2 gap-3" id="dActions"></div>
        <div><p class="ds-eyebrow mb-3">Sessions</p><div id="dSessions" class="space-y-2"></div></div>
    </div>
</aside>

<!-- Ajouter une session -->
<div id="sessionModal" class="ds-modal" role="dialog" aria-modal="true" aria-labelledby="sessionTitle">
    <div class="ds-modal-box">
        <h3 id="sessionTitle" class="text-xl font-extrabold text-[#26343b] mb-1">Ajouter une session</h3>
        <p class="text-sm text-[#6b7780] mb-5">Nouvelles dates pour <b id="sessionCampName"></b>. Le reste (description, âges, tarifs, options, images) est repris du séjour ; les familles verront un seul camp avec plusieurs sessions.</p>
        <form id="sessionForm" class="space-y-4">
            <input type="hidden" id="sessionToken">
            <div class="grid grid-cols-2 gap-3">
                <div><label class="ds-label" for="sessionDebut">Début</label><input type="date" id="sessionDebut" required class="ds-input"></div>
                <div><label class="ds-label" for="sessionFin">Fin</label><input type="date" id="sessionFin" required class="ds-input"></div>
            </div>
            <div><label class="ds-label" for="sessionLimite">Date limite d'inscription <span class="font-normal text-[#6b7780]">(facultatif)</span></label><input type="date" id="sessionLimite" class="ds-input"></div>
            <div><label class="ds-label" for="sessionPrix">Prix de cette session <span class="font-normal text-[#6b7780]">(facultatif, sinon identique)</span></label><input type="number" id="sessionPrix" min="0" step="0.01" class="ds-input"></div>
            <p id="sessionError" class="hidden text-sm font-semibold text-[#5b4a1c]" role="alert"></p>
            <div class="flex gap-3 pt-1"><button type="button" class="ds-btn ds-btn-light flex-1" data-close="sessionModal">Annuler</button><button type="submit" id="sessionSubmit" class="ds-btn flex-1">Ajouter</button></div>
        </form>
    </div>
</div>

<!-- Suppression -->
<div id="deleteModal" class="ds-modal" role="dialog" aria-modal="true" aria-labelledby="delTitle">
    <div class="ds-modal-box">
        <h3 id="delTitle" class="text-xl font-extrabold text-[#26343b]">Supprimer cette session</h3>
        <p class="text-sm text-[#6b7780] mt-1 mb-4">Action <b>irréversible</b>.</p>
        <form id="deleteForm" action="api/delete_camp.php" method="POST" class="space-y-4">
            <input type="hidden" name="token" id="modalToken">
            <div class="bg-[#f8f9fa] border border-[#e5e7eb] rounded-xl p-4 text-sm max-h-36 overflow-y-auto"><p class="font-bold text-[#26343b] mb-1">Inscrits concernés</p><div id="modalInscritsList" class="text-[#3b4a54]"></div></div>
            <div><label class="ds-label" for="confirmNameInput">Écrivez <b id="modalCampNameDisplay" class="select-all"></b> pour confirmer</label><input type="text" id="confirmNameInput" class="ds-input" autocomplete="off"></div>
            <label class="flex items-start gap-3 text-sm cursor-pointer"><input id="refundCheck" type="checkbox" class="mt-0.5 w-4 h-4 accent-[#2f3d45]"><span>Je m'engage sur l'honneur à rembourser intégralement les familles inscrites si le séjour est annulé.</span></label>
            <div class="flex gap-3"><button type="button" class="ds-btn ds-btn-light flex-1" data-close="deleteModal">Annuler</button><button type="submit" id="finalDeleteBtn" class="ds-btn flex-1" disabled>Supprimer définitivement</button></div>
        </form>
    </div>
</div>
<div id="toast" class="ds-toast" role="status"></div>

<script>
const CARDS = <?= json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
const eur = n => (Number(n) || 0).toLocaleString('fr-FR', { maximumFractionDigits: 2 }) + ' €';
const IC = {
  edit: '<path d="M4 20h4L19 9l-4-4L4 16v4zM13.5 6.5l4 4"/>', users: '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6M16 5.2a3 3 0 010 5.6M18 14.3c1.8.8 3 2.6 3 4.7"/>',
  share: '<circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M8.2 10.8l7.6-3.6M8.2 13.2l7.6 3.6"/>', plus: '<path d="M12 5v14M5 12h14"/>',
  eye: '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>', bolt: '<path d="M13 3L5 14h6l-1 7 8-11h-6l1-7z"/>', trash: '<path d="M5 7h14M10 7V4h4v3M7 7l1 13h8l1-13M10 11v6M14 11v6"/>'
};
const ico = n => `<svg class="w-5 h-5 flex-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${IC[n]}</svg>`;
function toast(m) { const t = $('toast'); t.textContent = m; t.classList.add('show'); clearTimeout(toast.t); toast.t = setTimeout(() => t.classList.remove('show'), 2600); }

// ---- filtres
let filt = 'up';
function applyFilter() {
    const q = ($('q') ? $('q').value : '').trim().toLowerCase(); let n = 0;
    document.querySelectorAll('.camp-card').forEach(c => {
        const ok = (filt === 'all' || (filt === 'up') === (c.dataset.up === '1')) && (!q || c.dataset.name.includes(q));
        c.classList.toggle('hidden', !ok); if (ok) n++;
    });
    if ($('none')) $('none').classList.toggle('hidden', n > 0);
}
document.querySelectorAll('[data-f]').forEach(b => b.addEventListener('click', () => {
    filt = b.dataset.f;
    document.querySelectorAll('[data-f]').forEach(x => { const on = x === b; x.setAttribute('aria-pressed', on); x.style.background = on ? 'var(--ds-btn)' : ''; x.style.color = on ? '#fff' : ''; x.style.borderColor = on ? 'var(--ds-btn)' : ''; });
    applyFilter();
}));
if ($('q')) $('q').addEventListener('input', applyFilter);
const first = document.querySelector('[data-f="up"]'); if (first) { if (!document.querySelector('.camp-card[data-up="1"]')) document.querySelector('[data-f="all"]').click(); else first.click(); }

// ---- panneau du séjour
let cur = null;
function openDrawer(i) {
    const c = CARDS[i]; cur = c;
    $('dTitle').textContent = c.nom;
    $('dSub').textContent = c.ville + (c.reserve ? ' · réservé aux ' + (c.reserve === 'Femme' ? 'filles' : 'garçons') : '');
    const act = (href, icon, label, extra = '') => `<a href="${href}" ${extra} class="ds-tile !p-4 !rounded-2xl !gap-3 text-sm font-bold text-[#26343b]">${ico(icon)}<span>${label}</span></a>`;
    const btn = (id, icon, label) => `<button type="button" id="${id}" class="ds-tile !p-4 !rounded-2xl !gap-3 text-sm font-bold text-[#26343b] text-left">${ico(icon)}<span>${label}</span></button>`;
    $('dActions').innerHTML =
        act('edit_camp.php?t=' + encodeURIComponent(c.token), 'edit', 'Modifier le séjour') +
        act('gestion_camp.php?t=' + encodeURIComponent(c.token), 'users', 'Voir les inscrits') +
        btn('dShare', 'share', 'Partager') + btn('dAdd', 'plus', 'Ajouter une session') +
        act('camp_details.php?t=' + encodeURIComponent(c.token), 'eye', 'Page publique', 'target="_blank" rel="noopener"') +
        act('boost.php?t=' + encodeURIComponent(c.token), 'bolt', 'Booster');
    $('dShare').onclick = () => share(c.token, c.nom);
    $('dAdd').onclick = () => openSession(c);
    $('dSessions').innerHTML = c.sessions.map((s, k) => {
        const pct = Math.min(100, Math.round(s.inscrits / s.quota * 100));
        const state = !s.valide ? (s.refuse ? 'Refusé' : 'En validation') : (s.fini ? 'Terminé' : '');
        return `<div class="border border-[#e5e7eb] rounded-2xl p-4">
          <div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="text-sm font-bold text-[#26343b]">${esc(s.label)}</p>
            <p class="text-xs text-[#6b7780] mt-0.5">${s.nuits} nuit${s.nuits > 1 ? 's' : ''} · ${eur(s.prix)} · ${s.inscrits}/${s.quota} inscrits${state ? ' · <b>' + state + '</b>' : ''}</p></div>
            <label class="ds-switch" title="Visible par les familles"><input type="checkbox" ${s.prive ? '' : 'checked'} onchange="setVisible('${s.token}', this)" aria-label="Session visible par les familles"><span></span></label></div>
          <div class="h-1.5 bg-[#f1f3f5] rounded-full mt-3 overflow-hidden"><div class="h-1.5 bg-[#2f3d45] rounded-full" style="width:${pct}%"></div></div>
          <div class="flex flex-wrap gap-2 mt-3">
            <a class="ds-btn ds-btn-light ds-btn-sm" href="edit_camp.php?t=${encodeURIComponent(s.token)}">Modifier</a>
            <a class="ds-btn ds-btn-light ds-btn-sm" href="gestion_camp.php?t=${encodeURIComponent(s.token)}">Inscrits</a>
            <button type="button" class="ds-btn ds-btn-light ds-btn-sm" onclick="share('${s.token}', CARDS[${i}].nom)">Partager</button>
            <button type="button" class="ds-iconbtn danger ml-auto" aria-label="Supprimer cette session" onclick="openDelete(${i}, ${k})">${ico('trash')}</button>
          </div></div>`;
    }).join('');
    $('drawer').classList.add('open'); $('drawer').setAttribute('aria-hidden', 'false'); $('scrim').classList.add('open');
    setTimeout(() => $('dClose').focus(), 80);
}
function closeDrawer() { $('drawer').classList.remove('open'); $('drawer').setAttribute('aria-hidden', 'true'); $('scrim').classList.remove('open'); }
document.querySelectorAll('.camp-card').forEach(c => c.addEventListener('click', () => openDrawer(+c.dataset.i)));
$('dClose').addEventListener('click', closeDrawer); $('scrim').addEventListener('click', closeDrawer);
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeDrawer(); document.querySelectorAll('.ds-modal.open').forEach(m => m.classList.remove('open')); } });
document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => $(b.dataset.close).classList.remove('open')));
document.querySelectorAll('.ds-modal').forEach(m => m.addEventListener('mousedown', e => { if (e.target === m) m.classList.remove('open'); }));

// ---- partage
async function share(token, nom) {
    const url = location.origin + '/camp_details.php?t=' + token;
    try { if (navigator.share) { await navigator.share({ title: nom, url }); return; } } catch (e) { if (e.name === 'AbortError') return; }
    try { await navigator.clipboard.writeText(url); toast('Lien copié.'); } catch (e) { prompt('Copiez le lien :', url); }
}

// ---- visibilité d'une session
async function setVisible(token, cb) {
    const on = cb.checked;
    try {
        const r = await fetch('api/update_camp_visibility.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'token=' + encodeURIComponent(token) + '&prive=' + (on ? 0 : 1) });
        const d = await r.json(); if (!d.success) throw 0;
        toast(on ? 'Session visible par les familles.' : 'Session masquée.');
    } catch (e) { cb.checked = !on; toast('Mise à jour impossible.'); }
}

// ---- ajouter une session
function openSession(c) {
    closeDrawer();
    $('sessionToken').value = c.token; $('sessionCampName').textContent = c.nom;
    const d = new Date(c.lastStart), f = new Date(c.lastEnd), days = Math.max(0, Math.round((f - d) / 86400000));
    let start = new Date(f); start.setDate(start.getDate() + 7); if (isNaN(start) || start < new Date()) start = new Date();
    const end = new Date(start); end.setDate(end.getDate() + days); const iso = x => x.toISOString().slice(0, 10);
    $('sessionDebut').value = iso(start); $('sessionFin').value = iso(end); $('sessionLimite').value = ''; $('sessionPrix').value = '';
    $('sessionError').classList.add('hidden'); $('sessionModal').classList.add('open');
}
$('sessionForm').addEventListener('submit', async e => {
    e.preventDefault();
    const b = $('sessionSubmit'), err = $('sessionError'); b.disabled = true; b.textContent = 'Ajout…'; err.classList.add('hidden');
    try {
        const r = await fetch('api/add_session.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({
            token: $('sessionToken').value, date_debut: $('sessionDebut').value, date_fin: $('sessionFin').value,
            date_limite_inscription: $('sessionLimite').value, prix: $('sessionPrix').value }) });
        const d = await r.json(); if (!r.ok || !d.success) throw new Error(d.error || 'Erreur');
        location.reload();
    } catch (x) { err.textContent = x.message; err.classList.remove('hidden'); b.disabled = false; b.textContent = 'Ajouter'; }
});

// ---- suppression
let delName = '';
function openDelete(i, k) {
    const s = CARDS[i].sessions[k]; delName = s.nom; closeDrawer();
    $('deleteForm').reset(); $('finalDeleteBtn').disabled = true;
    $('modalToken').value = s.token; $('modalCampNameDisplay').textContent = s.nom;
    $('modalInscritsList').innerHTML = s.liste.length ? '<ul class="list-disc pl-5 space-y-0.5">' + s.liste.map(n => '<li>' + esc(n) + '</li>').join('') + '</ul>' : '<span class="italic">Aucun inscrit.</span>';
    $('deleteModal').classList.add('open');
}
const chk = () => { $('finalDeleteBtn').disabled = !($('confirmNameInput').value.trim() === delName && $('refundCheck').checked); };
$('confirmNameInput').addEventListener('input', chk); $('refundCheck').addEventListener('change', chk);
</script>
<?php require_once 'partials/footer.php'; ?>

<?php
// mes_camps.php : « Mes séjours » du directeur. Un clic sur un séjour ouvre sa page de gestion (gestion_camp.php).
require_once 'api/config.php';
require_once 'api/sessions.php';
require_once 'api/options.php';

if (!isset($_SESSION['user']) || empty($_SESSION['user']['is_directeur'])) { header('Location: index.php'); exit; }

$userId = $_SESSION['user']['id'];
$camps = [];
$orgaNoms = [];
$error = null;

try {
    options_ensure($pdo);
    $stmtOrga = $pdo->prepare("SELECT id FROM organisateurs WHERE user_id = ?");
    $stmtOrga->execute([$userId]);
    $organisateurIds = $stmtOrga->fetchAll(PDO::FETCH_COLUMN);
    $stmtNom = $pdo->prepare("SELECT id, nom FROM organisateurs WHERE user_id = ?");
    $stmtNom->execute([$userId]);
    foreach ($stmtNom->fetchAll(PDO::FETCH_ASSOC) as $o) $orgaNoms[(int)$o['id']] = $o['nom'];
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
    $ongoing = false; $anyPending = false; $anyRefused = false;
    foreach ($list as $s) {
        if ($s['_start']->format('Y-m-d') <= $todayYmd && $s['_end']->format('Y-m-d') >= $todayYmd) $ongoing = true;
        if (empty($s['valide'])) { if (!empty($s['refuse'])) $anyRefused = true; else $anyPending = true; }
    }
    $eq = null;   // lien d'inscription de l'équipe d'animation (option B), porté par la première session qui l'a activée
    foreach ($list as $s) { if (!empty($s['equipe_active']) && !empty($s['equipe_lien'])) { $eq = ['t' => $s['token'], 'l' => $s['equipe_lien'], 'pub' => ($s['equipe_visibilite'] ?? '') === 'public']; break; } }
    $cards[] = [
        'eq' => $eq,
        'ts' => $ongoing ? 'cours' : ($upcoming ? 'avenir' : 'passe'),
        'val' => $anyRefused ? 'refuse' : ($anyPending ? 'attente' : 'ok'),
        'orga' => (int)$rep['organisateur_id'], 'orgaNom' => $orgaNoms[(int)$rep['organisateur_id']] ?? '',
        'theme' => trim((string)($rep['theme'] ?? '')),
        'start' => $list[0]['_start']->format('Y-m-d'), 'end' => $list[count($list) - 1]['_end']->format('Y-m-d'),
        'pmax' => $prices ? max($prices) : (float)$rep['prix'],
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
<title>Mes séjours - moncamp.fr</title>
<main class="ds-page" style="max-width:1200px">
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
        <div>
            <h1 class="ds-h1">Mes séjours</h1>
            <p class="ds-lead">Cliquez sur un séjour pour le gérer : inscrits, sessions, liens, équipe.</p>
        </div>
        <div class="flex items-center gap-2">
        <button type="button" id="loupe" class="ds-btn ds-btn-light" aria-expanded="false" aria-controls="filtres" aria-label="Rechercher et filtrer mes séjours"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg> Rechercher</button>
        <a href="create_camp.php" class="ds-btn"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> Publier un séjour</a>
        </div>
    </div>

    <?php if ($flash === 'created' || $flash === 'updated'): ?>
        <div class="ds-alert-ok mb-6 font-semibold" role="status"><?= $flash === 'created' ? 'Séjour enregistré. Il sera visible sur le site dès sa validation par l’équipe moncamp.fr (statut « En validation » en attendant).' : 'Séjour mis à jour.' ?></div>
    <?php endif; ?>
    <?php if ($error): ?><div class="ds-alert-err mb-6" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (!$cards): ?>
        <div class="ds-card p-12 text-center">
            <h2 class="text-xl font-extrabold text-[#26343b]">Aucun séjour pour le moment</h2>
            <p class="ds-lead mb-6">Commencez par publier votre premier séjour.</p>
            <a href="create_camp.php" class="ds-btn">Publier mon premier séjour</a>
        </div>
    <?php else: ?>
        <section id="filtres" class="ds-card p-4 sm:p-5 mb-4 hidden" aria-label="Filtrer et trier les séjours">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="lg:col-span-2"><label class="ds-label" for="q">Recherche</label><input id="q" type="search" class="ds-input" placeholder="Nom du séjour, ville, organisme" autocomplete="off"></div>
                <div><label class="ds-label" for="fTs">Période</label><select id="fTs" class="ds-select"><option value="all">Toutes</option><option value="avenir">À venir</option><option value="cours">En cours</option><option value="passe">Passés</option></select></div>
                <div><label class="ds-label" for="fVal">Statut</label><select id="fVal" class="ds-select"><option value="">Tous</option><option value="ok">Validé</option><option value="attente">En attente de validation</option><option value="refuse">Refusé</option></select></div>
                <div><label class="ds-label" for="fOrga">Organisme</label><select id="fOrga" class="ds-select"><option value="">Tous</option></select></div>
                <div><label class="ds-label" for="fVille">Ville</label><select id="fVille" class="ds-select"><option value="">Toutes</option></select></div>
                <div><label class="ds-label" for="fTheme">Type</label><select id="fTheme" class="ds-select"><option value="">Tous</option></select></div>
                <div><label class="ds-label" for="fSort">Trier par</label><select id="fSort" class="ds-select">
                    <option value="date_asc">Date (proche d'abord)</option><option value="date_desc">Date (lointaine d'abord)</option>
                    <option value="prix_asc">Prix croissant</option><option value="prix_desc">Prix décroissant</option>
                    <option value="nom">Nom (A à Z)</option><option value="inscrits">Plus d'inscrits</option></select></div>
                <div><label class="ds-label" for="fPmin">Prix min (€)</label><input id="fPmin" type="number" min="0" inputmode="numeric" class="ds-input"></div>
                <div><label class="ds-label" for="fPmax">Prix max (€)</label><input id="fPmax" type="number" min="0" inputmode="numeric" class="ds-input"></div>
                <div><label class="ds-label" for="fDu">Dates : à partir du</label><input id="fDu" type="date" class="ds-input"></div>
                <div><label class="ds-label" for="fAu">Dates : jusqu'au</label><input id="fAu" type="date" class="ds-input"></div>
            </div>
        </section>
        <div class="flex flex-wrap items-center gap-2 mb-6 min-h-[36px]">
            <p id="count" class="text-sm font-bold text-[#26343b] mr-2" role="status" aria-live="polite"></p>
            <div id="chips" class="flex flex-wrap gap-2"></div>
            <button type="button" id="reset" class="hidden text-sm font-semibold text-[#3d6a8e] hover:underline ml-1">Tout effacer</button>
        </div>
        <div id="grid" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($cards as $i => $c):
                $pct = min(100, $c['quota'] > 0 ? round($c['inscrits'] / $c['quota'] * 100) : 0);
                $nb = count($c['sessions']);
            ?>
            <button type="button" class="camp-card ds-card text-left overflow-hidden flex flex-col transition hover:shadow-[0_14px_34px_rgba(38,52,59,.12)] hover:-translate-y-0.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#3d6a8e]"
                    data-i="<?= $i ?>">
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
        <div id="none" class="hidden ds-card p-10 text-center"><p class="font-extrabold text-[#26343b]">Aucun séjour ne correspond</p><p class="ds-lead">Modifiez ou effacez les filtres pour élargir la recherche.</p></div>
    <?php endif; ?>
</main>

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

// ---- filtres, tri, recherche : rendu côté client, état dans l'URL (?q=&ts=&val=&orga=&ville=&theme=&sort=&pmin=&pmax=&du=&au=)
const F = { q: 'q', ts: 'fTs', val: 'fVal', orga: 'fOrga', ville: 'fVille', theme: 'fTheme', sort: 'fSort', pmin: 'fPmin', pmax: 'fPmax', du: 'fDu', au: 'fAu' };
const LBL = { q: 'Recherche', ts: 'Période', val: 'Statut', orga: 'Organisme', ville: 'Ville', theme: 'Type', pmin: 'Prix min', pmax: 'Prix max', du: 'À partir du', au: "Jusqu'au" };
const NEUTRAL = { ts: 'all', sort: 'date_asc' };
const norm = s => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
function fillSelect(id, vals) { [...new Set(vals.filter(Boolean))].sort((a, b) => a.localeCompare(b, 'fr')).forEach(v => $(id).add(new Option(v, v))); }
if ($('q')) {
    new Map(CARDS.map(c => [String(c.orga), c.orgaNom])).forEach((nom, id) => { if (nom) $('fOrga').add(new Option(nom, id)); });
    fillSelect('fVille', CARDS.map(c => c.ville)); fillSelect('fTheme', CARDS.map(c => c.theme));
    const grid = $('grid'), nodes = [...grid.children].filter(n => n.classList.contains('camp-card'));
    const get = k => $(F[k]).value.trim();
    const p0 = new URLSearchParams(location.search);
    Object.keys(F).forEach(k => {
        const el = $(F[k]); const v = p0.get(k);
        if (v !== null) { el.value = v; if (el.tagName === 'SELECT' && el.value !== v) el.value = NEUTRAL[k] || ''; }
        else if (k === 'ts') el.value = CARDS.some(c => c.ts !== 'passe') ? 'avenir' : 'all';
        else if (k === 'sort') el.value = 'date_asc';
    });
    const shown = k => get(k) && get(k) !== NEUTRAL[k];
    function apply() {
        const q = norm(get('q')), pmin = get('pmin') === '' ? null : +get('pmin'), pmax = get('pmax') === '' ? null : +get('pmax'), du = get('du'), au = get('au');
        const list = CARDS.map((c, i) => [c, i]).filter(([c]) =>
            (!q || norm(c.nom + ' ' + c.ville + ' ' + c.orgaNom + ' ' + c.theme).includes(q)) &&
            (!shown('ts') || c.ts === get('ts')) && (!get('val') || c.val === get('val')) && (!get('orga') || String(c.orga) === get('orga')) &&
            (!get('ville') || c.ville === get('ville')) && (!get('theme') || c.theme === get('theme')) &&
            (pmin === null || c.pmax >= pmin) && (pmax === null || c.prix <= pmax) &&
            (!du || c.end >= du) && (!au || c.start <= au));
        const by = get('sort'), key = c => c.upcoming ? c.sortKey : c.end;
        list.sort(([a], [b]) => by === 'prix_asc' ? a.prix - b.prix : by === 'prix_desc' ? b.prix - a.prix : by === 'nom' ? a.nom.localeCompare(b.nom, 'fr')
            : by === 'inscrits' ? b.inscrits - a.inscrits : by === 'date_desc' ? key(b).localeCompare(key(a))
            : (a.upcoming !== b.upcoming ? (a.upcoming ? -1 : 1) : (a.upcoming ? key(a).localeCompare(key(b)) : key(b).localeCompare(key(a)))));
        nodes.forEach(n => n.classList.add('hidden'));
        list.forEach(([, i]) => { nodes[i].classList.remove('hidden'); grid.appendChild(nodes[i]); });
        $('none').classList.toggle('hidden', list.length > 0);
        $('count').textContent = list.length + ' séjour' + (list.length > 1 ? 's' : '') + (list.length < CARDS.length ? ' sur ' + CARDS.length : '');
        const act = Object.keys(LBL).filter(shown);
        $('reset').classList.toggle('hidden', !act.length);
        $('chips').innerHTML = act.map(k => {
            const el = $(F[k]), v = el.tagName === 'SELECT' ? el.options[el.selectedIndex].text : get(k);
            return `<span class="ds-tag">${esc(LBL[k])} : ${esc(v)}<button type="button" data-rm="${k}" aria-label="Retirer le filtre ${esc(LBL[k])}">&times;</button></span>`;
        }).join('');
        const p = new URLSearchParams();
        Object.keys(F).forEach(k => { const v = get(k); if (v && !(k === 'sort' && v === 'date_asc')) p.set(k, v); });
        history.replaceState(null, '', location.pathname + (p.toString() ? '?' + p : ''));
    }
    Object.values(F).forEach(id => $(id).addEventListener(id === 'q' || id.startsWith('fP') ? 'input' : 'change', apply));
    $('chips').addEventListener('click', e => { const b = e.target.closest('[data-rm]'); if (b) { $(F[b.dataset.rm]).value = NEUTRAL[b.dataset.rm] || ''; apply(); } });
    $('reset').addEventListener('click', () => { Object.keys(F).forEach(k => { if (k !== 'sort') $(F[k]).value = NEUTRAL[k] || ''; }); apply(); });
    apply();
}

// ---- un clic ouvre la page de gestion du séjour
document.querySelectorAll('.camp-card').forEach(c => c.addEventListener('click', () => { location.href = 'gestion_camp.php?t=' + encodeURIComponent(CARDS[+c.dataset.i].token); }));

// ---- la recherche est repliée : la loupe la déplie (elle reste dépliée si un filtre est actif dans l'adresse)
(function () {
    const f = $('filtres'), b = $('loupe'); if (!f || !b) return;
    const set = open => { f.classList.toggle('hidden', !open); b.setAttribute('aria-expanded', open); if (open) { const q = $('q'); if (q) q.focus({ preventScroll: true }); } };
    const p = new URLSearchParams(location.search);
    set([...p.keys()].some(k => k !== 'ok' && k !== 'success' && !(k === 'ts' && p.get(k) === 'avenir') && !(k === 'sort' && p.get(k) === 'date_asc')));
    b.addEventListener('click', () => set(f.classList.contains('hidden')));
})();
</script>
<?php require_once 'partials/footer.php'; ?>

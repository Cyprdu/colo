<?php
// favorites.php : mes favoris, avec les mêmes cartes que l'accueil
require_once 'api/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user'])) { header('Location: login.php?next=favorites'); exit; }
$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Mes favoris - ColoMap</title>
<style>
    .fav-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(216px, 1fr)); gap:28px 20px; }
    .fcard { display:flex; flex-direction:column; transition:opacity .3s ease, transform .3s ease; }
    .fcard-org { height:28px; display:flex; align-items:center; gap:8px; margin-bottom:8px; }
    .fcard-avatar { width:24px; height:24px; border-radius:9999px; flex:none; overflow:hidden; background:#e5e7eb; display:flex; align-items:center; justify-content:center; color:#9aa3ab; font-size:11px; }
    .fcard-avatar img { width:100%; height:100%; object-fit:cover; }
    .fcard-org-name { font-size:13px; color:#3b4a54; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .fcard-media { position:relative; width:100%; aspect-ratio:1/1; border-radius:18px; overflow:hidden; background:#e5e7eb; }
    .fcard-media a { position:absolute; inset:0; display:block; }
    .fcard-media img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; transition:transform .5s ease; }
    .fcard:hover .fcard-media img { transform:scale(1.04); }
    .fcard-heart { position:absolute; top:10px; right:10px; z-index:2; width:34px; height:34px; border-radius:9999px; background:#fff; color:#e5484d; display:flex; align-items:center; justify-content:center; box-shadow:0 1px 3px rgba(0,0,0,.15); transition:transform .15s ease; }
    .fcard-heart:hover { transform:scale(1.1); }
    .fcard-heart svg path { fill:#e5484d; stroke:#e5484d; stroke-width:1.8; }
    .fcard-title { margin-top:12px; font-size:15px; font-weight:500; line-height:1.3; color:#26343b; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; min-height:2.6em; }
    .fcard-price { margin-top:4px; font-size:16px; color:#26343b; height:24px; line-height:24px; }
    .fcard-meta { font-size:12.5px; color:#6b7780; height:17px; line-height:17px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
</style>

<main class="ds-page">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="ds-h1">Mes favoris</h1>
            <p class="ds-lead" id="fav-sub">Les séjours que vous avez sauvegardés.</p>
        </div>
        <a href="recherche" class="ds-btn ds-btn-light">Explorer d'autres séjours</a>
    </div>

    <div id="favorites-list" class="fav-grid mt-10" aria-live="polite"></div>

    <div id="fav-empty" class="hidden mt-6 text-center py-16 rounded-3xl bg-[#f8f5ea]/70">
        <p class="text-[20px] font-bold text-[#26343b]">Aucun favori pour le moment</p>
        <p class="mt-2 text-[#6b7780]">Touchez le cœur d'un séjour pour le retrouver ici.</p>
        <a href="recherche" class="ds-btn mt-6">Trouver un séjour</a>
    </div>

    <div class="mt-14 rounded-2xl bg-[#f8f5ea] p-4 text-center overflow-hidden">
        <span class="text-[10px] text-[#9aa3ab] uppercase tracking-widest block mb-3">Publicité</span>
        <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-3659884670016121" crossorigin="anonymous"></script>
        <ins class="adsbygoogle" style="display:block" data-ad-format="fluid" data-ad-layout-key="-6t+ed+2i-1n-4w" data-ad-client="ca-pub-3659884670016121" data-ad-slot="8390131413"></ins>
        <script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const list = document.getElementById('favorites-list'), empty = document.getElementById('fav-empty'), sub = document.getElementById('fav-sub');
    const MONTHS = ['janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'];
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const HEART = '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.5l-1.35-1.22C5.9 15.02 3 12.4 3 9.1 3 6.5 5 4.5 7.6 4.5c1.5 0 2.9.7 3.8 1.8l.6.8.6-.8c.9-1.1 2.3-1.8 3.8-1.8 2.6 0 4.6 2 4.6 4.6 0 3.3-2.9 5.9-7.65 10.18L12 20.5z"/></svg>';
    const PLACEHOLDER = 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><rect width="200" height="200" fill="#e8ecf0"/><g fill="#9aa8b8"><path d="M84 52L66 80h10L60 104h12L54 130h60L96 104h12L92 80h10L84 52z"/><rect x="82" y="130" width="5" height="12"/><path d="M116 140l22-50 22 50z"/></g></svg>');

    // Dates corrigées (début erroné après la fin sur certains séjours importés)
    function dates(d1, d2) {
        let a = d1 ? new Date(d1) : null, b = d2 ? new Date(d2) : null;
        if (!a || isNaN(a)) return '';
        if (b && !isNaN(b) && a > b) { const c = new Date(a); c.setFullYear(b.getFullYear()); if (c > b) c.setFullYear(b.getFullYear() - 1); a = c; }
        const f = d => `${d.getDate()} ${MONTHS[d.getMonth()]}`;
        return (b && !isNaN(b)) ? `Du ${f(a)} au ${f(b)} ${b.getFullYear()}` : `Dès le ${f(a)} ${a.getFullYear()}`;
    }
    const price = p => { const n = Number(p); return n > 0 ? 'Dès <b>' + (Number.isInteger(n) ? n : n.toFixed(2).replace('.', ',')) + ' €</b>' : ''; };
    const media = u => { u = String(u || '').replace(/\\/g, '/'); return u ? (/^(https?:)?\/\//.test(u) || u.indexOf('uploads/') === 0 ? u : 'uploads/camps/' + u) : PLACEHOLDER; };

    function card(c) {
        const href = 'camp_details?t=' + encodeURIComponent(c.token);
        const logo = c.organisateur_logo ? `<img src="${esc(media(c.organisateur_logo))}" alt="" onerror="this.remove()">` : '👤';
        return `<article class="fcard" data-id="${esc(c.id)}">
            <div class="fcard-org"><span class="fcard-avatar">${logo}</span><span class="fcard-org-name">${esc(c.organisateur_nom || 'ColoMap')}</span></div>
            <div class="fcard-media"><a href="${href}" aria-label="${esc(c.nom)}"><img src="${esc(media(c.image_url))}" alt="" loading="lazy" onerror="this.onerror=null;this.src='${PLACEHOLDER}'"></a>
                <button type="button" class="fcard-heart" data-camp-id="${esc(c.id)}" aria-label="Retirer des favoris">${HEART}</button></div>
            <a href="${href}" class="block">
                <h3 class="fcard-title">${esc(c.nom)}</h3>
                <p class="fcard-price">${price(c.prix)}</p>
                <p class="fcard-meta">${esc(c.ville || '')}</p>
                <p class="fcard-meta">${esc(dates(c.date_debut, c.date_fin))}</p>
            </a></article>`;
    }

    function refreshCount() {
        const n = list.querySelectorAll('.fcard').length;
        sub.textContent = n ? `${n} séjour${n > 1 ? 's' : ''} sauvegardé${n > 1 ? 's' : ''}.` : 'Les séjours que vous avez sauvegardés.';
        empty.classList.toggle('hidden', n > 0);
    }

    list.addEventListener('click', async function (e) {
        const btn = e.target.closest('.fcard-heart'); if (!btn) return;
        const cardEl = btn.closest('.fcard');
        try {
            const res = await fetch('api/toggle_favorite.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ campId: btn.dataset.campId }) });
            const r = await res.json();
            if (r.success && !r.isFavorited) {
                cardEl.style.opacity = '0'; cardEl.style.transform = 'scale(.94)';
                setTimeout(() => { cardEl.remove(); refreshCount(); }, 300);
            }
        } catch (err) { console.error(err); }
    });

    list.innerHTML = Array(4).fill('<div><div style="height:28px"></div><div style="aspect-ratio:1/1;border-radius:18px;background:#eceef1"></div></div>').join('');
    fetch('api/get_favorites.php').then(r => { if (!r.ok) throw new Error('Impossible de charger vos favoris.'); return r.json(); })
        .then(camps => { list.innerHTML = camps.map(card).join(''); refreshCount(); })
        .catch(err => { list.innerHTML = ''; empty.classList.remove('hidden'); empty.querySelector('p').textContent = err.message; });
});
</script>

<?php require_once 'partials/footer.php'; ?>

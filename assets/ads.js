/* moncamp.fr : chargement différé des annonces AdSense.
   Les emplacements sont déclarés dans le HTML (.ad-box[data-ad-slot]) ; l'annonce n'est demandée que lorsque
   l'emplacement (ou le carrousel qui le contient) approche de l'écran. Cela préserve le LCP/INP (aucune requête publicitaire au chargement)
   et améliore la visibilité (Active View) des annonces réellement demandées.
   Un emplacement qui ne reçoit pas d'annonce (aucune annonce, bloqueur, script absent) est refermé : jamais d'espace vide. */
(function () {
    'use strict';
    var CLIENT = 'ca-pub-3659884670016121';
    var io = null, waiting = new Map();   // élément surveillé -> emplacements à charger quand il approche de l'écran

    function card(box) { var sel = box.getAttribute('data-remove'); return sel ? box.closest(sel) : null; }

    function collapse(box) {
        var c = card(box);
        if (c) { c.remove(); return; }   // carte de carrousel : retirée
        var wrap = box.closest('.ad-wrap');
        box.style.minHeight = '0';
        if (wrap) wrap.classList.add('ad-empty');
    }

    function load(box) {
        if (box.getAttribute('data-loaded')) return;
        box.setAttribute('data-loaded', '1');
        var ins = document.createElement('ins');
        ins.className = 'adsbygoogle';
        ins.style.display = 'block';
        ins.setAttribute('data-ad-client', CLIENT);
        ins.setAttribute('data-ad-slot', box.getAttribute('data-ad-slot'));
        var fmt = box.getAttribute('data-ad-format') || 'auto';
        ins.setAttribute('data-ad-format', fmt);
        if (fmt === 'auto') ins.setAttribute('data-full-width-responsive', 'true');
        var layout = box.getAttribute('data-ad-layout');
        if (layout) ins.setAttribute(layout === 'in-article' ? 'data-ad-layout' : 'data-ad-layout-key', layout);
        box.appendChild(ins);

        // réponse d'AdSense : « sans annonce » referme l'emplacement, une annonce servie fait apparaître la carte de carrousel
        new MutationObserver(function (m, obs) {
            var st = ins.getAttribute('data-ad-status');
            if (st === 'unfilled') { collapse(box); obs.disconnect(); }
            else if (st === 'filled') { var c = card(box); if (c) c.classList.add('is-filled'); obs.disconnect(); }
        }).observe(ins, { attributes: true, attributeFilter: ['data-ad-status'] });

        try { (window.adsbygoogle = window.adsbygoogle || []).push({}); } catch (e) { collapse(box); return; }
        // bloqueur de publicité, script AdSense absent ou annonce masquée : rien n'est servi, l'emplacement est refermé
        setTimeout(function () { if (ins.getAttribute('data-ad-status') !== 'filled' || !ins.offsetHeight) collapse(box); }, 6000);
    }

    // near : élément dont l'approche déclenche le chargement (le carrousel, pour une carte publicitaire hors écran à l'horizontale)
    function observe(box, near) {
        var t = near || box;
        if (!io) return load(box);
        if (!waiting.has(t)) { waiting.set(t, []); io.observe(t); }
        waiting.get(t).push(box);
    }

    if ('IntersectionObserver' in window) {
        io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (!e.isIntersecting) return;
                io.unobserve(e.target);
                (waiting.get(e.target) || []).forEach(load);
                waiting.delete(e.target);
            });
        }, { rootMargin: '400px 0px' });   // on commence un peu avant que l'annonce n'entre à l'écran
    }
    window.colomapAds = { observe: observe };
    [].forEach.call(document.querySelectorAll('.ad-box[data-ad-slot]'), function (b) { observe(b); });
})();

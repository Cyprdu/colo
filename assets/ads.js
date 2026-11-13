/* ColoMap : chargement différé des annonces AdSense.
   Les emplacements sont déclarés dans le HTML (.ad-box[data-ad-slot]) ; l'annonce n'est demandée que lorsque
   l'emplacement approche de l'écran. Cela préserve le LCP/INP (aucune requête publicitaire au chargement)
   et améliore la visibilité (Active View) des annonces réellement demandées. */
(function () {
    'use strict';
    var CLIENT = 'ca-pub-3659884670016121';
    var boxes = [].slice.call(document.querySelectorAll('.ad-box[data-ad-slot]'));
    if (!boxes.length) return;

    function collapse(box) {
        // aucune annonce disponible : on libère l'espace (sans trou blanc)
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

        // si AdSense répond « sans annonce », on referme l'emplacement
        new MutationObserver(function (m, obs) {
            if (ins.getAttribute('data-ad-status') === 'unfilled') { collapse(box); obs.disconnect(); }
            else if (ins.getAttribute('data-ad-status') === 'filled') obs.disconnect();
        }).observe(ins, { attributes: true, attributeFilter: ['data-ad-status'] });

        try { (window.adsbygoogle = window.adsbygoogle || []).push({}); } catch (e) { collapse(box); }
    }

    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) { if (e.isIntersecting) { io.unobserve(e.target); load(e.target); } });
        }, { rootMargin: '400px 0px' });   // on commence un peu avant que l'annonce n'entre à l'écran
        boxes.forEach(function (b) { io.observe(b); });
    } else {
        boxes.forEach(load);
    }
})();

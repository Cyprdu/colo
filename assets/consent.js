/* moncamp.fr : consentement aux cookies (CNIL). Choix par finalité (mesure d'audience, publicité), gardé 6 mois dans le cookie
   mc_consent = 1.<référence>.<audience 0|1><publicité 0|1>.<horodatage>, preuve enregistrée par api/consent.php (table consentements).
   Tant qu'aucun choix n'est fait, aucun script non essentiel n'est chargé (assets/ads.js attend l'événement « mc:consent »). */
(function () {
    'use strict';
    var DUREE = 182 * 86400;

    function lire() {
        var m = document.cookie.match(/(?:^|;\s*)mc_consent=1\.([a-f0-9]{16})\.([01])([01])\.(\d+)/);
        if (!m || Date.now() / 1000 - Number(m[4]) > DUREE) return null;
        return { ref: m[1], audience: m[2] === '1', pub: m[3] === '1' };
    }
    function signaler() {
        if (typeof window.gtag !== 'function') return;
        var p = mc.pub ? 'granted' : 'denied';
        window.gtag('consent', 'update', { ad_storage: p, ad_user_data: p, ad_personalization: p, analytics_storage: mc.audience ? 'granted' : 'denied' });
    }

    var lu = lire();
    var mc = window.mcConsent = { choisi: !!lu, audience: !!(lu && lu.audience), pub: !!(lu && lu.pub), ouvrir: ouvrir };
    if (lu) signaler();

    var box = null;
    function el(id) { return document.getElementById(id); }

    function enregistrer(a, p) {
        var ref = lu ? lu.ref : Array.prototype.map.call(crypto.getRandomValues(new Uint8Array(8)), function (b) { return (b < 16 ? '0' : '') + b.toString(16); }).join('');
        var change = mc.choisi && (a !== mc.audience || p !== mc.pub);
        document.cookie = 'mc_consent=1.' + ref + '.' + (a ? 1 : 0) + (p ? 1 : 0) + '.' + Math.floor(Date.now() / 1000)
            + '; Max-Age=' + DUREE + '; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
        lu = { ref: ref, audience: a, pub: p };
        var envoi = fetch('/api/consent.php', { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ref: ref, a: a ? 1 : 0, p: p ? 1 : 0 }) }).catch(function () {});
        fermer();
        if (change) { envoi.then(function () { location.reload(); }); return; }   // retrait ou changement : la page est rechargée sans les scripts concernés
        var premier = !mc.choisi;
        mc.choisi = true; mc.audience = a; mc.pub = p;
        signaler();
        if (premier) document.dispatchEvent(new CustomEvent('mc:consent'));
    }

    function fermer() { if (box) box.hidden = true; }
    function ouvrir(reglages, sansFocus) {
        if (!box) return;
        el('mc-c-aud').checked = mc.audience;
        el('mc-c-pub').checked = mc.pub;
        el('mc-c-reglages').hidden = !reglages;
        el('mc-c-perso').hidden = !!reglages;
        box.hidden = false;
        if (!sansFocus) (reglages ? el('mc-c-aud') : el('mc-c-refus')).focus();
    }

    function init() {
        box = el('mc-consent');
        if (!box) return;
        el('mc-c-refus').onclick = function () { enregistrer(false, false); };
        el('mc-c-accepte').onclick = function () { enregistrer(true, true); };
        el('mc-c-perso').onclick = function () { ouvrir(true); };
        el('mc-c-ok').onclick = function () { enregistrer(el('mc-c-aud').checked, el('mc-c-pub').checked); };
        document.addEventListener('click', function (e) {
            var t = e.target.closest && e.target.closest('[data-cookies]');
            if (t) { e.preventDefault(); ouvrir(true); }
        });
        if (!mc.choisi) ouvrir(false, true);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

(function () {
    if ('serviceWorker' in navigator) window.addEventListener('load', function () { navigator.serviceWorker.register('/service-worker.js').catch(function () {}); });
    if (window.matchMedia('(display-mode: standalone)').matches || navigator.standalone) return;
    var KEY = 'mc_install_off', deferred = null;
    function later(days) { try { localStorage.setItem(KEY, String(Date.now() + days * 864e5)); } catch (e) {} }
    function pages() { try { var n = (+sessionStorage.getItem('mc_pages') || 0) + 1; sessionStorage.setItem('mc_pages', n); return n; } catch (e) { return 0; } }
    var vues = pages();
    function eligible() {
        var off = 0;
        try { off = +localStorage.getItem(KEY) || 0; } catch (e) { return false; }
        return vues >= 2 && Date.now() > off && /(^|;\s*)mc_consent=/.test(document.cookie) && !document.querySelector('#m-bar, #msg-form, [data-no-install]');
    }
    function show(ios) {
        var b = document.getElementById('mc-install');
        if (!b || !eligible()) return;
        b.querySelector('[data-ios]').hidden = !ios;
        b.querySelector('[data-android]').hidden = ios;
        b.querySelector('[data-install]').hidden = ios;
        b.hidden = false;
        b.querySelector('[data-close]').onclick = function () { b.hidden = true; later(30); };
        b.querySelector('[data-install]').onclick = function () {
            b.hidden = true;
            if (!deferred) return;
            deferred.prompt();
            deferred.userChoice.then(function (r) { if (r.outcome !== 'accepted') later(30); deferred = null; });
        };
    }
    function ready(fn) { if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn); else fn(); }
    window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; ready(function () { show(false); }); });
    window.addEventListener('appinstalled', function () { var b = document.getElementById('mc-install'); if (b) b.hidden = true; later(3650); });
    var ua = navigator.userAgent;
    if (/iPhone|iPad|iPod/.test(ua) && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua)) ready(function () { setTimeout(function () { show(true); }, 4000); });
})();

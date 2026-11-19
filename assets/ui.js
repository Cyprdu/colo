/* ColoUI : fenêtres et notifications dans la DA du site (remplace alert / confirm / prompt du navigateur).
 *
 *   ColoUI.alert(message, { title, tone })                 -> Promise<void>
 *   ColoUI.confirm(message, { title, confirmLabel, cancelLabel, danger }) -> Promise<boolean>
 *   ColoUI.toast(message, { tone })                         tone : 'ok' | 'err' | 'info'
 *   ColoUI.copy(texte, { title })                           copie dans le presse-papiers, sinon fenêtre avec le texte sélectionné
 *   ColoUI.error(erreur | message)                          fenêtre d'erreur avec un message toujours lisible (jamais de détail technique)
 *   <form data-confirm="Message"> ou <button data-confirm="Message"> : demande confirmation avant l'envoi du formulaire.
 * Accessible : rôle alertdialog, focus piégé dans la fenêtre puis restitué, Échap annule, annonces vocales (aria-live). */
(function () {
    'use strict';
    if (window.ColoUI) return;

    var ICONS = {
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        err: '<path d="M12 3l9.5 16.5h-19L12 3z"/><path d="M12 10v4M12 17h.01"/>',
        ok: '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.8 2.8L16.5 9.5"/>',
        ask: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 114 2c-.8.6-1.5 1.1-1.5 2.2M12 17h.01"/>'
    };
    function svg(name) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[name] + '</svg>'; }
    function tx(s) { return window.mct ? window.mct(s) : s; }
    function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }

    // Un message technique (SQL, exception PHP, pile d'appels, « Failed to fetch »…) n'est jamais montré tel quel à l'utilisateur.
    var TECH = /(SQLSTATE|PDO|Exception|Fatal error|Warning:|Notice:|Undefined (index|variable|array)|Stack trace|on line \d+|\.php|Unexpected token|JSON|Failed to fetch|NetworkError|Load failed|TypeError|ReferenceError|syntax error|mysqli?_)/i;
    function friendly(msg, fallback) {
        var m = (msg && msg.message !== undefined ? msg.message : msg);
        m = (m == null ? '' : String(m)).trim();
        if (/Failed to fetch|NetworkError|Load failed|network/i.test(m)) return 'Connexion impossible. Vérifiez votre connexion internet puis réessayez.';
        if (!m || TECH.test(m) || m.length > 400) return fallback || 'Une erreur est survenue. Réessayez dans un instant.';
        return m;
    }

    var queue = [], open = false, lastFocus = null;

    function focusables(root) {
        return Array.prototype.slice.call(root.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')).filter(function (n) { return !n.disabled && n.offsetParent !== null; });
    }

    function show(opts) {
        return new Promise(function (resolve) { queue.push({ o: opts, resolve: resolve }); if (!open) next(); });
    }

    function next() {
        var item = queue.shift();
        if (!item) { open = false; return; }
        open = true;
        var o = item.o, uid = 'cu' + Date.now();
        lastFocus = document.activeElement;
        var back = el('div', 'cu-back');
        var box = el('div', 'cu-box' + (o.danger ? ' cu-danger' : ''));
        box.setAttribute('role', 'alertdialog'); box.setAttribute('aria-modal', 'true');
        box.setAttribute('aria-labelledby', uid + 't'); box.setAttribute('aria-describedby', uid + 'm');
        var ico = el('div', 'cu-ico cu-' + (o.tone || 'info'), svg(o.icon || 'info'));
        var title = el('h2', 'cu-title'); title.id = uid + 't'; title.textContent = tx(o.title);
        var msg = el('p', 'cu-msg'); msg.id = uid + 'm'; msg.textContent = tx(o.message);
        var actions = el('div', 'cu-actions');
        var done = false;
        function close(value) {
            if (done) return; done = true;
            document.removeEventListener('keydown', onKey, true);
            back.classList.remove('cu-in');
            setTimeout(function () { back.remove(); if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} } item.resolve(value); next(); }, 140);
        }
        var cancel = null, ok = el('button', 'cu-btn cu-primary'); ok.type = 'button'; ok.textContent = tx(o.confirmLabel || 'OK');
        if (o.cancelLabel) { cancel = el('button', 'cu-btn cu-light'); cancel.type = 'button'; cancel.textContent = tx(o.cancelLabel); cancel.onclick = function () { close(false); }; actions.appendChild(cancel); }
        ok.onclick = function () { close(true); }; actions.appendChild(ok);
        function onKey(e) {
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(o.cancelLabel ? false : true); return; }
            if (e.key === 'Tab') {
                var f = focusables(box); if (!f.length) return;
                var first = f[0], last = f[f.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        }
        back.addEventListener('mousedown', function (e) { if (e.target === back) close(o.cancelLabel ? false : true); });
        box.appendChild(ico); box.appendChild(title); box.appendChild(msg);
        if (o.field) { var inp = el('input', 'cu-field'); inp.type = 'text'; inp.readOnly = true; inp.value = o.field; inp.setAttribute('aria-label', o.title); box.appendChild(inp); setTimeout(function () { inp.focus(); inp.select(); }, 60); }
        box.appendChild(actions); back.appendChild(box); document.body.appendChild(back);
        document.addEventListener('keydown', onKey, true);
        requestAnimationFrame(function () { back.classList.add('cu-in'); });
        if (!o.field) setTimeout(function () { (o.danger && cancel ? cancel : ok).focus(); }, 30);
    }

    var region = null;
    function toast(message, opts) {
        opts = opts || {};
        if (!region) { region = el('div', 'cu-toasts'); region.setAttribute('role', 'status'); region.setAttribute('aria-live', 'polite'); document.body.appendChild(region); }
        var t = el('div', 'cu-toast cu-t-' + (opts.tone || 'info'));
        t.innerHTML = svg(opts.tone === 'err' ? 'err' : (opts.tone === 'ok' ? 'ok' : 'info'));
        var s = el('span'); s.textContent = tx(message); t.appendChild(s);
        function bye() { t.classList.remove('cu-in'); setTimeout(function () { t.remove(); }, 200); }
        t.addEventListener('click', bye);
        region.appendChild(t);
        requestAnimationFrame(function () { t.classList.add('cu-in'); });
        setTimeout(bye, opts.duration || (opts.tone === 'err' ? 6000 : 3800));
    }

    var ColoUI = {
        friendly: friendly,
        alert: function (message, o) {
            o = o || {};
            return show({ message: String(message == null ? '' : message), title: o.title || (o.tone === 'ok' ? 'C’est fait' : (o.tone === 'err' ? 'Une erreur est survenue' : 'Information')),
                tone: o.tone || 'info', icon: o.tone === 'err' ? 'err' : (o.tone === 'ok' ? 'ok' : 'info'), confirmLabel: o.confirmLabel || 'OK' }).then(function () {});
        },
        confirm: function (message, o) {
            o = o || {};
            return show({ message: String(message == null ? '' : message), title: o.title || 'Confirmer', tone: o.danger ? 'err' : 'info', icon: 'ask', danger: !!o.danger,
                confirmLabel: o.confirmLabel || 'Confirmer', cancelLabel: o.cancelLabel || 'Annuler' });
        },
        error: function (err, fallback) { return ColoUI.alert(friendly(err, fallback), { tone: 'err' }); },
        toast: toast,
        copy: function (text, o) {
            o = o || {};
            var ok = function () { toast(o.done || 'Lien copié.', { tone: 'ok' }); };
            if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text).then(ok, function () { return show({ message: 'Copiez le texte ci-dessous.', title: o.title || 'Copier', field: text, icon: 'info' }).then(function () {}); });
            return show({ message: 'Copiez le texte ci-dessous.', title: o.title || 'Copier', field: text, icon: 'info' }).then(function () {});
        }
    };
    window.ColoUI = ColoUI;

    // Filet de sécurité : tout alert() restant (y compris d'un script tiers) passe par la fenêtre du site
    window.alert = function (m) { ColoUI.alert(m); };

    // Confirmation déclarative : <form data-confirm="…"> ou bouton d'envoi avec data-confirm
    document.addEventListener('submit', function (e) {
        var f = e.target, sub = e.submitter, msg = (sub && sub.getAttribute && sub.getAttribute('data-confirm')) || (f.getAttribute && f.getAttribute('data-confirm'));
        if (!msg || f.__cuOk) { f.__cuOk = false; return; }
        e.preventDefault();
        var danger = (sub && sub.hasAttribute('data-danger')) || f.hasAttribute('data-danger');
        ColoUI.confirm(msg, { danger: danger, confirmLabel: (sub && sub.getAttribute('data-confirm-label')) || f.getAttribute('data-confirm-label') || 'Confirmer' }).then(function (ok) {
            if (!ok) return;
            f.__cuOk = true;
            if (f.requestSubmit) { try { f.requestSubmit(sub || undefined); return; } catch (x) {} }
            f.submit();
        });
    }, true);
})();

/* Tableaux : sur écran étroit, chaque tableau défile horizontalement dans son cadre au lieu de déborder de la page */
(function () {
    function wrap(root) {
        (root.querySelectorAll ? root.querySelectorAll('table') : []).forEach(function (t) {
            if (t.__w || t.closest('.ds-tablewrap,[class*="overflow-x"],[data-nowrap-table]')) { t.__w = 1; return; }
            var p = t.parentNode, w = document.createElement('div');
            if (!p) return;
            t.__w = 1; w.className = 'ds-tablewrap';
            p.insertBefore(w, t); w.appendChild(t);
        });
    }
    function go() { wrap(document); if (window.MutationObserver) new MutationObserver(function (m) { m.forEach(function (r) { r.addedNodes.forEach(function (n) { if (n.nodeType === 1) wrap(n.tagName === 'TABLE' ? { querySelectorAll: function () { return [n]; } } : n); }); }); }).observe(document.body, { childList: true, subtree: true }); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
})();

(function () {
    function label(t) {
        var h = [].map.call(t.querySelectorAll(':scope > thead th'), function (th) { return th.textContent.replace(/\s+/g, ' ').trim(); });
        [].forEach.call(t.querySelectorAll(':scope > tbody > tr'), function (tr) {
            var i = 0;
            [].forEach.call(tr.children, function (td) { if (!td.hasAttribute('data-label')) td.setAttribute('data-label', h[i] || ''); i += td.colSpan || 1; });
        });
    }
    var queued = false;
    function scan() { queued = false; [].forEach.call(document.querySelectorAll('table.ds-cards'), label); }
    function go() {
        scan();
        if (window.MutationObserver) new MutationObserver(function () { if (!queued) { queued = true; requestAnimationFrame(scan); } }).observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
})();

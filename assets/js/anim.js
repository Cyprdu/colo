/* Outils communs des pages animateurs (catalogue, profil, espace animation, dashboard directeur). */
window.ANIM = (function () {
    var MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    var ICONS = {
        pin: '<path d="M12 21s-7-6.2-7-11a7 7 0 1 1 14 0c0 4.8-7 11-7 11Z"/><circle cx="12" cy="10" r="2.5"/>',
        cal: '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.2-4 4.2-6 8-6s6.8 2 8 6"/>',
        file: '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/>',
        check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        x: '<path d="M6 6l12 12M18 6L6 18"/>',
        clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        link: '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/>',
        bell: '<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 21h4"/>'
    };
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function icon(n, cls) { return '<svg class="' + (cls || 'w-4 h-4') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[n] || '') + '</svg>'; }
    function day(s) { var d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? '' : d.getDate() + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear(); }
    function range(a, b) {
        var x = new Date(a + 'T00:00:00'), y = new Date(b + 'T00:00:00');
        if (isNaN(x)) return '';
        if (isNaN(y)) return x.getDate() + ' ' + MONTHS[x.getMonth()];
        return x.getDate() + ' ' + MONTHS[x.getMonth()] + ' – ' + y.getDate() + ' ' + MONTHS[y.getMonth()] + ' ' + y.getFullYear();
    }
    function money(n) { n = Number(n); return (Number.isInteger(n) ? String(n) : n.toFixed(2).replace('.', ',')) + ' €'; }
    var BAFA = { bafa: 'BAFA obtenu', en_cours: 'BAFA en cours', equivalent: 'Diplôme équivalent', aucun: 'Sans BAFA' };
    function statut(s) {
        var m = { 'En attente': ['#f8f5ea', '#5b4a1c', 'En attente'], 'Accepté': ['#e3efe6', '#1f5a33', 'Accepté'], 'Refusé': ['#f3e6e6', '#7a2b2b', 'Refusé'], "Liste d'attente": ['#e5eaef', '#2d5170', "Liste d'attente"] }[s] || ['#f1f3f5', '#3b4a54', s];
        return '<span class="ds-badge" style="background:' + m[0] + ';color:' + m[1] + '">' + esc(m[2]) + '</span>';
    }
    async function api(url, opts) {
        opts = opts || {};
        if (opts.json) { opts.method = 'POST'; opts.headers = { 'Content-Type': 'application/json' }; opts.body = JSON.stringify(opts.json); }
        var r = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts));
        var d = null; try { d = await r.json(); } catch (e) { }
        if (!r.ok) { var err = new Error((d && (d.error || d.message)) || 'Une erreur est survenue.'); err.data = d; throw err; }
        return d;
    }
    function toast(msg, bad) {
        var t = document.createElement('div');
        t.setAttribute('role', 'status');
        t.className = 'fixed left-1/2 bottom-8 z-[100] -translate-x-1/2 rounded-full px-6 py-3 text-[14px] font-semibold text-white shadow-2xl';
        t.style.background = bad ? '#7a2b2b' : '#26343b';
        t.textContent = msg; document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 2800);
    }
    return { esc: esc, icon: icon, day: day, range: range, money: money, BAFA: BAFA, statut: statut, api: api, toast: toast };
})();

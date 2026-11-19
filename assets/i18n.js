(function () {
    var T = window.MC_T || {}, P = [];
    var FR = 'janvier,février,mars,avril,mai,juin,juillet,août,septembre,octobre,novembre,décembre,janv.,févr.,avr.,juil.,sept.,oct.,nov.,déc.'.split(',');
    var EN = String(T['months.re'] || '').split(',');
    var MOIS = new RegExp('(^|[^\\d])(\\d{1,2})(?:er)? (' + FR.map(function (m) { return m.replace('.', '\\.'); }).join('|') + ')(?=[\\s,.)–-]|$)', 'g');
    var EUR = /(^|[^\d,.])(\d{1,3}(?:\s\d{3})*)(?:,(\d{2}))?\s?€/g;
    Object.keys(T).forEach(function (k) {
        if (k.indexOf('{') < 0) return;
        var noms = k.match(/\{\w+\}/g) || [];
        var re = k.replace(/[.*+?^$()|[\]\\]/g, '\\$&').replace(/\{(\w+)\}/g, function (m, n) {
            return n[0] === 'n' ? '(\\d[\\d\\s.,–-]*)' : n[0] === 'd' ? '(\\d\\S*(?: \\S+){0,2})' : '(.{1,40}?)';
        });
        P.push([new RegExp('^' + re + '$'), T[k], noms]);
    });
    function norm(s) { return s.replace(/’/g, "'").replace(/\s+/g, ' ').trim(); }
    function fmt(s) {
        return s.replace(EUR, function (m, a, n, c) { return a + '€' + n.replace(/\s/g, ',') + (c ? '.' + c : ''); })
                .replace(MOIS, function (m, a, d, mo) { return a + d + ' ' + (EN[FR.indexOf(mo)] || mo); });
    }
    function tr(s) {
        var k = norm(s);
        if (!k || !/[A-Za-zÀ-ÿ€]/.test(k)) return null;
        if (T[k]) return T[k];
        for (var i = 0; i < P.length; i++) {
            var m = k.match(P[i][0]);
            if (m) { var r = P[i][1]; P[i][2].forEach(function (n, j) { r = r.split(n).join(tr(m[j + 1]) || fmt(m[j + 1])); }); return r; }
        }
        if (k.length <= 90) { var f = fmt(k); if (f !== k) return f; }
        return null;
    }
    window.mct = function (s, v) { var r = typeof s === 'string' ? (tr(s) || s) : s; if (v) for (var x in v) r = String(r).split('{' + x + '}').join(v[x]); return r; };
    var SKIP = '[translate="no"], .notranslate, script, style, textarea, [contenteditable="true"]';
    function text(n) {
        var p = n.parentElement;
        if (!p || p.closest(SKIP)) return;
        var v = n.nodeValue, r = tr(v);
        if (r !== null && r !== norm(v)) n.nodeValue = v.match(/^\s*/)[0] + r + v.match(/\s*$/)[0];
    }
    function attrs(e) {
        ['placeholder', 'title', 'aria-label', 'alt'].forEach(function (a) {
            var v = e.getAttribute(a), r = v && tr(v);
            if (r && r !== norm(v)) e.setAttribute(a, r);
        });
    }
    function walk(root) {
        if (root.nodeType === 3) return text(root);
        if (root.nodeType !== 1 || root.closest(SKIP)) return;
        var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT), n;
        while ((n = w.nextNode())) text(n);
        attrs(root);
        root.querySelectorAll('[placeholder], [title], [aria-label], [alt]').forEach(attrs);
    }
    function go() {
        walk(document.body);
        new MutationObserver(function (list) { list.forEach(function (m) { m.addedNodes.forEach(walk); }); }).observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
})();

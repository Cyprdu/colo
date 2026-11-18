/* moncamp.fr : champ de mots-clés à pastilles (alias, « hashtags »).
   Les règles sont celles du serveur (api/motscles.php) : le navigateur les rappelle, le serveur décide.
   Usage : MotsCles.mount(element, { initial: ['PAJE'], max: 12, suggest: function () { return ['EPJ']; }, onChange: function (liste) {}, placeholder: '…' })
   Retourne { get(), set(liste), refresh() } */
(function () {
    'use strict';
    var MAXLEN = 30, MAXMOTS = 3;
    var STOP = ['de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'un', 'une', 'et', 'ou', 'en', 'au', 'aux', 'a', 'sur', 'pour', 'dans', 'avec', 'par'];
    var GENERIC = ['colo', 'colos', 'colonie', 'colonies', 'camp', 'camps', 'sejour', 'sejours', 'vacances', 'stage', 'stages', 'enfant', 'enfants', 'ado', 'ados'];

    function norm(s) {
        s = String(s || '').toLowerCase();
        try { s = s.normalize('NFD').replace(/[̀-ͯ]/g, ''); } catch (e) {}
        return s.replace(/œ/g, 'oe').replace(/[^a-z0-9]+/g, ' ').trim();
    }
    function clean(label) { return String(label || '').replace(/^[#\s]+/, '').replace(/\s+/g, ' ').trim(); }

    /** Retourne '' si le mot est accepté, sinon la raison du refus. */
    function check(label, list, max) {
        var n = norm(label);
        if (!n) return '';
        if (clean(label).length > MAXLEN) return 'Trop long (' + MAXLEN + ' caractères au maximum).';
        if (n.length < 2) return 'Trop court.';
        if (/@|https?|www\.|\.(com|fr|org|net)\b/i.test(label) || !/^[\p{L}\p{N}][\p{L}\p{N} '’\-.]*$/u.test(clean(label))) return 'Seulement des lettres et des chiffres : pas d\'adresse web ni d\'e-mail.';
        if (/\d{5,}/.test(n.replace(/ /g, ''))) return 'Pas de numéro de téléphone.';
        var mots = n.split(' ').filter(function (w) { return w.length > 1 && STOP.indexOf(w) === -1; });
        if (mots.length > MAXMOTS) return MAXMOTS + ' mots au maximum par mot-clé.';
        if (mots.every(function (w) { return GENERIC.indexOf(w) !== -1; })) return 'Trop général : tout le monde le tape, il n\'apporte rien (colo, séjour, camp…).';
        if (list.some(function (x) { return norm(x) === n; })) return 'Déjà dans la liste.';
        if (list.length >= max) return 'Limite atteinte : ' + max + ' mots-clés au maximum.';
        return '';
    }

    function mount(root, opt) {
        opt = opt || {};
        var max = opt.max || 12, list = [], uid = 'mc' + Math.random().toString(36).slice(2, 8);
        (opt.initial || []).forEach(function (l) { l = clean(l); if (l && !check(l, list, max)) list.push(l); });
        root.innerHTML = '<div class="mc-box"></div><p class="mc-msg" role="status" aria-live="polite"></p><div class="mc-sugg" hidden></div>';
        var box = root.querySelector('.mc-box'), msg = root.querySelector('.mc-msg'), sg = root.querySelector('.mc-sugg');
        var input = document.createElement('input');
        input.type = 'text'; input.className = 'mc-input'; input.maxLength = 60; input.autocomplete = 'off'; input.id = opt.inputId || uid;
        input.setAttribute('aria-label', opt.label || 'Ajouter un mot-clé'); input.placeholder = opt.placeholder || 'Tapez un mot puis Entrée';

        function emit() { if (opt.onChange) opt.onChange(list.slice()); }
        function render() {
            box.textContent = '';
            list.forEach(function (l, i) {
                var p = document.createElement('span'); p.className = 'mc-pill';
                p.innerHTML = '<i>#</i>'; p.appendChild(document.createTextNode(l));
                var b = document.createElement('button'); b.type = 'button'; b.setAttribute('aria-label', 'Retirer ' + l); b.innerHTML = '&times;';
                b.addEventListener('click', function () { list.splice(i, 1); render(); emit(); input.focus(); });
                p.appendChild(b); box.appendChild(p);
            });
            box.appendChild(input);
            var c = document.createElement('span'); c.className = 'mc-count'; c.textContent = list.length + ' / ' + max; box.appendChild(c);
            suggestions();
        }
        function add(raw) {
            var l = clean(raw); if (!l) return true;
            var err = check(l, list, max);
            if (err) { msg.textContent = '« ' + l + ' » : ' + err; return false; }
            list.push(l); msg.textContent = ''; render(); emit(); return true;
        }
        function suggestions() {
            var s = (opt.suggest ? opt.suggest() : []).filter(function (x) { return x && !check(x, list, max); }).slice(0, 8);
            sg.hidden = !s.length; sg.textContent = '';
            if (!s.length) return;
            sg.appendChild(document.createTextNode('Suggestions : '));
            s.forEach(function (x) { var b = document.createElement('button'); b.type = 'button'; b.textContent = '+ ' + x; b.addEventListener('click', function () { add(x); input.focus(); }); sg.appendChild(b); });
        }
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',' || e.key === ';') { e.preventDefault(); if (add(input.value)) input.value = ''; }
            else if (e.key === 'Backspace' && !input.value && list.length) { list.pop(); render(); emit(); input.focus(); }
        });
        input.addEventListener('input', function () { msg.textContent = ''; });
        input.addEventListener('blur', function () { if (input.value.trim() && add(input.value)) input.value = ''; });
        input.addEventListener('paste', function (e) {
            var t = (e.clipboardData || window.clipboardData).getData('text');
            if (/[,;\n]/.test(t)) { e.preventDefault(); t.split(/[,;\n]+/).forEach(function (x) { if (x.trim()) add(x); }); }
        });
        box.addEventListener('click', function (e) { if (e.target === box) input.focus(); });
        render();
        return { get: function () { return list.slice(); }, set: function (l) { list = []; (l || []).forEach(function (x) { x = clean(x); if (x && !check(x, list, max)) list.push(x); }); render(); emit(); }, refresh: suggestions };
    }

    window.MotsCles = { mount: mount, norm: norm, check: check };
})();

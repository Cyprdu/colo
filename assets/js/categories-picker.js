/* ColoMap : choix guidé des catégories d'un séjour, puis des précisions de chaque catégorie.
   Usage : CategoriesPicker.mount(element, { catalogue: [...api/get_categories.php .categories], value: { order: [], values: {} }, max: 4, onChange: function (value) {} })
   value = { order: ['spirituel', 'montagne'], values: { montagne: { niveau: ['debutant'] } } } : la première catégorie est la principale.
   Retourne { value(), set(value) } */
(function () {
    'use strict';

    function mount(root, opt) {
        var cat = opt.catalogue || [], max = opt.max || 4;
        var by = {}; cat.forEach(function (c) { by[c.slug] = c; });
        var v = { order: [], values: {} };
        function load(x) {
            v = { order: [], values: {} };
            ((x && x.order) || []).forEach(function (s) { if (by[s] && v.order.indexOf(s) === -1 && v.order.length < max) v.order.push(s); });
            v.order.forEach(function (s) { v.values[s] = {}; by[s].facettes.forEach(function (f) { var a = (((x.values || {})[s] || {})[f.cle] || []).filter(function (k) { return f.valeurs.some(function (o) { return o.cle === k; }); }); if (a.length) v.values[s][f.cle] = f.type === 'single' ? a.slice(0, 1) : a; }); });
        }
        load(opt.value);

        root.innerHTML = '<div class="cp-step"><b>1. Que propose votre séjour ?</b><span>Choisissez de 1 à ' + max + ' catégories. La première est la principale.</span></div><div class="cp-grid" role="group" aria-label="Catégories"></div><div class="cp-cards" aria-live="polite"></div>';
        var grid = root.querySelector('.cp-grid'), cards = root.querySelector('.cp-cards');

        function emit() { if (opt.onChange) opt.onChange({ order: v.order.slice(), values: v.values }); }
        function toggleCat(s) {
            var i = v.order.indexOf(s);
            if (i !== -1) { v.order.splice(i, 1); delete v.values[s]; }
            else if (v.order.length < max) { v.order.push(s); v.values[s] = {}; }
            draw(); emit();
        }
        function pick(s, f, k) {
            var cur = (v.values[s][f.cle] || []).slice(), i = cur.indexOf(k);
            if (f.type === 'single') cur = i === -1 ? [k] : [];
            else if (i === -1) cur.push(k); else cur.splice(i, 1);
            if (f.type === 'scale') cur.sort(function (a, b) { return idx(f, a) - idx(f, b); });
            if (cur.length) v.values[s][f.cle] = cur; else delete v.values[s][f.cle];
            drawCards(); emit();
        }
        function idx(f, k) { for (var i = 0; i < f.valeurs.length; i++) if (f.valeurs[i].cle === k) return i; return -1; }

        function drawGrid() {
            grid.textContent = '';
            cat.forEach(function (c) {
                var on = v.order.indexOf(c.slug) !== -1;
                var b = document.createElement('button'); b.type = 'button'; b.className = 'cp-chip'; b.textContent = c.nom;
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
                if (!on && v.order.length >= max) { b.disabled = true; b.title = max + ' catégories au maximum : retirez-en une pour en choisir une autre.'; }
                b.addEventListener('click', function () { toggleCat(c.slug); });
                grid.appendChild(b);
            });
        }
        function drawCards() {
            cards.textContent = '';
            if (!v.order.length) { cards.innerHTML = '<p class="cp-empty">Choisissez au moins une catégorie : elle détermine les filtres dans lesquels les familles retrouveront votre séjour.</p>'; return; }
            var h = document.createElement('p'); h.className = 'cp-step'; h.innerHTML = '<b>2. Précisez chaque catégorie</b><span>Facultatif, mais les familles filtrent dessus : plus vous précisez, mieux votre séjour est trouvé.</span>';
            cards.appendChild(h);
            v.order.forEach(function (s, pos) {
                var c = by[s], done = Object.keys(v.values[s] || {}).length;
                var card = document.createElement('div'); card.className = 'cp-card';
                var head = document.createElement('div'); head.className = 'cp-head';
                head.innerHTML = '<span class="cp-title"></span><span class="cp-badge' + (pos === 0 ? ' main' : '') + '">' + (pos === 0 ? 'Principale' : 'Secondaire') + '</span>';
                head.querySelector('.cp-title').textContent = c.nom;
                if (pos > 0) { var mk = document.createElement('button'); mk.type = 'button'; mk.className = 'cp-link'; mk.textContent = 'Définir comme principale'; mk.addEventListener('click', function () { v.order.splice(pos, 1); v.order.unshift(s); draw(); emit(); }); head.appendChild(mk); }
                var pr = document.createElement('span'); pr.className = 'cp-prog'; pr.textContent = c.facettes.length ? done + ' / ' + c.facettes.length + ' précisions' : ''; head.appendChild(pr);
                var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'cp-link'; rm.textContent = 'Retirer'; rm.addEventListener('click', function () { toggleCat(s); }); head.appendChild(rm);
                card.appendChild(head);
                var body = document.createElement('div'); body.className = 'cp-body';
                c.facettes.forEach(function (f) {
                    var q = document.createElement('div');
                    var hint = f.type === 'multi' ? 'plusieurs choix possibles' : (f.type === 'scale' ? 'plusieurs niveaux possibles si le séjour accueille plusieurs niveaux' : 'un seul choix');
                    q.innerHTML = '<div class="cp-q"></div>' + (f.aide ? '<div class="cp-aide"></div>' : '');
                    q.querySelector('.cp-q').innerHTML = '';
                    q.querySelector('.cp-q').appendChild(document.createTextNode(f.nom));
                    var sm = document.createElement('small'); sm.textContent = hint; q.querySelector('.cp-q').appendChild(sm);
                    if (f.aide) q.querySelector('.cp-aide').textContent = f.aide;
                    var wrap = document.createElement('div'); wrap.className = f.type === 'scale' ? 'cp-scale' : 'cp-opts'; wrap.setAttribute('role', 'group'); wrap.setAttribute('aria-label', f.nom);
                    var chosen = (v.values[s] || {})[f.cle] || [];
                    f.valeurs.forEach(function (o, i) {
                        var b = document.createElement('button'); b.type = 'button'; b.className = 'cp-opt'; b.textContent = (f.type === 'scale' ? (i + 1) + '. ' : '') + o.libelle;
                        b.setAttribute('aria-pressed', chosen.indexOf(o.cle) !== -1 ? 'true' : 'false');
                        b.addEventListener('click', function () { pick(s, f, o.cle); });
                        wrap.appendChild(b);
                    });
                    q.appendChild(wrap); body.appendChild(q);
                });
                if (!c.facettes.length) { var none = document.createElement('p'); none.className = 'cp-aide'; none.textContent = 'Pas de précision pour cette catégorie.'; body.appendChild(none); }
                card.appendChild(body); cards.appendChild(card);
            });
        }
        function draw() { drawGrid(); drawCards(); }
        draw();
        return { value: function () { return { order: v.order.slice(), values: v.values }; }, set: function (x) { load(x); draw(); emit(); } };
    }

    window.CategoriesPicker = { mount: mount };
})();

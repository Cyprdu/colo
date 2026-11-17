<?php
// messagerie.php : discussions avec les organisateurs. Une seule page : on change de conversation sans recharger.
require_once 'api/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login.php?next=messagerie');
    exit;
}
$activeConvId = (int)($_GET['conv_id'] ?? 0);
$myId = (int)$_SESSION['user']['id'];
$isDirecteur = !empty($_SESSION['user']['is_directeur']);
$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Messagerie - ColoMap</title>
<style>
    .msg-scroll::-webkit-scrollbar { width: 8px; }
    .msg-scroll::-webkit-scrollbar-thumb { background: #d3dbe0; border-radius: 8px; }
    .msg-scroll::-webkit-scrollbar-thumb:hover { background: #a9b8c6; }
    .conv-item { display:flex; gap:12px; padding:12px 14px; border-radius:16px; border:1px solid transparent; transition:background .15s ease, border-color .15s ease; cursor:pointer; }
    .conv-item:hover { background:#f1f5f9; }
    .conv-item.is-active { background:#eef2f4; border-color:#d3dbe0; }
    .bubble { padding:10px 14px; font-size:15px; line-height:1.5; white-space:pre-wrap; overflow-wrap:anywhere; }
    .bubble-me { background:#2f3d45; color:#fff; border-radius:18px 18px 4px 18px; }
    .bubble-them { background:#fff; color:#26343b; border:1px solid #e5e7eb; border-radius:18px 18px 18px 4px; }
    .msg-row .msg-actions { opacity:0; transition:opacity .15s ease; }
    .msg-row:hover .msg-actions, .msg-row:focus-within .msg-actions { opacity:1; }
    @media (hover:none) { .msg-row .msg-actions { opacity:1; } }
    .msg-pending { opacity:.6; }
    @keyframes msgin { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }
    .msg-new { animation: msgin .18s ease-out; }
    @media (prefers-reduced-motion: reduce) { .msg-new { animation:none; } }
    .chip { font-size:13px; font-weight:600; padding:8px 14px; border-radius:9999px; border:1.5px solid #d3dbe0; background:#fff; color:#3b4a54; transition:background .15s ease; }
    .chip:hover { background:#f1f5f9; }
</style>

<div class="flex bg-white overflow-hidden" style="height:calc(100dvh - 68px)">

    <!-- Liste des conversations -->
    <aside id="sidebar" class="w-full md:w-[360px] flex-none border-r border-[#e5e7eb] flex flex-col bg-white <?= $activeConvId ? 'hidden md:flex' : 'flex' ?>" aria-label="Conversations">
        <div class="px-5 pt-5 pb-3">
            <h1 class="text-[24px] font-extrabold text-[#26343b] leading-tight">Messagerie</h1>
            <div class="relative mt-3">
                <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-[#9aa3ab]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3" stroke-linecap="round"/></svg>
                <input id="conv-search" type="search" placeholder="Rechercher un séjour ou une personne" class="w-full rounded-full bg-[#f1f3f5] pl-10 pr-4 py-2.5 text-[14px] focus:outline-none focus:ring-2 focus:ring-[#2f3d45] focus:bg-white" aria-label="Rechercher une conversation">
            </div>
        </div>
        <div id="conv-list" class="flex-1 overflow-y-auto msg-scroll px-3 pb-4 space-y-1" role="list">
            <div class="p-10 text-center"><div class="animate-spin rounded-full h-7 w-7 border-b-2 border-[#26343b] mx-auto"></div></div>
        </div>
    </aside>

    <!-- Conversation ouverte -->
    <section id="chat-area" class="flex-1 min-w-0 flex-col bg-[#f6f7f8] <?= $activeConvId ? 'flex' : 'hidden md:flex' ?>" aria-label="Conversation">
        <div id="chat-empty" class="flex-1 flex flex-col items-center justify-center text-center p-8 <?= $activeConvId ? 'hidden' : '' ?>">
            <div class="w-20 h-20 rounded-full bg-white border border-[#e5e7eb] flex items-center justify-center mb-5">
                <svg class="w-9 h-9 text-[#3d6a8e]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
            </div>
            <h2 id="empty-title" class="text-[20px] font-extrabold text-[#26343b]">Choisissez une conversation</h2>
            <p id="empty-text" class="mt-2 text-[#6b7780] max-w-sm text-[15px]">Vos échanges avec les organisateurs apparaissent ici.</p>
            <a id="empty-cta" href="recherche" class="hidden mt-5 inline-flex px-5 py-3 rounded-xl bg-[#2f3d45] text-white font-bold text-[14px] hover:bg-[#1f2a30] transition">Trouver un séjour</a>
        </div>

        <div id="chat-pane" class="relative flex-1 min-h-0 flex-col <?= $activeConvId ? 'flex' : 'hidden' ?>">
            <header class="bg-white border-b border-[#e5e7eb] px-4 md:px-6 py-3 flex items-center gap-3">
                <button type="button" id="btn-back" class="md:hidden -ml-1 w-10 h-10 rounded-full hover:bg-[#f1f3f5] flex items-center justify-center" aria-label="Retour aux conversations">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <div id="h-avatar" class="w-11 h-11 flex-none rounded-full bg-[#3a4852] text-white font-bold text-[17px] flex items-center justify-center">?</div>
                <div class="min-w-0 flex-1">
                    <h2 id="h-contact" class="font-extrabold text-[#26343b] text-[16px] leading-tight truncate">…</h2>
                    <p id="h-camp" class="text-[13px] text-[#6b7780] truncate">…</p>
                </div>
                <a id="h-link" href="#" class="hidden flex-none text-[13px] font-bold px-3.5 py-2 rounded-lg border border-[#e5e7eb] text-[#26343b] hover:bg-[#f1f3f5] transition">Voir le séjour</a>
            </header>

            <div id="messages" class="flex-1 min-h-0 overflow-y-auto msg-scroll px-4 md:px-8 py-5" aria-live="polite"></div>

            <button type="button" id="btn-newmsg" class="hidden absolute left-1/2 -translate-x-1/2 bottom-28 px-4 py-2 rounded-full bg-[#2f3d45] text-white text-[13px] font-bold shadow-lg">↓ Nouveaux messages</button>

            <div id="suggest" class="hidden px-4 md:px-8 pb-2 flex flex-wrap gap-2"></div>

            <form id="msg-form" class="bg-white border-t border-[#e5e7eb] px-3 md:px-6 py-3 flex items-end gap-2">
                <textarea id="msg-input" rows="1" maxlength="4000" placeholder="Écrivez votre message…" aria-label="Votre message"
                          class="flex-1 resize-none rounded-2xl bg-[#f1f3f5] px-4 py-3 text-[15px] leading-snug max-h-40 focus:outline-none focus:ring-2 focus:ring-[#2f3d45] focus:bg-white"></textarea>
                <button type="submit" id="btn-send" class="w-12 h-12 flex-none rounded-full bg-[#2f3d45] text-white flex items-center justify-center hover:bg-[#1f2a30] active:scale-95 transition disabled:opacity-40" aria-label="Envoyer">
                    <svg class="w-5 h-5 rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                </button>
            </form>
            <p class="hidden md:block text-[11.5px] text-[#9aa3ab] bg-white px-6 pb-2 -mt-1">Entrée pour envoyer · Maj + Entrée pour aller à la ligne</p>
        </div>
    </section>
</div>

<script>
(function () {
    'use strict';
    var MY_ID = <?= $myId ?>;
    var IS_DIR = <?= $isDirecteur ? 'true' : 'false' ?>;
    var activeId = <?= $activeConvId ?>;
    var convs = [];
    var shown = {};          // id message -> { el, content }
    var lastDay = '';
    var $ = function (id) { return document.getElementById(id); };

    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
    function parseDate(s) { return new Date(String(s).replace(' ', 'T')); }
    function hhmm(d) { return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }); }
    function dayKey(d) { return d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate(); }
    function dayLabel(d) {
        var t = new Date(), y = new Date(); y.setDate(t.getDate() - 1);
        if (dayKey(d) === dayKey(t)) return "Aujourd'hui";
        if (dayKey(d) === dayKey(y)) return 'Hier';
        return d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
    }
    function listTime(s) {
        if (!s) return '';
        var d = parseDate(s), t = new Date();
        if (dayKey(d) === dayKey(t)) return hhmm(d);
        var y = new Date(); y.setDate(t.getDate() - 1);
        if (dayKey(d) === dayKey(y)) return 'Hier';
        return d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
    }
    function api(url, body) {
        return fetch(url, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : undefined);
    }
    function initial(c) { return ((c.contact_prenom || c.camp_nom || '?').trim().charAt(0) || '?').toUpperCase(); }

    // ---------- Liste ----------
    function renderList() {
        var q = $('conv-search').value.trim().toLowerCase();
        var list = $('conv-list');
        list.textContent = '';
        var items = convs.filter(function (c) {
            return !q || ((c.camp_nom || '') + ' ' + c.contact_prenom + ' ' + c.contact_nom).toLowerCase().indexOf(q) !== -1;
        });
        if (!items.length) {
            list.appendChild(el('p', 'p-8 text-center text-[14px] text-[#9aa3ab]', convs.length ? 'Aucun résultat.' : 'Aucune discussion.'));
            return;
        }
        items.forEach(function (c) {
            var a = el('a', 'conv-item' + (c.conversation_id == activeId ? ' is-active' : ''));
            a.href = 'messagerie?conv_id=' + c.conversation_id;
            a.setAttribute('role', 'listitem');
            a.addEventListener('click', function (e) { if (e.ctrlKey || e.metaKey || e.shiftKey) return; e.preventDefault(); openConv(c.conversation_id, true); });
            var unread = parseInt(c.unread_count, 10) || 0;
            a.appendChild(el('div', 'w-11 h-11 flex-none rounded-full bg-[#3a4852] text-white font-bold flex items-center justify-center', initial(c)));
            var body = el('div', 'min-w-0 flex-1');
            var top = el('div', 'flex items-baseline justify-between gap-2');
            top.appendChild(el('span', 'text-[14.5px] truncate ' + (unread ? 'font-extrabold text-[#26343b]' : 'font-bold text-[#26343b]'), c.contact_prenom + ' ' + c.contact_nom));
            top.appendChild(el('span', 'text-[11.5px] flex-none ' + (unread ? 'text-[#3d6a8e] font-bold' : 'text-[#9aa3ab]'), listTime(c.last_message_at)));
            body.appendChild(top);
            body.appendChild(el('p', 'text-[12px] text-[#3d6a8e] font-semibold truncate', c.camp_nom || 'Discussion'));
            var bottom = el('div', 'flex items-center gap-2 mt-0.5');
            var prev = (c.last_sender_id == MY_ID ? 'Vous : ' : '') + (c.last_message || '');
            bottom.appendChild(el('p', 'text-[13px] truncate flex-1 ' + (unread ? 'text-[#26343b] font-semibold' : 'text-[#6b7780]'), prev.replace(/\s+/g, ' ')));
            if (unread) bottom.appendChild(el('span', 'flex-none min-w-[20px] h-5 px-1.5 rounded-full bg-[#2f3d45] text-white text-[11px] font-bold flex items-center justify-center', unread > 99 ? '99+' : String(unread)));
            body.appendChild(bottom);
            a.appendChild(body);
            list.appendChild(a);
        });
    }

    function loadConvs() {
        return api('api/get_conversations.php').then(function (r) { return r.json(); }).then(function (d) {
            if (!Array.isArray(d)) return;
            convs = d;
            renderList();
            updateHeader();
            if (!convs.length) {
                $('empty-title').textContent = "Pas encore de discussion";
                $('empty-text').textContent = IS_DIR ? "Les messages des parents apparaîtront ici." : "Pour poser une question à un organisme, ouvrez la page d'un séjour et cliquez sur « Contacter l'organisateur ».";
                $('empty-cta').classList.toggle('hidden', IS_DIR);
            }
        }).catch(function () {
            $('conv-list').textContent = '';
            $('conv-list').appendChild(el('p', 'p-8 text-center text-[14px] text-red-600', 'Impossible de charger vos discussions.'));
        });
    }

    function current() { return convs.filter(function (c) { return c.conversation_id == activeId; })[0]; }
    function updateHeader() {
        var c = current(); if (!c) return;
        $('h-avatar').textContent = initial(c);
        $('h-contact').textContent = c.contact_prenom + ' ' + c.contact_nom;
        $('h-camp').textContent = c.camp_nom ? 'Séjour : ' + c.camp_nom : 'Discussion';
        var link = $('h-link');
        if (c.camp_token) { link.href = 'camp_details?t=' + encodeURIComponent(c.camp_token); link.classList.remove('hidden'); } else link.classList.add('hidden');
    }

    // ---------- Ouverture ----------
    function openConv(id, push) {
        activeId = id;
        shown = {}; lastDay = '';
        $('messages').textContent = '';
        $('suggest').classList.add('hidden');
        if (push) history.pushState({ c: id }, '', 'messagerie?conv_id=' + id);
        $('sidebar').classList.add('hidden', 'md:flex'); $('sidebar').classList.remove('flex');
        $('chat-area').classList.remove('hidden'); $('chat-area').classList.add('flex');
        $('chat-empty').classList.add('hidden');
        $('chat-pane').classList.remove('hidden'); $('chat-pane').classList.add('flex');
        renderList(); updateHeader();
        loadMessages(true);
        $('msg-input').focus();
    }
    function closeConv(push) {
        activeId = 0;
        if (push) history.pushState({ c: 0 }, '', 'messagerie');
        $('sidebar').classList.remove('hidden'); $('sidebar').classList.add('flex');
        $('chat-area').classList.add('hidden', 'md:flex'); $('chat-area').classList.remove('flex');
        $('chat-empty').classList.remove('hidden');
        $('chat-pane').classList.add('hidden'); $('chat-pane').classList.remove('flex');
        renderList();
    }
    window.addEventListener('popstate', function () {
        var id = parseInt(new URLSearchParams(location.search).get('conv_id'), 10) || 0;
        id ? openConv(id, false) : closeConv(false);
    });
    $('btn-back').addEventListener('click', function () { closeConv(true); });

    // ---------- Messages ----------
    function nearBottom() { var m = $('messages'); return m.scrollHeight - m.scrollTop - m.clientHeight < 120; }
    function scrollDown() { var m = $('messages'); m.scrollTop = m.scrollHeight; $('btn-newmsg').classList.add('hidden'); }
    $('btn-newmsg').addEventListener('click', scrollDown);
    $('messages').addEventListener('scroll', function () { if (nearBottom()) $('btn-newmsg').classList.add('hidden'); });

    function addDaySep(d) {
        var k = dayKey(d);
        if (k === lastDay) return;
        lastDay = k;
        var s = el('div', 'flex justify-center my-4');
        s.appendChild(el('span', 'text-[12px] font-semibold text-[#6b7780] bg-[#e9edf0] rounded-full px-3 py-1 capitalize', dayLabel(d)));
        $('messages').appendChild(s);
    }

    function buildRow(m, pending) {
        var mine = m.sender_id == MY_ID;
        var row = el('div', 'msg-row flex mb-1.5 ' + (mine ? 'justify-end' : 'justify-start') + (pending ? ' msg-pending' : ' msg-new'));
        var col = el('div', 'max-w-[82%] md:max-w-[62%] flex flex-col ' + (mine ? 'items-end' : 'items-start'));
        var wrap = el('div', 'flex items-end gap-1.5 ' + (mine ? 'flex-row-reverse' : ''));
        var b = el('div', 'bubble ' + (mine ? 'bubble-me' : 'bubble-them'), m.content);
        wrap.appendChild(b);
        if (mine && !pending && String(m.id).indexOf('tmp') !== 0) {
            var act = el('div', 'msg-actions flex gap-0.5 pb-1');
            var ed = el('button', 'w-7 h-7 rounded-full hover:bg-[#e9edf0] text-[#6b7780] flex items-center justify-center', '✎'); ed.type = 'button'; ed.title = 'Modifier'; ed.setAttribute('aria-label', 'Modifier le message');
            var de = el('button', 'w-7 h-7 rounded-full hover:bg-red-50 text-red-600 flex items-center justify-center', '🗑'); de.type = 'button'; de.title = 'Supprimer'; de.setAttribute('aria-label', 'Supprimer le message');
            ed.addEventListener('click', function () { editMsg(m.id); });
            de.addEventListener('click', function () { delMsg(m.id); });
            act.appendChild(ed); act.appendChild(de); wrap.appendChild(act);
        }
        col.appendChild(wrap);
        var meta = el('span', 'text-[11px] text-[#9aa3ab] mt-0.5 px-1 select-none', pending ? 'Envoi…' : hhmm(parseDate(m.created_at)) + (mine && m.is_read == 1 ? ' · Lu' : ''));
        col.appendChild(meta);
        row.appendChild(col);
        row._bubble = b; row._meta = meta;
        return row;
    }

    function loadMessages(first) {
        var id = activeId; if (!id) return Promise.resolve();
        return api('api/get_messages.php?conv_id=' + id).then(function (r) {
            if (r.status === 403) { closeConv(true); return null; }
            return r.json();
        }).then(function (msgs) {
            if (!msgs || id !== activeId || !Array.isArray(msgs)) return;
            var stick = first || nearBottom(), added = 0, ids = {};
            msgs.forEach(function (m) {
                ids[m.id] = 1;
                var s = shown[m.id];
                if (s) {
                    if (s.content !== m.content) { s.row._bubble.textContent = m.content; s.content = m.content; }   // message modifié
                    return;
                }
                // On remplace un message « en cours d'envoi » identique par la version enregistrée
                var d = parseDate(m.created_at);
                addDaySep(d);
                var row = buildRow(m, false);
                $('messages').appendChild(row);
                shown[m.id] = { row: row, content: m.content };
                if (m.sender_id != MY_ID) added++;
            });
            // messages supprimés ailleurs
            Object.keys(shown).forEach(function (k) { if (!ids[k] && String(k).indexOf('tmp') !== 0) { shown[k].row.remove(); delete shown[k]; } });
            if (stick) scrollDown(); else if (added) $('btn-newmsg').classList.remove('hidden');
            showSuggestions(msgs);
            if (msgs.some(function (m) { return m.sender_id != MY_ID && m.is_read == 0; })) {
                api('api/mark_messages_read.php', { conv_id: id }).then(loadConvs);
            }
        }).catch(function () {});
    }

    // Aide à la première question (parent qui contacte un organisme)
    var SUGGESTIONS = ["Quelles sont les dates et les horaires d'arrivée ?", "Mon enfant a une allergie, comment est-ce géré ?", "Reste-t-il des places disponibles ?", "Peut-on payer en plusieurs fois ?"];
    function showSuggestions(msgs) {
        var box = $('suggest');
        if (IS_DIR || msgs.length > 1 || box.childElementCount) { if (msgs.length > 1) box.classList.add('hidden'); return; }
        SUGGESTIONS.forEach(function (t) {
            var b = el('button', 'chip', t); b.type = 'button';
            b.addEventListener('click', function () { $('msg-input').value = t; autosize(); $('msg-input').focus(); });
            box.appendChild(b);
        });
        box.classList.remove('hidden');
    }

    // ---------- Envoi ----------
    function autosize() { var i = $('msg-input'); i.style.height = 'auto'; i.style.height = Math.min(i.scrollHeight, 160) + 'px'; }
    $('msg-input').addEventListener('input', autosize);
    $('msg-input').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); $('msg-form').requestSubmit(); }
    });
    $('msg-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var input = $('msg-input'), text = input.value.trim();
        if (!text || !activeId) return;
        var id = activeId, tmpId = 'tmp' + Date.now();
        var now = new Date();
        addDaySep(now);
        var row = buildRow({ id: tmpId, content: text, sender_id: MY_ID, created_at: now.toISOString() }, true);
        $('messages').appendChild(row); scrollDown();
        input.value = ''; autosize(); $('suggest').classList.add('hidden');
        api('api/send_message.php', { conv_id: id, content: text }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); }).then(function (x) {
            if (!x.ok) throw new Error(x.d.error || 'Erreur');
            row.remove();                       // la version enregistrée arrive par loadMessages
            if (id === activeId) loadMessages(false);
            loadConvs();
        }).catch(function (err) {
            row.classList.remove('msg-pending');
            row._bubble.className = 'bubble bubble-me';
            row._bubble.style.background = '#b42318';
            row._meta.textContent = 'Non envoyé : ' + (err.message || 'erreur réseau') + ' — cliquez pour réessayer';
            row._meta.style.cursor = 'pointer'; row._meta.style.color = '#b42318';
            row._meta.addEventListener('click', function () { row.remove(); input.value = text; autosize(); input.focus(); });
        });
    });

    // ---------- Modifier / supprimer ----------
    function editMsg(mid) {
        var s = shown[mid]; if (!s) return;
        var b = s.row._bubble, old = s.content;
        var ta = el('textarea', 'w-full min-w-[220px] rounded-xl bg-white text-[#26343b] p-2 text-[15px] border border-[#d3dbe0]'); ta.value = old; ta.rows = 2;
        b.textContent = ''; b.appendChild(ta); ta.focus();
        var bar = el('div', 'flex justify-end gap-2 mt-2');
        var ok = el('button', 'text-[12px] font-bold px-3 py-1 rounded-full bg-white text-[#26343b]', 'Enregistrer'); ok.type = 'button';
        var no = el('button', 'text-[12px] font-bold px-3 py-1 rounded-full text-white/80', 'Annuler'); no.type = 'button';
        bar.appendChild(no); bar.appendChild(ok); b.appendChild(bar);
        no.addEventListener('click', function () { b.textContent = old; });
        ok.addEventListener('click', function () {
            var v = ta.value.trim(); if (!v || v === old) { b.textContent = old; return; }
            api('api/edit_message.php', { message_id: mid, content: v }).then(function (r) { return r.json(); }).then(function (d) {
                if (d && d.success) { s.content = v; b.textContent = v; } else b.textContent = old;
            }).catch(function () { b.textContent = old; });
        });
    }
    async function delMsg(mid) {
        if (!(await ColoUI.confirm('Supprimer ce message ?', { danger: true, confirmLabel: 'Supprimer' }))) return;
        api('api/delete_message.php', { message_id: mid }).then(function (r) { return r.json(); }).then(function (d) {
            if (d && d.success && shown[mid]) { shown[mid].row.remove(); delete shown[mid]; loadConvs(); }
        });
    }

    // ---------- Démarrage et actualisation ----------
    $('conv-search').addEventListener('input', renderList);
    loadConvs().then(function () { if (activeId) openConv(activeId, false); });
    setInterval(function () { if (document.visibilityState === 'visible' && activeId) loadMessages(false); }, 4000);
    setInterval(function () { if (document.visibilityState === 'visible') loadConvs(); }, 12000);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { loadConvs(); if (activeId) loadMessages(false); } });
})();
</script>
</body>
</html>

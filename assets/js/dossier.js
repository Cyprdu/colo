// assets/js/dossier.js : rendu commun du dossier d'un inscrit (panneau de gestion_camp.php et page child_details.php).
// Données : api/dossier_inscrit.php. Dossier.sections(d) -> [{id, label, html}] ; Dossier.stack(d) -> html empilé ; Dossier.tabs(d, el) -> onglets.
(function () {
    const esc = s => String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    const eur = n => (Number(n) || 0).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 2 });
    const dateFr = (d, t) => { if (!d) return ''; const x = new Date(String(d).replace(' ', 'T')); return isNaN(x) ? '' : x.toLocaleDateString('fr-FR', t ? { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' } : undefined); };
    const age = d => { if (!d) return null; const b = new Date(d), n = new Date(); let a = n.getFullYear() - b.getFullYear(); if (n < new Date(n.getFullYear(), b.getMonth(), b.getDate())) a--; return a; };
    const MOYENS = window.DOSSIER_MOYENS || {};
    const dl = rows => { const r = rows.filter(x => x[1] !== null && x[1] !== undefined && x[1] !== ''); return r.length ? `<dl class="grid grid-cols-[130px_1fr] gap-x-4 gap-y-2 text-sm">${r.map(([k, v, raw]) => `<dt class="text-[#6b7780]">${k}</dt><dd class="font-semibold text-[#26343b] break-words">${raw ? v : esc(v)}</dd>`).join('')}</dl>` : '<p class="text-sm text-[#6b7780]">Non renseigné.</p>'; };
    const mail = m => m ? `<a class="text-[#3d6a8e] hover:underline" href="mailto:${esc(m)}">${esc(m)}</a>` : '';
    const tel = t => t ? `<a class="text-[#3d6a8e] hover:underline" href="tel:${esc(String(t).replace(/\s/g, ''))}">${esc(t)}</a>` : '';
    const sec = (id, label, html) => ({ id, label, html });
    const flag = (ok, label) => `<li class="flex items-center justify-between gap-3 py-2 border-b border-[#f1f3f5] last:border-0"><span>${label}</span><span class="font-bold ${ok ? 'text-[#26343b]' : 'text-[#a4372f]'}">${ok ? 'Oui' : 'Non'}</span></li>`;
    const STATUT = { PAYE: 'Payé', GRATUIT: 'Gratuit', EN_ATTENTE: 'En attente de paiement', A_REGLER: 'À encaisser' };

    function moyen(p) { return p.moyen ? (MOYENS[p.moyen] || p.moyen) : (p.mode === 'CARTE' ? 'Carte bancaire' : (p.statut === 'GRATUIT' ? 'Gratuit' : '')); }
    function pay(d, recu) {
        const p = d.paiement, st = STATUT[p.statut] || p.statut || '';
        return dl([['Tarif', p.tarif], ['Montant dû', p.prix != null ? eur(p.prix) : null], ['Montant réglé', eur(p.paye)], ['Moyen', moyen(p)], ['Statut', st]])
            + (recu && p.statut === 'A_REGLER' ? `<button type="button" class="mt-3 text-sm font-bold text-[#3d6a8e] underline hover:text-[#26343b]" data-recu="${esc(d.reservation)}">Marquer le règlement comme reçu</button>` : '');
    }
    function hist(d) { return `<ol class="space-y-3 text-sm">${d.historique.map(h => `<li class="flex gap-3"><span class="mt-1.5 w-2 h-2 rounded-full bg-[#2f3d45] flex-none"></span><span><b class="text-[#26343b]">${esc(h.label)}</b>${h.date ? `<span class="block text-xs text-[#6b7780]">${esc(dateFr(h.date, 1))}</span>` : ''}</span></li>`).join('')}</ol>`; }
    const rep = d => d.reponses && d.reponses.length ? dl(d.reponses.map(r => [esc(r.libelle), r.valeur || '—'])) : '';

    function enfant(d, o) {
        const e = d.enfant, s = d.sante, a = age(e.date_naissance), v = s.vaccins || {};
        const urg = s.urgence.nom || s.urgence.tel ? [s.urgence.nom, s.urgence.lien ? '(' + s.urgence.lien + ')' : '', s.urgence.tel].filter(Boolean).join(' ') : null;
        const parents = d.parents.map((p, i) => `<div class="${i ? 'mt-5 pt-5 border-t border-[#f1f3f5]' : ''}"><p class="text-xs font-extrabold uppercase tracking-wider text-[#6b7780] mb-2">Responsable ${i + 1}</p>${dl([['Nom', [p.civilite, p.prenom, p.nom].filter(Boolean).join(' ')], ['Lien', p.lien], ['Téléphone', tel(p.tel), 1], ['E-mail', mail(p.email), 1], ['Profession', p.profession]])}</div>`).join('') || '<p class="text-sm text-[#6b7780]">Non renseigné.</p>';
        const alerte = [s.allergies, s.infos, s.traitements].some(Boolean);
        const sante = `<div class="rounded-2xl p-4 mb-4 ${alerte ? 'bg-[#f8f5ea] border border-[#eadfb8]' : 'bg-[#f6f8f9]'}"><p class="text-sm font-bold text-[#26343b]">${alerte ? 'Points d’attention médicaux' : 'Aucun point d’attention déclaré'}</p>
            ${alerte ? `<ul class="mt-2 space-y-1 text-sm text-[#3b4a54]">${s.allergies ? `<li><b>Allergies :</b> ${esc(s.allergies)}</li>` : ''}${s.infos ? `<li><b>Santé :</b> ${esc(s.infos)}</li>` : ''}${s.traitements ? `<li><b>Traitement :</b> ${esc(s.traitements)}</li>` : ''}</ul>` : ''}</div>`
            + dl([['Régime', s.regime], ['Taille / poids', [s.taille && s.taille + ' cm', s.poids && s.poids + ' kg'].filter(Boolean).join(' · ')], ['Médecin', s.medecin], ['Urgence', urg], ['Vaccins', [v.dtp && 'DTP : ' + v.dtp, v.bcg && 'BCG : ' + v.bcg, v.autre].filter(Boolean).join(' · ')]])
            + `<p class="ds-hint mt-4">Ouverture de cette fiche enregistrée dans le journal d’accès aux données de santé${d.acces ? ' (' + d.acces + ' consultation' + (d.acces > 1 ? 's' : '') + ' par votre compte)' : ''}.</p>`;
        const docs = d.documents.length ? `<ul class="divide-y divide-[#f1f3f5]">${d.documents.map(x => `<li class="flex items-center justify-between gap-3 py-2.5 text-sm"><span class="font-semibold text-[#26343b]">${esc(x.label)}</span><a class="ds-btn ds-btn-light ds-btn-sm" href="${esc(x.url)}" target="_blank" rel="noopener">Ouvrir</a></li>`).join('')}</ul>` : '<p class="text-sm text-[#6b7780]">Aucun document déposé.</p>';
        const au = d.autorisations, auth = `<ul class="text-sm">${flag(au.droit_image, 'Droit à l’image')}${flag(au.contact, 'Contact autorisé')}${flag(au.accord_parental, 'Accord parental')}${flag(au.cgv, 'Conditions acceptées')}</ul>`;
        const notes = (d.commentaires ? `<p class="text-sm text-[#3b4a54] whitespace-pre-line">${esc(d.commentaires)}</p>` : '<p class="text-sm text-[#6b7780]">Aucun commentaire du parent.</p>') + (d.reponses.length ? `<p class="text-xs font-extrabold uppercase tracking-wider text-[#6b7780] mt-5 mb-2">Réponses au formulaire</p>${rep(d)}` : '');
        return [
            sec('enfant', 'Enfant', dl([['Prénom nom', e.prenom + ' ' + e.nom], ['Âge', a !== null ? a + ' ans (né' + (e.sexe === 'Femme' ? 'e' : '') + ' le ' + dateFr(e.date_naissance) + ')' : ''], ['Sexe', e.sexe === 'Femme' ? 'Fille' : (e.sexe ? 'Garçon' : '')], ['Adresse', e.adresse], ['Pays', e.pays], ['E-mail', mail(e.email), 1], ['Mobile', tel(e.tel), 1], ['Séjour', d.camp + ' · ' + (dateFr(d.dates.split(' → ')[0]) + ' → ' + dateFr(d.dates.split(' → ')[1]))]])),
            sec('parents', 'Parents', parents),
            sec('sante', 'Santé', sante),
            sec('documents', 'Documents', docs),
            sec('autorisations', 'Autorisations', auth),
            sec('paiement', 'Paiement', pay(d, o && o.recu)),
            sec('notes', 'Notes', notes),
            sec('historique', 'Historique', hist(d))
        ];
    }
    function animateur(d, o) {
        const a = age(d.date_naissance), u = d.urgence;
        return [
            sec('profil', 'Animateur', dl([['Nom', d.nom], ['Âge', a !== null ? a + ' ans' : ''], ['E-mail', mail(d.email), 1], ['Téléphone', tel(d.tel), 1], ['BAFA', d.bafa === 'aucun' ? 'Aucun' : d.bafa], ['Diplôme', d.diplome], ['Séjour', d.camp], ['Statut', d.statut]])),
            sec('sante', 'Santé et urgence', dl([['Allergies', d.allergies], ['Régime', d.regime], ['Urgence', [u.nom, u.tel].filter(Boolean).join(' · ')]])),
            sec('reponses', 'Réponses', d.reponses.length ? rep(d) : '<p class="text-sm text-[#6b7780]">Aucune réponse.</p>'),
            sec('paiement', 'Paiement', dl([['Tarif', d.paiement.tarif], ['Prix', eur(d.paiement.prix)], ['Réglé', eur(d.paiement.paye)], ['Statut', STATUT[d.paiement.statut] || d.paiement.statut]])),
            sec('historique', 'Historique', hist(d))
        ];
    }
    const sections = (d, o) => d.type === 'animateur' ? animateur(d, o) : enfant(d, o);
    const card = s => `<section class="ds-card p-5" id="dos-${s.id}"><h3 class="text-xs font-extrabold uppercase tracking-wider text-[#6b7780] mb-3">${s.label}</h3>${s.html}</section>`;
    function head(d) {
        if (d.type === 'animateur') return `<div class="min-w-0"><p class="text-lg font-extrabold text-[#26343b] truncate">${esc(d.nom)}</p><p class="text-sm text-[#6b7780]">Animateur · ${esc(d.camp)}</p></div>`;
        const e = d.enfant, a = age(e.date_naissance);
        return `<div class="flex items-center gap-4 min-w-0">${e.photo ? `<img src="${esc(e.photo)}" alt="" class="w-16 h-16 rounded-2xl object-cover bg-[#eef2f4] flex-none">` : `<span class="w-16 h-16 rounded-2xl bg-[#eef2f4] text-[#26343b] flex items-center justify-center text-xl font-extrabold flex-none">${esc((e.prenom[0] || '') + (e.nom[0] || ''))}</span>`}
            <div class="min-w-0"><p class="text-lg font-extrabold text-[#26343b] truncate">${esc(e.prenom)} ${esc(e.nom)}</p><p class="text-sm text-[#6b7780]">${a !== null ? a + ' ans · ' : ''}${esc(d.camp)}</p></div></div>`;
    }
    function tabs(d, el, o) {
        const S = sections(d, o); let cur = S[0].id;
        const draw = () => { el.innerHTML = `<div class="flex gap-1 overflow-x-auto border-b border-[#e5e7eb] mb-5" role="tablist">${S.map(s => `<button type="button" role="tab" data-dtab="${s.id}" aria-selected="${s.id === cur}" class="px-4 py-3 text-sm font-bold whitespace-nowrap border-b-2 -mb-px ${s.id === cur ? 'border-[#2f3d45] text-[#26343b]' : 'border-transparent text-[#6b7780] hover:text-[#26343b]'}">${s.label}</button>`).join('')}</div><div role="tabpanel">${S.find(s => s.id === cur).html}</div>`; };
        el.addEventListener('click', e => { const b = e.target.closest('[data-dtab]'); if (b) { cur = b.dataset.dtab; draw(); } });
        draw();
    }
    window.Dossier = { sections, head, tabs, stack: (d, o) => sections(d, o).map(card).join('<div class="h-3"></div>'), esc, eur };
})();

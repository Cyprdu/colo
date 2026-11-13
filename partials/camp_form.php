<?php
// partials/camp_form.php : formulaire « Publier un séjour » / « Modifier le séjour » (design system ColoMap).
// Attend : $camp (array|null : null = création), $organisateurs, $currentTarifs, $champsActuels, $themesList, $paysList, $att
require_once __DIR__ . '/../api/options.php';
require_once __DIR__ . '/../api/champs.php';

$att = $att ?? []; $edit = is_array($camp);
$val = fn($k, $d = '') => htmlspecialchars((string)($edit ? ($camp[$k] ?? $d) : $d), ENT_QUOTES);
$chk = fn($k) => $edit && !empty($camp[$k]) ? 'checked' : '';
$reserve = $edit ? (string)($camp['reserve_sexe'] ?? '') : '';
$requis = $edit ? options_criteres_valides($camp['champs_requis'] ?? '[]') : ['accord_parental'];
$moyens = $edit ? options_paiements_valides($camp['moyens_paiement'] ?? '') : ['carte'];
$quotasOn = $edit && ((int)($camp['quota_fille'] ?? 0) > 0 || (int)($camp['quota_garcon'] ?? 0) > 0);
$online = $edit ? !empty($camp['inscription_en_ligne']) : false;
$tagsInit = $edit ? array_values(array_filter(array_map('trim', explode(',', (string)($camp['type'] ?? ''))))) : [];
if ($edit && !empty($camp['theme']) && !in_array($camp['theme'], $themesList, true)) $themesList[] = $camp['theme'];

$ICO = [
    'building' => '<path d="M4 21V5a1 1 0 011-1h9a1 1 0 011 1v16M15 9h4a1 1 0 011 1v11M8 8h3M8 12h3M8 16h3M3 21h18"/>',
    'tent' => '<path d="M3 20L12 4l9 16H3zM12 4v16M9 20l3-6 3 6"/>',
    'pin' => '<path d="M12 21s7-6.2 7-11.5A7 7 0 005 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6M16 5.2a3 3 0 010 5.6M18 14.3c1.8.8 3 2.6 3 4.7"/>',
    'ticket' => '<path d="M3 8a2 2 0 012-2h14a2 2 0 012 2v2a2 2 0 000 4v2a2 2 0 01-2 2H5a2 2 0 01-2-2v-2a2 2 0 000-4V8z"/><path d="M14 6v12" stroke-dasharray="2 2"/>',
    'card' => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18M7 15h3"/>',
    'file' => '<path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
    'chat' => '<path d="M4 5h16v11H9l-5 4V5z"/><path d="M8 9h8M8 12h5"/>',
    'whistle' => '<circle cx="9" cy="14" r="5"/><path d="M13 11l8-4v4l-5 2M9 12v2"/>',
    'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    'image' => '<rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="1.8"/><path d="M21 16l-5-5-8 8"/>',
    'edit' => '<path d="M4 20h4L19 9l-4-4L4 16v4zM13.5 6.5l4 4"/>',
    'trash' => '<path d="M5 7h14M10 7V4h4v3M7 7l1 13h8l1-13M10 11v6M14 11v6"/>',
    'plus' => '<path d="M12 5v14M5 12h14"/>',
    'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    'close' => '<path d="M6 6l12 12M18 6L6 18"/>',
    'up' => '<path d="M12 19V5M6 11l6-6 6 6"/>', 'down' => '<path d="M12 5v14M6 13l6 6 6-6"/>',
];
$svg = fn($n, $cls = '') => '<svg class="' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $ICO[$n] . '</svg>';
$sec = fn($id, $ico, $title, $desc) => '<section id="' . $id . '" class="ds-card ds-sec ds-fade"><div class="ds-sec-head"><div class="ds-sec-ico">' . $svg($ico) . '</div><div><h2 class="ds-sec-title">' . $title . '</h2><p class="ds-sec-desc">' . $desc . '</p></div></div>';
$tick = $svg('check', 'w-3 h-3');
?>
<form id="campForm" class="ds-page" style="max-width:1180px" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="token" value="<?= $val('token') ?>">
    <div class="mb-8">
        <a href="mes_camps.php" class="text-sm font-semibold text-[#6b7780] hover:text-[#26343b]">&larr; Mes séjours</a>
        <h1 class="ds-h1 mt-2"><?= $edit ? 'Modifier le séjour' : 'Publier un séjour' ?></h1>
        <p class="ds-lead"><?= $edit ? htmlspecialchars($camp['nom']) . ' : toutes les informations et les options du séjour.' : 'Renseignez le séjour, choisissez vos options : tout se règle sur cette page.' ?></p>
    </div>

    <div class="grid lg:grid-cols-[210px_1fr] gap-8 items-start">
        <nav class="hidden lg:block sticky top-24 text-sm font-semibold text-[#6b7780]" aria-label="Sections">
            <?php foreach (['s-orga' => 'Organisme', 's-sejour' => 'Le séjour', 's-lieu' => 'Lieu et dates', 's-public' => 'Public et places', 's-inscription' => 'Inscriptions', 's-paiement' => 'Paiements acceptés', 's-fiche' => 'Fiche enfant', 's-questions' => 'Questions', 's-anim' => 'Animateurs', 's-infos' => 'Infos pratiques', 's-medias' => 'Visuels'] as $a => $t): ?>
                <a href="#<?= $a ?>" class="block px-3 py-2 rounded-lg hover:bg-white hover:text-[#26343b] transition"><?= $t ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="space-y-6 min-w-0">

            <?= $sec('s-orga', 'building', 'Organisme responsable', "L'organisme qui porte le séjour : ses coordonnées sont communiquées aux familles.") ?>
                <label class="ds-label" for="orga-select">Organisme *</label>
                <div class="flex gap-2">
                    <select id="orga-select" name="organisateur_id" class="ds-select" required>
                        <option value="">Choisir un organisme</option>
                        <?php foreach ($organisateurs as $o): ?>
                            <option value="<?= (int)$o['id'] ?>" <?= $edit && (int)$o['id'] === (int)$camp['organisateur_id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="ds-btn ds-btn-light" onclick="Orga.open()">Gérer</button>
                </div>
                <p class="ds-hint">« Gérer » permet de créer un nouvel organisme ou de modifier ses coordonnées, sans quitter cette page.</p>
            </section>

            <?= $sec('s-sejour', 'tent', 'Le séjour', 'Ce que les familles voient en premier.') ?>
                <div class="grid md:grid-cols-2 gap-5">
                    <div class="md:col-span-2"><label class="ds-label" for="f-nom">Nom du séjour *</label><input id="f-nom" name="nom" class="ds-input" required maxlength="200" value="<?= $val('nom') ?>" placeholder="Ex : Aventure dans les Alpes"></div>
                    <div><label class="ds-label" for="f-theme">Thème principal *</label>
                        <select id="f-theme" name="theme" class="ds-select" required><option value="">Sélectionner</option>
                            <?php foreach ($themesList as $t): ?><option <?= $edit && ($camp['theme'] ?? '') === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="ds-label" for="f-amin">Âge min *</label><input id="f-amin" type="number" min="0" max="30" name="age_min" class="ds-input" required value="<?= $val('age_min') ?>"></div>
                        <div><label class="ds-label" for="f-amax">Âge max *</label><input id="f-amax" type="number" min="0" max="30" name="age_max" class="ds-input" required value="<?= $val('age_max') ?>"></div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="ds-label" for="tagInput">Mots-clés (activités, ambiance), 15 maximum</label>
                        <div class="flex gap-2"><input id="tagInput" class="ds-input" placeholder="Surf, plage… puis Entrée" maxlength="40"><button type="button" class="ds-btn ds-btn-light" id="tagAdd">Ajouter</button></div>
                        <div id="tagList" class="flex flex-wrap gap-2 mt-3"></div>
                        <input type="hidden" name="type" id="tagHidden">
                    </div>
                    <div class="md:col-span-2"><label class="ds-label" for="f-desc">Description complète *</label><textarea id="f-desc" name="description" rows="6" class="ds-textarea" required maxlength="10000" placeholder="Programme, ambiance, journée type…"><?= $val('description') ?></textarea></div>
                </div>
            </section>

            <?= $sec('s-lieu', 'pin', 'Lieu et dates', "Où et quand se déroule le séjour.") ?>
                <div class="grid md:grid-cols-2 gap-5">
                    <div class="md:col-span-2 relative"><label class="ds-label" for="addressSearch">Adresse du centre ou lieu de rendez-vous *</label>
                        <input id="addressSearch" name="adresse" class="ds-input" required autocomplete="off" value="<?= $val('adresse') ?>" placeholder="Commencez à taper l'adresse…">
                        <div id="addrResults" class="hidden absolute left-0 right-0 top-full mt-1 z-20 bg-white border border-[#e5e7eb] rounded-xl shadow-lg max-h-56 overflow-y-auto"></div></div>
                    <div><label class="ds-label" for="villeInput">Ville *</label><input id="villeInput" name="ville" class="ds-input" required value="<?= $val('ville') ?>"></div>
                    <div><label class="ds-label" for="cpInput">Code postal</label><input id="cpInput" name="cp" class="ds-input" value="<?= $val('code_postal') ?>"></div>
                    <div><label class="ds-label" for="paysInput">Pays</label>
                        <select id="paysInput" name="pays" class="ds-select"><?php foreach ($paysList as $p): ?><option <?= ($edit ? ($camp['pays'] ?? 'France') : 'France') === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option><?php endforeach; ?></select></div>
                    <label class="ds-opt self-end"><input type="checkbox" name="itinerant" value="1" <?= $chk('itinerant') ?>><span class="ds-tick"><?= $tick ?></span><span><span class="ds-opt-title block">Séjour itinérant</span><span class="ds-opt-sub block">Le groupe change de lieu : l'adresse est le point de départ.</span></span></label>
                    <div id="etapesBox" class="md:col-span-2 <?= $chk('itinerant') ? '' : 'hidden' ?>">
                        <div class="ds-sub">
                            <p class="ds-label">Étapes du parcours</p>
                            <p class="ds-hint !mt-0 mb-3">Un lieu par jour : où l'on dort, et une note sur le trajet (ex. étape à vélo de 45 km).</p>
                            <div id="etapesList" class="space-y-3"></div>
                            <button type="button" class="ds-btn ds-btn-light ds-btn-sm mt-3" onclick="Etapes.add()"><?= $svg('plus', 'w-4 h-4') ?> Ajouter une étape</button>
                        </div>
                    </div>
                    <div><label class="ds-label" for="f-debut">Début *</label><input id="f-debut" type="date" name="date_debut" class="ds-input" required value="<?= $val('date_debut') ?>"></div>
                    <div><label class="ds-label" for="f-fin">Fin *</label><input id="f-fin" type="date" name="date_fin" class="ds-input" required value="<?= $val('date_fin') ?>"></div>
                </div>
            </section>

            <?= $sec('s-public', 'users', 'Public et places', 'Séjour mixte ou réservé, quotas par sexe : à vous de choisir.') ?>
                <p class="ds-label">Qui peut s'inscrire ?</p>
                <div class="grid sm:grid-cols-3 gap-3" id="reserveOpts">
                    <?php foreach (['' => ['Mixte', 'Filles et garçons'], SEXE_GARCON => ['Garçons uniquement', 'Séjour réservé aux garçons'], SEXE_FILLE => ['Filles uniquement', 'Séjour réservé aux filles']] as $k => [$t, $d]): ?>
                        <label class="ds-opt"><input type="radio" name="reserve_sexe" value="<?= $k ?>" <?= $reserve === $k ? 'checked' : '' ?>><span class="ds-tick"><?= $tick ?></span><span><span class="ds-opt-title block"><?= $t ?></span><span class="ds-opt-sub block"><?= $d ?></span></span></label>
                    <?php endforeach; ?>
                </div>
                <p class="ds-hint">Un séjour réservé n'apparaît pas dans les recherches et recommandations d'une famille dont l'enfant est de l'autre sexe, et l'inscription lui est refusée.</p>
                <div id="quotaWrap" class="mt-6">
                    <div class="ds-row">
                        <div><p class="ds-opt-title">Quotas filles / garçons</p><p class="ds-opt-sub">Limiter le nombre de filles et de garçons. Désactivé : premier arrivé, premier servi.</p></div>
                        <label class="ds-switch"><input type="checkbox" name="quotas_sexe_actifs" value="1" id="quotaToggle" <?= $quotasOn ? 'checked' : '' ?> aria-label="Activer les quotas filles et garçons"><span></span></label>
                    </div>
                    <div id="quotaBlock" class="grid grid-cols-2 gap-4 mt-4 <?= $quotasOn ? '' : 'hidden' ?>">
                        <div><label class="ds-label" for="q-f">Places filles</label><input id="q-f" type="number" min="0" name="quota_fille" class="ds-input" value="<?= $val('quota_fille') ?>"></div>
                        <div><label class="ds-label" for="q-g">Places garçons</label><input id="q-g" type="number" min="0" name="quota_garcon" class="ds-input" value="<?= $val('quota_garcon') ?>"></div>
                    </div>
                </div>
            </section>

            <?= $sec('s-inscription', 'ticket', 'Inscriptions', "Inscriptions en ligne via ColoMap, ou simple vitrine renvoyant vers votre site.") ?>
                <div class="ds-row">
                    <div><p class="ds-opt-title">Activer les inscriptions en ligne</p><p class="ds-opt-sub">Tarifs, places, paiement par carte et suivi des inscrits sur ColoMap.</p></div>
                    <label class="ds-switch"><input type="checkbox" name="inscription_en_ligne" id="toggleOnline" <?= $online ? 'checked' : '' ?> aria-label="Activer les inscriptions en ligne"><span></span></label>
                </div>
                <div id="tosBox" class="hidden mt-3 ds-alert-err text-sm">
                    Commission de <b>1 %</b> par transaction en ligne.
                    <label class="flex items-center gap-2 mt-2 font-semibold cursor-pointer"><input type="checkbox" id="acceptToS" <?= $edit ? 'checked' : '' ?>> J'accepte ces conditions.</label>
                </div>

                <div id="blockExternal" class="mt-6 <?= $online ? 'hidden' : '' ?>">
                    <div class="grid md:grid-cols-2 gap-5">
                        <div><label class="ds-label" for="f-prix">Prix affiché (€)</label><input id="f-prix" type="number" min="0" step="0.01" name="prix_simple" class="ds-input" value="<?= $edit && !$online ? $val('prix') : '' ?>" placeholder="Ex : 500"></div>
                        <div><label class="ds-label" for="f-lien">Lien d'inscription</label><input id="f-lien" name="lien_externe" class="ds-input" value="<?= $val('lien_externe') ?>" placeholder="https://mon-site.fr/inscription"></div>
                        <div class="md:col-span-2"><label class="ds-label" for="f-retour">Adresse ou email de retour du dossier</label><textarea id="f-retour" name="adresse_retour_dossier" rows="2" class="ds-textarea"><?= $val('adresse_retour_dossier') ?></textarea></div>
                    </div>
                </div>

                <div id="blockInternal" class="mt-6 space-y-7 <?= $online ? '' : 'hidden' ?>">
                    <div>
                        <div class="flex items-center justify-between gap-3 mb-3"><p class="ds-label !mb-0">Tarifs proposés aux familles *</p>
                            <button type="button" class="ds-btn ds-btn-light ds-btn-sm" onclick="Tarifs.open()"><?= $svg('plus', 'w-4 h-4') ?> Choisir / créer des tarifs</button></div>
                        <div id="tarifsSelected" class="space-y-2"></div>
                        <input type="hidden" name="tarifs" id="tarifsHidden">
                    </div>
                    <div class="grid md:grid-cols-3 gap-5">
                        <div><label class="ds-label" for="q-tot">Nombre total de places *</label><input id="q-tot" type="number" min="0" name="quota_global" class="ds-input" value="<?= $val('quota_global') ?>"></div>
                        <div><label class="ds-label" for="f-limite">Date limite d'inscription</label><input id="f-limite" type="date" name="date_limite_inscription" class="ds-input" value="<?= $val('date_limite_inscription') ?>"></div>
                        <div><label class="ds-label" for="f-frat">Remise fratrie (%)</label><input id="f-frat" type="number" min="0" max="100" name="remise_fratrie" class="ds-input" value="<?= $val('remise_fratrie', 0) ?>"></div>
                    </div>
                    <div class="ds-row">
                        <div><p class="ds-opt-title">Réservation « en attente de paiement »</p><p class="ds-opt-sub">Le parent réserve et règle plus tard ; sa place est gardée puis libérée automatiquement. Sinon : 30 minutes, le temps du paiement.</p>
                            <div id="attenteJours" class="mt-3 flex items-center gap-2 text-sm text-[#3b4a54]">Délai maximum <input type="number" name="attente_jours" min="1" max="<?= ATTENTE_MAX_JOURS ?>" value="<?= (int)($att['attente_jours'] ?? ATTENTE_DEFAUT_JOURS) ?>" class="ds-input !w-20 !py-2 text-center" aria-label="Délai en jours"> jour(s)</div></div>
                        <label class="ds-switch"><input type="checkbox" name="attente_paiement" id="attenteToggle" value="1" <?= !isset($att['attente_paiement']) || !empty($att['attente_paiement']) ? 'checked' : '' ?> aria-label="Autoriser les réservations en attente de paiement"><span></span></label>
                    </div>
                </div>
            </section>

            <?= $sec('s-paiement', 'card', 'Moyens de paiement acceptés', "Ce que votre organisme accepte. Seul le paiement par carte est traité automatiquement par le site ; les autres moyens se règlent directement avec vous.") ?>
                <div class="grid sm:grid-cols-2 gap-3">
                    <?php foreach (options_paiements() as $code => [$lib, $sub, $logo]): ?>
                        <label class="ds-opt items-center"><input type="checkbox" name="moyens_paiement[]" value="<?= $code ?>" <?= in_array($code, $moyens, true) ? 'checked' : '' ?>><span class="ds-tick"><?= $tick ?></span><span class="ds-pay-logo"><?= $logo ?></span><span><span class="ds-opt-title block"><?= htmlspecialchars($lib) ?></span><span class="ds-opt-sub block"><?= htmlspecialchars($sub) ?></span></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="mt-5"><label class="ds-label" for="f-instr">Consignes de paiement (facultatif)</label><textarea id="f-instr" name="instructions_paiement" rows="2" maxlength="1000" class="ds-textarea" placeholder="Ex : chèques à l'ordre de l'association X, à envoyer avant le 15 juin."><?= $val('instructions_paiement') ?></textarea></div>
            </section>

            <?= $sec('s-fiche', 'file', 'Fiche enfant : informations obligatoires', "Cochez ce que l'enfant doit avoir renseigné dans sa fiche pour s'inscrire. L'inscription est refusée tant qu'un champ imposé est vide.") ?>
                <div class="grid sm:grid-cols-2 gap-3">
                    <?php foreach (ENFANT_CRITERES as $k => [$lib]): ?>
                        <label class="ds-opt items-center"><input type="checkbox" name="champs_requis[]" value="<?= $k ?>" <?= in_array($k, $requis, true) ? 'checked' : '' ?>><span class="ds-tick"><?= $tick ?></span><span class="ds-opt-title"><?= htmlspecialchars($lib) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <p class="ds-hint">Nom, prénom, date de naissance et sexe sont toujours obligatoires. Les parents sont invités à compléter la fiche avant de payer.</p>
            </section>

            <?= $sec('s-questions', 'chat', 'Vos propres questions', "Posées pour chaque enfant à l'inscription ; les réponses apparaissent dans la fiche de l'inscrit.") ?>
                <div id="champsList" class="space-y-3"></div>
                <button type="button" class="ds-btn ds-btn-light mt-4" onclick="Champs.add()"><?= $svg('plus', 'w-4 h-4') ?> Ajouter une question</button>
            </section>

            <?= $sec('s-anim', 'whistle', 'Recrutement des animateurs', "Recrutez l'équipe d'animation via ColoMap.") ?>
                <div class="ds-row">
                    <div><p class="ds-opt-title">Recruter les animateurs via ColoMap</p><p class="ds-opt-sub">Les animateurs peuvent candidater depuis la page du séjour.</p></div>
                    <label class="ds-switch"><input type="checkbox" name="gestion_animateur" id="toggleAnim" <?= $chk('gestion_animateur') ?> aria-label="Recruter les animateurs via ColoMap"><span></span></label>
                </div>
                <div id="animBlock" class="mt-4 space-y-4 <?= $chk('gestion_animateur') ? '' : 'hidden' ?>">
                    <div class="grid grid-cols-3 gap-4">
                        <div><label class="ds-label" for="a-tot">Total</label><input id="a-tot" type="number" min="0" name="quota_max_anim" class="ds-input" value="<?= $val('quota_max_anim') ?>"></div>
                        <div><label class="ds-label" for="a-f">Dont femmes</label><input id="a-f" type="number" min="0" name="quota_anim_fille" class="ds-input" value="<?= $val('quota_anim_fille') ?>"></div>
                        <div><label class="ds-label" for="a-g">Dont hommes</label><input id="a-g" type="number" min="0" name="quota_anim_garcon" class="ds-input" value="<?= $val('quota_anim_garcon') ?>"></div>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-3">
                        <?php foreach (['anim_plus_18' => 'Majeurs uniquement', 'bafa_obligatoire' => 'BAFA obligatoire', 'remuneration_anim' => 'Animateurs rémunérés', 'anim_doit_payer' => "L'animateur paie une participation"] as $k => $t): ?>
                            <label class="ds-opt items-center"><input type="checkbox" name="<?= $k ?>" <?= $chk($k) ?>><span class="ds-tick"><?= $tick ?></span><span class="ds-opt-title"><?= $t ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="max-w-xs"><label class="ds-label" for="a-prix">Participation de l'animateur (€)</label><input id="a-prix" type="number" min="0" step="0.01" name="prix_anim" class="ds-input" value="<?= $val('prix_anim') ?>"></div>
                </div>
            </section>

            <?= $sec('s-infos', 'info', 'Infos pratiques', "Facultatif : rassure les familles, affiché sur la page du séjour.") ?>
                <div class="grid md:grid-cols-2 gap-5">
                    <div><label class="ds-label" for="i-heb">Hébergement</label>
                        <select id="i-heb" name="hebergement" class="ds-select"><?php foreach (HEBERGEMENTS as $k => $t): ?><option value="<?= $k ?>" <?= $edit && ($camp['hebergement'] ?? '') === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
                    <div><label class="ds-label" for="i-enc">Encadrement</label><input id="i-enc" name="encadrement" maxlength="255" class="ds-input" value="<?= $val('encadrement') ?>" placeholder="Ex : 1 animateur pour 8 enfants"></div>
                    <div class="md:col-span-2"><label class="ds-label" for="i-trans">Transport</label><input id="i-trans" name="transport" maxlength="255" class="ds-input" value="<?= $val('transport') ?>" placeholder="Ex : départ en car de Paris Bercy, ou rendez-vous sur place"></div>
                    <div><label class="ds-label" for="i-inc">Ce qui est inclus</label><textarea id="i-inc" name="inclus" rows="3" maxlength="2000" class="ds-textarea" placeholder="Pension complète, activités, assurance…"><?= $val('inclus') ?></textarea></div>
                    <div><label class="ds-label" for="i-prev">À prévoir par la famille</label><textarea id="i-prev" name="a_prevoir" rows="3" maxlength="2000" class="ds-textarea" placeholder="Argent de poche, sac de couchage…"><?= $val('a_prevoir') ?></textarea></div>
                </div>
            </section>

            <?= $sec('s-medias', 'image', 'Visuels et visibilité', 'La photo de couverture et la vidéo teaser.') ?>
                <div class="grid md:grid-cols-2 gap-5">
                    <div>
                        <label class="ds-label" for="f-img">Image de couverture <?= $edit ? '(laisser vide pour garder l\'actuelle)' : '*' ?></label>
                        <?php if ($edit && !empty($camp['image_url'])): ?><img src="<?= $val('image_url') ?>" alt="" class="w-full h-36 object-cover rounded-xl border border-[#e5e7eb] mb-3"><?php endif; ?>
                        <input id="f-img" type="file" name="image" accept="image/jpeg,image/png,image/webp" class="ds-input !p-3" <?= $edit ? '' : 'required' ?>>
                    </div>
                    <div><label class="ds-label" for="f-video">Vidéo teaser (lien YouTube)</label><input id="f-video" type="url" name="video_url" class="ds-input" value="<?= $val('video_url') ?>" placeholder="https://www.youtube.com/watch?v=…">
                        <label class="ds-opt mt-4"><input type="checkbox" name="prive" <?= $chk('prive') ?>><span class="ds-tick"><?= $tick ?></span><span><span class="ds-opt-title block">Séjour privé</span><span class="ds-opt-sub block">Caché de la recherche, accessible uniquement par son lien.</span></span></label></div>
                </div>
            </section>
        </div>
    </div>

    <div class="ds-bar -mx-5 mt-8 px-5 py-4">
        <div class="max-w-[1180px] mx-auto flex flex-wrap items-center justify-between gap-3">
            <p id="formMsg" class="text-sm font-semibold text-[#5b4a1c]" role="alert"></p>
            <div class="flex gap-3 ml-auto">
                <a href="mes_camps.php" class="ds-btn ds-btn-light">Annuler</a>
                <button type="submit" id="submitBtn" class="ds-btn"><?= $edit ? 'Enregistrer les modifications' : 'Publier le séjour' ?></button>
            </div>
        </div>
    </div>
</form>

<!-- Organismes -->
<div id="modalOrga" class="ds-modal" role="dialog" aria-modal="true" aria-labelledby="orgaTitle">
    <div class="ds-modal-box">
        <div id="orgaList">
            <div class="flex items-start justify-between gap-3 mb-1"><h3 id="orgaTitle" class="text-xl font-extrabold text-[#26343b]">Mes organismes</h3><button type="button" class="ds-iconbtn" onclick="Orga.close()" aria-label="Fermer"><?= $svg('close') ?></button></div>
            <p class="text-sm text-[#6b7780] mb-5">Sélectionnez l'organisme du séjour, ou modifiez ses coordonnées.</p>
            <div id="orgaItems" class="space-y-2"></div>
            <button type="button" class="ds-btn ds-btn-light w-full mt-4" onclick="Orga.form()"><?= $svg('plus', 'w-4 h-4') ?> Nouvel organisme</button>
        </div>
        <form id="orgaForm" class="hidden space-y-4" novalidate>
            <h3 id="orgaFormTitle" class="text-xl font-extrabold text-[#26343b]">Nouvel organisme</h3>
            <div><label class="ds-label" for="o-nom">Nom *</label><input id="o-nom" class="ds-input" maxlength="150"></div>
            <div><label class="ds-label" for="o-mail">Email *</label><input id="o-mail" type="email" class="ds-input"></div>
            <div class="grid grid-cols-2 gap-3"><div><label class="ds-label" for="o-tel">Téléphone</label><input id="o-tel" class="ds-input" maxlength="30"></div><div><label class="ds-label" for="o-web">Site web</label><input id="o-web" class="ds-input" maxlength="255" placeholder="https://"></div></div>
            <p id="orgaErr" class="text-sm font-semibold text-[#5b4a1c]" role="alert"></p>
            <div class="flex gap-3"><button type="button" class="ds-btn ds-btn-light flex-1" onclick="Orga.back()">Retour</button><button type="submit" class="ds-btn flex-1" id="orgaSave">Enregistrer</button></div>
        </form>
    </div>
</div>

<!-- Tarifs -->
<div id="modalTarifs" class="ds-modal" role="dialog" aria-modal="true" aria-labelledby="tarifsTitle">
    <div class="ds-modal-box wide">
        <div id="tarifsList">
            <div class="flex items-start justify-between gap-3 mb-1"><h3 id="tarifsTitle" class="text-xl font-extrabold text-[#26343b]">Tarifs du séjour</h3><button type="button" class="ds-iconbtn" onclick="Tarifs.close()" aria-label="Fermer"><?= $svg('close') ?></button></div>
            <p class="text-sm text-[#6b7780] mb-5">Cochez les tarifs proposés à ce séjour. Les tarifs appartiennent à l'organisme et peuvent servir à plusieurs séjours.</p>
            <div id="tarifsItems" class="space-y-2"></div>
            <div class="flex gap-3 mt-5"><button type="button" class="ds-btn ds-btn-light flex-1" onclick="Tarifs.form()"><?= $svg('plus', 'w-4 h-4') ?> Nouveau tarif</button><button type="button" class="ds-btn flex-1" onclick="Tarifs.close()">Terminer</button></div>
        </div>
        <form id="tarifForm" class="hidden space-y-4" novalidate>
            <h3 id="tarifFormTitle" class="text-xl font-extrabold text-[#26343b]">Nouveau tarif</h3>
            <div><label class="ds-label" for="t-nom">Nom du tarif *</label><input id="t-nom" class="ds-input" maxlength="120" placeholder="Ex : Tarif normal, Tarif solidaire"></div>
            <div class="ds-row">
                <div><p class="ds-opt-title">Montant libre</p><p class="ds-opt-sub">Le parent choisit la somme (tarif solidaire, soutien…).</p></div>
                <label class="ds-switch"><input type="checkbox" id="t-libre" aria-label="Montant libre"><span></span></label>
            </div>
            <div><label class="ds-label" for="t-prix" id="t-prix-l">Prix (€)</label><input id="t-prix" type="number" min="0" step="0.01" class="ds-input" placeholder="0"></div>
            <div id="t-min-box" class="hidden"><label class="ds-label" for="t-min">Montant minimum (€)</label><input id="t-min" type="number" min="0" step="0.01" class="ds-input" placeholder="Vide : le prix ci-dessus sert de minimum"><p class="ds-hint">Le parent ne pourra pas payer moins que ce montant (contrôlé côté serveur).</p></div>
            <p id="tarifErr" class="text-sm font-semibold text-[#5b4a1c]" role="alert"></p>
            <div class="flex gap-3"><button type="button" class="ds-btn ds-btn-light flex-1" onclick="Tarifs.back()">Retour</button><button type="submit" class="ds-btn flex-1" id="tarifSave">Enregistrer</button></div>
        </form>
    </div>
</div>
<div id="toast" class="ds-toast" role="status"></div>

<script>
const IS_EDIT = <?= $edit ? 'true' : 'false' ?>;
const CAMP_ID = <?= $edit ? (int)$camp['id'] : 0 ?>;
const CHAMP_TYPES = <?= json_encode(CHAMPS_TYPES, JSON_UNESCAPED_UNICODE) ?>;
const ICON = { edit: <?= json_encode($svg('edit')) ?>, trash: <?= json_encode($svg('trash')) ?>, up: <?= json_encode($svg('up')) ?>, down: <?= json_encode($svg('down')) ?>, close: <?= json_encode($svg('close')) ?> };
let ORGAS = <?= json_encode(array_map(fn($o) => ['id' => (int)$o['id'], 'nom' => $o['nom'], 'email' => $o['email'] ?? '', 'tel' => $o['tel'] ?? '', 'web' => $o['web'] ?? ''], $organisateurs), JSON_UNESCAPED_UNICODE) ?>;
let SELECTED = <?= json_encode(array_map(fn($t) => (int)$t['id'], $currentTarifs ?? [])) ?>;
let TARIFS = <?= json_encode(array_map(fn($t) => ['id' => (int)$t['id'], 'nom' => $t['nom'], 'prix' => (float)$t['prix'], 'montant_libre' => (int)$t['montant_libre'], 'montant_min' => $t['montant_min'] ?? null, 'organisateur_id' => (int)($t['organisateur_id'] ?? 0)], $currentTarifs ?? []), JSON_UNESCAPED_UNICODE) ?>;
let CHAMPS = <?= json_encode(array_map(fn($c) => ['id' => $c['id'], 'libelle' => $c['libelle'], 'type' => $c['type'], 'options' => (string)$c['options'], 'obligatoire' => $c['obligatoire']], $champsActuels ?? []), JSON_UNESCAPED_UNICODE) ?>;
let ETAPES = <?= json_encode(array_map(fn($e) => ['jour' => (int)$e['jour'], 'lieu' => $e['lieu'], 'description' => (string)$e['description']], $etapesActuelles ?? []), JSON_UNESCAPED_UNICODE) ?>;
let TAGS = <?= json_encode($tagsInit, JSON_UNESCAPED_UNICODE) ?>;

const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
const eur = n => (Number(n) || 0).toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' €';
function toast(msg) { const t = $('toast'); t.textContent = msg; t.classList.add('show'); clearTimeout(toast.t); toast.t = setTimeout(() => t.classList.remove('show'), 2600); }
async function api(url, body) {
    const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    let d = {}; try { d = await r.json(); } catch (e) {}
    if (!r.ok) throw new Error(d.error || d.message || 'Une erreur est survenue.');
    return d;
}
function modal(id, open) {
    const m = $(id); m.classList.toggle('open', open);
    if (open) { const f = m.querySelector('input:not([type=hidden]),button'); setTimeout(() => f && f.focus(), 60); }
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.ds-modal.open').forEach(m => m.classList.remove('open')); });
document.querySelectorAll('.ds-modal').forEach(m => m.addEventListener('mousedown', e => { if (e.target === m) m.classList.remove('open'); }));

// ------------------------------------------------ mots-clés
function renderTags() {
    $('tagList').innerHTML = TAGS.map((t, i) => `<span class="ds-tag">${esc(t)}<button type="button" aria-label="Retirer ${esc(t)}" onclick="TAGS.splice(${i},1);renderTags()">×</button></span>`).join('');
    $('tagHidden').value = TAGS.join(', ');
}
function addTag() {
    const v = $('tagInput').value.trim();
    if (v) {
        const t = v.charAt(0).toUpperCase() + v.slice(1).toLowerCase();
        if (TAGS.length >= 15) toast('15 mots-clés maximum.'); else if (!TAGS.includes(t)) TAGS.push(t);
        $('tagInput').value = ''; renderTags();
    }
    $('tagInput').focus();
}
$('tagAdd').addEventListener('click', addTag);
$('tagInput').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addTag(); } });
renderTags();

// ------------------------------------------------ adresse (OpenStreetMap)
(function () {
    const inp = $('addressSearch'), box = $('addrResults'); let timer;
    inp.addEventListener('input', () => {
        clearTimeout(timer);
        if (inp.value.length < 4) { box.classList.add('hidden'); return; }
        timer = setTimeout(() => fetch('https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=5&q=' + encodeURIComponent(inp.value))
            .then(r => r.json()).then(list => {
                box.innerHTML = '';
                list.forEach(p => {
                    const b = document.createElement('button'); b.type = 'button'; b.textContent = p.display_name;
                    b.className = 'block w-full text-left px-4 py-3 text-sm hover:bg-[#f1f3f5] border-b border-[#f1f3f5] last:border-0';
                    b.onclick = () => {
                        const a = p.address || {};
                        inp.value = [a.house_number, a.road].filter(Boolean).join(' ') || p.display_name.split(',')[0];
                        $('villeInput').value = a.city || a.town || a.village || '';
                        $('cpInput').value = a.postcode || '';
                        const s = $('paysInput'); [...s.options].forEach((o, i) => { if (a.country && o.text.toLowerCase() === a.country.toLowerCase()) s.selectedIndex = i; });
                        box.classList.add('hidden');
                    };
                    box.appendChild(b);
                });
                box.classList.toggle('hidden', !list.length);
            }).catch(() => {}), 450);
    });
    document.addEventListener('click', e => { if (!box.contains(e.target) && e.target !== inp) box.classList.add('hidden'); });
})();

// ------------------------------------------------ bascules d'affichage
function refreshToggles() {
    const on = $('toggleOnline').checked;
    $('blockExternal').classList.toggle('hidden', on);
    $('blockInternal').classList.toggle('hidden', !on);
    $('tosBox').classList.toggle('hidden', !on || IS_EDIT);
    $('animBlock').classList.toggle('hidden', !$('toggleAnim').checked);
    $('quotaBlock').classList.toggle('hidden', !$('quotaToggle').checked);
    $('attenteJours').classList.toggle('opacity-40', !$('attenteToggle').checked);
    // séjour réservé à un sexe : pas de quota filles / garçons
    const reserve = document.querySelector('input[name=reserve_sexe]:checked').value;
    $('quotaWrap').classList.toggle('hidden', reserve !== '');
    if (reserve !== '') $('quotaToggle').checked = false;
}
['toggleOnline', 'toggleAnim', 'quotaToggle', 'attenteToggle'].forEach(i => $(i).addEventListener('change', refreshToggles));
document.querySelectorAll('input[name=reserve_sexe]').forEach(r => r.addEventListener('change', refreshToggles));
refreshToggles();

// ------------------------------------------------ organismes
const Orga = {
    editing: null,
    open() { this.back(); this.render(); modal('modalOrga', true); },
    close() { modal('modalOrga', false); },
    back() { $('orgaList').classList.remove('hidden'); $('orgaForm').classList.add('hidden'); },
    render() {
        const cur = $('orga-select').value;
        $('orgaItems').innerHTML = ORGAS.length ? ORGAS.map(o => `
            <div class="ds-row !py-3"><div class="min-w-0"><p class="ds-opt-title truncate">${esc(o.nom)}${String(o.id) === cur ? ' <span class="ds-badge ml-1">Choisi</span>' : ''}</p><p class="ds-opt-sub truncate">${esc(o.email)}${o.tel ? ' · ' + esc(o.tel) : ''}</p></div>
            <div class="flex items-center gap-1 flex-none"><button type="button" class="ds-btn ds-btn-light ds-btn-sm" onclick="Orga.pick(${o.id})">Choisir</button><button type="button" class="ds-iconbtn" aria-label="Modifier ${esc(o.nom)}" onclick="Orga.form(${o.id})">${ICON.edit}</button></div></div>`).join('')
            : '<p class="text-sm text-[#6b7780]">Aucun organisme pour le moment.</p>';
    },
    pick(id) { $('orga-select').value = id; $('orga-select').dispatchEvent(new Event('change')); this.close(); toast('Organisme sélectionné.'); },
    form(id) {
        this.editing = id || null;
        const o = ORGAS.find(x => x.id === id) || { nom: '', email: '', tel: '', web: '' };
        $('orgaFormTitle').textContent = id ? "Modifier l'organisme" : 'Nouvel organisme';
        $('o-nom').value = o.nom; $('o-mail').value = o.email; $('o-tel').value = o.tel; $('o-web').value = o.web; $('orgaErr').textContent = '';
        $('orgaList').classList.add('hidden'); $('orgaForm').classList.remove('hidden'); $('o-nom').focus();
    }
};
$('orgaForm').addEventListener('submit', async e => {
    e.preventDefault();
    const b = $('orgaSave'); b.disabled = true; $('orgaErr').textContent = '';
    const body = { nom: $('o-nom').value.trim(), email: $('o-mail').value.trim(), tel: $('o-tel').value.trim(), web: $('o-web').value.trim() };
    try {
        if (!body.nom || !body.email) throw new Error('Le nom et l\'email sont obligatoires.');
        let o;
        if (Orga.editing) o = (await api('api/gerer_orga_tarifs.php', { action: 'orga_update', id: Orga.editing, ...body })).organisme;
        else { const d = await api('api/create_organisateur.php', body); o = { id: parseInt(d.id), nom: body.nom, email: body.email, tel: body.tel, web: body.web }; }
        o.id = parseInt(o.id);
        const i = ORGAS.findIndex(x => x.id === o.id);
        if (i >= 0) ORGAS[i] = o; else ORGAS.push(o);
        const sel = $('orga-select'), opt = [...sel.options].find(x => x.value == o.id);
        if (opt) opt.textContent = o.nom; else sel.add(new Option(o.nom, o.id));
        if (!Orga.editing) { sel.value = o.id; sel.dispatchEvent(new Event('change')); }
        toast(Orga.editing ? 'Organisme mis à jour.' : 'Organisme créé et sélectionné.');
        Orga.back(); Orga.render();
    } catch (err) { $('orgaErr').textContent = err.message; }
    b.disabled = false;
});

// ------------------------------------------------ tarifs
const Tarifs = {
    editing: null,
    async load() {
        const id = $('orga-select').value;
        if (!id) { TARIFS = TARIFS.filter(t => SELECTED.includes(t.id)); return; }
        try {
            const r = await fetch('api/get_tarifs_by_organisateur.php?organisateur_id=' + encodeURIComponent(id));
            const list = await r.json();
            if (Array.isArray(list)) {
                // on garde aussi les tarifs déjà retenus (autre organisme possible)
                const keep = TARIFS.filter(t => SELECTED.includes(t.id) && !list.some(l => parseInt(l.id) === t.id));
                TARIFS = list.map(t => ({ id: parseInt(t.id), nom: t.nom, prix: parseFloat(t.prix), montant_libre: parseInt(t.montant_libre), montant_min: t.montant_min, organisateur_id: parseInt(t.organisateur_id) })).concat(keep);
            }
        } catch (e) {}
    },
    async open() {
        if (!$('orga-select').value) { toast("Choisissez d'abord l'organisme."); $('orga-select').focus(); return; }
        await this.load(); this.back(); this.render(); modal('modalTarifs', true);
    },
    close() { modal('modalTarifs', false); },
    back() { $('tarifsList').classList.remove('hidden'); $('tarifForm').classList.add('hidden'); },
    label(t) { return t.montant_libre ? (parseFloat(t.montant_min) > 0 ? 'Libre, minimum ' + eur(t.montant_min) : 'Montant libre') : eur(t.prix); },
    render() {
        $('tarifsItems').innerHTML = TARIFS.length ? TARIFS.map(t => `
            <div class="ds-row !py-3"><label class="flex items-center gap-3 min-w-0 cursor-pointer flex-1"><input type="checkbox" class="w-5 h-5 accent-[#2f3d45] flex-none" ${SELECTED.includes(t.id) ? 'checked' : ''} onchange="Tarifs.toggle(${t.id}, this.checked)"><span class="min-w-0"><span class="ds-opt-title block truncate">${esc(t.nom)}</span><span class="ds-opt-sub block">${this.label(t)}</span></span></label>
            <div class="flex gap-1 flex-none"><button type="button" class="ds-iconbtn" aria-label="Modifier ${esc(t.nom)}" onclick="Tarifs.form(${t.id})">${ICON.edit}</button><button type="button" class="ds-iconbtn danger" aria-label="Supprimer ${esc(t.nom)}" onclick="Tarifs.remove(${t.id})">${ICON.trash}</button></div></div>`).join('')
            : '<p class="text-sm text-[#6b7780]">Aucun tarif pour cet organisme. Créez le premier.</p>';
    },
    toggle(id, on) { SELECTED = on ? [...new Set([...SELECTED, id])] : SELECTED.filter(x => x !== id); renderSelectedTarifs(); },
    form(id) {
        this.editing = id || null;
        const t = TARIFS.find(x => x.id === id) || { nom: '', prix: '', montant_libre: 0, montant_min: '' };
        $('tarifFormTitle').textContent = id ? 'Modifier le tarif' : 'Nouveau tarif';
        $('t-nom').value = t.nom; $('t-prix').value = t.prix === '' ? '' : t.prix; $('t-libre').checked = !!t.montant_libre; $('t-min').value = t.montant_min || ''; $('tarifErr').textContent = '';
        this.libre(); $('tarifsList').classList.add('hidden'); $('tarifForm').classList.remove('hidden'); $('t-nom').focus();
    },
    libre() { const l = $('t-libre').checked; $('t-min-box').classList.toggle('hidden', !l); $('t-prix-l').textContent = l ? 'Montant suggéré (€)' : 'Prix (€)'; },
    async remove(id) {
        const t = TARIFS.find(x => x.id === id);
        if (!confirm('Supprimer définitivement le tarif « ' + t.nom + ' » ?')) return;
        try {
            await api('api/gerer_orga_tarifs.php', { action: 'tarif_delete', id });
            TARIFS = TARIFS.filter(x => x.id !== id); SELECTED = SELECTED.filter(x => x !== id);
            this.render(); renderSelectedTarifs(); toast('Tarif supprimé.');
        } catch (e) { toast(e.message); }
    }
};
$('t-libre').addEventListener('change', () => Tarifs.libre());
$('tarifForm').addEventListener('submit', async e => {
    e.preventDefault();
    const b = $('tarifSave'); b.disabled = true; $('tarifErr').textContent = '';
    const libre = $('t-libre').checked ? 1 : 0;
    const body = { nom: $('t-nom').value.trim(), prix: $('t-prix').value || 0, montant_libre: libre, montant_min: libre ? ($('t-min').value || 0) : 0 };
    try {
        if (!body.nom) throw new Error('Donnez un nom au tarif.');
        let t;
        if (Tarifs.editing) t = (await api('api/gerer_orga_tarifs.php', { action: 'tarif_update', id: Tarifs.editing, ...body })).tarif;
        else { const d = await api('api/create_tarif.php', { ...body, organisateur_id: $('orga-select').value }); t = { id: d.id, nom: body.nom, prix: body.prix, montant_libre: libre, montant_min: d.montant_min, organisateur_id: d.organisateur_id }; }
        t = { id: parseInt(t.id), nom: t.nom, prix: parseFloat(t.prix) || 0, montant_libre: parseInt(t.montant_libre), montant_min: t.montant_min, organisateur_id: parseInt(t.organisateur_id) };
        const i = TARIFS.findIndex(x => x.id === t.id);
        if (i >= 0) TARIFS[i] = t; else { TARIFS.push(t); SELECTED.push(t.id); }
        renderSelectedTarifs(); Tarifs.back(); Tarifs.render(); toast(Tarifs.editing ? 'Tarif mis à jour.' : 'Tarif créé et ajouté au séjour.');
    } catch (err) { $('tarifErr').textContent = err.message; }
    b.disabled = false;
});
function renderSelectedTarifs() {
    const list = SELECTED.map(id => TARIFS.find(t => t.id === id)).filter(Boolean);
    $('tarifsSelected').innerHTML = list.length ? list.map(t => `
        <div class="ds-row !py-3"><div class="min-w-0"><p class="ds-opt-title truncate">${esc(t.nom)}</p><p class="ds-opt-sub">${Tarifs.label(t)}</p></div>
        <button type="button" class="ds-iconbtn" aria-label="Retirer ${esc(t.nom)}" onclick="Tarifs.toggle(${t.id}, false)">${ICON.close}</button></div>`).join('')
        : '<p class="ds-hint !mt-0">Aucun tarif pour le moment : ajoutez-en au moins un.</p>';
    $('tarifsHidden').value = JSON.stringify(SELECTED);
}
$('orga-select').addEventListener('change', async () => {
    await Tarifs.load();
    SELECTED = SELECTED.filter(id => TARIFS.some(t => t.id === id && t.organisateur_id === parseInt($('orga-select').value)));
    renderSelectedTarifs();
});
renderSelectedTarifs();

// ------------------------------------------------ questions personnalisées
const Champs = {
    add() { CHAMPS.push({ id: 0, libelle: '', type: 'texte', options: '', obligatoire: 0 }); this.render(); },
    move(i, d) { const j = i + d; if (j < 0 || j >= CHAMPS.length) return; [CHAMPS[i], CHAMPS[j]] = [CHAMPS[j], CHAMPS[i]]; this.render(); },
    render() {
        $('champsList').innerHTML = CHAMPS.length ? CHAMPS.map((c, i) => `
            <div class="border border-[#e5e7eb] rounded-2xl p-4 bg-[#fafbfb]">
              <div class="grid md:grid-cols-12 gap-3 items-end">
                <div class="md:col-span-6"><label class="ds-label">Question</label><input class="ds-input" maxlength="255" value="${esc(c.libelle)}" oninput="CHAMPS[${i}].libelle=this.value" placeholder="Ex : Sait-il nager ?"></div>
                <div class="md:col-span-3"><label class="ds-label">Type de réponse</label><select class="ds-select" onchange="CHAMPS[${i}].type=this.value;Champs.render()">${Object.entries(CHAMP_TYPES).map(([k, v]) => `<option value="${k}" ${c.type === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></div>
                <label class="md:col-span-1 flex items-center gap-2 text-sm font-semibold pb-3"><input type="checkbox" class="w-4 h-4 accent-[#2f3d45]" ${c.obligatoire ? 'checked' : ''} onchange="CHAMPS[${i}].obligatoire=this.checked?1:0"> Requise</label>
                <div class="md:col-span-2 flex justify-end gap-1 pb-1"><button type="button" class="ds-iconbtn" aria-label="Monter" onclick="Champs.move(${i},-1)">${ICON.up}</button><button type="button" class="ds-iconbtn" aria-label="Descendre" onclick="Champs.move(${i},1)">${ICON.down}</button><button type="button" class="ds-iconbtn danger" aria-label="Supprimer" onclick="CHAMPS.splice(${i},1);Champs.render()">${ICON.trash}</button></div>
              </div>
              ${c.type === 'liste' ? `<div class="mt-3"><label class="ds-label">Choix proposés (un par ligne)</label><textarea rows="3" class="ds-textarea" oninput="CHAMPS[${i}].options=this.value" placeholder="Débutant&#10;Confirmé">${esc(c.options)}</textarea></div>` : ''}
            </div>`).join('') : '<p class="ds-hint !mt-0">Aucune question pour le moment : le formulaire se limite à la fiche enfant.</p>';
    }
};
Champs.render();

// ------------------------------------------------ étapes du parcours (séjour itinérant)
const Etapes = {
    add() { const last = ETAPES[ETAPES.length - 1]; ETAPES.push({ jour: last ? last.jour + 1 : 1, lieu: '', description: '' }); this.render(); },
    render() {
        $('etapesList').innerHTML = ETAPES.length ? ETAPES.map((e, i) => `
            <div class="border border-[#e5e7eb] rounded-2xl p-4 bg-[#fafbfb]"><div class="grid grid-cols-12 gap-3 items-end">
              <div class="col-span-3 sm:col-span-2"><label class="ds-label">Jour</label><input type="number" min="1" class="ds-input" value="${e.jour}" oninput="ETAPES[${i}].jour=+this.value"></div>
              <div class="col-span-9 sm:col-span-9"><label class="ds-label">Lieu de couchage / ville</label><input class="ds-input" maxlength="160" value="${esc(e.lieu)}" oninput="ETAPES[${i}].lieu=this.value" placeholder="Ex : Gîte de Saint-Flour"></div>
              <div class="col-span-12 sm:col-span-1 flex justify-end"><button type="button" class="ds-iconbtn danger" aria-label="Supprimer l'étape" onclick="ETAPES.splice(${i},1);Etapes.render()">${ICON.trash}</button></div>
              <div class="col-span-12"><label class="ds-label">Note sur le trajet</label><textarea rows="2" maxlength="1000" class="ds-textarea" oninput="ETAPES[${i}].description=this.value" placeholder="Ex : 45 km à vélo, pique-nique au bord du lac">${esc(e.description)}</textarea></div>
            </div></div>`).join('') : '<p class="ds-hint !mt-0">Aucune étape pour le moment.</p>';
    }
};
document.querySelector('input[name=itinerant]').addEventListener('change', e => { $('etapesBox').classList.toggle('hidden', !e.target.checked); if (e.target.checked && !ETAPES.length) Etapes.add(); });
Etapes.render();

// ------------------------------------------------ envoi
$('campForm').addEventListener('submit', async e => {
    e.preventDefault();
    const msg = $('formMsg'), btn = $('submitBtn'); msg.textContent = '';
    const form = $('campForm');
    // champs visibles invalides : on les montre
    const bad = [...form.querySelectorAll('[required]')].find(el => el.offsetParent !== null && !el.checkValidity());
    if (bad) { bad.scrollIntoView({ block: 'center', behavior: 'smooth' }); bad.reportValidity(); return; }
    if ($('toggleOnline').checked && !IS_EDIT && !$('acceptToS').checked) { msg.textContent = 'Acceptez les conditions de commission pour ouvrir les inscriptions en ligne.'; $('tosBox').scrollIntoView({ block: 'center' }); return; }
    if ($('toggleOnline').checked && !SELECTED.length) { msg.textContent = 'Ajoutez au moins un tarif pour ouvrir les inscriptions en ligne.'; $('tarifsSelected').scrollIntoView({ block: 'center' }); return; }
    btn.disabled = true; const label = btn.textContent; btn.textContent = 'Enregistrement…';
    try {
        const fd = new FormData(form);
        fd.set('type', TAGS.join(', ')); fd.set('tarifs', JSON.stringify(SELECTED)); fd.set('etapes', JSON.stringify(ETAPES.filter(x => x.lieu.trim() !== '')));
        const r = await fetch(IS_EDIT ? 'api/update_camp.php' : 'api/add_camp.php', { method: 'POST', body: fd });
        let d = {}; try { d = await r.json(); } catch (x) {}
        if (!r.ok || !d.success) throw new Error(d.message || 'Enregistrement impossible.');
        const cid = IS_EDIT ? CAMP_ID : d.camp_id;
        const questions = CHAMPS.filter(c => c.libelle.trim() !== '');
        if (cid && (questions.length || IS_EDIT)) await api('api/save_champs.php', { camp_id: cid, champs: questions });
        location.href = 'mes_camps.php?ok=' + (IS_EDIT ? 'updated' : 'created');
    } catch (err) {
        msg.textContent = err.message; btn.disabled = false; btn.textContent = label;
    }
});
</script>

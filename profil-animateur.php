<?php
// profil-animateur.php : profil de candidature de l'animateur (présentation, BAFA / formation, expériences, disponibilités, CV).
$body_class = 'bg-white';
require_once 'partials/header.php';
if (empty($_SESSION['user']['is_animateur'])) { header('Location: login.php?redirect=' . urlencode('profil-animateur.php')); exit; }
$back = (isset($_GET['retour']) && preg_match('/^[a-z0-9_.\-?=&]{1,200}$/i', $_GET['retour'])) ? $_GET['retour'] : '';
?>
<title>Mon profil d'animateur - ColoMap</title>
<main class="ds-page" style="max-width:760px">
    <p class="ds-eyebrow">Espace animateur</p>
    <h1 class="ds-h1">Mon profil de candidature</h1>
    <p class="ds-lead">Ces informations sont transmises aux directeurs des séjours auxquels vous postulez. Rien n'est visible publiquement.</p>

    <?php $u = $_SESSION['user']; ?>
    <section class="ds-card mt-8 p-5 flex flex-wrap items-center gap-4" aria-label="Identité et contact">
        <?php if (!empty($u['photo_url'])): ?><img src="<?= htmlspecialchars($u['photo_url']) ?>" alt="" class="w-16 h-16 rounded-full object-cover">
        <?php else: ?><span class="w-16 h-16 rounded-full bg-[#3a4852] text-white text-[24px] font-extrabold flex items-center justify-center"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)($u['prenom'] ?? 'A'), 0, 1))) ?></span><?php endif; ?>
        <div class="min-w-0 flex-1"><p class="font-extrabold text-[#26343b]"><?= htmlspecialchars(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''))) ?></p>
            <p class="text-[14px] text-[#6b7780] truncate"><?= htmlspecialchars((string)($u['email'] ?? '')) ?></p></div>
        <a href="edit_profile" class="ds-btn ds-btn-light">Modifier ma photo, mon identité et mes coordonnées</a>
    </section>

    <form id="f" class="ds-card mt-6 p-6 sm:p-8 space-y-6" enctype="multipart/form-data" novalidate>
        <div>
            <label class="ds-label" for="presentation">Présentation</label>
            <textarea id="presentation" name="presentation" rows="5" maxlength="2000" class="ds-textarea" placeholder="Qui êtes-vous, pourquoi animer, votre approche avec les enfants…"></textarea>
        </div>
        <div>
            <span class="ds-label">Diplôme d'animation</span>
            <div id="statuts" class="flex flex-wrap gap-2"></div>
            <input type="hidden" name="bafa_statut" id="bafa_statut" value="aucun">
        </div>
        <div id="bafa-extra" class="grid sm:grid-cols-2 gap-4 hidden">
            <div><label class="ds-label" for="bafa_numero">Numéro de BAFA (si obtenu)</label><input id="bafa_numero" name="bafa_numero" maxlength="40" class="ds-input"></div>
            <div><label class="ds-label" for="formation">Formation en cours ou équivalence</label><input id="formation" name="formation" maxlength="255" class="ds-input" placeholder="Ex. session d'approfondissement en juillet, BPJEPS, STAPS…"></div>
        </div>
        <div>
            <label class="ds-label" for="experiences">Expériences</label>
            <textarea id="experiences" name="experiences" rows="4" maxlength="3000" class="ds-textarea" placeholder="Séjours, centres de loisirs, baby-sitting, associations…"></textarea>
        </div>
        <div>
            <label class="ds-label" for="disponibilites">Disponibilités</label>
            <textarea id="disponibilites" name="disponibilites" rows="2" maxlength="1000" class="ds-textarea" placeholder="Ex. juillet complet, 2 premières semaines d'août…"></textarea>
        </div>
        <div>
            <label class="ds-label" for="cv">CV (PDF, DOC ou DOCX, 5 Mo maximum)</label>
            <div id="cv-cur" class="mb-2 text-[14px] text-[#3b4a54] hidden"></div>
            <input type="file" id="cv" name="cv" accept=".pdf,.doc,.docx" class="ds-file">
            <p class="mt-2 text-[12px] text-[#6b7780]">Votre CV n'est accessible qu'aux directeurs des séjours auxquels vous postulez.</p>
        </div>
        <div id="msg" class="hidden"></div>
        <div class="flex flex-wrap gap-3">
            <button type="submit" class="ds-btn" id="save">Enregistrer</button>
            <?php if ($back): ?><a class="ds-btn ds-btn-light" href="<?= htmlspecialchars($back) ?>">Retour à ma candidature</a><?php endif; ?>
        </div>
    </form>
</main>
<script src="assets/js/anim.js?v=3"></script>
<script>
(function () {
    var A = window.ANIM, $ = function (i) { return document.getElementById(i); };
    function setStatut(v) {
        $('bafa_statut').value = v;
        [].forEach.call($('statuts').children, function (b) { b.style.background = b.dataset.v === v ? '#26343b' : '#fff'; b.style.color = b.dataset.v === v ? '#fff' : '#3b4a54'; b.setAttribute('aria-pressed', b.dataset.v === v); });
        $('bafa-extra').classList.toggle('hidden', v === 'aucun');
    }
    Object.keys(A.BAFA).forEach(function (k) {
        var b = document.createElement('button'); b.type = 'button'; b.className = 'ds-chip'; b.dataset.v = k; b.textContent = A.BAFA[k];
        b.addEventListener('click', function () { setStatut(k); }); $('statuts').appendChild(b);
    });
    function cv(p) {
        var c = $('cv-cur');
        if (p.has_cv) { c.classList.remove('hidden'); c.innerHTML = A.icon('file', 'w-4 h-4 inline mr-1') + '<a class="underline" target="_blank" rel="noopener" href="api/anim_cv.php">' + A.esc(p.cv_nom || 'Mon CV') + '</a> &nbsp;<label class="text-[13px] text-[#6b7780]"><input type="checkbox" id="del-cv"> supprimer</label>'; }
        else c.classList.add('hidden');
    }
    A.api('api/anim_animateur.php?a=profil').then(function (p) {
        ['presentation', 'bafa_numero', 'formation', 'experiences', 'disponibilites'].forEach(function (k) { $(k).value = p[k] || ''; });
        setStatut(p.bafa_statut || 'aucun'); cv(p);
    }).catch(function (e) { A.toast(e.message, true); });
    $('f').addEventListener('submit', async function (ev) {
        ev.preventDefault();
        var fd = new FormData($('f')); if ($('del-cv') && $('del-cv').checked) fd.append('supprimer_cv', '1');
        $('save').disabled = true;
        try {
            var r = await A.api('api/anim_animateur.php?a=profil', { method: 'POST', body: fd });
            var p = await A.api('api/anim_animateur.php?a=profil'); cv(p); $('cv').value = '';
            var m = $('msg'); m.className = r.complet ? 'ds-alert-ok' : 'ds-alert-err';
            m.textContent = r.complet ? 'Profil enregistré : vous pouvez postuler.' : 'Profil enregistré, mais incomplet : ajoutez une présentation et, si vous avez un BAFA ou une formation, son numéro ou son intitulé.';
        } catch (e) { var m2 = $('msg'); m2.className = 'ds-alert-err'; m2.textContent = e.message; }
        $('save').disabled = false;
    });
})();
</script>
<?php require_once 'partials/footer.php'; ?>

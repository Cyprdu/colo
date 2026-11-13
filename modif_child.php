<?php
/**
 * Page : modif_child.php
 * Objectif : Permettre au parent de modifier TOUTES les informations de son enfant
 */

require_once 'api/config.php';
require_once 'api/enfants.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$token = (string)($_GET['id'] ?? '');
$parent_id = (int)$_SESSION['user']['id'];
$message = '';
$message_type = ''; // 'success' ou 'error'

// Anciens liens à id numérique : convertis en jeton (uniquement si l'enfant est bien à ce parent)
if (ctype_digit($token)) {
    $j = '';
    $c = $pdo->prepare("SELECT id FROM enfants WHERE id = ? AND parent_id = ?");
    $c->execute([(int)$token, $parent_id]);
    if ($c->fetchColumn()) $j = enfant_jeton($pdo, (int)$token);
    header('Location: ' . ($j ? 'modif_child?id=' . $j : 'children'));
    exit;
}
$enfant = enfant_par_jeton($pdo, $token, $parent_id);
if (!$enfant) { header('Location: children'); exit; }
$child_id = (int)$enfant['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Liste blanche : jamais de nom de colonne venant du formulaire
    $textes = ['prenom','nom','date_naissance','sexe','civilite','adresse','code_postal','ville','pays',
        'tel_mobile_enfant','tel_fixe_enfant','email_enfant','taille','poids','infos_sante','allergies','regime_alimentaire',
        'medecin_nom','medecin_tel',
        'resp1_civilite','resp1_nom','resp1_prenom','resp1_email','resp1_tel','resp1_statut','resp1_profession',
        'resp2_civilite','resp2_nom','resp2_prenom','resp2_email','resp2_tel','resp2_statut','resp2_profession','commentaires'];
    $cases = ['droit_image','autorisation_contact','accord_parental','cgv_accepte','newsletter_accepte'];

    $vals = [];
    foreach ($textes as $k) $vals[$k] = trim($_POST[$k] ?? '');
    foreach ($cases as $k) $vals[$k] = isset($_POST[$k]) ? 1 : 0;
    $vals['vaccins_data'] = json_encode([
        'dtp' => trim($_POST['vaccin_dtp'] ?? ''), 'bcg' => trim($_POST['vaccin_bcg'] ?? ''), 'autre' => trim($_POST['vaccin_autre'] ?? '')
    ]);

    if ($vals['prenom'] === '' || $vals['nom'] === '' || $vals['date_naissance'] === '') {
        $message = "Les champs Prénom, Nom et Date de naissance sont obligatoires.";
        $message_type = 'error';
    } else {
        try {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($vals)));
            $stmt = $pdo->prepare("UPDATE enfants SET $set WHERE id = ? AND parent_id = ?");
            $stmt->execute([...array_values($vals), $child_id, $parent_id]);
            $message = "Les informations ont été mises à jour avec succès.";
            $message_type = 'success';
        } catch (Exception $e) {
            error_log('modif_child: ' . $e->getMessage());
            $message = "Erreur lors de la mise à jour. Réessayez dans un instant.";
            $message_type = 'error';
        }
    }
}

// Données fraîches (après un enregistrement)
$st = $pdo->prepare("SELECT * FROM enfants WHERE id = ? AND parent_id = ?");
$st->execute([$child_id, $parent_id]);
$enfant = $st->fetch(PDO::FETCH_ASSOC) ?: $enfant;

$e = fn($k) => htmlspecialchars((string)($enfant[$k] ?? ''));
$vac = json_decode($enfant['vaccins_data'] ?? '', true) ?: [];
$in = function ($k, $label, $type = 'text', $ph = '') use ($e) {
    echo '<div><label class="ds-label" for="f-' . $k . '">' . $label . '</label><input id="f-' . $k . '" type="' . $type . '" name="' . $k . '" value="' . $e($k) . '" class="ds-input"' . ($ph ? ' placeholder="' . $ph . '"' : '') . '></div>';
};
$civ = function ($k, $label) use ($enfant) {
    echo '<div><label class="ds-label" for="f-' . $k . '">' . $label . '</label><select id="f-' . $k . '" name="' . $k . '" class="ds-select"><option value="">—</option>';
    foreach (['M.', 'Mme'] as $o) echo '<option' . (($enfant[$k] ?? '') === $o ? ' selected' : '') . '>' . $o . '</option>';
    echo '</select></div>';
};
$chk = function ($k, $label, $hint = '') use ($enfant) {
    echo '<label class="flex items-start gap-3 cursor-pointer"><input type="checkbox" name="' . $k . '" value="1" class="mt-1 w-4 h-4"' . (!empty($enfant[$k]) ? ' checked' : '') . '><span><span class="block text-[14px] font-semibold text-[#26343b]">' . $label . '</span>' . ($hint ? '<span class="block text-[12.5px] text-[#6b7780]">' . $hint . '</span>' : '') . '</span></label>';
};

$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Modifier la fiche de <?= htmlspecialchars($enfant['prenom']) ?> - ColoMap</title>

<main class="ds-page" style="max-width:860px">
    <a href="children" class="inline-flex items-center gap-2 text-[14px] font-semibold text-[#3b4a54] hover:text-[#26343b]">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12H4m6-6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Mes enfants
    </a>

    <div class="mt-5 flex items-center gap-4">
        <span class="w-14 h-14 flex-none rounded-full bg-[#3a4852] text-white text-[22px] font-bold flex items-center justify-center"><?= htmlspecialchars(mb_strtoupper(mb_substr($enfant['prenom'], 0, 1))) ?></span>
        <div>
            <h1 class="ds-h1">Fiche de <?= htmlspecialchars($enfant['prenom']) ?></h1>
            <p class="ds-lead" style="margin-top:2px">Ces informations sont transmises aux organisateurs des séjours auxquels l'enfant est inscrit.</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="<?= $message_type === 'success' ? 'ds-alert-ok' : 'ds-alert-err' ?> mt-6 flex items-start gap-3" role="<?= $message_type === 'success' ? 'status' : 'alert' ?>">
            <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> mt-0.5"></i>
            <p class="font-semibold"><?= htmlspecialchars($message) ?></p>
        </div>
    <?php endif; ?>

    <form method="POST" class="mt-8 space-y-6">

        <section class="ds-card p-6 sm:p-8" aria-labelledby="h-general">
            <h2 id="h-general" class="ds-eyebrow mb-5">Identité</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <?php $civ('civilite', 'Civilité'); ?>
                <div>
                    <label class="ds-label" for="f-sexe">Sexe</label>
                    <select id="f-sexe" name="sexe" class="ds-select">
                        <option value="Homme" <?= $enfant['sexe'] === 'Homme' ? 'selected' : '' ?>>Garçon</option>
                        <option value="Femme" <?= $enfant['sexe'] === 'Femme' ? 'selected' : '' ?>>Fille</option>
                        <option value="Autre" <?= $enfant['sexe'] === 'Autre' ? 'selected' : '' ?>>Autre</option>
                    </select>
                </div>
                <?php $in('prenom', 'Prénom'); $in('nom', 'Nom'); $in('date_naissance', 'Date de naissance', 'date'); ?>
            </div>
        </section>

        <section class="ds-card p-6 sm:p-8" aria-labelledby="h-coord">
            <h2 id="h-coord" class="ds-eyebrow mb-5">Coordonnées de l'enfant</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div class="sm:col-span-2"><?php $in('adresse', 'Adresse'); ?></div>
                <?php $in('code_postal', 'Code postal'); $in('ville', 'Ville'); $in('pays', 'Pays'); $in('email_enfant', 'E-mail', 'email'); $in('tel_mobile_enfant', 'Mobile', 'tel'); $in('tel_fixe_enfant', 'Téléphone fixe', 'tel'); ?>
            </div>
        </section>

        <section class="ds-card p-6 sm:p-8" aria-labelledby="h-sante">
            <h2 id="h-sante" class="ds-eyebrow mb-5">Santé et informations physiques</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <?php $in('taille', 'Taille (cm)', 'number', 'ex : 145'); $in('poids', 'Poids (kg)', 'text', 'ex : 35'); ?>
            </div>
            <div class="mt-5 space-y-5">
                <div><label class="ds-label" for="f-infos_sante">Problèmes de santé / traitements</label><textarea id="f-infos_sante" name="infos_sante" rows="3" class="ds-textarea" placeholder="Rien à signaler…"><?= $e('infos_sante') ?></textarea></div>
                <div><label class="ds-label" for="f-allergies">Allergies</label><textarea id="f-allergies" name="allergies" rows="2" class="ds-textarea" placeholder="Aucune…"><?= $e('allergies') ?></textarea></div>
                <?php $in('regime_alimentaire', 'Régime alimentaire'); ?>
            </div>
            <div class="mt-5 grid grid-cols-1 sm:grid-cols-3 gap-5">
                <div><label class="ds-label" for="f-vaccin_dtp">Vaccin DTP (date)</label><input id="f-vaccin_dtp" name="vaccin_dtp" class="ds-input" value="<?= htmlspecialchars($vac['dtp'] ?? '') ?>"></div>
                <div><label class="ds-label" for="f-vaccin_bcg">Vaccin BCG (date)</label><input id="f-vaccin_bcg" name="vaccin_bcg" class="ds-input" value="<?= htmlspecialchars($vac['bcg'] ?? '') ?>"></div>
                <div><label class="ds-label" for="f-vaccin_autre">Autres vaccins</label><input id="f-vaccin_autre" name="vaccin_autre" class="ds-input" value="<?= htmlspecialchars($vac['autre'] ?? '') ?>"></div>
            </div>
        </section>

        <section class="ds-card p-6 sm:p-8" aria-labelledby="h-medecin">
            <h2 id="h-medecin" class="ds-eyebrow mb-5">Médecin traitant</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5"><?php $in('medecin_nom', 'Nom du médecin'); $in('medecin_tel', 'Téléphone du médecin', 'tel'); ?></div>
        </section>

        <?php foreach ([1 => 'Responsable légal n°1', 2 => 'Responsable légal n°2'] as $n => $titre): ?>
        <section class="ds-card p-6 sm:p-8" aria-labelledby="h-resp<?= $n ?>">
            <h2 id="h-resp<?= $n ?>" class="ds-eyebrow mb-5"><?= $titre ?></h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <?php $civ("resp{$n}_civilite", 'Civilité'); $in("resp{$n}_statut", "Lien avec l'enfant", 'text', 'Père, mère, tuteur…');
                $in("resp{$n}_nom", 'Nom'); $in("resp{$n}_prenom", 'Prénom'); $in("resp{$n}_email", 'E-mail', 'email'); $in("resp{$n}_tel", 'Téléphone', 'tel'); $in("resp{$n}_profession", 'Profession'); ?>
            </div>
        </section>
        <?php endforeach; ?>

        <section class="ds-card p-6 sm:p-8" aria-labelledby="h-autor">
            <h2 id="h-autor" class="ds-eyebrow mb-5">Autorisations et préférences</h2>
            <div class="space-y-4">
                <?php $chk('droit_image', "Droit à l'image", "Photos et vidéos du séjour utilisables par l'organisateur.");
                $chk('autorisation_contact', 'Autorisation de contact', "L'organisateur peut joindre l'enfant directement.");
                $chk('accord_parental', 'Accord parental', "J'autorise mon enfant à participer aux séjours.");
                $chk('cgv_accepte', 'Conditions générales acceptées');
                $chk('newsletter_accepte', 'Newsletter', 'Recevoir les actualités et nouveaux séjours par e-mail.'); ?>
            </div>
            <div class="mt-5"><label class="ds-label" for="f-commentaires">Commentaires</label><textarea id="f-commentaires" name="commentaires" rows="3" class="ds-textarea"><?= $e('commentaires') ?></textarea></div>
        </section>

        <div class="flex flex-wrap justify-end gap-3 pt-2">
            <a href="children" class="ds-btn ds-btn-light">Annuler</a>
            <button type="submit" class="ds-btn">Enregistrer les modifications</button>
        </div>
    </form>
</main>

<?php require_once 'partials/footer.php'; ?>

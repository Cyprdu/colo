<?php
/**
 * Page : modif_child.php
 * Objectif : Permettre au parent de modifier les informations de son enfant
 */

require_once 'api/config.php';

// 1. Sécurité : Vérification de connexion
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$child_id = $_GET['id'] ?? null;
$parent_id = $_SESSION['user']['id'];
$message = '';
$message_type = ''; // 'success' ou 'error'

if (!$child_id) {
    header('Location: reservations.php');
    exit;
}

// 2. Traitement du formulaire (Mise à jour)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupération et nettoyage des données
    $prenom = trim($_POST['prenom'] ?? '');
    $nom = trim($_POST['nom'] ?? '');
    $date_naissance = $_POST['date_naissance'] ?? '';
    $sexe = $_POST['sexe'] ?? '';
    
    $taille = trim($_POST['taille'] ?? '');
    $poids = trim($_POST['poids'] ?? '');
    $infos_sante = trim($_POST['infos_sante'] ?? '');
    $allergies = trim($_POST['allergies'] ?? '');
    $regime = trim($_POST['regime_alimentaire'] ?? '');
    
    $medecin_nom = trim($_POST['medecin_nom'] ?? '');
    $medecin_tel = trim($_POST['medecin_tel'] ?? '');

    // Validation basique
    if (empty($prenom) || empty($nom) || empty($date_naissance)) {
        $message = "Les champs Prénom, Nom et Date de naissance sont obligatoires.";
        $message_type = 'error';
    } else {
        try {
            // Requête de mise à jour sécurisée
            $sql = "UPDATE enfants SET 
                    prenom = ?, nom = ?, date_naissance = ?, sexe = ?,
                    taille = ?, poids = ?, infos_sante = ?, allergies = ?, regime_alimentaire = ?,
                    medecin_nom = ?, medecin_tel = ?
                    WHERE id = ? AND parent_id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $prenom, $nom, $date_naissance, $sexe,
                $taille, $poids, $infos_sante, $allergies, $regime,
                $medecin_nom, $medecin_tel,
                $child_id, $parent_id
            ]);

            $message = "Les informations ont été mises à jour avec succès.";
            $message_type = 'success';
            
            // On peut rediriger vers la page précédente si on veut, ou rester ici
            // header("Location: info_inscrit.php?child_id=$child_id..."); 

        } catch (Exception $e) {
            $message = "Erreur lors de la mise à jour : " . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// 3. Récupération des données actuelles de l'enfant
try {
    $stmt = $pdo->prepare("SELECT * FROM enfants WHERE id = ? AND parent_id = ?");
    $stmt->execute([$child_id, $parent_id]);
    $enfant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$enfant) {
        die('<div class="container mx-auto py-12 text-center text-red-600 font-bold">Enfant introuvable ou accès refusé.</div>');
    }
} catch (Exception $e) {
    die("Erreur de chargement.");
}

$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Modifier la fiche de <?= htmlspecialchars($enfant['prenom']) ?> - ColoMap</title>

<main class="ds-page" style="max-width:860px">
    <a href="javascript:history.back()" class="inline-flex items-center gap-2 text-[14px] font-semibold text-[#3b4a54] hover:text-[#26343b]">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12H4m6-6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Retour
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
            <h2 id="h-general" class="ds-eyebrow mb-5">Informations générales</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div><label class="ds-label" for="f-prenom">Prénom</label><input id="f-prenom" type="text" name="prenom" value="<?= htmlspecialchars($enfant['prenom']) ?>" class="ds-input" ></div>
                <div><label class="ds-label" for="f-nom">Nom</label><input id="f-nom" type="text" name="nom" value="<?= htmlspecialchars($enfant['nom']) ?>" class="ds-input" ></div>
                <div><label class="ds-label" for="f-date_naissance">Date de naissance</label><input id="f-date_naissance" type="date" name="date_naissance" value="<?= htmlspecialchars($enfant['date_naissance']) ?>" class="ds-input" ></div>
                <div>
                    <label class="ds-label" for="f-sexe">Sexe</label>
                    <select id="f-sexe" name="sexe" class="ds-select">
                        <option value="Homme" <?= $enfant['sexe'] === 'Homme' ? 'selected' : '' ?>>Garçon</option>
                        <option value="Femme" <?= $enfant['sexe'] === 'Femme' ? 'selected' : '' ?>>Fille</option>
                        <option value="Autre" <?= $enfant['sexe'] === 'Autre' ? 'selected' : '' ?>>Autre</option>
                    </select>
                </div>
            </div>
        </section>

        <section class="ds-card p-6 sm:p-8" aria-labelledby="h-sante">
            <h2 id="h-sante" class="ds-eyebrow mb-5">Santé et informations physiques</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div><label class="ds-label" for="f-taille">Taille (cm)</label><input id="f-taille" type="number" name="taille" value="<?= htmlspecialchars($enfant['taille'] ?? '') ?>" class="ds-input" placeholder="ex : 145"></div>
                <div><label class="ds-label" for="f-poids">Poids (kg)</label><input id="f-poids" type="text" name="poids" value="<?= htmlspecialchars($enfant['poids'] ?? '') ?>" class="ds-input" placeholder="ex : 35"></div>
            </div>
            <div class="mt-5 space-y-5">
                <div>
                    <label class="ds-label" for="f-infos_sante">Problèmes de santé / traitements</label>
                    <textarea id="f-infos_sante" name="infos_sante" rows="3" class="ds-textarea" placeholder="Rien à signaler…"><?= htmlspecialchars($enfant['infos_sante'] ?? '') ?></textarea>
                </div>
                <div>
                    <label class="ds-label" for="f-allergies">Allergies</label>
                    <textarea id="f-allergies" name="allergies" rows="2" class="ds-textarea" placeholder="Aucune…"><?= htmlspecialchars($enfant['allergies'] ?? '') ?></textarea>
                </div>
                <div><label class="ds-label" for="f-regime_alimentaire">Régime alimentaire</label><input id="f-regime_alimentaire" type="text" name="regime_alimentaire" value="<?= htmlspecialchars($enfant['regime_alimentaire'] ?? '') ?>" class="ds-input" ></div>
            </div>
        </section>

        <section class="ds-card p-6 sm:p-8" aria-labelledby="h-medecin">
            <h2 id="h-medecin" class="ds-eyebrow mb-5">Médecin traitant</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div><label class="ds-label" for="f-medecin_nom">Nom du médecin</label><input id="f-medecin_nom" type="text" name="medecin_nom" value="<?= htmlspecialchars($enfant['medecin_nom'] ?? '') ?>" class="ds-input" ></div>
                <div><label class="ds-label" for="f-medecin_tel">Téléphone du médecin</label><input id="f-medecin_tel" type="tel" name="medecin_tel" value="<?= htmlspecialchars($enfant['medecin_tel'] ?? '') ?>" class="ds-input" ></div>
            </div>
        </section>

        <div class="flex flex-wrap justify-end gap-3 pt-2">
            <a href="javascript:history.back()" class="ds-btn ds-btn-light">Annuler</a>
            <button type="submit" class="ds-btn">Enregistrer les modifications</button>
        </div>
    </form>
</main>

<?php require_once 'partials/footer.php'; ?>

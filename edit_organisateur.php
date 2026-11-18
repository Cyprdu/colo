<?php
// 1. CONFIG
require_once 'api/config.php';
require_once 'api/motscles.php';

// 2. SÉCURITÉ
if (!isset($_SESSION['user']) || empty($_SESSION['user']['is_directeur'])) {
    header('Location: index.php');
    exit;
}

// 3. LOGIQUE - Récupération des données existantes
$organisateurId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?? filter_input(INPUT_POST, 'organisateur_id', FILTER_VALIDATE_INT);
$userId = $_SESSION['user']['id'];
$organisateur = null;
$message = [];
$error = null;

if (!$organisateurId) {
    $error = "ID d'organisme non spécifié.";
} else {
    try {
        // Fetch Organisateur Data
        $stmt = $pdo->prepare("SELECT * FROM organisateurs WHERE id = ? AND user_id = ?");
        $stmt->execute([$organisateurId, $userId]);
        $organisateur = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$organisateur) {
            $error = "Organisme introuvable ou vous n'êtes pas autorisé à le modifier.";
        }

    } catch (Exception $e) {
        $error = "Erreur SQL : " . $e->getMessage();
    }
}

// 4. LOGIQUE - Traitement du formulaire (UPDATE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $organisateur && $organisateurId) {
    // Récupérer et valider les données du formulaire
    $nom = trim($_POST['nom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $tel = trim($_POST['tel'] ?? '');
    $web = trim($_POST['web'] ?? '');
    $iban = trim($_POST['iban'] ?? '');
    $bic_swift = trim($_POST['bic_swift'] ?? '');
    $adresse_complete = trim($_POST['adresse_complete'] ?? '');
    $code_postal_orga = trim($_POST['code_postal_orga'] ?? '');
    $ville_orga = trim($_POST['ville_orga'] ?? '');
    $pays_orga = trim($_POST['pays_orga'] ?? '');
    $statut_legal = trim($_POST['statut_legal'] ?? '');
    
    // Garder l'URL du logo existant par défaut
    $logo_url = $organisateur['logo_url']; 

    // --- Gestion de l'upload de Logo (CORRIGÉ) ---
    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        
        $upload_dir = 'uploads/logos/'; 
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        
        $file_name = $_FILES['logo_file']['name'];
        $file_tmp_name = $_FILES['logo_file']['tmp_name'];
        $file_size = $_FILES['logo_file']['size'];
        $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Validation basique
        if (!in_array($file_extension, $allowed_extensions)) {
            $message['error'] = "Format de fichier non autorisé. Utilisez JPG, PNG ou GIF.";
        } elseif ($file_size > 2000000) { // Max 2MB
            $message['error'] = "Le fichier est trop volumineux (max 2MB).";
        } else {
            // Créer le dossier s'il n'existe pas
            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    $message['error'] = "Impossible de créer le répertoire d'upload.";
                }
            }

            if (!isset($message['error'])) {
                // Générer un nom unique
                $new_file_name = uniqid('logo_') . '.' . $file_extension;
                $target_file = $upload_dir . $new_file_name;
                
                // Déplacer le fichier
                if (move_uploaded_file($file_tmp_name, $target_file)) {
                    // Succès : mise à jour de l'URL du logo
                    $logo_url = $target_file;
                    
                    // OPTIONNEL : Supprimer l'ancien logo si un nouveau est uploadé
                    if (!empty($organisateur['logo_url']) && file_exists($organisateur['logo_url'])) {
                        unlink($organisateur['logo_url']);
                    }

                } else {
                    $message['error'] = "Erreur lors du déplacement du fichier sur le serveur.";
                }
            }
        }
    }
    // -----------------------------------------------------------------------------------

    if (empty($message)) {
        try {
            $updateSql = "UPDATE organisateurs SET 
                            nom = ?, email = ?, tel = ?, web = ?, 
                            logo_url = ?, iban = ?, bic_swift = ?, 
                            adresse_complete = ?, code_postal_orga = ?, 
                            ville_orga = ?, pays_orga = ?, statut_legal = ?
                          WHERE id = ? AND user_id = ?";
            
            $stmtUpdate = $pdo->prepare($updateSql);
            $stmtUpdate->execute([
                $nom, $email, $tel, $web, $logo_url, 
                $iban, $bic_swift, $adresse_complete, $code_postal_orga, 
                $ville_orga, $pays_orga, $statut_legal,
                $organisateurId, $userId
            ]);

            $message['success'] = "Les informations de l'organisme ont été mises à jour avec succès.";
            // Alias (« PAJE ») : contrôlés à part ; un alias refusé n'empêche pas l'enregistrement du reste
            if (isset($_POST['motscles'])) {
                $alias = mc_sauver_orga($pdo, (int)$organisateurId, $nom, (string)$_POST['motscles']);
                if ($alias['motscles_refuses']) $message['success'] .= ' Alias refusés : ' . implode(' ; ', array_map(function ($r) { return '« ' . $r['label'] . ' » (' . $r['raison'] . ')'; }, $alias['motscles_refuses']));
            }
            
            // Recharger les données pour afficher les nouvelles valeurs
            $stmt = $pdo->prepare("SELECT * FROM organisateurs WHERE id = ? AND user_id = ?");
            $stmt->execute([$organisateurId, $userId]);
            $organisateur = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $message['error'] = "Erreur SQL lors de la mise à jour : " . $e->getMessage();
        }
    }
}

// 5. AFFICHAGE HTML
$body_class = 'bg-white';
require_once 'partials/header.php';
?>

<title>Modifier l'organisme - moncamp.fr</title>

<?php mc_ensure($pdo); $aliasInit = $organisateur ? (mc_lire($pdo, 'orga', [(int)$organisateurId])[(int)$organisateurId] ?? []) : []; ?>
<link rel="stylesheet" href="assets/css/colomap-search.css">
<main class="ds-page" style="max-width:860px">
    <a href="dashboard_organisme.php?organisateur_id=<?= (int)$organisateurId ?>" class="inline-flex items-center gap-2 text-[14px] font-semibold text-[#3b4a54] hover:text-[#26343b]">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12H4m6-6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Tableau de bord
    </a>
    <p class="ds-eyebrow mt-6">Mes organismes</p>
    <h1 class="ds-h1">Modifier l'organisme</h1>

    <?php if ($error): ?>
        <div class="ds-alert-err mt-6" role="alert"><p class="font-semibold"><?= htmlspecialchars($error) ?></p></div>
    <?php elseif ($organisateur): ?>
        <?php if (isset($message['success'])): ?><div class="ds-alert-ok mt-6 font-semibold" role="status"><?= htmlspecialchars($message['success']) ?></div><?php endif; ?>
        <?php if (isset($message['error'])): ?><div class="ds-alert-err mt-6 font-semibold" role="alert"><?= htmlspecialchars($message['error']) ?></div><?php endif; ?>

        <form action="edit_organisateur.php?id=<?= (int)$organisateurId ?>" method="POST" enctype="multipart/form-data" class="mt-8 space-y-6">
            <input type="hidden" name="organisateur_id" value="<?= (int)$organisateurId ?>">

            <section class="ds-card ds-sec">
                <div class="ds-sec-head"><span class="ds-sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21V8l8-5 8 5v13M9 21v-6h6v6"/></svg></span><div><h2 class="ds-sec-title">Informations générales</h2><p class="ds-sec-desc">Ce que les familles voient sur vos séjours.</p></div></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="ds-label" for="logo_file">Logo</label>
                        <div class="flex items-center gap-3">
                            <?php if (!empty($organisateur['logo_url'])): ?><img src="<?= htmlspecialchars($organisateur['logo_url']) ?>" alt="Logo actuel" class="w-14 h-14 object-contain border border-[#e5e7eb] rounded-xl bg-white"><?php endif; ?>
                            <input type="file" name="logo_file" id="logo_file" accept="image/*" class="ds-file">
                        </div>
                        <p class="ds-hint">PNG, JPG ou GIF, 2 Mo maximum.</p>
                    </div>
                <div><label class="ds-label" for="statut_legal">Statut légal (ex. association loi 1901)</label><input class="ds-input" type="text" name="statut_legal" id="statut_legal" value="<?= htmlspecialchars($organisateur['statut_legal'] ?? '') ?>"></div>
                <div class="sm:col-span-2"><label class="ds-label" for="nom">Nom de l'organisme</label><input class="ds-input" type="text" name="nom" id="nom" value="<?= htmlspecialchars($organisateur['nom'] ?? '') ?>" required></div>
                <div><label class="ds-label" for="email">Email de contact</label><input class="ds-input" type="email" name="email" id="email" value="<?= htmlspecialchars($organisateur['email'] ?? '') ?>" required></div>
                <div><label class="ds-label" for="tel">Téléphone</label><input class="ds-input" type="tel" name="tel" id="tel" value="<?= htmlspecialchars($organisateur['tel'] ?? '') ?>"></div>
                <div class="sm:col-span-2"><label class="ds-label" for="web">Site web</label><input class="ds-input" type="url" name="web" id="web" value="<?= htmlspecialchars($organisateur['web'] ?? '') ?>"></div>
                </div>
            </section>

            <section class="ds-card ds-sec">
                <div class="ds-sec-head"><span class="ds-sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.6 13.4l-7.2 7.2a2 2 0 01-2.8 0L3 13V3h10l7.6 7.6a2 2 0 010 2.8z"/><circle cx="7.5" cy="7.5" r="1.3"/></svg></span><div><h2 class="ds-sec-title">Alias et mots-clés de l'organisme</h2><p class="ds-sec-desc">Les noms sous lesquels on vous connaît. Tous vos séjours remontent quand une famille les tape.</p></div></div>
                <label class="ds-label" for="orgaKwInput">Alias <span class="font-normal text-[#6b7780]">(8 maximum)</span></label>
                <div id="orgaKw"></div>
                <input type="hidden" name="motscles" id="orgaKwHidden" value="<?= htmlspecialchars(json_encode($aliasInit, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
                <div class="mc-help"><b>Ces mots sont ceux que les familles tapent ; ils améliorent la visibilité de vos séjours.</b> Exemple : « PAJE » pour la Pastorale des Jeunes de l'Ain. Mettez des noms, des sigles, des orthographes courantes : pas de thèmes (« ski », « cheval »), ils se règlent dans les catégories de chaque séjour. Le nom d'un autre organisme ou son alias sera refusé.</div>
            </section>

            <section class="ds-card ds-sec">
                <div class="ds-sec-head"><span class="ds-sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="3"/><path d="M3 10h18"/></svg></span><div><h2 class="ds-sec-title">Coordonnées bancaires</h2><p class="ds-sec-desc">Utilisées pour le versement des fonds collectés pour vos séjours.</p></div></div>
                <div class="grid gap-4 sm:grid-cols-2">
                <div><label class="ds-label" for="iban">IBAN</label><input class="ds-input" type="text" name="iban" id="iban" value="<?= htmlspecialchars($organisateur['iban'] ?? '') ?>" maxlength="34" autocomplete="off"></div>
                <div><label class="ds-label" for="bic_swift">BIC / SWIFT</label><input class="ds-input" type="text" name="bic_swift" id="bic_swift" value="<?= htmlspecialchars($organisateur['bic_swift'] ?? '') ?>" maxlength="11" autocomplete="off"></div>
                </div>
            </section>

            <section class="ds-card ds-sec">
                <div class="ds-sec-head"><span class="ds-sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-6.2 7-11a7 7 0 10-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg></span><div><h2 class="ds-sec-title">Adresse administrative</h2></div></div>
                <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><label class="ds-label" for="adresse_complete">Adresse</label><input class="ds-input" type="text" name="adresse_complete" id="adresse_complete" value="<?= htmlspecialchars($organisateur['adresse_complete'] ?? '') ?>"></div>
                <div><label class="ds-label" for="code_postal_orga">Code postal</label><input class="ds-input" type="text" name="code_postal_orga" id="code_postal_orga" value="<?= htmlspecialchars($organisateur['code_postal_orga'] ?? '') ?>"></div>
                <div><label class="ds-label" for="ville_orga">Ville</label><input class="ds-input" type="text" name="ville_orga" id="ville_orga" value="<?= htmlspecialchars($organisateur['ville_orga'] ?? '') ?>"></div>
                <div class="sm:col-span-2"><label class="ds-label" for="pays_orga">Pays</label><input class="ds-input" type="text" name="pays_orga" id="pays_orga" value="<?= htmlspecialchars($organisateur['pays_orga'] ?? 'France') ?>"></div>
                </div>
            </section>

            <div class="flex justify-end"><button type="submit" class="ds-btn">Enregistrer les modifications</button></div>
        </form>
    <?php endif; ?>
</main>

<script src="assets/js/motscles.js"></script>
<script>
(function () {
    var h = document.getElementById('orgaKwHidden'), root = document.getElementById('orgaKw');
    if (!h || !root) return;
    var init = []; try { init = JSON.parse(h.value) || []; } catch (e) {}
    MotsCles.mount(root, { initial: init, max: 8, inputId: 'orgaKwInput', placeholder: 'Ex : PAJE, puis Entrée', onChange: function (l) { h.value = JSON.stringify(l); } });
})();
</script>
<?php require_once 'partials/footer.php'; ?>

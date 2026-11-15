<?php
require_once 'api/config.php';
require_once 'api/security.php';

// 1. Récupération
$token = $_GET['t'] ?? '';
$codeFromUrl = $_GET['code'] ?? ''; 

$error = null;
$success = null;
$user = null;

if (empty($token)) { header('Location: index.php'); exit; }

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE url_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) { 
        // Token introuvable ou déjà brûlé
        header('Location: index.php'); 
        exit; 
    }

    // --- LOGIQUE CONNEXION AUTO (PC) ---
    // Si l'utilisateur est déjà vérifié (via le téléphone par exemple)
    // Et qu'il arrive ici avec le token valide, on le connecte !
    if ($user['is_verified'] == 1) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        unset($user['password']);
        $_SESSION['user'] = $user;
        
        // SÉCURITÉ : On brûle le token maintenant qu'il est connecté
        $burn = $pdo->prepare("UPDATE users SET url_token = NULL WHERE id = ?");
        $burn->execute([$user['id']]);

        header('Location: index.php'); 
        exit;
    }

    // --- TRAITEMENT FORMULAIRE (Téléphone) ---
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $code = (string)($_POST['full_code'] ?? '');
        // Code à 6 chiffres : on limite les essais (sinon il se devine en quelques minutes)
        if (sec_blocked($pdo, 'code', (string)$token, 6, 30) || sec_blocked($pdo, 'code', sec_ip(), 30, 30)) {
            $error = "Trop d'essais. Patientez 30 minutes, puis redemandez un code si besoin.";
        } elseif (hash_equals((string)$user['verification_token'], $code)) {
            sec_clear($pdo, 'code', (string)$token);
            
            // VALIDATION : On passe is_verified à 1
            // IMPORTANT : On NE supprime PAS encore le url_token ici
            // Pour permettre au PC de se connecter via le reload
            $update = $pdo->prepare("UPDATE users SET is_verified = 1 WHERE id = ?");
            $update->execute([$user['id']]);
            
            $success = true;
            
            // On connecte aussi cet appareil (téléphone)
            if (session_status() === PHP_SESSION_NONE) session_start();
            unset($user['password']);
            $_SESSION['user'] = $user;
            $_SESSION['user']['is_verified'] = 1; // Mise à jour session
            
            // Redirection
            header("refresh:2;url=index.php");
        } else {
            sec_hit($pdo, 'code', (string)$token); sec_hit($pdo, 'code', sec_ip());
            $error = "Code incorrect.";
        }
    }
} catch (Exception $e) { $error = "Erreur système."; }

$body_class = 'bg-white';
require_once 'partials/header.php';
?>

<title>Vérification - ColoMap</title>

<main class="ds-page flex items-center justify-center" style="max-width:480px">
    <div class="w-full ds-fade">
        <div class="text-center mb-6">
            <h1 class="ds-h1">Vérification</h1>
            <p class="ds-lead">Un code à 6 chiffres a été envoyé à <strong class="text-[#26343b]"><?= htmlspecialchars($user['email']) ?></strong></p>
        </div>
        <div class="ds-card p-6 sm:p-8">
            <?php if ($success): ?>
                <div class="text-center py-4" role="status">
                    <span class="mx-auto mb-4 flex items-center justify-center h-12 w-12 rounded-full bg-[#2f3d45] text-white"><svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.4" d="M5 13l4 4L19 7"/></svg></span>
                    <h2 class="text-lg font-extrabold text-[#26343b]">Compte validé</h2>
                    <p class="ds-lead">Connexion en cours...</p>
                </div>
            <?php else: ?>
                <?php if ($error): ?><div class="ds-alert-err mb-5 text-sm text-center font-semibold" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

                <form action="" method="POST" id="otpForm">
                    <input type="hidden" name="full_code" id="full_code">
                    <div class="mb-6" role="group" aria-label="Code de vérification à 6 chiffres">
                        <div class="flex justify-between gap-2" id="otp-container">
                            <?php for($i=0; $i<6; $i++): ?>
                                <input type="text" maxlength="1" aria-label="Chiffre <?= $i + 1 ?>" class="otp-input ds-input !px-0 !h-14 w-12 text-center text-2xl font-extrabold" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" required>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <button type="submit" id="submitBtn" class="ds-btn w-full">Valider mon compte</button>
                </form>

                <div id="loadingSync" class="hidden mt-6 text-center" role="status">
                    <svg class="animate-spin h-5 w-5 text-[#2f3d45] mx-auto mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <p class="text-sm text-[#6b7780]">Validation détectée sur un autre appareil...</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const token = "<?= htmlspecialchars($token) ?>";
    const inputs = document.querySelectorAll('.otp-input');
    const hiddenInput = document.getElementById('full_code');
    const urlCode = "<?= htmlspecialchars($codeFromUrl) ?>";
    let isSubmitting = false;

    // 1. AUTO-FILL
    const updateHidden = () => { let c=''; inputs.forEach(i=>c+=i.value); hiddenInput.value=c; };
    if (urlCode && urlCode.length === 6) {
        urlCode.split('').forEach((d, i) => { if(inputs[i]) inputs[i].value = d; });
        updateHidden();
        setTimeout(() => document.getElementById('submitBtn').click(), 500);
    } else { if(inputs[0]) inputs[0].focus(); }

    // 2. INPUT LOGIC
    inputs.forEach((input, index) => {
        input.addEventListener('input', (e) => {
            if(e.target.value.match(/^[0-9]$/)) { updateHidden(); if(index < 5) inputs[index+1].focus(); }
            else { e.target.value = ''; }
        });
        input.addEventListener('keydown', (e) => {
            if(e.key === 'Backspace' && !e.target.value && index > 0) inputs[index-1].focus();
        });
        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const data = e.clipboardData.getData('text').trim();
            if(/^\d{6}$/.test(data)) {
                data.split('').forEach((d,i) => inputs[i].value = d);
                updateHidden(); inputs[5].focus(); document.getElementById('submitBtn').click();
            }
        });
    });

    // 3. CROSS-DEVICE SYNC
    // On vérifie régulièrement si le compte est validé ailleurs
    let checkInterval = setInterval(() => {
        if(isSubmitting) return; // Ne pas vérifier si on soumet déjà
        
        fetch('api/check_status.php?t=' + token)
            .then(res => res.json())
            .then(data => {
                if (data.verified) {
                    clearInterval(checkInterval);
                    // Afficher le chargement
                    document.getElementById('otpForm').style.display = 'none';
                    document.getElementById('loadingSync').classList.remove('hidden');
                    
                    // Recharger la page pour déclencher la connexion PHP (Top du fichier)
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else if (data.deleted) {
                    window.location.href = 'index.php'; // Compte supprimé
                }
            })
            .catch(err => {});
    }, 2000); 
    
    document.getElementById('otpForm').addEventListener('submit', () => { isSubmitting = true; });
});
</script>
<?php require_once 'partials/footer.php'; ?>
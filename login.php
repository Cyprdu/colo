<?php
// Fichier: login.php
session_start();
require_once 'api/config.php';
require_once 'api/security.php';

// Page à ouvrir après connexion :
//   ?next=nom_de_page            (nom simple)
//   ?redirect=URL_de_ce_site     (adresse complète ou relative, même domaine uniquement, avec ses paramètres)
if (isset($_GET['next']) && preg_match('/^[a-z0-9_-]{1,40}$/i', $_GET['next'])) {
    $_SESSION['after_login_url'] = $_GET['next'] . '.php';
}
if (isset($_GET['redirect'])) {
    $u = parse_url((string)$_GET['redirect']);
    if ($u !== false && (!isset($u['host']) || strcasecmp($u['host'], $_SERVER['HTTP_HOST'] ?? '') === 0)) {
        $page = basename($u['path'] ?? '');
        $qs   = isset($u['query']) ? '?' . $u['query'] : '';
        if (preg_match('/^[a-z0-9_-]{1,40}(\.php)?$/i', $page) && preg_match('/^(\?[A-Za-z0-9_=&%.\-]{0,200})?$/', $qs)) {
            $_SESSION['after_login_url'] = (substr($page, -4) === '.php' ? $page : $page . '.php') . $qs;
        }
    }
}
function after_login_url() {
    $target = $_SESSION['after_login_url'] ?? '';
    unset($_SESSION['after_login_url']);
    $file = strtok($target, '?');
    return ($file && is_file(__DIR__ . '/' . $file)) ? $target : 'index.php';
}

// 1. Initialisation Google
$login_url = '#'; 
if (file_exists('api/google_config.php')) {
    require_once 'api/google_config.php';
    if (isset($client)) {
        $login_url = $client->createAuthUrl();
    }
}

// 2. Logique "Se souvenir de moi" (Cookie)
if (!isset($_SESSION['user']) && isset($_COOKIE['remember_me'])) {
    list($user_id, $token) = explode(':', $_COOKIE['remember_me']);
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // On vérifie le token
        if ($user && !empty($user['remember_token']) && hash_equals($user['remember_token'], hash('sha256', $token))) {
            if (isset($user['is_active']) && $user['is_active'] == 0) {
                // Compte désactivé
            } else {
                $_SESSION['user'] = $user;
                // Rotation du token
                $new_token = bin2hex(random_bytes(16));
                $new_hash = hash('sha256', $new_token);
                $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?")->execute([$new_hash, $user['id']]);
                setcookie('remember_me', $user['id'] . ':' . $new_token, time() + (86400 * 30), "/", "", false, true);
                header("Location: " . after_login_url());
                exit();
            }
        }
    } catch (Exception $e) {}
}

// 3. Redirection si déjà connecté
if (isset($_SESSION['user'])) {
    header("Location: " . after_login_url());
    exit();
}

$error = '';

// 4. Traitement du formulaire de connexion
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $remember = isset($_POST['remember_me']);

    $ipKey = sec_ip(); $mailKey = (string)$email;
    if (sec_blocked($pdo, 'login', $ipKey, 25, 15) || ($mailKey !== '' && sec_blocked($pdo, 'login', $mailKey, 6, 15))) {
        $error = "Trop de tentatives de connexion. Patientez quelques minutes avant de réessayer.";
    } elseif (!sec_turnstile_ok()) {
        $error = "Vérification anti-robot échouée. Réessayez.";
    } elseif (!empty($email) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // MODIFICATION ICI :
                // On a supprimé le bloc qui interdisait la connexion si google_id existait.
                // On tente directement de vérifier le mot de passe.
                
                if (password_verify($password, $user['password'])) {
                    
                    // Vérification compte actif
                    if (isset($user['is_active']) && $user['is_active'] == 0) {
                        $error = "Votre compte a été désactivé. Contactez le support.";
                    } else {
                        // Connexion réussie : nouvel identifiant de session (anti-fixation), et le hachage du mot de passe ne reste pas en session
                        session_regenerate_id(true);
                        unset($user['password'], $user['remember_token'], $user['verification_token']);
                        $_SESSION['user'] = $user;
                        sec_clear($pdo, 'login', $mailKey);

                        // Si l'utilisateur n'avait pas son email vérifié mais qu'il se connecte (cas rare ici mais possible), 
                        // on peut considérer que le login valide l'accès, ou on laisse tel quel.

                        // Gestion Cookie "Se souvenir de moi"
                        if ($remember) {
                            $token = bin2hex(random_bytes(16)); 
                            $hash = hash('sha256', $token);
                            try {
                                $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?")->execute([$hash, $user['id']]);
                                setcookie('remember_me', $user['id'] . ':' . $token, ['expires' => time() + (86400 * 30), 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
                            } catch (Exception $e) {}
                        } else {
                            if(isset($_COOKIE['remember_me'])) {
                                setcookie('remember_me', '', time() - 3600, "/");
                                try {
                                    $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?")->execute([$user['id']]);
                                } catch(Exception $e) {}
                            }
                        }

                        header("Location: " . after_login_url());
                        exit();
                    }
                } else {
                    // Mot de passe incorrect (ou pas de mot de passe car compte 100% Google)
                    sec_hit($pdo, 'login', $ipKey); sec_hit($pdo, 'login', $mailKey);
                    $error = "Email ou mot de passe incorrect.";
                }
            } else {
                sec_hit($pdo, 'login', $ipKey); sec_hit($pdo, 'login', $mailKey);
                $error = "Email ou mot de passe incorrect.";
            }
        } catch (Exception $e) {
            error_log('login: ' . $e->getMessage());
            $error = "Connexion momentanément indisponible. Réessayez dans un instant.";
        }
    } else {
        $error = "Veuillez remplir tous les champs.";
    }
}

// Gestion des erreurs URL
if (isset($_GET['error']) && $_GET['error'] == 'google_auth_failed') {
    $error = "Échec de l'authentification Google.";
} elseif (isset($_GET['error']) && $_GET['error'] == 'google_api_error') {
    $error = "Erreur de communication avec Google.";
}

// 5. Page d'entrée (écran partagé : scène de colonie + formulaire)
$auth_title    = 'Connexion - moncamp.fr';
$scene_kicker  = 'Bon retour parmi nous';
$scene_title   = "Entrez dans l'aventure.";
$scene_text    = 'Retrouvez vos séjours, vos favoris et vos échanges avec les organisateurs, là où vous les avez laissés.';
$scene_points  = [['fa-campground', 'Des centaines de séjours à découvrir'], ['fa-shield-heart', 'Organisateurs vérifiés'], ['fa-lock', 'Paiement sécurisé par Stripe']];
$auth_alt      = ['text' => 'Nouveau ici ?', 'label' => 'Créer un compte', 'href' => 'register.php'];
require_once 'partials/auth_start.php';
?>
<div class="rise">
    <div>
        <h1 class="auth-title">Connexion</h1>
        <p class="auth-sub">Content de vous revoir. Le feu de camp est déjà allumé.</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="ds-alert-err flex items-start gap-3 mt-6" role="alert">
            <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
            <p class="text-[14px] font-semibold"><?php echo htmlspecialchars($error); ?></p>
        </div>
    <?php endif; ?>

    <form class="mt-7 space-y-5" action="" method="POST" data-auth>
        <div>
            <label for="email" class="ds-label">Adresse email</label>
            <div class="field">
                <i class="fa-regular fa-envelope ico"></i>
                <input id="email" name="email" type="email" autocomplete="email" required placeholder="vous@exemple.fr" class="ds-input">
            </div>
        </div>
        <div>
            <label for="password" class="ds-label">Mot de passe</label>
            <div class="field">
                <i class="fa-solid fa-lock ico"></i>
                <input id="password" name="password" type="password" autocomplete="current-password" required placeholder="Votre mot de passe" class="ds-input" style="padding-right:48px">
                <button type="button" onclick="togglePassword()" class="absolute inset-y-0 right-0 pr-4 flex items-center text-[#9aa3ab] hover:text-[#26343b]" title="Afficher / masquer le mot de passe" aria-label="Afficher ou masquer le mot de passe">
                    <i class="fa-solid fa-eye" id="eye-icon"></i>
                </button>
            </div>
        </div>
        <div class="flex items-center justify-between gap-3 text-[14px]">
            <label for="remember_me" class="flex items-center gap-2 cursor-pointer select-none text-[#3b4a54]">
                <input id="remember_me" name="remember_me" type="checkbox" class="h-4 w-4 rounded border-gray-300 accent-[#2f3d45] cursor-pointer"> Se souvenir de moi
            </label>
            <a href="forgot_password.php" class="font-semibold text-[#3d6a8e] hover:text-[#2d5170] hover:underline">Mot de passe oublié ?</a>
        </div>
        <button type="submit" class="auth-btn"><span>Entrer dans l'aventure</span><i class="fa-solid fa-arrow-right arrow"></i><span class="spin"></span></button>
        <?= sec_turnstile_widget() ?>
    </form>

    <div class="auth-or">ou</div>

    <a href="<?php echo htmlspecialchars($login_url); ?>" class="google-btn">
        <img class="h-5 w-5" src="https://www.svgrepo.com/show/475656/google-color.svg" alt="" width="20" height="20"> Continuer avec Google
    </a>
</div>

<script>
function togglePassword() {
    const x = document.getElementById('password'), i = document.getElementById('eye-icon');
    const show = x.type === 'password';
    x.type = show ? 'text' : 'password';
    i.classList.toggle('fa-eye', !show);
    i.classList.toggle('fa-eye-slash', show);
}
</script>
<?php require_once 'partials/auth_end.php'; ?>

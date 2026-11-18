<?php
// Fichier: contact.php
require_once 'api/config.php';
require_once __DIR__ . '/api/security.php';
require_once __DIR__ . '/api/adm_core.php';
require_once __DIR__ . '/api/contact_lib.php';
$body_class = 'bg-white';
require_once 'partials/header.php';

$msg_success = '';
$msg_error = '';

// Variables par défaut
$nom = ''; $prenom = ''; $email = '';
$motif_select = ''; 
$motif_autre = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Récupération User
    if (isset($_SESSION['user'])) {
        $nom = $_SESSION['user']['nom'];
        $prenom = $_SESSION['user']['prenom'];
        $email = $_SESSION['user']['email'];
        $user_id = $_SESSION['user']['id'];
    } else {
        $nom = htmlspecialchars(trim($_POST['nom']));
        $prenom = htmlspecialchars(trim($_POST['prenom']));
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $user_id = NULL;
    }
    
    // 2. Traitement du Motif "Autre"
    $motif_select = htmlspecialchars(trim($_POST['motif']));
    $message = htmlspecialchars(trim($_POST['message']));
    
    // Si "Autre" est choisi, on prend le champ de précision
    if ($motif_select === 'Autre') {
        $precision = htmlspecialchars(trim($_POST['motif_autre']));
        if (!empty($precision)) {
            $final_motif = "Autre : " . $precision;
        } else {
            $final_motif = "Autre (Non précisé)";
        }
    } else {
        $final_motif = $motif_select;
    }

    $ip = sec_ip();
    if ($nom && $prenom && $email && $final_motif && $message && mb_strlen($message) < 10) {
        $msg_error = "Votre message est un peu court : détaillez votre demande.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($message) > 5000) {
        $msg_error = "Vérifiez votre e-mail et la longueur du message (5000 caractères au plus).";
    } elseif (sec_blocked($pdo, 'contact', $ip, 5, 60) || sec_blocked($pdo, 'contact_jour', $ip, 15, 1440)) {
        $msg_error = "Vous avez envoyé beaucoup de messages. Réessayez dans une heure.";
    } elseif (!sec_turnstile_ok()) {
        $msg_error = "La vérification anti-robot a échoué. Réessayez.";
    } elseif ($nom && $prenom && $email && $final_motif && $message) {
        try {
            adm_core_ensure($pdo);
            $spam = contact_spam_reason($_POST, isset($_SESSION['user']));
            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare("INSERT INTO contact_messages (user_id, nom, prenom, email, motif, message, token, ip, dossier, spam_raison, lu) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $nom, $prenom, $email, $final_motif, $message, $token, $ip, $spam ? 'spam' : 'inbox', $spam, $spam ? 1 : 0]);
            $mid = (int)$pdo->lastInsertId();
            sec_trace($pdo, $user_id ? (int)$user_id : null, 'contact', $mid);
            sec_hit($pdo, 'contact', $ip); sec_hit($pdo, 'contact_jour', $ip);
            if (!$spam) adm_notif_add('contact', 'm' . $mid, 'Message de ' . html_entity_decode($prenom . ' ' . $nom) . ' : ' . html_entity_decode($final_motif), 'admin_contacts.php?m=' . $token, 'contacts.read');
            $msg_success = "Votre demande a bien été envoyée ! Nous vous répondrons par email.";
            $message = '';
            $motif_select = '';
        } catch (Exception $e) {
            error_log('contact: ' . $e->getMessage());
            $msg_error = "Une erreur est survenue. Veuillez réessayer.";
        }
    } else {
        $msg_error = "Veuillez remplir tous les champs.";
    }
}
?>
<title>Nous contacter - moncamp.fr</title>
<style>
    .faq-item { border: 1.5px solid #e5e7eb; border-radius: 16px; background: #fff; transition: border-color .15s ease, box-shadow .2s ease; }
    .faq-item[open] { border-color: #a9b8c6; box-shadow: 0 8px 24px rgba(38, 52, 59, .07); }
    .faq-item summary { list-style: none; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 16px 20px; font-weight: 700; color: #26343b; font-size: 15.5px; }
    .faq-item summary::-webkit-details-marker { display: none; }
    .faq-item summary:hover { color: #3d6a8e; }
    .faq-item .chev { flex: none; width: 26px; height: 26px; border-radius: 9999px; background: #f1f3f5; display: flex; align-items: center; justify-content: center; transition: transform .2s ease, background .2s ease; color: #3b4a54; font-size: 11px; }
    .faq-item[open] .chev { transform: rotate(180deg); background: #2f3d45; color: #fff; }
    .faq-answer { padding: 0 20px 18px; color: #3b4a54; line-height: 1.7; font-size: 15px; }
</style>

<main class="ds-page">
    <div class="max-w-2xl">
        <h1 class="ds-h1">Nous contacter</h1>
        <p class="ds-lead">Une question, une suggestion, un souci avec une réservation ? Écrivez-nous, nous vous répondons par email.</p>
    </div>

    <?php if ($msg_success): ?>
        <div class="ds-alert-ok mt-8 flex items-start gap-3" role="status">
            <i class="fa-solid fa-circle-check mt-0.5 text-[#2f3d45]"></i>
            <div><p class="font-bold">Message envoyé</p><p class="text-[14px]"><?php echo $msg_success; ?></p></div>
        </div>
    <?php endif; ?>
    <?php if ($msg_error): ?>
        <div class="ds-alert-err mt-8 flex items-start gap-3" role="alert">
            <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
            <div><p class="font-bold">Un souci</p><p class="text-[14px]"><?php echo $msg_error; ?></p></div>
        </div>
    <?php endif; ?>

    <div class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-8">

        <section class="ds-card p-6 sm:p-8 lg:col-span-2" aria-label="Formulaire de contact">
            <form action="" method="POST" class="space-y-5">
                <input type="hidden" name="ts" value="<?php echo htmlspecialchars(contact_form_ts()); ?>">
                <div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"><label for="c-site">Site web</label><input id="c-site" type="text" name="site_web" tabindex="-1" autocomplete="off" value=""></div>
                <?php if (!isset($_SESSION['user'])): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div><label class="ds-label" for="c-prenom">Prénom</label><input id="c-prenom" type="text" name="prenom" value="<?php echo htmlspecialchars($prenom); ?>" required class="ds-input"></div>
                        <div><label class="ds-label" for="c-nom">Nom</label><input id="c-nom" type="text" name="nom" value="<?php echo htmlspecialchars($nom); ?>" required class="ds-input"></div>
                    </div>
                    <div><label class="ds-label" for="c-email">Email</label><input id="c-email" type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required class="ds-input" placeholder="vous@exemple.fr"></div>
                <?php else: ?>
                    <div class="flex items-center gap-4 rounded-2xl bg-[#f8f5ea] p-4">
                        <span class="w-11 h-11 flex-none rounded-full bg-[#3a4852] text-white font-bold flex items-center justify-center"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($_SESSION['user']['prenom'], 0, 1))); ?></span>
                        <div class="min-w-0">
                            <p class="font-bold text-[#26343b]">Connecté en tant que <?php echo htmlspecialchars($_SESSION['user']['prenom'] . ' ' . $_SESSION['user']['nom']); ?></p>
                            <p class="text-[13px] text-[#6b7780] truncate"><?php echo htmlspecialchars($_SESSION['user']['email']); ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <div>
                    <label class="ds-label" for="motif-select">Motif de la demande</label>
                    <select name="motif" id="motif-select" required onchange="toggleAutreInput()" class="ds-select">
                        <option value="" disabled <?php echo $motif_select === '' ? 'selected' : ''; ?>>Choisissez un motif…</option>
                        <?php foreach (['Renseignement séjour' => 'Renseignement sur un séjour', 'Inscription / Réservation' => "Problème d'inscription / Réservation", 'Compte / Connexion' => 'Problème de compte / Connexion', 'Partenariat / Organisateur' => 'Espace Organisateur / Partenariat', 'Recrutement / Animation' => 'Recrutement / Espace Animation', 'Autre' => 'Autre demande (préciser)'] as $val => $label): ?>
                            <option value="<?php echo htmlspecialchars($val); ?>" <?php echo ($motif_select === $val || (($_GET['motif'] ?? '') === $val && $motif_select === '')) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="div-autre" class="hidden">
                    <label class="ds-label" for="input-autre">Précisez votre demande</label>
                    <input type="text" name="motif_autre" id="input-autre" placeholder="Sujet de votre message…" class="ds-input">
                </div>

                <div>
                    <label class="ds-label" for="c-message">Votre message</label>
                    <textarea id="c-message" name="message" rows="7" required placeholder="Détaillez votre demande ici…" class="ds-textarea"></textarea>
                </div>

                <?php echo sec_turnstile_widget(); ?>
                <button type="submit" class="ds-btn w-full" style="padding:15px 22px"><i class="fa-solid fa-paper-plane"></i> Envoyer le message</button>
            </form>
        </section>

        <aside class="space-y-5">
            <div class="ds-card p-6">
                <p class="ds-eyebrow">Par email</p>
                <a href="mailto:auth@moncamp.fr" class="mt-2 block text-[17px] font-bold text-[#26343b] hover:text-[#3d6a8e]">auth@moncamp.fr</a>
                <p class="mt-2 text-[14px] text-[#6b7780]">Nous répondons en général sous 48 heures ouvrées.</p>
            </div>
            <div class="ds-card p-6">
                <p class="ds-eyebrow">Réponses rapides</p>
                <ul class="mt-3 space-y-3 text-[15px] font-semibold">
                    <li><a href="aide#parents" class="flex items-center justify-between text-[#26343b] hover:text-[#3d6a8e]">Parents <span>→</span></a></li>
                    <li><a href="aide#organisateurs" class="flex items-center justify-between text-[#26343b] hover:text-[#3d6a8e]">Organisateurs <span>→</span></a></li>
                    <li><a href="aide#animateurs" class="flex items-center justify-between text-[#26343b] hover:text-[#3d6a8e]">Animateurs <span>→</span></a></li>
                    <li><a href="aide#paiements" class="flex items-center justify-between text-[#26343b] hover:text-[#3d6a8e]">Paiements &amp; sécurité <span>→</span></a></li>
                </ul>
            </div>
            <?php if (isset($_SESSION['user'])): ?>
            <div class="ds-card p-6">
                <p class="ds-eyebrow">Une réservation ?</p>
                <p class="mt-2 text-[14px] text-[#6b7780]">Retrouvez-la, gérez-la et contactez directement l'organisateur.</p>
                <a href="reservations" class="ds-btn ds-btn-light mt-4 w-full">Mes réservations</a>
            </div>
            <?php endif; ?>
        </aside>
    </div>

    <section class="mt-16" aria-labelledby="h-faq">
        <h2 id="h-faq" class="text-[24px] font-extrabold text-[#26343b]">Questions fréquentes</h2>
        <p class="mt-1 text-[#6b7780]">Une réponse immédiate aux demandes les plus courantes.</p>
        <div class="mt-6 space-y-3 max-w-3xl">
            <details class="faq-item">
                <summary><span>J'ai oublié mon mot de passe, que faire ?</span><span class="chev"><i class="fa-solid fa-chevron-down"></i></span></summary>
                <div class="faq-answer">Pas de panique ! Vous pouvez réinitialiser votre mot de passe à tout moment en cliquant sur le lien 
                        <a href="forgot_password.php" class="font-bold underline text-[#3d6a8e]">Mot de passe oublié</a> sur la page de connexion. 
                        Un email vous sera envoyé avec les instructions pour en créer un nouveau.</div>
            </details>
            <details class="faq-item">
                <summary><span>Je n'arrive pas à me connecter</span><span class="chev"><i class="fa-solid fa-chevron-down"></i></span></summary>
                <div class="faq-answer">Vérifiez bien que vous utilisez l'adresse email fournie lors de votre inscription. Assurez-vous également que la touche "Majuscule" n'est pas activée par erreur.
                        Si le problème persiste, essayez de réinitialiser votre mot de passe ou contactez-nous via le formulaire ci-dessus.</div>
            </details>
            <details class="faq-item">
                <summary><span>Comment inscrire mon enfant à un séjour ?</span><span class="chev"><i class="fa-solid fa-chevron-down"></i></span></summary>
                <div class="faq-answer">C'est très simple :
                        <ol class="list-decimal list-inside mt-2 space-y-1 ml-2">
                            <li>Connectez-vous à votre compte.</li>
                            <li>Allez dans "Gérer mes enfants" pour créer une fiche enfant.</li>
                            <li>Parcourez les séjours, choisissez celui qui vous plaît et cliquez sur "Réserver".</li>
                            <li>Suivez les étapes pour finaliser l'inscription.</li>
                        </ol></div>
            </details>
            <details class="faq-item">
                <summary><span>Je suis un organisateur, comment proposer mes séjours ?</span><span class="chev"><i class="fa-solid fa-chevron-down"></i></span></summary>
                <div class="faq-answer">moncamp.fr est ouvert aux organisateurs professionnels. Vous devez créer un compte "Directeur" ou faire une demande pour le devenir. 
                        Rendez-vous dans la section <a href="organisateurs.php" class="font-bold underline text-[#3d6a8e]">Espace Organisateur</a> pour en savoir plus et commencer vos démarches.</div>
            </details>
            <details class="faq-item">
                <summary><span>Le paiement est-il sécurisé ?</span><span class="chev"><i class="fa-solid fa-chevron-down"></i></span></summary>
                <div class="faq-answer">Oui, absolument. Nous utilisons <strong>Stripe</strong>, une plateforme de paiement mondialement reconnue, pour sécuriser toutes les transactions effectuées sur notre site. Vos coordonnées bancaires ne sont jamais stockées sur nos serveurs.</div>
            </details>
        </div>
    </section>

</main>

<script>
function toggleAutreInput() {
    const select = document.getElementById('motif-select');
    const divAutre = document.getElementById('div-autre');
    const inputAutre = document.getElementById('input-autre');
    if (select.value === 'Autre') {
        divAutre.classList.remove('hidden');
        inputAutre.setAttribute('required', 'required');
        setTimeout(() => inputAutre.focus(), 100);
    } else {
        divAutre.classList.add('hidden');
        inputAutre.removeAttribute('required');
        inputAutre.value = '';
    }
}
document.addEventListener('DOMContentLoaded', toggleAutreInput);
</script>

<?php require_once 'partials/footer.php'; ?>

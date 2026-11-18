<?php
// Fichier: view_reply.php
require_once 'api/config.php';
$body_class = 'bg-white';
require_once 'partials/header.php';

$token = $_GET['t'] ?? '';
$message = null;

if ($token) {
    $stmt = $pdo->prepare("SELECT * FROM contact_messages WHERE token = ? AND statut = 'Traité'");
    $stmt->execute([$token]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<title>Réponse du support - moncamp.fr</title>
<style>
    .reply-content a { color: var(--ds-accent); text-decoration: underline; font-weight: 600; }
    .reply-content ul { list-style-type: disc; padding-left: 1.5rem; margin-bottom: 1rem; }
    .reply-content ol { list-style-type: decimal; padding-left: 1.5rem; margin-bottom: 1rem; }
    .reply-content p { margin-bottom: 0.75rem; }
    .reply-content strong { font-weight: 700; color: var(--ds-ink); }
</style>

<main class="ds-page" style="max-width:760px">
<?php if ($message): ?>
    <div class="text-center mb-8">
        <span class="ds-badge">Dossier résolu</span>
        <h1 class="ds-h1 mt-3">Réponse du support</h1>
        <p class="ds-lead">Suite à votre demande du <?= date('d/m/Y', strtotime($message['created_at'])) ?></p>
    </div>

    <section class="ds-card overflow-hidden mb-6" aria-label="Votre message">
        <div class="bg-[#f8f9fa] px-6 py-3 border-b border-[#e5e7eb]"><h2 class="ds-eyebrow">Votre message</h2></div>
        <p class="p-6 text-[#4b5964] italic leading-relaxed">« <?= nl2br(htmlspecialchars($message['message'])) ?> »</p>
    </section>

    <section class="ds-card overflow-hidden" aria-label="Réponse de l'équipe">
        <div class="bg-[#2f3d45] px-6 py-4 text-white">
            <h2 class="font-extrabold text-lg leading-tight">L'équipe moncamp.fr</h2>
            <p class="text-xs text-white/70">Répondu le <?= date('d/m/Y à H:i', strtotime($message['replied_at'])) ?></p>
        </div>
        <div class="p-6 sm:p-8">
            <div class="reply-content text-[#26343b] text-[17px] leading-relaxed"><?= $message['reponse'] ?></div>
            <div class="mt-8 pt-6 border-t border-[#e5e7eb] flex items-center gap-3">
                <img src="favico.png" alt="" class="w-8 h-8 opacity-80">
                <p class="text-sm text-[#6b7780] font-semibold">Cordialement, le service support.</p>
            </div>
        </div>
    </section>

    <div class="text-center mt-10"><a href="/" class="ds-btn ds-btn-light">Retour à l'accueil</a></div>
<?php else: ?>
    <div class="ds-card p-12 text-center ds-fade">
        <h1 class="text-2xl font-extrabold text-[#26343b]">Lien invalide ou expiré</h1>
        <p class="ds-lead mb-8">Nous ne trouvons pas cette réponse.</p>
        <a href="contact.php" class="ds-btn">Nous contacter</a>
    </div>
<?php endif; ?>
</main>

<?php require_once 'partials/footer.php'; ?>

<?php
// partials/footer3.php : pied de page de l'accueil (fidèle à la maquette)
// Les liens pointent vers des pages existantes ; legal.php regroupe les pages légales.
?>
<footer class="bg-[#434e5a] text-white mt-16" role="contentinfo">
    <div class="w-full max-w-[1320px] mx-auto px-6 sm:px-10 pt-14 pb-16">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-[1.1fr_1fr_1.3fr_1.1fr]">

            <!-- Marque -->
            <div>
                <a href="./" aria-label="moncamp.fr, accueil"><?php require_once __DIR__ . '/logo.php'; echo mc_logo('light', true, 32); ?></a>
                <p class="mt-3 text-[15px] leading-snug text-white/90 max-w-[230px]">Prêt à trouver une nouvelle aventure pour vos enfants ?</p>
                <div class="mt-5 flex flex-wrap gap-2.5">
                    <button type="button" data-soon class="inline-flex items-center gap-2 h-10 pl-3 pr-4 rounded-lg bg-black border border-white/30 text-white hover:bg-[#161616] hover:border-white/60 transition cursor-pointer" aria-label="Télécharger dans l'App Store (bientôt disponible)">
                        <i class="fa-brands fa-apple text-[20px]"></i>
                        <span class="leading-none text-left"><span class="block text-[8px] text-white/80">Télécharger dans</span><span class="block text-[13px] font-semibold -mt-px">l'App Store</span></span>
                    </button>
                    <button type="button" data-soon class="inline-flex items-center gap-2 h-10 pl-3 pr-4 rounded-lg bg-black border border-white/30 text-white hover:bg-[#161616] hover:border-white/60 transition cursor-pointer" aria-label="Télécharger sur Google Play (bientôt disponible)">
                        <i class="fa-brands fa-google-play text-[18px] text-[#34d1a0]"></i>
                        <span class="leading-none text-left"><span class="block text-[8px] text-white/80 uppercase">Télécharger sur</span><span class="block text-[13px] font-semibold -mt-px">Google Play</span></span>
                    </button>
                </div>
            </div>

            <!-- À propos -->
            <nav aria-label="À propos de moncamp.fr">
                <h2 class="text-[16px] font-bold mb-4">À propos de moncamp.fr</h2>
                <ul class="space-y-3 text-[15px] text-white/85">
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="a_propos">Qui sommes-nous ?</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="contact">Contactez-nous</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="aide#animateurs">Nous rejoindre</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="contact">Partenariat</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="contact">Lettre Socle</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="contact">Faire un don</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="contact">Le Blog</a></li>
                </ul>
            </nav>

            <!-- Aide -->
            <nav aria-label="Besoin d'aide">
                <h2 class="text-[16px] font-bold mb-4">Besoin D'aide ?</h2>
                <ul class="space-y-3 text-[15px] text-white/85">
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="aide#parents">Comment inscrire mon enfant ?</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="aide#organisateurs">Comment puis-je devenir organisateur ?</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="aide#paiements">Quels sont les coûts de moncamp.fr ?</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="classement">Comment sont classés les séjours ?</a></li>
                </ul>
            </nav>

            <!-- Légal -->
            <nav aria-label="Informations légales">
                <h2 class="text-[16px] font-bold mb-4">Informations légales</h2>
                <ul class="space-y-3 text-[15px] text-white/85">
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="legal#mentions">Mentions légales</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="legal#cgu">CGU</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="legal#cgv">Conditions de vente</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="legal#confidentialite">Politique de confidentialité</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="legal#cookies">Politique cookies</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="legal#cookies" data-cookies>Gérer les cookies</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="mes_donnees">Mes données</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="legal#accessibilite">Accessibilité</a></li>
                </ul>
            </nav>
        </div>

        <div class="mt-12 pt-6 border-t border-white/15 flex flex-wrap items-center justify-between gap-3">
            <p class="text-[12px] text-white/60">&copy; <?= date('Y') ?> moncamp.fr. <?= t('Tous droits réservés.') ?></p>
            <nav aria-label="<?= t('Langue') ?>" class="flex items-center gap-1 text-[13px]">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="text-white/60 mr-1" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3z"/></svg>
                <?php foreach (['fr' => 'Français', 'en' => 'English'] as $code => $nom): $on = mc_lang() === $code; ?>
                <a href="<?= htmlspecialchars(mc_switch_url($code)) ?>" hreflang="<?= $code ?>" lang="<?= $code ?>" class="inline-flex items-center min-h-[40px] px-3 rounded-lg <?= $on ? 'bg-white/15 font-bold text-white' : 'text-white/75 hover:text-white hover:bg-white/10' ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= $nom ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
    </div>
</footer>
<div id="mc-consent" role="dialog" aria-labelledby="mc-c-titre" aria-describedby="mc-c-texte" hidden class="fixed z-[120] inset-x-3 bottom-3 sm:inset-x-auto sm:left-1/2 sm:-translate-x-1/2 sm:w-[640px] max-h-[calc(100vh-24px)] overflow-y-auto rounded-2xl bg-white text-[#26343b] border border-[#e5e7eb] shadow-2xl p-5 sm:p-6" style="margin-bottom:env(safe-area-inset-bottom)">
    <style>#mc-consent[hidden], #mc-consent [hidden] { display: none !important; }</style>
    <h2 id="mc-c-titre" class="text-[17px] font-extrabold">Vos choix sur les cookies</h2>
    <p id="mc-c-texte" class="mt-2 text-[14px] leading-relaxed text-[#3b4a54]">Des cookies nécessaires font fonctionner le site. Avec votre accord, nous utilisons aussi des cookies de mesure d’audience et de publicité (Google AdSense), qui financent le service. Refuser est sans conséquence. Choix gardé 6 mois, modifiable via « Gérer les cookies » en bas de page. <a href="legal#cookies" class="underline font-semibold">En savoir plus</a></p>
    <div id="mc-c-reglages" hidden class="mt-4 divide-y divide-[#e5e7eb] border-y border-[#e5e7eb]">
        <div class="py-3 flex items-start justify-between gap-4"><div><p class="font-bold text-[14px]">Nécessaires</p><p class="text-[13px] text-[#6b7780]">Connexion, sécurité, panier d’inscription, mémorisation de ce choix et de la langue. Toujours actifs : le site ne fonctionne pas sans eux.</p></div><span class="text-[12px] font-bold text-[#6b7780] whitespace-nowrap pt-1">Toujours actifs</span></div>
        <label class="py-3 flex items-start justify-between gap-4 cursor-pointer"><span><span class="block font-bold text-[14px]">Mesure d’audience</span><span class="block text-[13px] text-[#6b7780]">Outils tiers de statistiques de fréquentation. Les statistiques internes de moncamp.fr fonctionnent sans cookie.</span></span><span class="ds-switch mt-1"><input type="checkbox" id="mc-c-aud"><span></span></span></label>
        <label class="py-3 flex items-start justify-between gap-4 cursor-pointer"><span><span class="block font-bold text-[14px]">Publicité</span><span class="block text-[13px] text-[#6b7780]">Annonces Google personnalisées selon votre navigation. Sans votre accord, Google peut afficher des annonces non personnalisées, sans cookie publicitaire.</span></span><span class="ds-switch mt-1"><input type="checkbox" id="mc-c-pub"><span></span></span></label>
        <div class="py-3 flex justify-end"><button type="button" id="mc-c-ok" class="ds-btn ds-btn-light">Enregistrer mes choix</button></div>
    </div>
    <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-2">
        <button type="button" id="mc-c-refus" class="ds-btn justify-center">Tout refuser</button>
        <button type="button" id="mc-c-perso" class="ds-btn ds-btn-light justify-center">Personnaliser</button>
        <button type="button" id="mc-c-accepte" class="ds-btn justify-center">Tout accepter</button>
    </div>
</div>
<div id="mc-install" hidden role="dialog" aria-labelledby="mc-install-t" class="fixed z-[90] left-3 right-3 sm:left-auto sm:right-4 sm:w-[400px] rounded-2xl bg-white text-[#26343b] border border-[#e5e7eb] shadow-2xl p-3 pl-4 flex items-center gap-3" style="bottom:calc(12px + env(safe-area-inset-bottom))">
    <style>#mc-install[hidden], #mc-install [hidden] { display: none !important; }</style>
    <img src="/assets/icons/icon-192.png" alt="" width="44" height="44" class="w-11 h-11 rounded-xl flex-none">
    <div class="min-w-0 flex-1">
        <p id="mc-install-t" class="text-[14px] font-bold leading-tight">Installer l’application</p>
        <p data-android class="text-[12.5px] text-[#6b7780] leading-snug mt-0.5">Accès direct depuis l’écran d’accueil.</p>
        <p data-ios class="text-[12.5px] text-[#6b7780] leading-snug mt-0.5">Touchez <i class="fa-solid fa-arrow-up-from-bracket" aria-label="Partager"></i> puis « Sur l’écran d’accueil ».</p>
    </div>
    <button type="button" data-install class="ds-btn ds-btn-sm flex-none">Installer</button>
    <button type="button" data-close class="ds-iconbtn flex-none" aria-label="Plus tard"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
</div>
<div id="soon-toast" role="status" aria-live="polite" class="fixed left-1/2 bottom-8 z-[100] -translate-x-1/2 translate-y-4 opacity-0 pointer-events-none transition-all duration-300 rounded-full bg-[#26343b] px-6 py-3 text-[14px] font-semibold text-white shadow-2xl">Bientôt disponible ;)</div>
<script>
(function () {
    var toast = document.getElementById('soon-toast'), timer;
    document.querySelectorAll('[data-soon]').forEach(function (b) {
        b.addEventListener('click', function () {
            toast.classList.remove('opacity-0', 'translate-y-4');
            clearTimeout(timer);
            timer = setTimeout(function () { toast.classList.add('opacity-0', 'translate-y-4'); }, 2400);
        });
    });
})();
</script>
</body>
</html>

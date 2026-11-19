<?php
$mc_reseaux = array_filter(['instagram' => '', 'facebook' => '', 'linkedin' => '', 'x-twitter' => '', 'youtube' => '']);
?>
<footer class="bg-[#26343b] text-white mt-16" role="contentinfo">
    <div class="w-full max-w-[1280px] mx-auto px-6 sm:px-10 pt-14 pb-12">
        <div class="grid gap-x-10 gap-y-12 sm:grid-cols-2 lg:grid-cols-[1.25fr_1fr_1.3fr_1fr]">
            <div>
                <a href="./" aria-label="moncamp.fr, accueil" class="inline-block"><?php require_once __DIR__ . '/logo.php'; echo mc_logo('light', true, 34); ?></a>
                <p class="mt-5 text-[15px] leading-relaxed text-white/80 max-w-[300px]">Colonies, camps et séjours pour vos enfants : trouvez, comparez et inscrivez en quelques minutes.</p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <button type="button" data-soon class="inline-flex items-center gap-2 h-11 pl-3 pr-4 rounded-lg bg-black border border-white/25 hover:border-white/60 transition" aria-label="Télécharger dans l'App Store (bientôt disponible)">
                        <i class="fa-brands fa-apple text-[21px]"></i>
                        <span class="leading-none text-left"><span class="block text-[9px] text-white/75">Télécharger dans</span><span class="block text-[14px] font-semibold">l'App Store</span></span>
                    </button>
                    <button type="button" data-soon class="inline-flex items-center gap-2 h-11 pl-3 pr-4 rounded-lg bg-black border border-white/25 hover:border-white/60 transition" aria-label="Disponible sur Google Play (bientôt disponible)">
                        <i class="fa-brands fa-google-play text-[18px] text-[#34d1a0]"></i>
                        <span class="leading-none text-left"><span class="block text-[9px] text-white/75 uppercase">Disponible sur</span><span class="block text-[14px] font-semibold">Google Play</span></span>
                    </button>
                </div>
                <div class="mt-8 flex items-center gap-3">
                    <span class="flex-none inline-flex items-center justify-center w-12 h-12 rounded-full border-2 border-white/80"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="10.5" width="16" height="10" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/></svg></span>
                    <p class="text-[14.5px] font-semibold leading-snug">Paiement sécurisé<br><span class="font-normal text-white/70">et données de santé chiffrées</span></p>
                </div>
            </div>

            <nav aria-label="À propos de moncamp.fr">
                <h2 class="font-display text-[17px] mb-5">À propos de moncamp.fr</h2>
                <ul class="space-y-3.5 text-[15px]">
                    <li><a class="text-white/80 hover:text-white transition-colors" href="a_propos">Qui sommes-nous ?</a></li>
                    <li><a class="text-white/80 hover:text-white transition-colors" href="contact">Contactez-nous</a></li>
                    <li><a class="text-white/80 hover:text-white transition-colors" href="aide#animateurs">Nous rejoindre</a></li>
                    <li><a class="text-white/80 hover:text-white transition-colors" href="contact">Partenariat</a></li>
                    <li><a class="text-white/80 hover:text-white transition-colors" href="organisme">Tous les organismes</a></li>
                </ul>
            </nav>

            <nav aria-label="Besoin d'aide">
                <h2 class="font-display text-[17px] mb-5">Besoin d’aide ?</h2>
                <ul class="space-y-3.5 text-[15px]">
                    <li><a class="text-white/80 hover:text-white transition-colors" href="aide#parents">Comment inscrire mon enfant ?</a></li>
                    <li><a class="text-white/80 hover:text-white transition-colors" href="aide#organisateurs">Comment devenir organisateur ?</a></li>
                    <li><a class="text-white/80 hover:text-white transition-colors" href="aide#paiements">Quels sont les coûts de moncamp.fr ?</a></li>
                    <li><a class="text-white/80 hover:text-white transition-colors" href="classement">Comment sont classés les séjours ?</a></li>
                    <li><a class="text-white/80 hover:text-white transition-colors" href="aide">Autres questions fréquentes</a></li>
                </ul>
            </nav>

            <div>
                <nav aria-label="Informations légales">
                    <h2 class="font-display text-[17px] mb-5">Informations légales</h2>
                    <ul class="space-y-3.5 text-[15px]">
                        <li><a class="text-white/80 hover:text-white transition-colors" href="legal#mentions">Mentions légales</a></li>
                        <li><a class="text-white/80 hover:text-white transition-colors" href="legal#cgu">CGU</a></li>
                        <li><a class="text-white/80 hover:text-white transition-colors" href="legal#cgv">Conditions de vente</a></li>
                        <li><a class="text-white/80 hover:text-white transition-colors" href="legal#confidentialite">Politique de confidentialité</a></li>
                        <li><a class="text-white/80 hover:text-white transition-colors" href="legal#cookies">Cookies</a></li>
                        <li><a class="text-white/80 hover:text-white transition-colors" href="legal#cookies" data-cookies>Gérer les cookies</a></li>
                        <li><a class="text-white/80 hover:text-white transition-colors" href="mes_donnees">Mes données</a></li>
                        <li><a class="text-white/80 hover:text-white transition-colors" href="legal#accessibilite">Accessibilité</a></li>
                    </ul>
                </nav>
                <h2 class="font-display text-[17px] mt-9 mb-3"><label for="mc-langue">Langue du site</label></h2>
                <div class="relative w-full max-w-[220px]">
                    <select id="mc-langue" class="w-full h-12 appearance-none rounded-lg bg-white text-[#26343b] text-[15px] pl-4 pr-10 border-0 focus:ring-2 focus:ring-[#8fb3d0] cursor-pointer" onchange="location.href=this.value">
                        <?php foreach (['fr' => 'Français', 'en' => 'English'] as $code => $nom): ?>
                        <option value="<?= htmlspecialchars(mc_switch_url($code)) ?>" lang="<?= $code ?>"<?= mc_lang() === $code ? ' selected' : '' ?>><?= $nom ?></option>
                        <?php endforeach; ?>
                    </select>
                    <svg class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-[#26343b]" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </div>
            </div>
        </div>
    </div>
    <div class="border-t border-white/15">
        <div class="w-full max-w-[1280px] mx-auto px-6 sm:px-10 pt-6 flex flex-col-reverse sm:flex-row items-center justify-between gap-4" style="padding-bottom:calc(1.5rem + env(safe-area-inset-bottom))">
            <div class="flex items-center gap-5 text-[20px] text-white/85">
                <?php foreach ($mc_reseaux as $ico => $url): ?>
                <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener" class="hover:text-white" aria-label="<?= ucfirst($ico) ?>"><i class="fa-brands fa-<?= $ico ?>"></i></a>
                <?php endforeach; ?>
            </div>
            <p class="text-[14px] text-white/75">&copy; <?= date('Y') ?> moncamp.fr — <?= t('Tous droits réservés.') ?></p>
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

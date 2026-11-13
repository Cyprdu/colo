<?php
// partials/footer3.php : pied de page de l'accueil (fidèle à la maquette)
// Les liens pointent vers des pages existantes ; legal.php regroupe les pages légales.
?>
<footer class="bg-[#12372a] text-white mt-16" role="contentinfo">
    <div class="w-full max-w-[1320px] mx-auto px-6 sm:px-10 pt-14 pb-16">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-[1.1fr_1fr_1.3fr_1.1fr]">

            <!-- Marque -->
            <div>
                <a href="./" class="font-display inline-block text-[24px] font-bold text-white leading-none">ColoMap</a>
                <p class="mt-3 text-[15px] leading-snug text-white/90 max-w-[230px]">Prêt à trouver une nouvelle aventure pour vos enfants ?</p>
                <div class="mt-5 flex flex-wrap gap-2.5">
                    <span class="inline-flex items-center gap-2 h-10 pl-3 pr-4 rounded-lg bg-black border border-white/30 text-white" title="Bientôt disponible">
                        <i class="fa-brands fa-apple text-[20px]"></i>
                        <span class="leading-none text-left"><span class="block text-[8px] text-white/80">Télécharger dans</span><span class="block text-[13px] font-semibold -mt-px">l'App Store</span></span>
                    </span>
                    <span class="inline-flex items-center gap-2 h-10 pl-3 pr-4 rounded-lg bg-black border border-white/30 text-white" title="Bientôt disponible">
                        <i class="fa-brands fa-google-play text-[18px] text-[#34d1a0]"></i>
                        <span class="leading-none text-left"><span class="block text-[8px] text-white/80 uppercase">Télécharger sur</span><span class="block text-[13px] font-semibold -mt-px">Google Play</span></span>
                    </span>
                </div>
            </div>

            <!-- À propos -->
            <nav aria-label="À propos de ColoMap">
                <h2 class="text-[16px] font-bold mb-4">À propos de ColoMap</h2>
                <ul class="space-y-3 text-[15px] text-white/85">
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
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="aide#paiements">Quels sont les coûts de ColoMap ?</a></li>
                </ul>
            </nav>

            <!-- Légal -->
            <nav aria-label="Informations légales">
                <h2 class="text-[16px] font-bold mb-4">Informations légales</h2>
                <ul class="space-y-3 text-[15px] text-white/85">
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="legal#mentions">Mentions légales</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="legal#cgu">CGU</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="legal#confidentialite">Politique de confidentialité</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="legal#cookies">Cookies</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="legal#accessibilite">Accessibilité</a></li>
                </ul>
            </nav>
        </div>

        <p class="mt-12 pt-6 border-t border-white/15 text-[12px] text-white/60">&copy; <?= date('Y') ?> ColoMap. Tous droits réservés.</p>
    </div>
</footer>
</body>
</html>

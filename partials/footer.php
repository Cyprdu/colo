<?php
// partials/footer3.php : pied de page de l'accueil (fidèle à la maquette)
// Les liens pointent vers des pages existantes ; legal.php regroupe les pages légales.
?>
<footer class="bg-[#434e5a] text-white mt-16" role="contentinfo">
    <div class="w-full max-w-[1320px] mx-auto px-6 sm:px-10 pt-14 pb-16">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-[1.1fr_1fr_1.3fr_1.1fr]">

            <!-- Marque -->
            <div>
                <a href="./" class="font-display inline-block text-[24px] font-bold text-white leading-none">ColoMap</a>
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
            <nav aria-label="À propos de ColoMap">
                <h2 class="text-[16px] font-bold mb-4">À propos de ColoMap</h2>
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
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="mes_donnees">Mes données</a></li>
                    <li><a class="hover:text-white hover:underline underline-offset-4" href="legal#accessibilite">Accessibilité</a></li>
                </ul>
            </nav>
        </div>

        <p class="mt-12 pt-6 border-t border-white/15 text-[12px] text-white/60">&copy; <?= date('Y') ?> ColoMap. Tous droits réservés.</p>
    </div>
</footer>
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

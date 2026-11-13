            </div><!-- /auth-card -->
            <p class="mt-6 text-center text-[12.5px] text-[#9aa3ab]">En continuant, vous acceptez nos <a href="legal#cgu" class="underline">conditions d'utilisation</a> et notre <a href="legal#confidentialite" class="underline">politique de confidentialité</a>.</p>
        </div>
    </main>
</div>

<script>
// Effet de profondeur : les couches de la scène suivent très légèrement la souris
(function () {
    var scene = document.querySelector('.auth-scene');
    if (!scene || window.matchMedia('(prefers-reduced-motion: reduce)').matches || !window.matchMedia('(hover: hover)').matches) return;
    var layers = [].slice.call(scene.querySelectorAll('.layer'));
    scene.addEventListener('mousemove', function (e) {
        var r = scene.getBoundingClientRect();
        var nx = (e.clientX - r.left) / r.width - 0.5, ny = (e.clientY - r.top) / r.height - 0.5;
        layers.forEach(function (l) {
            var d = parseFloat(l.getAttribute('data-depth')) || 0;
            l.style.transform = 'translate(' + (-nx * d).toFixed(2) + 'px,' + (-ny * d * 0.5).toFixed(2) + 'px)';
        });
    });
    scene.addEventListener('mouseleave', function () { layers.forEach(function (l) { l.style.transform = ''; }); });
})();

// Bouton en cours d'envoi : petit indicateur (la page se recharge ou redirige ensuite)
document.querySelectorAll('form[data-auth]').forEach(function (f) {
    f.addEventListener('submit', function () {
        var b = f.querySelector('.auth-btn'); if (b && f.checkValidity()) b.classList.add('is-loading');
    });
});
</script>
</body>
</html>

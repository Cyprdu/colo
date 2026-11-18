<?php
// partials/mk_shop.php : fenêtre d'achat de points (carte ou chiffre d'affaires) + petites fonctions JS communes.
// Variables attendues : $mkOrgaId (int), $mkWalletCents (int, solde utilisable), $mkCampToken (string, facultatif : retour sur boost.php)
$mkCampToken = $mkCampToken ?? '';
$mkWalletCents = max(0, (int)($mkWalletCents ?? 0));
require_once __DIR__ . '/../api/marketing_lib.php';
?>
<div id="mkShop" class="mk-modal" role="dialog" aria-modal="true" aria-labelledby="mkShopTitle">
    <div class="mk-modal-box">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start">
            <div>
                <h2 id="mkShopTitle" class="text-[20px] font-extrabold text-[#26343b]">Acheter des points</h2>
                <p class="mk-note" style="margin-top:4px">Valables pour tous les séjours de l'organisme, sans date d'expiration.</p>
            </div>
            <button type="button" onclick="mkClose()" aria-label="Fermer" class="ds-btn ds-btn-light mk-btn-sm" style="padding:8px 10px">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
            </button>
        </div>

        <div class="mk-stat" style="margin-top:16px;padding:14px 16px">
            <div class="k">Gains disponibles sur moncamp.fr</div>
            <div class="v" style="font-size:22px"><?= number_format($mkWalletCents / 100, 2, ',', ' ') ?> €</div>
            <div class="s">Votre chiffre d'affaires net de commission, pas encore versé. Payer vos points avec ce solde évite tout paiement par carte.</div>
        </div>

        <div style="margin-top:16px;display:grid;gap:10px">
        <?php $i = 0; foreach (mk_packs() as $key => $p): $i++; $eur = $p['cents'] / 100; $canWallet = $mkWalletCents >= $p['cents']; ?>
            <div class="mk-pack<?= $key === 'standard' ? ' best' : '' ?>">
                <div class="row">
                    <div>
                        <b class="text-[#26343b]"><?= htmlspecialchars($p['name']) ?></b>
                        <?php if ($key === 'standard'): ?><span class="mk-pill info" style="margin-left:6px">Le plus choisi</span><?php endif; ?>
                        <div class="mk-note"><?= $p['points'] ?> points<?php $bonus = round(($p['points'] / ($eur / MK_POINT_EUR) - 1) * 100); if ($bonus > 0): ?> · <b>+<?= $bonus ?> % offerts</b><?php endif; ?></div>
                    </div>
                    <div class="mk-price"><?= number_format($eur, 2, ',', ' ') ?> €</div>
                </div>
                <div class="acts">
                    <button type="button" class="ds-btn mk-btn-sm" <?= $canWallet ? '' : 'disabled title="Solde insuffisant"' ?> onclick="mkBuyWallet('<?= $key ?>', this)">Payer avec mes gains</button>
                    <button type="button" class="ds-btn ds-btn-light mk-btn-sm" onclick="mkBuyCard('<?= $key ?>', this)">Payer par carte</button>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <p class="mk-note" style="margin-top:14px">Paiement par carte sécurisé par Stripe. Les points achetés ne sont pas remboursables une fois dépensés. Les prix sont indiqués en euros.</p>
    </div>
</div>
<div id="mkToast" class="mk-toast" role="status" aria-live="polite"></div>
<script src="https://js.stripe.com/v3/"></script>
<script>
(function () {
    var ORGA = <?= (int)$mkOrgaId ?>, TOKEN = <?= json_encode($mkCampToken) ?>;
    function toast(msg, err) { var t = document.getElementById('mkToast'); t.textContent = msg; t.className = 'mk-toast show' + (err ? ' err' : ''); clearTimeout(window.__mkT); window.__mkT = setTimeout(function () { t.className = 'mk-toast'; }, 4500); }
    function post(url, body) {
        return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) }).then(function (r) { return r.json(); });
    }
    window.mkToast = toast; window.mkPost = post;
    window.mkOpen = function () { document.getElementById('mkShop').classList.add('open'); };
    window.mkClose = function () { document.getElementById('mkShop').classList.remove('open'); };
    document.getElementById('mkShop').addEventListener('click', function (e) { if (e.target === this) mkClose(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') mkClose(); });
    window.mkBuyCard = function (pack, btn) {
        btn.disabled = true;
        post('api/create_stripe_boost_session.php', TOKEN ? { pack: pack, token: TOKEN } : { pack: pack, organisateur_id: ORGA })
            .then(function (d) { if (d.url) { location.href = d.url; } else { toast(d.error || 'Paiement indisponible.', true); btn.disabled = false; } })
            .catch(function () { toast('Erreur réseau.', true); btn.disabled = false; });
    };
    window.mkBuyWallet = function (pack, btn) {
        btn.disabled = true;
        post('api/buy_points_wallet.php', { pack: pack, organisateur_id: ORGA })
            .then(function (d) { if (d.success) { toast('Points ajoutés à votre solde.'); setTimeout(function () { location.reload(); }, 900); } else { toast(d.message || 'Achat impossible.', true); btn.disabled = false; } })
            .catch(function () { toast('Erreur réseau.', true); btn.disabled = false; });
    };
})();
</script>

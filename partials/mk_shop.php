<?php
require_once __DIR__ . '/../api/orga_lib.php';
// partials/mk_shop.php : fenêtre d'achat de points (carte ou chiffre d'affaires) + petites fonctions JS communes.
// Variables attendues : $mkOrgaId (int, jamais affiché : seul son jeton public part au navigateur), $mkWalletCents (int, solde utilisable), $mkCampToken (string, facultatif : retour sur boost.php)
$mkCampToken = $mkCampToken ?? '';
$mkSepa = !empty(fin_sepa_info($pdo, (int)$mkOrgaId)['sepa_pm']);
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

        <div style="margin-top:16px;display:grid;gap:10px">
        <?php $i = 0; foreach (mk_packs() as $key => $p): $i++; $eur = $p['cents'] / 100; ?>
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
                    <button type="button" class="ds-btn mk-btn-sm" onclick="mkBuyCard('<?= $key ?>', this)">Payer par carte</button>
                    <?php if ($mkSepa): ?><button type="button" class="ds-btn ds-btn-light mk-btn-sm" onclick="mkBuySepa('<?= $key ?>', this)">Payer par prélèvement</button><?php endif; ?>
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
    var ORGA = <?= json_encode(fin_orga_tok((int)$mkOrgaId)) ?>, TOKEN = <?= json_encode($mkCampToken) ?>;
    function toast(msg, err) { var t = document.getElementById('mkToast'); t.textContent = msg; t.className = 'mk-toast show' + (err ? ' err' : ''); clearTimeout(window.__mkT); window.__mkT = setTimeout(function () { t.className = 'mk-toast'; }, 4500); }
    function post(url, body) {
        return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) }).then(function (r) { return r.json(); });
    }
    window.mkToast = toast; window.mkPost = post;
    window.mkOpen = function () { document.getElementById('mkShop').classList.add('open'); };
    window.mkClose = function () { document.getElementById('mkShop').classList.remove('open'); };
    document.getElementById('mkShop').addEventListener('click', function (e) { if (e.target === this) mkClose(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') mkClose(); });
    window.mkBuySepa = async function (pack, btn) {
        if (!(await ColoUI.confirm('Le montant sera prélevé sur le compte bancaire de votre mandat SEPA. Les points sont crédités à réception du prélèvement.', { title: 'Payer par prélèvement ?', confirmLabel: 'Prélever' }))) return;
        btn.disabled = true;
        fetch('api/payer_points_sepa.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, credentials: 'same-origin', body: JSON.stringify({ pack: pack, o: ORGA, idem: newIdem() }) })
            .then(function (r) { return r.json(); })
            .then(function (d) { toast(d.message || d.error || 'Prélèvement indisponible.', !d.ok); if (!d.ok) btn.disabled = false; })
            .catch(function () { toast('Erreur réseau.', true); btn.disabled = false; });
    };
    window.mkBuyCard = function (pack, btn) {
        btn.disabled = true;
        post('api/create_stripe_boost_session.php', TOKEN ? { pack: pack, token: TOKEN } : { pack: pack, o: ORGA })
            .then(function (d) { if (d.url) { location.href = d.url; } else { toast(d.error || 'Paiement indisponible.', true); btn.disabled = false; } })
            .catch(function () { toast('Erreur réseau.', true); btn.disabled = false; });
    };
    var busy = false, PACKS = <?= json_encode(array_map(function ($p) { return ['name' => $p['name'], 'points' => $p['points'], 'cents' => $p['cents']]; }, mk_packs())) ?>;
    function eur(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
    function csrf() { var m = document.querySelector('meta[name=csrf]'); return m ? m.content : ''; }
    function newIdem() { var a = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(a); return Array.prototype.map.call(a, function (x) { return ('0' + x.toString(16)).slice(-2); }).join(''); }
})();
</script>

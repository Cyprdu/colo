<?php
// partials/logos_paiement.php : bandeau « paiement sécurisé » avec les moyens proposés par Stripe Checkout (CB, Visa, Mastercard, Apple Pay, Google Pay).
// Logos officiels stockés dans assets/img/paiement (voir SOURCES.md). Affichage : echo logos_paiement(). Le choix réel des moyens (selon l'appareil et le pays) est fait par Stripe.
require_once __DIR__ . '/../api/options.php';
if (!function_exists('logos_paiement')) {
    function logos_paiement(bool $note = true): string {
        $h = '<div class="mt-5 text-center"><p class="text-xs text-[#6b7780] mb-3 flex items-center justify-center gap-1.5"><svg class="w-3.5 h-3.5 text-[#3b4a54]" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>Paiement sécurisé par Stripe · données bancaires jamais conservées par moncamp.fr</p>'
           . '<div class="flex justify-center gap-1.5">' . implode('', array_map(fn($i) => '<span style="display:block;width:46px">' . $i . '</span>', options_logos_carte())) . '</div>';
        if ($note) $h .= '<p class="text-[11px] text-[#8a959d] mt-3">Les moyens proposés (Apple Pay, Google Pay, Link…) dépendent de votre appareil et de votre pays. Ils s\'affichent à l\'étape de paiement.</p>';
        return $h . '</div>';
    }
}

<?php
// partials/logos_paiement.php : bandeau « paiement sécurisé » avec les moyens proposés par Stripe Checkout (carte CB / Visa / Mastercard, Apple Pay, Google Pay, Link).
// Marques en SVG intégré (aucun hébergeur externe). Affichage : echo logos_paiement(). Le choix réel des moyens (selon l'appareil et le pays) est fait par Stripe.
if (!function_exists('logos_paiement')) {
    function logos_paiement(bool $note = true): string {
        $b = 'class="h-7 w-auto flex-none" role="img" viewBox="0 0 48 30"';
        $box = '<rect x=".5" y=".5" width="47" height="29" rx="4.5" fill="#fff" stroke="#d3dbe0"/>';
        $logos = [
            ['Carte Bancaire (CB)', '<svg ' . $b . ' aria-label="Carte Bancaire (CB)"><defs><linearGradient id="lpcb" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#1b4f9c"/><stop offset=".55" stop-color="#17a2a6"/><stop offset="1" stop-color="#32a852"/></linearGradient></defs><rect width="48" height="30" rx="5" fill="url(#lpcb)"/><text x="24" y="20.5" text-anchor="middle" font-family="Arial,sans-serif" font-size="14" font-weight="900" fill="#fff" letter-spacing=".5">CB</text></svg>'],
            ['Visa', '<svg ' . $b . ' aria-label="Visa">' . $box . '<text x="24" y="20.5" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="13.5" font-weight="900" font-style="italic" fill="#1a1f71" letter-spacing="-.3">VISA</text></svg>'],
            ['Mastercard', '<svg ' . $b . ' aria-label="Mastercard">' . $box . '<circle cx="19" cy="15" r="8" fill="#eb001b"/><circle cx="29" cy="15" r="8" fill="#f79e1b"/><path d="M24 8.6a8 8 0 010 12.8 8 8 0 010-12.8z" fill="#ff5f00"/></svg>'],
            ['Apple Pay', '<svg ' . $b . ' aria-label="Apple Pay"><rect width="48" height="30" rx="5" fill="#000"/><g transform="translate(9.5 6.5) scale(.7)" fill="#fff"><path d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zM15.53 3.83c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.701"/></g><text x="35" y="19.5" text-anchor="middle" font-family="Arial,sans-serif" font-size="9.5" font-weight="700" fill="#fff">Pay</text></svg>'],
            ['Google Pay', '<svg ' . $b . ' aria-label="Google Pay">' . $box . '<text x="14" y="20" text-anchor="middle" font-family="Arial,sans-serif" font-size="14" font-weight="700" fill="#4285f4">G</text><text x="31" y="19.5" text-anchor="middle" font-family="Arial,sans-serif" font-size="9.5" font-weight="700" fill="#3c4043">Pay</text></svg>'],
            ['Link', '<svg ' . $b . ' aria-label="Link"><rect width="48" height="30" rx="5" fill="#00d66f"/><text x="24" y="19.5" text-anchor="middle" font-family="Arial,sans-serif" font-size="11.5" font-weight="800" fill="#011e0f">Link</text></svg>'],
        ];
        $h = '<div class="mt-5 text-center"><p class="text-xs text-[#6b7780] mb-3 flex items-center justify-center gap-1.5"><svg class="w-3.5 h-3.5 text-[#3b4a54]" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>Paiement sécurisé par Stripe · données bancaires jamais conservées par moncamp.fr</p>'
           . '<ul class="flex flex-wrap justify-center items-center gap-2" aria-label="Moyens de paiement acceptés">';
        foreach ($logos as [$nom, $svg]) $h .= '<li title="' . htmlspecialchars($nom) . '">' . $svg . '</li>';
        $h .= '</ul>';
        if ($note) $h .= '<p class="text-[11px] text-[#8a959d] mt-3">Les moyens proposés (Apple Pay, Google Pay, Link…) dépendent de votre appareil et de votre pays. Ils s\'affichent à l\'étape de paiement.</p>';
        return $h . '</div>';
    }
}

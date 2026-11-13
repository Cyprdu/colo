# Versements aux organismes : Stripe Connect (Express)

## Architecture retenue : « separate charges and transfers »

1. La famille paie ColoMap (Checkout, inchangé).
2. Le webhook (`api/stripe_webhook.php`) enregistre **une ligne `fin_paiements` par inscription** : brut, commission (calculée côté serveur, `fin_commission_cents`), net, date de déblocage = début du séjour + `PAYOUT_DELAY_DAYS` (défaut 1). Le solde `organisateurs.portefeuille` est crédité du **net**.
3. `api/cron_virements.php` (toutes les heures) transfère le net des séjours débloqués vers le compte Connect Express de l'organisme (`Transfer`, clé d'idempotence `colomap-transfer-<id>`), en deux temps : réservation du solde en base (transaction), puis appel Stripe ; une erreur définitive remet tout en attente, une erreur réseau est rejouée.
4. Stripe verse ensuite l'organisme sur son IBAN (saisi chez Stripe : ColoMap ne collecte plus ni IBAN ni pièce d'identité).

Pourquoi pas les « destination charges » : l'argent partirait chez l'organisme immédiatement, avant le séjour, sans possibilité de rembourser les familles en cas d'annulation. Pourquoi Express et pas Custom (ancien système) : Stripe gère le KYC, l'onboarding et le tableau de bord ; ColoMap n'a plus à stocker de données sensibles.

## Remboursements, litiges
- `charge.refunded` : réduit le net dû (commission rendue au prorata). Si déjà versé, le solde devient négatif et bloque les versements suivants jusqu'à compensation. Remboursement total : inscription passée à « Annulé ».
- `charge.dispute.created/closed` : fonds gelés, dégelés si gagné, traités comme remboursement si perdu.

## Tables (créées automatiquement, idempotent)
`fin_paiements`, `fin_transferts`, `fin_audit`, `stripe_events`, `stripe_comptes`, `camp_stats_jour`, `boost_achats`, `points_mouvements`.

## À configurer
`api/config.php` : `STRIPE_WEBHOOK_SECRET` (existe), **`STRIPE_CONNECT_WEBHOOK_SECRET`**, **`CRON_SECRET`**, optionnel `PLATFORM_COMMISSION_PERCENT` (défaut 1.0), `PAYOUT_DELAY_DAYS` (défaut 1).
Stripe : activer Connect (Express), 2 endpoints webhook vers `https://moncamp.fr/api/stripe_webhook.php` (événements du compte : `checkout.session.completed`, `checkout.session.expired`, `charge.refunded`, `charge.dispute.created`, `charge.dispute.closed`, `transfer.reversed` ; événements des comptes connectés : `account.updated`, `payout.failed`). Cron : `0 * * * * php /chemin/api/cron_virements.php` ou GET `api/cron_virements.php?key=CRON_SECRET`.
Contrôle des calculs : `php tests/finance_check.php`.

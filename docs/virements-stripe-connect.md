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

## Commission « tout compris » et comptabilité (mise à jour)

- La commission ColoMap = **pourcentage + part fixe (centimes)** par inscription, **frais Stripe inclus** (défaut 2,5 % + 0,30 € : 25 € payés → commission 0,93 €, net organisme 24,07 €). Réglable dans **Administration > Comptabilité > Commission** (mot de passe demandé, journalisé, s'applique aux seuls paiements futurs ; chaque ligne `fin_paiements` garde son taux).
- Frais Stripe **réels** : relevés dans `balance_transaction` du PaymentIntent juste après le webhook (`fin_sync_fees`), rattrapés par `api/cron_virements.php` et par le bouton « relever maintenant ». Colonnes ajoutées automatiquement : `frais_stripe_cents` (NULL = inconnu), `frais_litige_cents`, `commission_fixe_cents`, `frais_maj`. Marge nette = commission conservée (nette des remboursements) − frais Stripe − frais de litige. Alerte : tuile « Paiements à marge négative » sur `admin.php` + bandeau dans Comptabilité.
- Remboursement : la commission est rendue au prorata, **les frais Stripe ne sont pas remboursés** par Stripe : la marge de ce paiement baisse (peut devenir négative, c'est voulu et visible). Litige : frais de litige relevés sur l'événement `charge.dispute.created`, jamais restitués même si le litige est gagné.
- Comptabilité (`admin_compta.php`) : synthèse par période / organisme / mois, rapprochement avec le solde et les virements Stripe, exports CSV (`admin_compta_export.php`, `;`, UTF-8 BOM, ré-authentification exigée, protection contre l'injection de formules). Aucun identifiant numérique dans les URLs (jeton HMAC d'organisme).

## À régler soi-même dans le dashboard Stripe (ColoMap ne peut pas le faire)
1. **Clés live** : `STRIPE_SECRET_KEY` (sk_live_...) et clés publiques dans `api/config.php` ; activer le compte (vérification d'identité de la société/du gérant, IBAN de ColoMap).
2. **Connect** : Paramètres > Connect > activer, type **Express**. Paramètres > Connect > **Branding / Personnalisation** : couleurs (#26343b, #2f3d45, crème #f8f5ea), logo (carré) et icône : c'est ce que voit l'organisme pendant l'onboarding et dans son espace Stripe.
3. **Webhooks** (2 endpoints vers `https://moncamp.fr/api/stripe_webhook.php`) : « Votre compte » (`checkout.session.completed`, `checkout.session.expired`, `charge.refunded`, `charge.dispute.created`, `charge.dispute.closed`, `transfer.reversed`) → `STRIPE_WEBHOOK_SECRET` ; « Comptes connectés » (`account.updated`, `payout.failed`) → `STRIPE_CONNECT_WEBHOOK_SECRET`.
4. **Virements de ColoMap vers sa banque : passer en MANUEL** (Paramètres > Activité de l'entreprise > Virements / Compte bancaire et devises). Un virement automatique vide le solde Stripe, argent des organismes compris, et les transferts vers eux échouent. Virer uniquement le « montant versable » affiché dans Comptabilité > Rapprochement.
5. **Échéancier de versement des organismes vers leur banque** : Paramètres > Connect > Paramètres des comptes connectés > virements (quotidien par défaut ; hebdomadaire/mensuel possible).
6. Vérifier la **tarification réelle** (Paramètres > Facturation > Tarification) : cartes UE, UK, hors Europe, litige, frais Connect Express (abonnement par compte actif + frais par virement vers l'organisme) ; ajuster la commission si la marge est négative.
7. Cron : `0 * * * * php /chemin/api/cron_virements.php` (déblocages + relevé des frais).

## Calendrier des versements (hebdomadaire, avant le séjour)
- **Jour fixe par organisme** (`organisateurs.jour_versement`, 1 lundi à 6 samedi, jamais dimanche) : attribué automatiquement au jour le moins chargé (nombre d'organismes, puis volume), migration idempotente des organismes existants. Modifiable seulement par un administrateur (Comptabilité > Versements, mot de passe demandé). Le cron s'exécute **chaque jour** et ne verse que les organismes dont c'est le jour, en rattrapant les jours manqués (7 max) : `0 6 * * * php /chemin/api/cron_virements.php`.
- **Éligibilité** : une inscription est versable `délai de sécurité` jours après son paiement (défaut 7, réglable), sans attendre le séjour. À la mise en service, les paiements déjà en attente sont ramenés à ce délai (migration unique).
- **Réserve de remboursement** (défaut 20 %, réglable) : retenue sur le net tant qu'un remboursement est encore possible (séjour non commencé et fenêtre « 50 % » de la politique d'annulation ouverte). Libérée ensuite, versée au passage suivant (versements partiels suivis par `fin_paiements.verse_cents` et `fin_transfert_lignes`).
- **Remboursement / litige perdu après versement** : demande de reversement (`fin_reversals`), exécutée par `transfers->createReversal` (une opération `fin_reversal_ops` par appel, clé d'idempotence `colomap-rev-<id>`, rejouée à l'identique en cas de coupure réseau). Si Stripe refuse (solde du compte connecté insuffisant) : **dette** (`statut = dette`), solde de l'organisme négatif, compensé automatiquement sur ses prochains versements, alerte sur `admin.php` ; un administrateur peut retenter.
- Tests : `php tests/finance_check.php` ; intégration (base jetable) : `FIN_TEST_DSN="mysql:host=127.0.0.1;port=3399;dbname=t" php tests/versements_integration.php`.
- **À vérifier côté Stripe** : un reversement prélève le **solde disponible** du compte Express de l'organisme ; s'il a déjà été viré sur sa banque (virements quotidiens par défaut), le reversement échoue et devient une dette. Pour limiter ce cas : programme de virement hebdomadaire/mensuel pour les comptes connectés (Paramètres > Connect), et la réserve de 20 %. Le solde Stripe de ColoMap doit rester suffisant pour les transferts (virements bancaires de ColoMap en manuel).

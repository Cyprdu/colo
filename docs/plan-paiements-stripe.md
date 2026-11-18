# Plan : paiements et versements 100 % pilotés par Stripe, tableau de bord clair

Document destiné à une session Claude Opus 5.5 qui applique les changements. Lire d'abord `api/finance_lib.php` (en-tête), `api/versements_lib.php`, `api/cron_virements.php`, `api/stripe_webhook.php`, `api/create_stripe_session_multi.php`, `api/annulation_lib.php`, `api/compta_lib.php`, `dashboard_organisme.php`, `api/dashboard_stats.php`, `admin_compta.php`.

## 0. Réponses aux questions du propriétaire

- **Le tableau de bord Stripe est-il obligatoire ?** Non. Les comptes Connect Express donnent un tableau de bord Stripe, mais il est facultatif. Tout ce qu'il affiche (solde, paiements, virements, commissions, remboursements) est lisible par l'API Stripe : on peut donc tout afficher dans `dashboard_organisme.php`. On garde Stripe uniquement pour ce qu'il fait mieux que nous : vérification d'identité (KYC), IBAN, exécution des virements bancaires, litiges. Le lien « Ouvrir Stripe » reste un petit bouton secondaire.
- **Pourquoi c'est confus aujourd'hui :** le modèle actuel est « charges et transferts séparés ». La famille paie moncamp.fr, l'argent reste sur NOTRE solde Stripe, puis un cron transfère au compte de l'organisme un jour de la semaine, avec 20 % de réserve et un délai de 7 jours. Résultat : l'organisme voit un paiement de la famille dans nos écrans mais RIEN dans son Stripe pendant des jours, et deux logiques (site et Stripe) ne racontent pas la même histoire.
- **Objectif :** une seule vérité lisible : « la famille paie X € → moncamp.fr garde Y € de commission → Z € arrivent sur VOTRE compte Stripe → versés sur votre banque le … ».

## 1. Décision d'architecture (recommandée) : « destination charges »

Passer de « separate charges and transfers » à **destination charges avec `application_fee_amount`** :

- Checkout Session créée avec `payment_intent_data[transfer_data][destination] = acct_xxx` et `payment_intent_data[application_fee_amount] = commission en centimes`, plus `on_behalf_of = acct_xxx` (le relevé bancaire de la famille affiche l'organisme).
- À l'encaissement, Stripe place automatiquement le net sur le compte Connect de l'organisme (visible dans SON solde Stripe) et la commission sur le nôtre. Plus de transfert manuel, plus de cron de transferts, plus de jour de versement hebdomadaire.
- Les frais Stripe sont facturés à la plateforme : la commission « tout compris » actuelle (2,5 % + 0,30 €) les couvre déjà. Garder le calcul serveur unique `fin_commission_split()`. Garder le relevé des frais réels (`balance_transaction`) pour la marge admin.
- **Plus de réserve de 20 % retenue sur le site.** Le risque d'annulation est géré autrement (section 2).
- Compatibilité : les paiements déjà enregistrés avec l'ancien modèle restent traités par l'ancien code (colonne `fin_paiements.mode` = `legacy` / `destination`). Ne pas migrer l'historique.

Si le propriétaire refuse d'envoyer l'argent à l'organisme avant le séjour, utiliser la variante de la section 2.

## 2. Protection contre les remboursements, sans réserve cachée sur notre site

Rien ne doit rester caché sur notre solde. Utiliser les réglages de versement du compte Connect de l'organisme :

- Mettre `settings.payouts.schedule` de chaque compte à `manual` (ou `delay_days` fixe) via l'API à la création et à la synchronisation du compte. L'argent est alors dans le solde Stripe de l'organisme, visible par lui, mais ne part vers sa banque qu'au déclenchement.
- Déclenchement automatique par un job unique (le cron horaire `api/cron_tout.php` existe déjà) : créer un `Payout` sur le compte connecté (en-tête `Stripe-Account`) pour la part dont le séjour a démarré depuis N jours (réglage admin, défaut 3 jours). Le cron reste le seul endroit qui décide ; plus de « jour de la semaine ».
- Remboursement : `refunds.create` avec `reverse_transfer = true` et, selon la politique d'annulation, `refund_application_fee` vrai ou faux. Si le solde de l'organisme est insuffisant, Stripe peut créer un solde négatif : décider avec le propriétaire (valeur par défaut : récupération par compensation sur les prochains encaissements, afficher « à compenser » dans le dashboard).
- Alternative plus simple si le propriétaire accepte : versements automatiques quotidiens Stripe (`interval = daily`, `delay_days` = 7) et AUCUN job de notre côté.

À trancher et à écrire en tête du code : qui supporte le risque de solde négatif, et la durée du délai.

## 3. Tableau de bord organisme (`dashboard_organisme.php`) : une page lisible

Principes : peu de texte, bulles « ? » pour le détail, pas de jargon, une seule couleur d'accent, mobile d'abord.

1. **En-tête** : nom de l'organisme, état du compte Stripe (Prêt / Action requise avec bouton unique « Compléter mon compte »), bouton secondaire « Ouvrir mon espace Stripe ».
2. **Bloc « Où va l'argent »** (la cascade, toujours visible, non filtrée par défaut) : Encaissé par les familles → Commission moncamp.fr (bulle « ce que couvre la commission : frais de paiement, hébergement, support ») → **Pour vous** → Déjà versé sur votre banque → En attente de versement (avec la prochaine date et la règle en une phrase). Chaque ligne cliquable ouvre la liste des paiements concernés.
3. **Prochains versements** : tableau court (date prévue, montant, séjours concernés), statut Stripe (en cours, payé, échoué avec raison).
4. **Activité** (graphiques) : courbe encaissé par mois et inscriptions par mois, remplissage par séjour.
5. **Filtres** : une seule barre repliée par défaut « Filtrer ▾ » ; les champs (Type, Séjour, Session, Moyen de paiement, Période avec raccourcis 30 j / 3 mois / 12 mois) ne s'appliquent QUE quand on clique sur « Appliquer » (jamais au changement d'un champ). Bouton « Réinitialiser ». Période par défaut : 12 mois. Les chiffres de la cascade et des versements ne dépendent pas des filtres ; les graphiques et la liste de paiements, oui. Écrire cette règle dans une bulle.
6. **Liste des paiements** (tableau + clic = détail) : date, famille, séjour, brut, commission, pour vous, statut, reçu PDF. Export CSV.
7. **Identifiant d'URL** : remplacer `organisateur_id=13` par un jeton long opaque (chantier « identifiants opaques », à coordonner ; ne pas laisser le numéro).

## 4. Tableau de bord admin (`admin_compta.php`)

- Une page « Commission » : commission encaissée (période), frais Stripe réels, marge nette, par organisme et par mois ; écart de rapprochement avec Stripe (balance transactions) en rouge si différent de zéro.
- Journal comptable unique, en ajout seulement : `fin_ledger` (id, date, type paiement / commission / frais / remboursement / versement / ajustement, organisateur, objet, montant signé, devise, référence Stripe, acteur, ip). Toutes les pages lisent ce journal ; Stripe sert à rapprocher.
- Supprimer ou masquer les réglages devenus inutiles (jour de versement, réserve 20 %) mais garder le code legacy tant qu'il reste des paiements `legacy`.

## 5. Tâches, dans cet ordre (un commit par tâche, branche dédiée, base MariaDB jetable pour les tests)

1. **Audit et cartographie** (lecture seule) : lister tout ce qui parle de réserve, jour de versement, transferts (`fin_release_due`, `fin_transfer_legacy`, `versements_lib.php`, crons, admin). Écrire le résultat dans `docs/finance-audit.md`.
2. **Journal `fin_ledger`** + fonction d'écriture unique `fin_ledger_add()` (transaction, idempotence par référence Stripe) + backfill en lecture seule des paiements existants.
3. **Création de la session Checkout** en destination charges (colonne `fin_paiements.mode`), commission via `fin_commission_split()`, `on_behalf_of`, métadonnées (inscriptions, organisateur). Un panier = un organisme (vérifier la règle actuelle).
4. **Webhook** : écrire dans `fin_paiements` et `fin_ledger` à `checkout.session.completed` / `payment_intent.succeeded` ; gérer `charge.refunded`, `charge.dispute.*`, `payout.paid` / `payout.failed` (compte connecté), `account.updated`. Idempotence via `stripe_events`.
5. **Versements** : réglage du schedule du compte Connect à la synchronisation du compte, job de payout conditionnel dans `cron_tout.php` (ou schedule quotidien Stripe selon la décision de la section 2) ; supprimer la notion de « jour de versement » de l'interface ; garder le bouton admin « Lancer le versement maintenant » et le brancher sur `Payout`.
6. **Remboursements et annulations** (`annulation_lib.php`) : `reverse_transfer` / `refund_application_fee` selon la politique, écriture au journal, cas « solde négatif ».
7. **Dashboard organisme** (section 3) : `api/dashboard_stats.php` lit `fin_ledger`, filtres appliqués au clic uniquement, cascade, versements, liste + CSV + reçus.
8. **Dashboard admin** (section 4) + rapprochement avec Stripe.
9. **Textes** : une page d'aide courte « Comprendre mes paiements » (une cascade illustrée, 6 questions fréquentes), bulles « ? » dans le dashboard, mise à jour de `legal.php` (conditions de commission et de versement) et du texte envoyé aux directeurs.
10. **Tests et déploiement** : `tests/finance_check.php` étendu (commission, arrondis, remboursement partiel et total, litige, solde négatif, webhook rejoué, double clic), test d'intégration sur base jetable, essai de bout en bout en mode TEST Stripe avec la carte 4242 puis une carte européenne et une non européenne, vérification que le net arrive bien sur le compte connecté de test.

## 6. Critères de réussite

- Un paiement test apparaît en moins d'une minute dans : le solde Stripe du compte connecté, le dashboard organisme et le journal admin, avec les mêmes montants (brut, commission, net).
- Un organisme comprend en 10 secondes : combien il a encaissé, combien est à lui, ce que moncamp.fr garde, quand l'argent part.
- Plus aucun montant ne reste sur la plateforme sans être affiché comme « en attente » avec une date et une règle.
- Les filtres ne déclenchent aucun rechargement tant qu'on n'a pas cliqué « Appliquer ».
- Aucune donnée bancaire sur notre serveur ; toutes les écritures d'argent sont journalisées (qui, quand, IP, avant et après).

## 7. Décisions retenues (valeurs par défaut validées, appliquer sans redemander)

1. Le net arrive sur le compte Stripe de l'organisme dès le paiement ; le versement vers sa banque est différé (schedule `manual` piloté par le cron).
2. Remboursement après versement : supporté par l'organisme, récupéré par compensation sur ses encaissements suivants ; affiché « à compenser ».
3. Commission en cas d'annulation : conserver la règle actuelle de `annulation_lib.php` (la documenter dans le dashboard et `legal.php`).
4. Versement bancaire : 3 jours après le début du séjour (réglable dans l'admin).
5. Commission « tout compris » inchangée (frais hors UE inclus) ; afficher la marge réelle par paiement dans l'admin pour suivre le risque.

## 8. Points à valider avec le propriétaire (historique)

1. L'argent peut-il arriver sur le compte Stripe de l'organisme dès le paiement (avec versement bancaire différé par Stripe), ou doit-il rester chez nous jusqu'au séjour ? (recommandation : dès le paiement, versement bancaire différé de quelques jours.)
2. Qui supporte un remboursement quand l'organisme a déjà été versé ? (recommandation : l'organisme, par compensation.)
3. La commission est-elle remboursée en cas d'annulation ? (valeur actuelle à confirmer dans `annulation_lib.php`.)
4. Délai avant versement bancaire : 3 jours après le début du séjour, ou 7 ?
5. Frais de Stripe sur cartes hors UE : toujours inclus dans la commission « tout compris » (marge négative possible, déjà observée) ou supplément ?

# Audit finances : réserve, jour de versement, transferts (avant passage aux « destination charges »)

Cartographie du code « separate charges and transfers » et sort de chaque élément. `legacy` = paiements déjà enregistrés (colonne `fin_paiements.mode`), traités jusqu'au bout par l'ancien circuit.

| Élément | Où | Rôle actuel | Après |
|---|---|---|---|
| `organisateurs.portefeuille` | `finance_lib` (crédit net au paiement), `versements_lib` (débit au transfert), `moyens_paiement_lib` (commission hors carte), `gains_lib` (points), `equipe.php`, `fin_apply_refund` | Solde dû par moncamp.fr à l'organisme | Legacy : inchangé. Destination : jamais crédité (le net est chez Stripe) ; un solde **négatif** (commission hors carte, points, dette legacy) est récupéré sur la commission (`application_fee_amount`) des paiements suivants |
| Jour de versement (`organisateurs.jour_versement`, `FIN_JOURS`, `fin_assign_jours`, `fin_set_jour`, `fin_next_payout_date`, `fin_days_due`, réglage `cron_dernier_jour`) | `versements_lib`, `admin_compta` (onglet Versements), `compta_versements`, `fin_overview`, `dashboard_organisme` | Un organisme n'est versé qu'un jour fixe par semaine | Retiré de l'interface ; le cron legacy tourne chaque heure sans condition de jour. Colonnes conservées (pas de migration destructive) |
| Réserve 20 % (`reserve_pct`, `fin_reserve_needed`, `fin_reserve_release_day`, `fin_row_parts`) | `versements_lib`, `admin_compta`, `controle_lib`, `dashboard_organisme` | Part du net gardée sur NOTRE solde tant qu'une annulation est possible | Legacy seulement (réglage masqué, valeur conservée). Destination : aucune réserve, l'argent est sur le compte Stripe de l'organisme, versement bancaire différé |
| Délai de sécurité (`delai_securite_jours`, `fin_release_date`, `date_deblocage`) | `versements_lib`, `fin_record_checkout`, `equipe_confirmer` | Paiement versable 7 j après encaissement | Legacy seulement. Destination : `date_deblocage` = début du séjour + `payout_jours` (défaut 3, admin) |
| Transferts (`fin_release_due`, `fin_prepare_transfer`, `fin_execute_transfer`, `fin_retry_pending`, `fin_transferts`, `fin_transfert_lignes`) | `versements_lib`, `cron_virements`, `admin_compta`, `admin_demande_virement` | Transfer plateforme → compte Connect | Legacy seulement (filtre `mode = 'legacy'`). `fin_transferts` porte aussi les **payouts** (`type = 'payout'`, `stripe_transfer_id` = `po_…`) |
| Solde historique (`fin_transfer_legacy`) | `admin_demande_virement` | Ancien solde sans suivi par inscription | Inchangé |
| Reversements (`fin_reversal_*`, `fin_reversals`, `fin_reversal_ops`) | `versements_lib`, webhook, `admin_compta` | Récupérer un trop-versé après remboursement | Legacy seulement. Destination : `reverse_transfer` natif au remboursement ; litige perdu → reprise (`a_reprendre_cents`) |
| Crons | `cron_tout.php` → `cron_virements.php` | Transferts du jour + frais Stripe | + versements bancaires (`fin_payouts_run`) et reprises litiges |
| Webhook | `stripe_webhook.php` | checkout, refund, dispute, account.updated, payout.failed (audit) | + `payout.paid` / `payout.failed` (statut du versement, lignes remises en attente), écriture du journal `fin_ledger` |
| Remboursements initiés par le site | `annulation_lib.php`, `equipe.php` | `refunds.create` | Destination : `reverse_transfer = true`, `refund_application_fee = false` (commission conservée, règle actuelle) |
| Checkout | `create_stripe_session_multi.php`, `create_stripe_session.php`, `equipe_inscrire.php` | Charge sur la plateforme | `fin_destination_params()` : destination + commission + `on_behalf_of` si le compte accepte les cartes. Compte non prêt → legacy (repli) |

## Règles retenues (section 7 du plan)
- Le net arrive sur le compte Stripe de l'organisme dès le paiement ; versements bancaires en `manual`, déclenchés par le cron **3 jours après le début du séjour** (réglage admin `payout_jours`).
- Montant d'un versement = min(disponible Stripe, solde Stripe − part des séjours pas encore éligibles ou en litige). L'argent legacy déjà transféré est donc versé aussi.
- Remboursement après versement : supporté par l'organisme ; le solde Stripe devient négatif et Stripe le compense sur les encaissements suivants (affiché « à compenser »).
- Annulation par la famille : commission conservée (règle actuelle de `annulation_lib.php`).
- Commission « tout compris » inchangée (`fin_commission_split()`), marge réelle par paiement dans l'admin.

## Journal `fin_ledger` (ajout seulement)
Vue de l'organisme, montants signés : `paiement` +brut, `commission` −commission (+ part rendue), `remboursement` −brut remboursé, `ajustement` (points payés avec le solde), `versement` −virement bancaire. `frais` (frais Stripe réels, à la charge de moncamp.fr) est exclu des totaux de l'organisme.
Pour vous = paiement + commission + remboursement + ajustement ; en attente = pour vous − versé (négatif = à compenser).
Les reversements ne sont pas des lignes : le remboursement a déjà réduit « pour vous ». Les transferts legacy faits après le passage en versements manuels ne sont pas des lignes non plus : c'est le payout qui compte.

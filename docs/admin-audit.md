# Audit de l'espace administrateur (18/11/2026)

## Périmètre lu
`admin.php`, `admin_lib.php`, `admin_api.php`, `admin_list.php`, `admin_compta.php`, `admin_compta_export.php`, `admin_audit.php`, `admin_db.php`, `admin_sql.php`, `admin_erreurs.php`, `tableau_de_controle.php`, redirections `admin_messages|requests|camp_requests|history_*.php`, `assets/admin.js|css`, `partials/header.php`, `contact.php`, `profile.php` + `api/request_director_access.php`, `api/process_request.php`.

## Points solides (conservés)
- `adm_guard()` : admin revérifié en base à chaque requête, session liée au navigateur, inactivité 30 min, CSRF sur tout POST, en-têtes stricts (CSP sans script en ligne).
- `adm_reauth()` : mot de passe redemandé (10 min), limitation de tentatives.
- `admin_audit` : journal en base ; console SQL avec classification, confirmation et jeton à usage unique.
- Comptabilité riche (`fin_ledger` en ajout seulement, rapprochement Stripe, dettes, ajustements, exports CSV).

## Problèmes
| # | Gravité | Constat |
|---|---|---|
| 1 | Haute | Aucun rôle : tout `is_admin=1` a tous les droits (SQL, base, exports, rôles). Impossible d'ouvrir un accès à un comptable ou au support. |
| 2 | Haute | `api/process_request.php` : se fie au drapeau de session `is_admin` (pas revérifié en base), pas de CSRF, pas d'audit. |
| 3 | Haute | `api/request_director_access.php` : pas de CSRF ; la demande ne collecte AUCUNE information (ni structure, ni SIRET) ; aucun avertissement à l'admin. La validation existe (Utilisateurs > filtre « Demandes directeur ») mais elle est cachée dans une liste générique : d'où l'impression qu'elle a disparu. |
| 4 | Haute | `contact.php` : aucun anti-robot (ni Turnstile, ni pot de miel, ni limite par IP) → spam dans les messages. |
| 5 | Moyenne | Identifiants numériques exposés partout (`#id`, `data-id`, `admin_db.php?pk=`). |
| 6 | Moyenne | Barre latérale `position:sticky` dans une grille + en-tête sticky dans `.main` : sur les pages longues / tableaux larges la barre « part » (débordement horizontal de `.main`) ; menu mobile sans voile ni fermeture. |
| 7 | Moyenne | Styles en ligne (`style="…"`) dans `admin_compta.php` : bloqués par la CSP → petits défauts d'affichage. |
| 8 | Moyenne | Journal d'audit sans avant/après, sans filtre de date ni export. |
| 9 | Moyenne | Pas de notifications ; badges calculés à chaque page sans filtre par droit. |
| 10 | Basse | En-tête du site pour un admin : liens Favoris / Contact / Aide inutiles, lien mort `admin_messages` (redirection). |
| 11 | Basse | Erreurs applicatives non paginées (150 dernières), pas de regroupement. |
| 12 | Basse | Réponse de contact envoyée comme lien seulement ; pas de statut intermédiaire ni d'assignation. |

## Décisions
- Un socle commun `api/adm_core.php` (rôles, permissions, jetons opaques, notifications) utilisable aussi par l'en-tête du site.
- Une seule fonction de contrôle `adm_can('perm')`, appelée par `adm_page('perm')` (pages) et la table action → permission de `admin_api.php`.
- CSP stricte conservée : la coquille admin reproduit l'en-tête du site (même liens, même logo) sans Tailwind ni script en ligne.
- Jetons : `organisateurs.public_id`, `camps.token`, `contact_messages.token` ; sinon jeton chiffré `adm_tok()` (AES, sans colonne nouvelle).

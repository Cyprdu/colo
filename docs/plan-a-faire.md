# Plan à faire (lots à lancer un par un sur « relance »)

Consignes pour chaque session : Opus 5.5 extra, niveau poussé, aucun commentaire inutile dans le code, rapport final court, branche dédiée depuis main, un commit par point, tests sur base jetable, ne pas pousser (fusion par la session principale). Jamais `?>` dans un commentaire `//`.

## Lot 1 — Création de séjour (`create.php`, `partials/camp_form.php`, `api/camp_save.php`, `api/add_camp.php`, `api/update_camp.php`)
- [x] Bug : à la création, erreur « Séjour introuvable… gestion des animateurs » puis redirection « Séjour enregistré » : corriger la séquence (le séjour doit exister avant la config animateurs).
- [x] Mots-clés : bug « ce mot est déjà dans la liste » juste après l'ajout ; garder le focus dans le champ après Entrée ; supprimer les suggestions ; texte explicatif derrière un « ? » sans exemple EPJ ; si le mot est déjà dans les mots de l'organisme : « Déjà dans les mots de votre organisme, inutile de le répéter ici ».
- [x] Tarifs : quota par tarif, et quotas par sexe (filles / garçons) par tarif ; tarif réservé à un âge ou une tranche d'âge (collégien, lycéen, étudiant…) avec contrôle à l'inscription.
- [x] Âge : le directeur choisit âge exact, tranches (collège, lycée, études sup, adulte…) ou les deux ; l'inscription accepte selon ce choix.
- [x] Animateurs : plusieurs catégories d'encadrants (animateurs, logistique/TTV, cuisine…), chacune avec ses tarifs, quotas (dont filles/garçons), questions ; l'animateur choisit sa catégorie.
- [x] Moyens de paiement : bouton « Autre » (libellé + logo téléversé).
- [x] Inscriptions multi-personnes génériques : un tarif peut couvrir N participants avec des rôles (ex. père + fils, prix pour deux), formulaire d'inscription généré pour chaque rôle. Modèle de données générique (participants par inscription), pas une case dédiée ; caché dans « Options avancées ».
- [x] Anticiper les cas particuliers : options avancées repliées, formulaire long mais clair, aide « ? ».

## Lot 2 — Recherche et accueil (`index.php`, `partials/header.php`, `recherche.php`, `api/search_lib.php`)
- [x] Bandeau de recherche de l'accueil : plus fin, sapins proportionnés (modèle : site « Ma colo de confiance »).
- [x] Catégories de l'en-tête : déroulé au survol, clic sur une catégorie = recherche immédiate.
- [x] Localisation : bouton « Autour de moi » (géolocalisation) dans l'en-tête et la recherche.
- [x] Âge : liste des enfants + « Autre âge » saisissable, recherche possible sans enfant enregistré.
- [x] Recommandations : plus de diversité (rotation, catégories variées, éviter les répétitions entre carrousels).
- [x] Publicités dans les carrousels, discrètes (vérifier l'intégration AdSense existante).
- [x] Clic sur un séjour depuis l'accueil ou une liste : ouvrir la session la plus proche à venir.

## Lot 3 — Page séjour (`camp_details.php`)
- [x] Carte plus petite, retirer le bandeau d'attribution OpenStreetMap si la licence le permet (sinon attribution discrète).
- [x] « Organisé par » : logo de l'organisme à la place du rond avec la lettre.
- [x] Boutons Partager / Favori / Calendrier et infos (lieu, dates, âges, vues, favoris) mieux intégrés et alignés.
- [x] Moyens de paiement acceptés : vrais logos officiels (CB, chèque, virement, espèces, ANCV chèques-vacances, etc.) téléchargés et stockés dans le projet.
- [x] Référencement externe : « Réserver ma place » vers le site de l'organisme avec paramètre de suivi (`utm_source=moncamp.fr` + identifiant de clic) ; compteur de clics par séjour et par organisme, tableau dans l'admin et le dashboard organisme.

## Lot 4 — Parents et enfants
- [x] `modif_child.php` : changer la photo de l'enfant (vérifier l'existant) ; `children` : photo à la place du rond gris.
- [x] Page « mon séjour animateur » : toujours blanche sans instructions — vérifier en ligne après déploiement du lot fiche enfant, corriger si besoin.

## Lot 5 — Légal et RGPD
- [x] Bandeau de consentement cookies conforme CNIL (refuser aussi simple qu'accepter, choix par finalité, AdSense et mesure d'audience bloqués avant consentement, preuve de consentement, lien « Gérer les cookies » dans le pied de page).
- [x] Audit des obligations : mentions légales, CGU, CGV/conditions de vente, politique de confidentialité, registre, durées de conservation, droits RGPD, médiation de la consommation, données de mineurs et de santé (hébergement HDS à évaluer), accessibilité ; mettre à jour `legal.php` et les pages concernées.

## Lot 6 — Traduction
- [x] Sélecteur de langue dans le pied de page (FR par défaut, EN) ; tous les textes de l'interface traduits (fichiers de langue), annonces des séjours non traduites.

## Lot 7 — Mobile et PWA
- [x] Refaire le responsive de tout le site : bouton du menu à droite, zones sûres iOS (encoche / caméra), éléments trop hauts, réservation fluide sur téléphone.
- [x] PWA optimisée (manifest, icônes, cache, installation).

## Lot 8 — Lancement commercial
- [x] Factures de commission mensuelles (PDF, numérotation continue, avoirs, mentions légales, envoi e-mail, dashboard organisme, admin + export, conservation 10 ans).
- [x] Revendication des fiches importées (statut « non revendiquée », badge, formulaire avec preuve, validation Modération, transfert + audit, signalement droit d'auteur, mention légale).
- [x] Surveillance : `api/sante_site.php` (base, disque, cron, erreurs), battement du cron, alertes e-mail (1 par heure max).
- [x] Sauvegardes quotidiennes compressées hors `public_html`, chiffrées si `HEALTH_KEY`, rotation 7 + 4, page admin, `docs/restauration.md`.
- [x] Double authentification TOTP (suprême, finances, technique), codes de secours, réinitialisation par le suprême.
- [ ] Référencement : `sitemap.xml` dynamique, `robots.txt`, title/description/canonical/Open Graph, JSON-LD Event et Organization, hreflang, pages 404 et 410.
- [ ] Page « Tarifs organisateurs » (commission, frais Stripe, versements, hors carte, dette, FAQ, simulateur) + liens.
- [ ] Script `docs/purge_avant_lancement.sql` (données de test, sans toucher aux imports), non exécuté.

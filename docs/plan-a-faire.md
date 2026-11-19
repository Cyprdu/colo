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
- [ ] Bandeau de recherche de l'accueil : plus fin, sapins proportionnés (modèle : site « Ma colo de confiance »).
- [ ] Catégories de l'en-tête : déroulé au survol, clic sur une catégorie = recherche immédiate.
- [ ] Localisation : bouton « Autour de moi » (géolocalisation) dans l'en-tête et la recherche.
- [ ] Âge : liste des enfants + « Autre âge » saisissable, recherche possible sans enfant enregistré.
- [ ] Recommandations : plus de diversité (rotation, catégories variées, éviter les répétitions entre carrousels).
- [ ] Publicités dans les carrousels, discrètes (vérifier l'intégration AdSense existante).
- [ ] Clic sur un séjour depuis l'accueil ou une liste : ouvrir la session la plus proche à venir.

## Lot 3 — Page séjour (`camp_details.php`)
- [ ] Carte plus petite, retirer le bandeau d'attribution OpenStreetMap si la licence le permet (sinon attribution discrète).
- [ ] « Organisé par » : logo de l'organisme à la place du rond avec la lettre.
- [ ] Boutons Partager / Favori / Calendrier et infos (lieu, dates, âges, vues, favoris) mieux intégrés et alignés.
- [ ] Moyens de paiement acceptés : vrais logos officiels (CB, chèque, virement, espèces, ANCV chèques-vacances, etc.) téléchargés et stockés dans le projet.
- [ ] Référencement externe : « Réserver ma place » vers le site de l'organisme avec paramètre de suivi (`utm_source=moncamp.fr` + identifiant de clic) ; compteur de clics par séjour et par organisme, tableau dans l'admin et le dashboard organisme.

## Lot 4 — Parents et enfants
- [ ] `modif_child.php` : changer la photo de l'enfant (vérifier l'existant) ; `children` : photo à la place du rond gris.
- [ ] Page « mon séjour animateur » : toujours blanche sans instructions — vérifier en ligne après déploiement du lot fiche enfant, corriger si besoin.

## Lot 5 — Légal et RGPD
- [ ] Bandeau de consentement cookies conforme CNIL (refuser aussi simple qu'accepter, choix par finalité, AdSense et mesure d'audience bloqués avant consentement, preuve de consentement, lien « Gérer les cookies » dans le pied de page).
- [ ] Audit des obligations : mentions légales, CGU, CGV/conditions de vente, politique de confidentialité, registre, durées de conservation, droits RGPD, médiation de la consommation, données de mineurs et de santé (hébergement HDS à évaluer), accessibilité ; mettre à jour `legal.php` et les pages concernées.

## Lot 6 — Traduction
- [ ] Sélecteur de langue dans le pied de page (FR par défaut, EN) ; tous les textes de l'interface traduits (fichiers de langue), annonces des séjours non traduites.

## Lot 7 — Mobile et PWA
- [ ] Refaire le responsive de tout le site : bouton du menu à droite, zones sûres iOS (encoche / caméra), éléments trop hauts, réservation fluide sur téléphone.
- [ ] PWA optimisée (manifest, icônes, cache, installation).

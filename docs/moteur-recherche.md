# Moteur de recherche et de classement ColoMap

Conception et mode d'emploi. Code : `api/search_text.php` (texte), `api/search_lib.php` (moteur), `api/catalogue.php` (catégories et précisions),
`api/motscles.php` (mots-clés et alias), `api/search_stats.php` (journal anonyme). Tests : `php tests/search_check.php`.

## 1. Ce que voit une famille

- Une phrase libre : « cheval 12 ans moins de 500 € été 2027 ». L'âge, le budget et la période deviennent des filtres (affichés : « Nous avons compris : … »,
  désactivables), les autres mots sont cherchés.
- Les accents, majuscules, pluriels, synonymes (« poney » = équitation), débuts de mots (« montagn ») et fautes légères (« equitaiton ») sont gérés ;
  « Vouliez-vous dire … ? » ; autocomplétion dans la barre de l'en-tête (alias, organismes, catégories, séjours, villes).
- Filtres : catégories (plusieurs, « au moins une » ou « toutes »), puis précisions de chaque catégorie choisie, avec le nombre de séjours par réponse.
  Puces des filtres actifs, adresse partageable : `recherche?name=…&cat[]=montagne&cat[]=spirituel&fv[]=montagne:niveau:debutant&catmode=all`.
  Les anciens liens `themes[]=Montagne` fonctionnent toujours (ils sont traduits en catégories).
- Page de transparence : `/classement` (lit les poids dans le code, elle ne peut pas mentir).

## 2. Données

| Table | Rôle |
|---|---|
| `cat_categories`, `cat_facettes`, `cat_valeurs` | le catalogue : 22 catégories, 52 questions (facettes), 244 réponses ; type `single` / `multi` / `scale` (niveaux ordonnés) |
| `camp_categories` (camp, catégorie, principale) | catégories d'un séjour (4 au plus ; la première est la principale) |
| `camp_facettes` (camp, catégorie, facette, valeur) | réponses d'un séjour, une ligne par valeur, indexée pour filtrer |
| `camp_motscles`, `organisme_motscles` (id, mot normalisé, étiquette) | mots-clés d'un séjour (12 max) et alias d'un organisme (8 max), index sur `mot` |
| `recherche_termes`, `recherche_termes_camps` | journal anonyme : terme, jour, compteurs ; affichages et visites par séjour |
| `sq_meta` | version du catalogue, dernier rattrapage |

Tout est créé au premier usage (idempotent), rien à lancer. Le catalogue officiel est `cat_data()` dans `api/catalogue.php` : pour ajouter une catégorie ou une
précision, ajouter une entrée et augmenter `CAT_VERSION` (les lignes ajoutées à la main en base ne sont jamais supprimées).
**Migration** : `camps.theme` et `camps.type` ne sont jamais modifiés. Au premier appel (puis au plus une fois par jour), chaque séjour sans catégorie reçoit
celles qui correspondent à son thème et à ses mots libres (`cat_legacy_guess`), et ses anciens mots libres deviennent des mots-clés. À l'enregistrement,
`camps.theme` reçoit le thème de la catégorie principale (ou garde l'ancien s'il reste cohérent) et `camps.type` reçoit les mots-clés : le reste du site
(accueil, animateurs…) continue de les lire.

## 3. Algorithme

### 3.1 Texte → racines
`sq_norm` (minuscules, sans accents) → mots utiles (sans « de, la, pour… » ni mots passe-partout « colo, séjour, camp ») → racine (`chevaux` → `cheval`, `camps` → `camp`).
`sq_parse_query` retire de la phrase l'âge (`12 ans`, `12-14 ans`, `moins de 12 ans`), le budget (`moins de 500 €`, `400 euros`) et la période (`été 2027`, `Noël`, `juillet`).

### 3.2 Variantes d'un mot tapé (`sq_expand`)
exact = 1 · synonyme = 0,75 · début de mot = 0,85 (dernier mot, souvent incomplet) ou 0,7 · faute = 0,6 (1 erreur) ou 0,5 (2 erreurs, mots de 8 lettres et plus).
Fautes : seulement si le mot est inconnu du site, même première lettre, distance de Damerau-Levenshtein ≤ 1 (4 à 7 lettres) ou 2 (8 lettres et plus), jamais pour 3 lettres ou moins.

### 3.3 Pertinence d'un séjour (0 à 100)
Poids des champs (`SQ_FIELD_W`) : mots-clés du séjour 90 · alias de l'organisme 80 · nom du séjour 60 · nom de l'organisme 55 · thème historique 40 · catégories 40 · précisions 30 · ville 25 · description 6.

```
meilleur(mot)  = max sur les champs et variantes de  poids_champ × qualité_variante × confiance      (jamais une somme : le bourrage ne sert à rien)
idf(mot)       = ln(1 + N / (1 + nb de séjours qui contiennent le mot))                               (un mot rare compte plus)
pertinence     = 100 × Σ idf × meilleur / (Σ idf × 90)                       exigée pour tous les mots (60 % dès 3 mots) ; seuil 5
```
`confiance` d'un mot-clé de séjour : 1 s'il parle du séjour (dans son nom, ses catégories, sa description… ou un synonyme) ou est le sigle de son nom ;
0,5 s'il emprunte un thème connu sans rapport avec le séjour ; 0,9 sinon (alias propre : « EPJ »). Les alias d'organisme ont une confiance de 1 mais ne peuvent ni être un thème
ni le nom ou l'alias d'un autre organisme. Si aucun séjour ne contient tous les mots, on retombe sur « au moins un mot » (signalé).

### 3.4 Score final
```
organique = 0,50 pertinence + 0,12 proximité + 0,10 disponibilité + 0,14 qualité + 0,04 nouveauté + 0,10 profil            (avec texte)
organique = 0,22 proximité + 0,15 disponibilité + 0,30 qualité + 0,08 nouveauté + 0,25 profil                              (sans texte)
proximité      = 30 + 70·e^(−jours/45)            (départ déjà commencé : 60)
disponibilité  = 0 (complet) · 90 (≤ 5 places) · 100 · 75 (places non limitées ou inconnues)       complet : score × 0,35
qualité        = 100 × (0,6·min(1, ctr/0,15) + 0,4·min(1, conversion/0,10)), ctr = (clics+1)/(affichages+20), conversion = (inscriptions+0,5)/(clics+10)   sur 90 jours
nouveauté      = 40 + 60·e^(−âge/21 jours)
profil         = 50 (+25 âge de l'enfant dans la tranche, −20 sinon ; +15 catégorie déjà aimée ; +10 organisme déjà aimé)   (favoris, réservations, séjours vus ; 50 sans compte)
boost effectif = 1 + (boost_mk_score − 1) × (0,4 + 0,6 × pertinence/100)      (mk_score : à la une ×1,55, dernière minute ×1,20, remontée ×(1+0,5·e^(−h/24)))
score          = organique × boost effectif × rotation(±3 % toutes les 3 h)      + 8 points par catégorie choisie en plus que le séjour possède
```
Le boost amplifie la pertinence au lieu de la remplacer : un séjour mis à la une mais sans rapport avec la recherche n'apparaît pas, et un séjour peu pertinent profite peu de son boost.

### 3.5 Diversité (`sq_diversifier`)
Sur les 96 premières places : on place le meilleur score, puis chaque séjour déjà placé d'un organisme multiplie par 0,75 le score de ses suivants (rafales cassées sans effacer
un organisme très pertinent). Exception : un organisme cherché par son nom ou son alias n'est pas dispersé.

### 3.6 Catégories et précisions
Un séjour passe si, pour au moins une (ou toutes, `catmode=all`) des catégories choisies, il la possède et satisfait les précisions choisies pour cette catégorie (OU entre les valeurs d'une précision, ET entre précisions).
Compteurs : par catégorie = séjours trouvés par le texte ; par réponse = séjours qui passent tous les autres filtres (la précision elle-même exceptée, comme dans toute recherche à facettes). Un camp à plusieurs sessions compte une fois.

## 4. Directeurs
- **Séjour** : section « Catégories et précisions » (choisir 1 à 4 catégories, puis répondre aux questions de chacune) et « Mots-clés et alias du séjour » (champ à pastilles avec suggestions : sigle du nom, mots du nom, ville).
- **Organisme** : section « Alias et mots-clés de l'organisme » dans « Modifier l'organisme », à la création, et dans la fenêtre « Gérer » du formulaire de séjour.
- **Marketing** : tableau « Ce que les familles ont tapé pour vous trouver » (termes, affichages, visites), et termes souvent tapés sans résultat.
- Limites : 12 mots-clés par séjour, 8 alias par organisme, 30 caractères, 3 mots ; refusés : adresses web, e-mails, numéros, mots passe-partout, nom ou alias d'un autre organisme, thèmes comme alias d'organisme.

## 5. Vie privée et sécurité
Journal des recherches : terme normalisé + compteurs, sans IP, compte ni identifiant ; pas d'e-mail ni de numéro ; une recherche compte une fois par session et par jour ; un directeur ne voit un terme que s'il a été tapé par au moins 3 recherches ; effacement à 400 jours.
Toutes les requêtes sont préparées ; les listes `IN (…)` ne contiennent que des entiers issus de la base ; les filtres de catégories sont validés contre le catalogue ; l'autocomplétion est limitée à 120 appels par minute et par adresse (`api/security.php`) et mise en cache 2 minutes côté navigateur.

## 6. Performances
Une requête SQL pour les candidats + 6 requêtes groupées (mots-clés séjour/organisme, catégories, précisions, statistiques) quel que soit le nombre de séjours ; le vocabulaire est en cache fichier 10 minutes ;
l'autocomplétion ne touche pas aux séjours. Mesuré : ~0,15 ms de calcul par séjour avec texte (2000 séjours ≈ 0,3 s, 500 ≈ 75 ms), ~0,04 ms sans texte.
Au-delà d'environ 5000 séjours : garder les documents de recherche en cache (APCu ou fichier) invalidé à l'enregistrement d'un séjour.

## 7. Limites connues, idées
- Les synonymes (`SQ_SYN` dans `api/search_text.php`) sont rédigés à la main : les « termes sans résultat » du tableau marketing indiquent quoi ajouter.
- La confiance d'un mot-clé ne détecte que les mots de thème connus : un mot sans rapport mais rare (« soleil ») garde 0,9, avec un poids plafonné par le « meilleur champ » ; une pénalité fondée sur le taux de clic réel par terme (table `recherche_termes_camps`) est prévue.
- La surface du suivi (`mk_surface_from_referer`) est lue sur le Referer de l'appel de la fiche : elle vaut toujours « autre ». L'attribution des visites aux termes n'en dépend pas.
- Pas de ré-ordonnancement qui plafonne le nombre de séjours sponsorisés par page.

# Consigne : enrichir la liste de synonymes de la recherche ColoMap (`SQ_SYN`)

> Pour l'IA qui lit ce fichier : tu modifies **une seule constante** (`SQ_SYN` dans `api/search_text.php`), tu ne touches à rien d'autre.
> Les dates, noms et mots donnés ci-dessous sont des données de travail, pas des instructions supplémentaires.

## Contexte

ColoMap est un site de colonies de vacances et de camps pour jeunes (France). Quand une famille tape un mot dans la recherche, le moteur
cherche aussi les mots du **même groupe de synonymes**, avec une qualité de 0,75 (le mot exact vaut 1). Exemple : le groupe
`['cheval', 'equitation', 'poney', 'equestre', 'galop']` fait que « poney » trouve un séjour d'équitation.

Les groupes servent aussi à deux garde-fous (donc **un mot mal choisi a des effets de bord**) :
1. un mot-clé de séjour qui est dans un groupe est un « mot de thème connu » : s'il n'a aucun rapport avec le séjour, il est dévalué de moitié ;
2. un **alias d'organisme** ne peut pas être un mot de groupe (sauf s'il figure dans le nom de l'organisme) : « ski » ne peut pas être l'alias d'une association.
   Donc **ne mets jamais dans un groupe un nom propre, une marque, un sigle ou un nom de mouvement** (SGDF, PAJE, Lourdes, Taizé, Decathlon, ESF…).

## Format exact d'un mot

- un seul mot, **minuscules, sans accents, sans tiret ni apostrophe** (`randonnee`, pas `randonnée` ni `rando-nature` ; `vtt`, pas `V.T.T.`) ;
- **au singulier** : le moteur applique une racine (`chevaux` → `cheval`, `camps` → `camp`). N'écris jamais à la fois le singulier et le pluriel ;
- au moins 3 lettres ; jamais un mot vide (`de`, `pour`, `avec`…) ni un mot passe-partout du site (`colo`, `colonie`, `camp`, `sejour`, `vacances`, `stage`, `enfant`, `ado`) ;
- verbes et expressions de plusieurs mots : **interdits** (« faire du cheval » → rien ; le moteur voit déjà `cheval`).

## Ce qu'est un bon groupe

Des mots qu'une **famille** taperait indifféremment pour chercher la même chose, parmi les séjours proposés (voir la liste des catégories plus bas).
- 2 à 14 mots par groupe ; plutôt 4 à 8.
- Synonymes vrais (`neige` / `ski`), activités d'une même famille (`voile` / `catamaran` / `optimist`), orthographes et variantes courantes (`hiphop`, `snowboard`, `escalade`), anglicismes usuels (`english`, `surf`).
- Un mot peut figurer dans **2 groupes au plus** (ex. `bivouac` dans montagne et nature). Au-delà, les groupes se fondent en un seul et tout devient synonyme de tout.
- Les relations sont **symétriques** : si A est dans le groupe de B, B trouve A. Ne mets donc pas un mot **plus général** avec un mot **plus précis** si la famille qui tape le général serait déçue : `sport` ↔ `football` serait mauvais (qui tape « sport » veut tout voir, pas que du foot) ; `football` ↔ `foot` est bon.
  Règle : « si quelqu'un tape A, veut-il vraiment voir un séjour décrit uniquement avec B ? » Si la réponse n'est pas un oui franc, ne les groupe pas.

## À ne pas faire

- pas de mots ambigus (`mer` est ok ; `bleu`, `grand`, `ete`, `jeune`, `noel` non : ils servent à autre chose) ;
- pas de mots de prix, d'âge, de dates ou de lieux (l'analyse de la phrase les traite déjà) ;
- pas de villes, régions, pays, départements ;
- pas de qualificatifs vagues (`super`, `top`, `fun`, `original`) ;
- pas de supprimer ou renommer un groupe existant sans raison : tu **ajoutes** des groupes, ou tu **complètes** un groupe avec des mots de même sens.

## Les catégories du site (pour t'orienter)

Spirituel & religieux · Montagne · Ski & neige · Sports · Équitation · Mer & nautique · Nature & aventure · Voyage & langues · Arts créatifs · Musique & chant ·
Théâtre, danse & cirque · Sciences & numérique · Cuisine & pâtisserie · Scoutisme & vie de camp · Solidarité & humanitaire · Écologie, ferme & animaux · Bien-être ·
Jeux & imaginaire · Cinéma, médias & écriture · Histoire & patrimoine · Vélo & itinérance · Colonie multi-activités.
Les détails de chaque catégorie (disciplines, niveaux, confessions…) sont dans `cat_data()` de `api/catalogue.php` : **les libellés des réponses y sont des mots que les familles tapent**, c'est ta première source d'idées
(ex. « Via ferrata », « Canyoning », « Bushcraft », « Sophrologie », « Escape game »).

## Où trouver des idées

1. `cat_data()` dans `api/catalogue.php` (catégories, alias, réponses) ;
2. le tableau marketing « termes sans résultat » (`sq_termes_sans_resultat`) : des mots réellement tapés qui n'ont rien trouvé. S'ils désignent une activité qu'on propose sous un autre nom, c'est un synonyme à ajouter. Si un terme est en réalité un nom propre ou un lieu : ne l'ajoute pas ;
3. ta connaissance des activités de colonies et camps en France (équipements, disciplines, jeux, termes d'animateurs, noms usuels d'activités).

## Livrable

Modifie `SQ_SYN` dans `api/search_text.php` (même syntaxe : tableau de tableaux de chaînes, une ligne par groupe, groupes existants conservés, nouveaux groupes à la suite, thématiquement regroupés).
Ensuite :

```bash
php tests/synonymes_check.php     # forme : un mot, minuscules, sans accents, pas de doublon, pas de mot passe-partout, 2 groupes max par mot
php tests/search_check.php        # le moteur : doit rester vert
```

Réponds avec : le nombre de groupes ajoutés et de mots ajoutés ; la liste des groupes ajoutés ou modifiés ; et, pour chaque cas douteux que tu as écarté, une phrase de justification (ex. « `foot` ↔ `sport` écarté : trop général »).

## Exemples

Bons :
```php
['football', 'foot', 'soccer'],
['escalade', 'varappe', 'bloc', 'grimpe'],
['voile', 'catamaran', 'optimist', 'deriveur'],
['theatre', 'comedie', 'spectacle', 'scene', 'improvisation'],
```
Mauvais :
```php
['sport', 'football', 'basket', 'tennis'],        // général ↔ précis : sport trouverait tout, football trouverait du tennis
['ski', 'neige', 'sgdf'],                          // sigle de mouvement
['mer', 'vacances', 'ete'],                        // mots passe-partout ou de période
['cheval', 'chevaux'],                             // pluriel : déjà géré par la racine
['faire du cheval', 'monter a cheval'],            // plusieurs mots
```

# Restauration d'une sauvegarde moncamp.fr

## Où sont les sauvegardes

- Créées chaque nuit (à partir de 3 h) par le cron horaire `api/cron_tout.php`, hors du dossier web : `../sauvegardes_moncamp/` à côté de `public_html` (ou le dossier de la constante `BACKUP_DIR` dans `api/config.php`).
- Noms : `quotidien-AAAA-MM-JJ.sql.gz.enc` (7 conservées) et `hebdo-AAAA-Wnn.sql.gz.enc` (copie du dimanche, 4 conservées).
- `.enc` = chiffré en AES-256-GCM avec une clé dérivée de `HEALTH_KEY`. Sans `HEALTH_KEY`, le fichier est un simple `.sql.gz`.
- Liste et téléchargement : Administration > Technique > Sauvegardes (mot de passe redemandé, téléchargement journalisé).
- Méthode : `mysqldump` (avec déclencheurs) quand l'hébergement l'autorise, sinon export PDO par lots (sans déclencheurs : ils sont recréés automatiquement par le site au premier passage).

**Conservez `HEALTH_KEY` à part (gestionnaire de mots de passe)** : sans elle, ni les sauvegardes chiffrées ni les documents de santé ne sont lisibles. Recopiez aussi régulièrement une sauvegarde hors de Hostinger.

## 1. Récupérer le fichier

Depuis l'administration (Sauvegardes > icône de téléchargement), ou par le gestionnaire de fichiers / SFTP de Hostinger.

## 2. Déchiffrer (fichiers `.enc`)

Sur n'importe quel ordinateur avec PHP 8 (le dépôt du site suffit, aucune base nécessaire) :

```
php api/sauvegarde_lib.php dechiffrer quotidien-2026-11-20.sql.gz.enc restauration.sql.gz <HEALTH_KEY>
```

« Clé incorrecte ou fichier altéré » : mauvaise clé, ou fichier tronqué pendant le transfert.

## 3. Restaurer

**Toujours tester d'abord dans une base vide**, puis seulement remplacer la base de production.

1. hPanel > Bases de données > créer une base vide (ou vider la base cible après l'avoir elle-même sauvegardée).
2. Décompresser : `gunzip restauration.sql.gz` (ou 7-Zip sous Windows) → `restauration.sql`.
3. Importer :
   - en ligne de commande : `mysql -h <hôte> -u <utilisateur> -p <base> < restauration.sql`
   - ou phpMyAdmin > base > Importer (fichiers volumineux : importer le `.sql.gz` directement, phpMyAdmin le décompresse).
4. Si la base restaurée a un autre nom, mettre à jour `DB_NAME` (et `DB_USER` / `DB_PASS`) dans `api/config.php`.
5. Mettre le site en maintenance n'est pas nécessaire pour une base de test ; pour la production, prévenir l'équipe et restaurer en heure creuse.

## 4. Vérifier

- Se connecter à l'administration : tableau de bord, comptabilité, dernières inscriptions.
- `api/sante_site.php?key=<CRON_SECRET>` doit répondre `"statut": "OK"`.
- Les paiements Stripe postérieurs à la sauvegarde ne sont pas dans la base restaurée : comparer avec le tableau de bord Stripe ; les webhooks rejoués depuis Stripe (Développeurs > Événements > Renvoyer) recréent les écritures manquantes sans doublon.
- Les fichiers envoyés (photos, documents de santé dans `uploads/`) ne sont pas dans le dump SQL : ils se restaurent depuis les sauvegardes de fichiers Hostinger (hPanel > Fichiers > Sauvegardes).

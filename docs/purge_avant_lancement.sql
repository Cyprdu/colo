-- =====================================================================
-- moncamp.fr : purge des données de TEST avant le lancement commercial.
-- À importer dans phpMyAdmin (onglet SQL ou Importer), base de production, UNE fois, juste avant l'ouverture.
--
-- /!\ IRRÉVERSIBLE. Faites d'abord une sauvegarde (Administration > Technique > Sauvegardes, ou phpMyAdmin > Exporter).
-- Premier passage conseillé en APERÇU : laissez @executer = 0, lisez les compteurs, puis relancez avec @executer = 1.
--
-- SUPPRIME : toutes les inscriptions (et participants, réponses, inscriptions d'animateurs), tous les paiements, transferts,
--            versements, remboursements, dettes, commissions, journal comptable et factures de test, achats de boost et de points,
--            statistiques et clics de test, journaux techniques (erreurs, tentatives) ;
--            les COMPTES DE TEST dont l'e-mail correspond à @motif (jamais un administrateur), avec leurs enfants, favoris, messages,
--            profils animateur, demandes, et les organismes et séjours créés par ces comptes de test.
-- CONSERVE : les organismes et séjours importés (rattachés au compte du propriétaire, qui n'est pas un compte de test),
--            les vrais comptes, catégories, tarifs, réglages, comptes Stripe liés, journal d'audit de l'administration.
-- Les déclencheurs « ajout seulement » de fin_ledger et fin_factures sont retirés le temps de la purge ;
-- le site les recrée tout seul au premier chargement d'une page de finances.
-- =====================================================================

SET @executer = 0;
-- Comptes de test : e-mails en @test.*, @example.*, @exemple.*, yopmail, mailinator, adresses « +test » ou commençant par « test ». À adapter.
SET @motif = '(@test(\\.|$)|@example\\.|@exemple\\.|yopmail\\.|mailinator\\.|\\+test|^test)';

DROP TEMPORARY TABLE IF EXISTS purge_users;
CREATE TEMPORARY TABLE purge_users (id INT PRIMARY KEY) SELECT id FROM users WHERE LOWER(email) REGEXP @motif AND COALESCE(is_admin, 0) = 0;
DROP TEMPORARY TABLE IF EXISTS purge_orgas;
CREATE TEMPORARY TABLE purge_orgas (id INT PRIMARY KEY) SELECT o.id FROM organisateurs o JOIN purge_users p ON p.id = o.user_id;
DROP TEMPORARY TABLE IF EXISTS purge_camps;
CREATE TEMPORARY TABLE purge_camps (id INT PRIMARY KEY) SELECT c.id FROM camps c JOIN purge_orgas p ON p.id = c.organisateur_id;

-- APERÇU : ce qui sera supprimé
SELECT (SELECT COUNT(*) FROM purge_users) AS comptes_de_test,
       (SELECT GROUP_CONCAT(email ORDER BY email SEPARATOR ', ') FROM users WHERE id IN (SELECT id FROM purge_users)) AS emails,
       (SELECT COUNT(*) FROM purge_orgas) AS organismes_de_test,
       (SELECT COUNT(*) FROM purge_camps) AS sejours_de_test,
       (SELECT COUNT(*) FROM inscriptions) AS inscriptions,
       (SELECT COUNT(*) FROM organisateurs WHERE id NOT IN (SELECT id FROM purge_orgas)) AS organismes_conserves,
       (SELECT COUNT(*) FROM camps WHERE id NOT IN (SELECT id FROM purge_camps)) AS sejours_conserves;

SET FOREIGN_KEY_CHECKS = 0;

-- Déclencheurs « ajout seulement » : retirés même en aperçu (aucun effet sur les données, recréés automatiquement par le site)
DROP TRIGGER IF EXISTS fin_ledger_no_del;
DROP TRIGGER IF EXISTS fin_ledger_no_upd;
DROP TRIGGER IF EXISTS fin_factures_no_del;
DROP TRIGGER IF EXISTS fin_factures_no_upd;

-- 1. Inscriptions, paiements et finances de test (toutes : aucune vente réelle avant le lancement)
SET @t = 'inscription_reponses';    SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'inscription_participants'; SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'inscriptions';            SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'equipe_reponses';         SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'equipe_inscriptions';     SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'fin_paiements';           SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'fin_transferts';          SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'fin_transfert_lignes';    SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'fin_annulations';         SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'fin_payouts';             SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'fin_reversals';           SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'fin_reversal_ops';        SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'fin_commissions_hors';    SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'fin_gains_journal';       SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'fin_audit';               SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'fin_ledger';              SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'fin_factures';            SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'compta_clotures';         SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'virements';               SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'boost_achats';            SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'points_mouvements';       SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'camp_stats_jour';         SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'clics_sortants';          SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF(@executer = 1, "DELETE FROM adm_kv WHERE k IN ('factures:mois')", 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF(@executer = 1, 'UPDATE organisateurs SET portefeuille = 0', 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'organisateurs' AND column_name = 'solde_points'), 'UPDATE organisateurs SET solde_points = 0', 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'camps' AND column_name = 'vues'), 'UPDATE camps SET vues = 0', 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. Comptes de test, leurs organismes et leurs séjours (jamais les organismes importés, rattachés au compte du propriétaire)
SET @t = 'camp_categories'; SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE camp_id IN (SELECT id FROM purge_camps)'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'camp_facettes';   SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE camp_id IN (SELECT id FROM purge_camps)'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'camp_motscles';   SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE camp_id IN (SELECT id FROM purge_camps)'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'camp_champs';     SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE camp_id IN (SELECT id FROM purge_camps)'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'camp_etapes';     SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE camp_id IN (SELECT id FROM purge_camps)'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'camps_tarifs';    SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE camp_id IN (SELECT id FROM purge_camps)'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'favoris';         SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE camp_id IN (SELECT id FROM purge_camps) OR user_id IN (SELECT id FROM purge_users)'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'tarifs';          SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE organisateur_id IN (SELECT id FROM purge_orgas)'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF(@executer = 1, 'DELETE FROM camps WHERE id IN (SELECT id FROM purge_camps)', 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'stripe_comptes';  SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE organisateur_id IN (SELECT id FROM purge_orgas)'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF(@executer = 1, 'DELETE FROM organisateurs WHERE id IN (SELECT id FROM purge_orgas)', 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @t = 'messages';        SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE conversation_id IN (SELECT id FROM conversations WHERE user_1_id IN (SELECT id FROM purge_users) OR user_2_id IN (SELECT id FROM purge_users))'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'conversations';   SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE user_1_id IN (SELECT id FROM purge_users) OR user_2_id IN (SELECT id FROM purge_users)'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'enfants';         SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE parent_id IN (SELECT id FROM purge_users)'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'animateur_profils'; SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE user_id IN (SELECT id FROM purge_users)'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'animateur_prefs'; SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE user_id IN (SELECT id FROM purge_users)'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'animateur_notifs'; SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE user_id IN (SELECT id FROM purge_users)'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'demandes_organisateur'; SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE user_id IN (SELECT id FROM purge_users)'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'orga_revendications'; SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE user_id IN (SELECT id FROM purge_users)'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'consentements';   SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE user_id IN (SELECT id FROM purge_users)'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'contact_messages'; SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '` WHERE user_id IN (SELECT id FROM purge_users) OR LOWER(email) REGEXP @motif'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF(@executer = 1, 'DELETE FROM users WHERE id IN (SELECT id FROM purge_users)', 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. Journaux techniques de la période de test
SET @t = 'app_erreurs';     SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'sec_tentatives';  SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'admin_attempts';  SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'adm_notif_lus';   SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @t = 'adm_notifs';      SET @s = IF(@executer = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @t), CONCAT('DELETE FROM `', @t, '`'), 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET FOREIGN_KEY_CHECKS = 1;

-- Contrôle (après exécution : inscriptions = 0, comptes_de_test_restants = 0, organismes et séjours conservés inchangés)
SELECT IF(@executer = 1, 'PURGE EXÉCUTÉE', 'APERÇU SEULEMENT : rien n''a été supprimé (passez @executer à 1)') AS mode,
       (SELECT COUNT(*) FROM inscriptions) AS inscriptions,
       (SELECT COUNT(*) FROM users WHERE LOWER(email) REGEXP @motif AND COALESCE(is_admin, 0) = 0) AS comptes_de_test_restants,
       (SELECT COUNT(*) FROM organisateurs) AS organismes,
       (SELECT COUNT(*) FROM camps) AS sejours;

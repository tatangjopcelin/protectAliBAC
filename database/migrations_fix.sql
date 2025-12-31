-- Script SQL pour corriger les problèmes de base de données
-- À exécuter dans votre base de données MySQL

-- 1. Ajouter les colonnes start_break et end_break à la table schedules
ALTER TABLE `schedules` 
ADD COLUMN IF NOT EXISTS `start_break` TIME NULL AFTER `end_time`,
ADD COLUMN IF NOT EXISTS `end_break` TIME NULL AFTER `start_break`;

-- 2. Créer la table breaks (si elle n'existe pas)
CREATE TABLE IF NOT EXISTS `breaks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `time_entry_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `start_break` TIMESTAMP NULL,
  `end_break` TIMESTAMP NULL,
  `duration_minutes` INT NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  INDEX `breaks_time_entry_id_index` (`time_entry_id`),
  INDEX `breaks_user_id_index` (`user_id`),
  INDEX `breaks_start_break_index` (`start_break`),
  CONSTRAINT `breaks_time_entry_id_foreign` FOREIGN KEY (`time_entry_id`) REFERENCES `time_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `breaks_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





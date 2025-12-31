-- Script SQL pour marquer tous les comptes existants comme vérifiés
-- À exécuter manuellement si la migration ne peut pas être exécutée via artisan

UPDATE users 
SET email_verified_at = NOW() 
WHERE email_verified_at IS NULL;





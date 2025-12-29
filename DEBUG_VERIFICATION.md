# 🔍 Guide de débogage - Vérification d'email

## Problème : Impossible de se connecter après vérification

### Étapes de diagnostic

1. **Vérifier si le compte existe dans `users`** :
```sql
SELECT id, name, email, email_verified_at, created_at 
FROM users 
WHERE email = 'votre-email@example.com';
```

2. **Vérifier si une inscription est en attente dans `pending_registrations`** :
```sql
SELECT * FROM pending_registrations 
WHERE email = 'votre-email@example.com';
```

3. **Vérifier les logs Laravel** :
```bash
tail -f storage/logs/laravel.log
```

### Solutions selon le cas

#### Cas 1 : Le compte n'existe pas dans `users` mais existe dans `pending_registrations`
- **Cause** : La vérification n'a pas créé le compte
- **Solution** : Vérifier les logs pour voir l'erreur, puis réessayer la vérification

#### Cas 2 : Le compte existe dans `users` mais `email_verified_at` est NULL
- **Cause** : Ancien compte créé avant l'implémentation de la vérification
- **Solution** : Exécuter cette commande SQL :
```sql
UPDATE users 
SET email_verified_at = NOW() 
WHERE email = 'votre-email@example.com' 
AND email_verified_at IS NULL;
```

#### Cas 3 : Le compte existe et est vérifié mais la connexion échoue
- **Cause** : Problème de cache ou de session
- **Solution** :
  1. Vider le cache : `php artisan cache:clear`
  2. Vérifier que le mot de passe est correct
  3. Vérifier les logs pour voir l'erreur exacte

### Commandes utiles

```bash
# Vider tous les caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear

# Voir les logs en temps réel
tail -f storage/logs/laravel.log

# Vérifier les routes
php artisan route:list | grep verify
```

### Vérification manuelle

Si le problème persiste, vous pouvez marquer manuellement un compte comme vérifié :

```sql
-- Pour un compte spécifique
UPDATE users 
SET email_verified_at = NOW() 
WHERE email = 'votre-email@example.com';

-- Pour tous les comptes existants (à utiliser avec précaution)
UPDATE users 
SET email_verified_at = NOW() 
WHERE email_verified_at IS NULL;
```


# Notifications en production (Push, Email, SMS)

Ce document vérifie que **toutes** les notifications (push, email, SMS) sont bien envoyées automatiquement ou à l’action utilisateur, et ce qu’il faut configurer en production.

---

## 1. Récapitulatif par domaine

| Domaine | Déclencheur | Email | Push | SMS |
|--------|-------------|-------|------|-----|
| **Tâche du jour** | Automatique (cron 07:00) | ✅ | ✅ | ✅ |
| **Péremption / Produit périmé** | Automatique (cron 10:30, 16:00) | ✅ | ✅ | ✅ |
| **Rapport de paie distribué** | Action : distribution par admin | ✅ | ✅ | ✅ |
| **Planning publié** | Action : publication par admin | ✅ | ✅ | ✅ |
| **Super tâche assignée** | Action : création ou réassignation | ✅ | ✅ | ✅ |
| **Super tâches manquantes** | Automatique (cron lundi 08:00) | ✅ | ✅ | ✅ |
| **Tâche créée** | Action : création de tâche | ✅ | ❌ | ❌ |
| **Test notification push** | Action : bouton « Notification de test » | ❌ | ✅ | ❌ |

Les canaux (email, push, SMS) respectent les **préférences utilisateur** par canal (`notification_preferences`). Par défaut, SMS est activé pour : `payroll_report`, `schedule_published`, `expiration`, `expired`, `task_due_today`, `super_task_assigned`, `super_task_missing`.

---

## 2. Notifications automatiques (planifiées)

Elles ne partent **que si le scheduler Laravel est exécuté** en production.

### Commande cron à configurer sur le serveur

```bash
* * * * * cd /chemin/vers/protectAli && php artisan schedule:run >> /dev/null 2>&1
```

(Exécution toutes les minutes ; Laravel lance ensuite les tâches planifiées au bon moment.)

### Tâches planifiées (`routes/console.php`)

| Commande | Fréquence | Effet |
|----------|-----------|--------|
| `tasks:notify-due-today` | Tous les jours 07:00 | Email + push + SMS « tâche du jour » |
| `products:check-expiration` | Tous les jours 10:30 et 16:00 | Crée des alertes → Email + push + SMS péremption (Chef/Directeur/Admin) |
| `products:handle-expired` | Tous les jours 11:00 | Gestion produits périmés (stock à 0) |
| `super-tasks:check-weekly` | Chaque lundi 08:00 | Email + push + SMS aux managers (admin/chef/directeur) si super tâches manquantes pour la semaine |
| `absences:detect` | Tous les jours 01:00 | Détection absences |

Sans la ligne **cron** ci‑dessus, **aucune** de ces notifications automatiques ne partira en production.

---

## 3. Configuration requise en production

### Push (FCM Android / APNs iOS)

- **Android** : dans `.env`  
  `FCM_CREDENTIALS_JSON=storage/app/firebase-credentials.json`  
  (fichier JSON du compte de service Firebase présent dans `storage/app/`.)
- **iOS** : dans `.env`  
  `APN_KEY_PATH`, `APN_KEY_ID`, `APN_TEAM_ID`, `APN_BUNDLE_ID`  
  (et optionnellement `APN_SANDBOX=false` pour la prod.)

### Email

- Configurer le driver mail dans `.env` (SMTP, Mailgun, etc.) :  
  `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, etc.

### SMS (Twilio, France)

- Dans `.env` :  
  `TWILIO_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM`  
  (compte payant recommandé pour éviter le préfixe « Sent from your Twilio trial account ».)

### Utilisateurs

- **Push** : l’employé doit avoir enregistré un token (app mobile ouverte et connectée au moins une fois).
- **SMS** : l’employé doit avoir un **numéro de téléphone** renseigné (`users.phone`, format France 06/07).
- **Email** : l’employé doit avoir un `email` valide (et `email_verified_at` pour les notifications qui le vérifient).

---

## 4. Vérification rapide

1. **Cron** : la ligne `* * * * * ... php artisan schedule:run` est bien installée sur le serveur de production.
2. **.env** : FCM, APNs (si iOS), Mail, Twilio sont remplis et cohérents avec l’environnement (prod).
3. **Logs** : en cas de doute, vérifier `storage/logs/laravel.log` après un envoi ou après l’heure planifiée (07:00, 10:30, 16:00).

Si tout est en place, **push, mail et SMS** partent bien automatiquement (tâche du jour, péremption) et à l’action (distribution paie, publication planning) en production.

# Notifications push – configuration et dépannage

## Pourquoi les notifications push ne fonctionnent pas ?

Plusieurs causes possibles selon votre environnement.

---

### 1. Vous testez dans le navigateur (localhost / PWA)

**Les push ne fonctionnent pas sur le web.** Le module `@capacitor/push-notifications` est désactivé sur la plateforme `web`. Il faut utiliser l’**application native** :

- **iPhone** : build avec Xcode et lancer sur un appareil ou simulateur (avec capabilities Push).
- **Android** : build avec Android Studio et lancer sur un appareil (FCM à configurer pour les vrais envois).

Dans l’app (Mon compte > « Envoyer une notification de test »), si vous êtes sur navigateur vous verrez : *« Test possible uniquement sur l’app iPhone ou Android »*.

---

### 2. Côté backend : APNs non configuré (iOS)

Le serveur envoie les notifications iOS via **Apple Push Notification service (APNs)**. Si la configuration est manquante ou incorrecte, le test renverra une erreur du type :  
*« APNs non configuré côté serveur. Définissez APN_KEY_ID, APN_TEAM_ID, APN_BUNDLE_ID et APN_KEY_PATH (fichier .p8) dans le .env du backend. »*

À faire :

1. **Créer une clé APNs** dans [Apple Developer](https://developer.apple.com) :  
   Certificates, Identifiers & Profiles → Keys → créer une clé avec **Apple Push Notifications service (APNs)** activé.  
   Télécharger le fichier **.p8** (une seule fois).

2. **Renseigner le `.env`** du backend (dossier `protectAli`) :

   ```env
   APN_KEY_ID=XXXXXXXXXX
   APN_TEAM_ID=XXXXXXXXXX
   APN_BUNDLE_ID=com.tdblg.app
   APN_KEY_PATH=/chemin/absolu/vers/AuthKey_XXXXX.p8
   APN_SANDBOX=true
   ```

   - `APN_KEY_ID` : identifiant de la clé (ex. `AuthKey_XXXXX.p8` → `XXXXX`).
   - `APN_TEAM_ID` : Team ID du compte Apple Developer.
   - `APN_BUNDLE_ID` : doit être le même que l’app (ex. `com.tdblg.app` pour Brole).
   - `APN_KEY_PATH` : chemin absolu vers le fichier `.p8` sur la machine qui exécute le backend (ex. `storage/app/AuthKey_XXXXX.p8`).
   - `APN_SANDBOX=true` pour le développement, `false` pour la production.

3. Vérifier que le fichier `.p8` est lisible par l’utilisateur qui lance PHP (droits, chemin).

Sans ces variables (ou avec un chemin invalide), le backend renverra **503** et le message d’erreur ci‑dessus.

---

### 3. Vous êtes sur Android

Actuellement, **le test de notification** (« Envoyer une notification de test ») est implémenté uniquement pour **iOS** (APNs). Sur Android :

- L’app peut enregistrer le token push (FCM) et l’envoyer au backend.
- Mais le backend **n’envoie pas encore** de notification de test aux appareils Android (FCM non branché côté serveur).

Vous verrez par exemple :  
*« Le test de notification est disponible uniquement sur iPhone pour le moment. »*  
Pour avoir les push sur Android plus tard, il faudra configurer Firebase (FCM) côté backend et appeler l’API FCM pour l’envoi.

---

### 4. Aucun appareil iOS enregistré

Si vous êtes bien sur l’app **iPhone** et que le test indique qu’aucun appareil n’est enregistré :

- Vous devez **accepter les notifications** quand l’app le demande (au démarrage ou après connexion).
- Si vous avez refusé : **Réglages iPhone → Brole → Notifications** → activer les notifications, puis rouvrir l’app et vous reconnecter pour que le token soit enregistré.
- Vérifier que l’app a bien la **capability Push Notifications** dans Xcode (Signing & Capabilities) et que le projet iOS est à jour (`npx cap sync ios`).

Une fois le token enregistré et le backend configuré (APNs), le test doit envoyer une notification sur l’appareil.

---

### 5. Envoi réel des notifications (alertes, tâches, etc.)

Le service backend `NotificationService` envoie désormais les notifications push aux **appareils iOS** enregistrés (via `PushToken` + `ApnService`). Les événements qui déclenchent une notification (alertes, tâches, etc.) enverront bien une push sur iPhone si :

- APNs est configuré dans le `.env` (voir §2).
- L’utilisateur a au moins un token iOS enregistré (voir §4).

---

## Checklist rapide

| Étape | À vérifier |
|--------|------------|
| App | Tester sur l’**app native** (iPhone/Android), pas dans le navigateur. |
| iOS | Capability **Push Notifications** activée dans Xcode. |
| iPhone | Notifications **autorisées** pour Brole dans Réglages. |
| Backend | `.env` avec `APN_KEY_ID`, `APN_TEAM_ID`, `APN_BUNDLE_ID`, `APN_KEY_PATH` (fichier .p8 valide). |
| Test | Mon compte → « Envoyer une notification de test » après connexion sur iPhone. |

Si après tout ça le test échoue encore, regarder les **logs Laravel** (`storage/logs/laravel.log`) et les réponses API (code 400 / 502 / 503) pour le message exact renvoyé par le backend.

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

### 3. Côté backend : FCM non configuré (Android)

Le serveur envoie les notifications Android via **Firebase Cloud Messaging (FCM)**. Si la configuration est manquante, le test renverra une erreur du type :  
*« FCM non configuré (Android). Définissez FCM_CREDENTIALS_JSON dans le .env. »*

À faire :

1. **Créer un projet Firebase** (ou utiliser un existant) sur [Firebase Console](https://console.firebase.google.com).
2. **Activer Cloud Messaging** et lier l’app Android (package `com.tdblg.app`) avec le fichier `google-services.json` dans `frontend/android/app/`.
3. **Générer une clé de compte de service** : Firebase Console → Project settings → Service accounts → Generate new private key. Télécharger le fichier JSON.
4. **Placer le fichier** dans le backend (ex. `protectAli/storage/app/firebase-credentials.json`) et **ne pas le versionner** (ajouter à `.gitignore` si besoin).
5. **Renseigner le `.env`** du backend :

   ```env
   FCM_CREDENTIALS_JSON=storage/app/firebase-credentials.json
   ```
   (ou chemin absolu vers le fichier JSON.)

Sans cette variable (ou avec un fichier invalide), le backend ne pourra pas envoyer de notification de test aux appareils Android.

---

### 4. Aucun appareil enregistré (iOS ou Android)

Si le test indique qu’aucun appareil n’est enregistré :

- **iOS** : accepter les notifications quand l’app le demande. Si vous avez refusé : Réglages iPhone → Brole → Notifications → activer, puis rouvrir l’app et vous reconnecter. Vérifier la **capability Push Notifications** dans Xcode et faire `npx cap sync ios`.
- **Android** : accepter les notifications à l’invite. Vérifier que `google-services.json` est bien présent dans `frontend/android/app/` (téléchargé depuis Firebase Console pour le package `com.tdblg.app`). Rebuild l’app et vous reconnecter.

Une fois le token enregistré et le backend configuré (APNs pour iOS, FCM pour Android), le test doit envoyer une notification sur l’appareil.

---

### 5. Envoi réel des notifications (alertes, tâches, etc.)

Le service backend `NotificationService` envoie les notifications push aux appareils enregistrés : **iOS** via `ApnService` (APNs), **Android** via `FcmService` (FCM). Les événements (alertes, tâches, etc.) enverront une push si :

- **iOS** : APNs est configuré dans le `.env` (voir §2) et l’utilisateur a au moins un token iOS.
- **Android** : FCM est configuré (voir §3) et l’utilisateur a au moins un token Android.

---

## Checklist rapide

| Étape | À vérifier |
|--------|------------|
| App | Tester sur l’**app native** (iPhone/Android), pas dans le navigateur. |
| iOS | Capability **Push Notifications** activée dans Xcode ; notifications autorisées pour Brole dans Réglages. |
| Android | Fichier **google-services.json** dans `frontend/android/app/` ; notifications autorisées pour l’app. |
| Backend iOS | `.env` avec `APN_KEY_ID`, `APN_TEAM_ID`, `APN_BUNDLE_ID`, `APN_KEY_PATH` (fichier .p8 valide). |
| Backend Android | `.env` avec `FCM_CREDENTIALS_JSON` pointant vers le fichier JSON du compte de service Firebase. |
| Test | Mon compte → « Envoyer une notification de test » après connexion sur l’app. |

Si après tout ça le test échoue encore, regarder les **logs Laravel** (`storage/logs/laravel.log`) et les réponses API (code 400 / 502 / 503) pour le message exact renvoyé par le backend.

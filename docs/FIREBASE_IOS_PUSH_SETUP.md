# Notifications push iOS – configuration pas à pas

Pour que les notifications push fonctionnent sur **iPhone**, il faut configurer **Apple (APNs)** et **Xcode**. Contrairement à Android (Firebase/FCM), iOS utilise le service **APNs** d’Apple.

---

## Prérequis

- Un **compte Apple Developer** (payant, 99 €/an) : [developer.apple.com](https://developer.apple.com)
- **Xcode** installé sur ton Mac
- Le **Bundle ID** de l’app doit être cohérent partout : **`com.tdblg.app`** (comme dans `capacitor.config.ts` et le backend)

---

## Étape 1 : Vérifier le Bundle ID dans Xcode

1. Ouvre le projet iOS dans Xcode :
   ```bash
   cd frontend/ios/App
   open App.xcworkspace
   ```
2. Dans le navigateur de projet (gauche), sélectionne le **projet** (icône bleue) puis la **target** « App ».
3. Onglet **Signing & Capabilities**.
4. Vérifie que **Bundle Identifier** = **`com.tdblg.app`**.
   - Si tu vois `com.jopcelin.brole` (ou autre), change-le en **`com.tdblg.app`** pour rester aligné avec le backend et Android.

---

## Étape 2 : Activer la capacité « Push Notifications » dans Xcode

1. Toujours dans **Signing & Capabilities**, clique sur **« + Capability »**.
2. Cherche **« Push Notifications »** et double-clique pour l’ajouter.
3. Sauvegarde (Cmd+S). Xcode peut créer ou modifier un fichier `.entitlements` avec `aps-environment`.

C’est nécessaire pour que l’app puisse recevoir des notifications APNs.

---

## Étape 3 : Créer une clé APNs dans Apple Developer

1. Va sur **[developer.apple.com](https://developer.apple.com)** → **Account** (ou **Certificates, Identifiers & Profiles**).
2. Dans le menu de gauche : **Certificates, Identifiers & Profiles** → **Keys**.
3. Clique sur **« + »** (créer une clé).
4. **Key Name** : par ex. `Brole Push` ou `APNs Brole`.
5. Coche **« Apple Push Notifications service (APNs) »**.
6. Clique sur **Continue** puis **Register**.
7. Sur l’écran suivant : **Download** pour télécharger le fichier **`.p8`** (tu ne pourras plus le retélécharger plus tard).
8. Note l’**Key ID** affiché (ex. `ABC123XYZ`) : ce sera `APN_KEY_ID` dans le `.env`.

---

## Étape 4 : Récupérer le Team ID et le Bundle ID

- **Team ID** : Apple Developer → **Membership** (ou en haut à droite de la page Certificates, Identifiers & Profiles). C’est une valeur du type `ABCD1234`.
- **Bundle ID** : doit être **`com.tdblg.app`** (comme dans ton app et le backend).  
  Si besoin, crée ou vérifie l’**App ID** dans **Identifiers** avec ce Bundle ID et active **Push Notifications** dans les capabilities de l’App ID.

---

## Étape 5 : Placer la clé .p8 sur le serveur (backend)

1. Copie le fichier **`.p8`** téléchargé (ex. `AuthKey_ABC123XYZ.p8`) sur la machine où tourne le backend Laravel.
2. Mets-le dans un dossier sûr, par ex. :  
   **`protectAli/storage/app/AuthKey_ABC123XYZ.p8`**
3. Ne **commite pas** ce fichier dans Git. Vérifie qu’il est dans **`.gitignore`** (ex. `storage/app/*.p8` ou le nom du fichier).

---

## Étape 6 : Configurer le fichier `.env` du backend

Dans **`protectAli/.env`**, ajoute ou modifie :

```env
# Apple Push Notifications (APNs) – iOS
APN_KEY_ID=ABC123XYZ
APN_TEAM_ID=ABCD1234
APN_BUNDLE_ID=com.tdblg.app
APN_KEY_PATH=storage/app/AuthKey_ABC123XYZ.p8
APN_SANDBOX=true
```

À remplacer par tes vraies valeurs :

| Variable       | Où la trouver |
|----------------|----------------|
| `APN_KEY_ID`   | Key ID affiché quand tu as créé la clé (sans le préfixe `AuthKey_`) |
| `APN_TEAM_ID`  | Team ID dans ton compte Apple Developer |
| `APN_BUNDLE_ID`| `com.tdblg.app` (identique à l’app et Xcode) |
| `APN_KEY_PATH` | Chemin vers le fichier `.p8` (relatif à la racine du projet Laravel ou absolu) |
| `APN_SANDBOX`  | `true` pour dev / TestFlight, `false` pour l’app en production sur l’App Store |

---

## Étape 7 : Tester sur un vrai iPhone

Les push **ne fonctionnent pas** sur le simulateur iOS. Il faut un **appareil physique**.

1. Connecte ton iPhone au Mac.
2. Dans Xcode, choisis ton iPhone comme destination et lance l’app (Run).
3. À la première connexion dans l’app, **accepte les notifications** quand l’app le demande.
4. Va dans **Mon compte** (ou Paramètres) → **« Envoyer une notification de test »**.
5. Tu devrais recevoir la notification sur l’iPhone.

Si le test échoue : vérifier les logs Laravel (`storage/logs/laravel.log`) et le message d’erreur affiché dans l’app (ex. « APNs non configuré », « Invalid token », etc.).

---

## Récapitulatif

| Étape | Action |
|-------|--------|
| 1 | Bundle ID = `com.tdblg.app` dans Xcode |
| 2 | Ajouter la capacité **Push Notifications** dans Xcode |
| 3 | Créer une clé APNs (.p8) dans Apple Developer et la télécharger |
| 4 | Noter Key ID et Team ID |
| 5 | Copier le `.p8` dans `protectAli/storage/app/` (et ne pas le versionner) |
| 6 | Remplir `APN_*` dans `protectAli/.env` |
| 7 | Tester sur un **vrai iPhone** avec « Envoyer une notification de test » |

---

## En production (App Store)

- Passe **`APN_SANDBOX=false`** dans le `.env` du serveur de production.
- La même clé APNs (.p8) fonctionne pour le mode sandbox et production.

Pour plus de détails ou erreurs courantes, voir [PUSH_NOTIFICATIONS.md](./PUSH_NOTIFICATIONS.md).

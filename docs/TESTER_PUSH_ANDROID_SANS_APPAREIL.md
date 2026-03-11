# Tester les notifications push Android sans avoir d’Android

Tu peux vérifier que les push Android fonctionnent soit avec un **émulateur**, soit en utilisant **un téléphone Android d’un proche**.

---

## Option 1 : Émulateur Android (sur ton Mac)

### Prérequis

- **Android Studio** installé : [developer.android.com/studio](https://developer.android.com/studio)
- Un **appareil virtuel** avec **Google Play** (pour que FCM fonctionne)

### Étapes

1. **Ouvrir Android Studio** → **Device Manager** (icône téléphone/tablette) → **Create Device**.
2. Choisir un **modèle** (ex. Pixel 6) → **Next**.
3. Choisir une **image système** :
   - Prendre une image avec l’icône **Google Play** (ex. « Tiramisu » ou « UpsideDownCake » avec **Google Play**).
   - Ne pas prendre « Google APIs » seul (sans Play) : FCM ne marche souvent pas dessus.
4. Finir la création de l’appareil virtuel (AVD).
5. **Lancer l’émulateur** (bouton Play à côté de l’AVD).
6. **Installer l’app Brole** sur l’émulateur :
   - Depuis le projet : `cd frontend && npx cap run android` (avec l’émulateur déjà ouvert),  
   - ou build APK puis glisser-déposer l’APK sur l’émulateur.
7. Dans l’app sur l’émulateur : **se connecter**, **accepter les notifications** quand demandé.
8. Aller dans **Mon compte** → **Notification de test** → lancer le test.

Si tout est configuré (backend avec FCM, `google-services.json` dans l’app), la notification peut apparaître sur l’émulateur (bannière ou tiroir de notifications).

**Remarque :** Sur certains émulateurs ou versions, les notifications FCM peuvent être capricieuses. Si rien n’apparaît, vérifier que l’image a bien **Google Play** et qu’un compte Google est connecté dans les paramètres de l’émulateur.

---

## Option 2 : Téléphone Android d’un proche (ou d’un testeur)

Quelqu’un d’autre peut faire le test sur son Android ; toi tu vérifies côté backend.

### Ce que la personne fait sur son Android

1. Installer l’app Brole (APK ou via un lien de test).
2. Se connecter avec **ton compte** (ou un compte de test que tu utilises).
3. Accepter les **notifications** quand l’app le demande.
4. Aller dans **Mon compte** → **Notification de test** et appuyer sur le bouton.

Si le backend et FCM sont bien configurés, elle reçoit la notification sur son téléphone.

### Ce que tu peux faire côté backend (sans toucher à l’appareil)

- Tu peux **déclencher un envoi de test** via l’API avec le même utilisateur :
  - Appeler en POST (avec le token d’auth de cet utilisateur) :  
    `POST /api/push-tokens/send-test`
  - Par exemple avec **Postman**, **curl** ou un script, en étant connecté en tant que cet utilisateur.

Comme ça, tu vérifies que l’envoi depuis le backend vers FCM fonctionne, même sans avoir toi-même un Android.

---

## Vérifier que le backend envoie bien à Android

- **Logs Laravel** : après un test, regarder `storage/logs/laravel.log` pour des erreurs FCM.
- **Base de données** : table `push_tokens` — vérifier qu’il existe un enregistrement avec `platform = 'android'` pour l’utilisateur qui a ouvert l’app sur Android. Si le token est là et que `send-test` ne renvoie pas d’erreur, la chaîne backend → FCM est en place.

---

## En résumé

| Situation | Solution |
|-----------|----------|
| Tu as un Mac, pas d’Android | Émulateur Android Studio avec image **Google Play** + installer l’app + test dans Mon compte. |
| Tu ne peux pas utiliser d’émulateur | Un proche installe l’app sur son Android, se connecte, accepte les notifications et lance « Notification de test ». |
| Tu veux juste vérifier l’envoi backend | Faire enregistrer un token Android (émulateur ou proche), puis appeler `POST /api/push-tokens/send-test` avec l’auth de cet utilisateur. |

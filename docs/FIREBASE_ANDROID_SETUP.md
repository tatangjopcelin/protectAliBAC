# Configurer Firebase pour les notifications push Android

Ce guide explique comment créer un projet Firebase et récupérer le fichier `google-services.json` pour l’app Brole (package `com.tdblg.app`).

---

## Étape 1 : Créer un compte et ouvrir la console Firebase

1. Va sur **[https://console.firebase.google.com](https://console.firebase.google.com)**.
2. Connecte-toi avec un **compte Google** (Gmail).
3. Si c’est ta première fois, accepte les conditions d’utilisation.

---

## Étape 2 : Créer un projet Firebase (ou en utiliser un existant)

### Option A : Créer un nouveau projet

1. Clique sur **« Créer un projet »** (ou **« Add project »**).
2. **Nom du projet** : par exemple `Brole` ou `Boucher2`.
3. Clique sur **« Continuer »**.
4. **Google Analytics** : tu peux l’activer ou le désactiver (pas obligatoire pour les notifications). Clique sur **« Continuer »** puis **« Créer le projet »**.
5. Quand le projet est prêt, clique sur **« Continuer »**.

### Option B : Utiliser un projet existant

1. Sur la page d’accueil Firebase, clique sur le nom du projet existant dans la liste à gauche.

---

## Étape 3 : Ajouter l’application Android au projet

1. Sur la page d’accueil du projet, repère la carte **« Android »** (icône robot vert) et clique sur l’icône **Android**.
2. Tu arrives sur **« Enregistrer votre application »**.

Renseigne :

| Champ | Valeur à mettre |
|--------|------------------|
| **Nom du package Android** | `com.tdblg.app` |
| **Surnom de l’application** | `Brole` (optionnel) |
| **Certificat de signature SHA-1** | (optionnel pour les push de base ; tu peux laisser vide pour l’instant) |

3. Clique sur **« Enregistrer l’application »**.

---

## Étape 4 : Télécharger le fichier `google-services.json`

1. Sur l’écran suivant, Firebase te propose de **télécharger `google-services.json`**.
2. Clique sur **« Télécharger google-services.json »**.
3. Le fichier est enregistré dans ton dossier Téléchargements (souvent `google-services.json`).

---

## Étape 5 : Placer le fichier dans le projet

1. **Ouvre le dossier de ton projet** sur ton ordinateur :  
   `.../boucher2/frontend/android/app/`
2. **Copie** le fichier `google-services.json` téléchargé **dans** ce dossier `app/`.

Tu dois obtenir cette structure :

```
frontend/
  android/
    app/
      google-services.json   ← le fichier doit être ici
      build.gradle
      ...
```

Sous macOS/Linux, en terminal depuis la racine du projet (`boucher2`) :

```bash
# Remplace "~/Downloads/google-services.json" par le vrai chemin si besoin
cp ~/Downloads/google-services.json frontend/android/app/google-services.json
```

---

## Étape 6 : Vérifier que le build Android l’utilise

Le projet est déjà configuré pour utiliser ce fichier. Dans `frontend/android/app/build.gradle` il y a :

```gradle
try {
    def servicesJSON = file('google-services.json')
    if (servicesJSON.text) {
        apply plugin: 'com.google.gms.google-services'
    }
} catch(Exception e) {
    logger.info("google-services.json not found, ...")
}
```

Dès que `google-services.json` est présent dans `frontend/android/app/`, le plugin Google Services est appliqué et FCM peut fonctionner.

---

## Récapitulatif

| Étape | Action |
|--------|--------|
| 1 | Aller sur [console.firebase.google.com](https://console.firebase.google.com) et se connecter |
| 2 | Créer un projet (ex. « Brole ») ou en sélectionner un |
| 3 | Ajouter une app **Android** avec le package **`com.tdblg.app`** |
| 4 | Télécharger **google-services.json** |
| 5 | Copier **google-services.json** dans **frontend/android/app/** |

Ensuite, pour que le **backend** puisse envoyer les notifications (test inclus), il faut aussi configurer le **compte de service Firebase** et `FCM_CREDENTIALS_JSON` : voir la section « Côté backend : FCM » dans [PUSH_NOTIFICATIONS.md](./PUSH_NOTIFICATIONS.md).

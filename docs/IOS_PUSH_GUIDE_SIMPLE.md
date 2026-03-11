# Notifications push iPhone – guide simple, étape par étape

Ce guide explique **exactement** quoi faire, dans l’ordre. Tu peux le suivre du début à la fin.

---

## Ce qu’il faut savoir avant de commencer

- Sur **iPhone**, les notifications passent par **Apple** (pas par Firebase comme sur Android).
- Il te faut un **compte Apple Developer** payant (99 €/an). Sans ça, tu ne peux pas créer la clé pour les notifications.
- Les notifications **ne marchent pas** sur le simulateur iPhone : il faut un **vrai téléphone** branché au Mac.

---

# ÉTAPE 1 : Activer les notifications dans Xcode

**Xcode** = le logiciel Apple pour développer des apps sur Mac.

1. Sur ton Mac, ouvre **Finder**.
2. Va dans le dossier de ton projet, puis : **boucher2** → **frontend** → **ios** → **App**.
3. Tu dois voir un fichier qui s’appelle **App.xcworkspace** (icône blanche/bleue). **Double-clique dessus**.  
   → Xcode s’ouvre avec ton projet.
4. À **gauche** dans Xcode, tu vois une liste de dossiers et fichiers. Tout en haut, clique sur l’icône bleue **« App »** (le projet, pas un dossier).
5. Au **milieu** de l’écran, tu vois plusieurs onglets : **General**, **Signing & Capabilities**, etc. Clique sur **« Signing & Capabilities »**.
6. Tu vois une zone avec des « capabilities » (droits de l’app). Clique sur le bouton **« + Capability »** (en haut à gauche de cette zone).
7. Une liste s’ouvre. Tape **« Push »** dans la recherche. Tu vois **« Push Notifications »**. Double-clique dessus pour l’ajouter.
8. Tu dois voir apparaître une ligne **« Push Notifications »** dans la liste des capabilities. C’est bon.
9. Enregistre si besoin : **Fichier** → **Enregistrer** (ou Cmd+S).

**Résumé étape 1 :** Tu as dit à Xcode que ton app a le droit d’utiliser les notifications push.

---

# ÉTAPE 2 : Créer la clé APNs sur le site Apple

**APNs** = le service d’Apple qui envoie les notifications à ton app. Pour que ton serveur puisse envoyer des notifications, Apple te donne une **clé** (un fichier .p8).

1. Ouvre ton navigateur et va sur : **https://developer.apple.com**
2. Connecte-toi avec ton **compte Apple Developer** (celui qui paie les 99 €/an).
3. En haut de la page, clique sur **« Account »** (Compte).
4. Dans le menu de gauche, cherche **« Certificates, Identifiers & Profiles »** et clique dessus.
5. Dans le menu qui s’affiche à gauche, clique sur **« Keys »** (Clés).
6. Clique sur le bouton bleu **« + »** (à côté de « Keys ») pour créer une nouvelle clé.
7. Donne un nom à la clé, par exemple : **Brole Push**.
8. Plus bas, coche **une seule case** : **« Apple Push Notifications service (APNs) »**. Ne coche rien d’autre.
9. Clique sur **« Continue »** puis sur **« Register »**.
10. Sur l’écran suivant, tu vois **« Download »**. Clique dessus pour télécharger le fichier.  
    → Un fichier **.p8** est téléchargé (souvent dans ton dossier **Téléchargements**).  
    **Important :** Apple ne te laissera plus le télécharger après. Garde ce fichier en lieu sûr.
11. Sur la même page, tu vois écrit **« Key ID »** avec un code (ex. : ABC12DEF3). **Note ce Key ID** sur un papier ou dans un fichier texte : tu en auras besoin plus tard.

**Résumé étape 2 :** Tu as créé une clé sur le site Apple et téléchargé le fichier .p8. Tu as noté le Key ID.

---

# ÉTAPE 3 : Trouver ton Team ID

Le **Team ID** est un code qui identifie ton équipe (ou toi) chez Apple.

1. Toujours sur **developer.apple.com**, va dans **« Membership »** (Adhésion) dans le menu, ou regarde en haut à droite après être allé dans **Account**.
2. Tu vois une section **« Membership details »**. Il y a une ligne **« Team ID »** avec un code (ex. : ABCD1234). **Note ce code.**

**Résumé étape 3 :** Tu as noté ton Team ID.

---

# ÉTAPE 4 : Mettre le fichier .p8 dans ton projet backend

Ton **backend** (le serveur Laravel dans le dossier **protectAli**) doit pouvoir lire le fichier .p8 pour envoyer les notifications. Il faut donc le placer au bon endroit.

1. Sur ton Mac, ouvre **Finder**.
2. Va dans le dossier **boucher2** → **protectAli** → **storage** → **app**.
3. Trouve le fichier **.p8** que tu as téléchargé à l’étape 2 (souvent dans **Téléchargements**). Son nom ressemble à : **AuthKey_ABC12DEF3.p8** (avec ton Key ID dedans).
4. **Copie** ce fichier (clic droit → Copier) et **colle-le** dans le dossier **protectAli/storage/app/**.
5. Note le **nom exact** du fichier (par ex. **AuthKey_ABC12DEF3.p8**). Tu en auras besoin à l’étape 5.

**Résumé étape 4 :** Le fichier .p8 est maintenant dans **protectAli/storage/app/**.

---

# ÉTAPE 5 : Remplir le fichier .env du backend

Le fichier **.env** contient les réglages secrets de ton serveur. Il faut y mettre les infos Apple pour les notifications.

1. Ouvre ton projet dans **Cursor** (ou un éditeur de texte).
2. Ouvre le fichier : **protectAli/.env** (à la racine du dossier **protectAli**).
3. Cherche les lignes qui commencent par **APN_**. Si elles n’existent pas, ajoute-les à la fin du fichier.
4. Remplis ou modifie pour avoir **exactement** ceci (en mettant **tes** vraies valeurs à la place des exemples) :

```env
APN_KEY_ID=ABC12DEF3
APN_TEAM_ID=ABCD1234
APN_BUNDLE_ID=com.tdblg.app
APN_KEY_PATH=storage/app/AuthKey_ABC12DEF3.p8
APN_SANDBOX=true
```

À remplacer :
- **ABC12DEF3** → le **Key ID** que tu as noté à l’étape 2 (sans « AuthKey_ » ni « .p8 »).
- **ABCD1234** → ton **Team ID** noté à l’étape 3.
- **AuthKey_ABC12DEF3.p8** → le **nom exact** du fichier .p8 que tu as mis dans **storage/app/** à l’étape 4.

Ne change **pas** :
- **APN_BUNDLE_ID=com.tdblg.app** → ça doit rester comme ça.
- **APN_SANDBOX=true** → pour les tests ; tu le passeras à **false** plus tard pour l’app en production.

5. Enregistre le fichier **.env**.

**Résumé étape 5 :** Le backend sait maintenant où est la clé Apple et comment s’identifier.

---

# ÉTAPE 6 : Tester sur un vrai iPhone

1. Branche ton **iPhone** au Mac avec le câble.
2. Ouvre le projet dans **Xcode** (comme à l’étape 1).
3. En haut de Xcode, à côté du bouton Play, il y a un menu qui affiche un appareil (ex. « iPhone 15 » ou le nom de ton téléphone). Choisis **ton iPhone** (pas « Simulator »).
4. Clique sur le bouton **Play** (triangle) pour lancer l’app sur ton téléphone.
5. Sur ton iPhone, quand l’app te demande d’autoriser les **notifications**, clique sur **Autoriser** (ou « Allow »).
6. Connecte-toi dans l’app si besoin.
7. Va dans **Mon compte** ou **Paramètres**, puis cherche le bouton **« Envoyer une notification de test »** (ou équivalent) et appuie dessus.
8. Tu devrais recevoir une notification sur ton iPhone quelques secondes après.

Si ça ne marche pas : ouvre le fichier **protectAli/storage/logs/laravel.log** et regarde les dernières lignes pour voir l’erreur. Tu peux aussi me dire le message d’erreur affiché dans l’app.

---

# Récapitulatif en une phrase par étape

1. **Xcode** : Signing & Capabilities → + Capability → Push Notifications.
2. **Apple** : Keys → + → cocher APNs → Register → Download le .p8 → noter le Key ID.
3. **Apple** : noter le Team ID (Membership).
4. **Projet** : copier le .p8 dans **protectAli/storage/app/**.
5. **Projet** : éditer **protectAli/.env** avec APN_KEY_ID, APN_TEAM_ID, APN_BUNDLE_ID, APN_KEY_PATH, APN_SANDBOX.
6. **iPhone** : lancer l’app depuis Xcode sur ton téléphone, accepter les notifications, puis « Envoyer une notification de test ».

Si tu bloques sur une étape précise (par ex. « je ne trouve pas Keys » ou « je ne vois pas Signing & Capabilities »), dis-moi le numéro de l’étape et ce que tu vois à l’écran, et on fera cette partie ensemble.

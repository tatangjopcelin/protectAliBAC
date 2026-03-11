# Utiliser ngrok pour tester l'app (émulateur + mobile)

## 1. Installer ngrok

- Télécharge : https://ngrok.com/download  
- Ou avec Homebrew : `brew install ngrok`  
- Crée un compte gratuit sur ngrok.com et récupère ton authtoken, puis : `ngrok config add-authtoken TON_TOKEN`

## 2. Démarrer le backend

Docker (depuis la racine du projet) :

```bash
docker compose up -d app
```

Le backend doit écouter sur le port **8001** (Docker expose 8001:8000).

## 3. Lancer ngrok

Dans un terminal :

```bash
ngrok http 8001
```

Tu obtiens une URL du type : **https://xxxx-xx-xx-xx-xx.ngrok-free.app**

Copie cette URL (sans le `/` à la fin).

## 4. Mettre l'URL dans l'app

Ouvre **frontend/src/environments/environment.ts** et remplace :

```ts
apiUrl: 'https://REMPLACER_PAR_TON_URL_NGROK/api',
```

par (avec ta vraie URL ngrok) :

```ts
apiUrl: 'https://xxxx-xx-xx-xx-xx.ngrok-free.app/api',
```

Enregistre le fichier.

## 5. Rebuild et lancer l'app

```bash
cd frontend
npm run build -- --configuration=development
npx cap sync android
```

Puis dans Android Studio : **Run** sur l’émulateur (ou sur un vrai téléphone).

## 6. Tester

Ouvre l’app, écran de connexion, **Se connecter**. L’app appellera le backend via l’URL ngrok.

**Note :** À chaque redémarrage de ngrok (version gratuite), l’URL change. Il faudra alors mettre à jour `environment.ts` et refaire un build + sync.

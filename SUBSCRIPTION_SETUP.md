# Configuration de l'abonnement par carte (Stripe)

## 1. Compte Stripe

- Créez un compte sur [Stripe](https://dashboard.stripe.com).
- En mode test, utilisez les clés de test (préfixées par `pk_test_` et `sk_test_`).

## 2. Variables d'environnement (.env)

```env
STRIPE_KEY=pk_test_xxx          # Clé publique (frontend si besoin)
STRIPE_SECRET=sk_test_xxx       # Clé secrète (backend)
STRIPE_WEBHOOK_SECRET=whsec_xxx # Secret du webhook (voir ci-dessous)
CASHIER_CURRENCY=eur
CASHIER_CURRENCY_LOCALE=fr_FR
STRIPE_PRICE_ESSENTIEL=price_xxx
STRIPE_PRICE_PRO=price_xxx
STRIPE_PRICE_PRO_ANNUEL=price_xxx
```

## 3. Créer les produits et prix dans Stripe

1. Dans le dashboard Stripe : **Produits** → **Ajouter un produit**.
2. Créez par exemple :
   - **Essentiel** : prix récurrent 19,90 € / mois → copiez l’ID du prix (ex. `price_xxx`).
   - **Pro** : 49,90 € / mois.
   - **Pro Annuel** : 499 € / an.
3. Collez ces IDs dans `.env` (`STRIPE_PRICE_ESSENTIEL`, etc.).

## 4. Webhook Stripe

1. Stripe → **Développeurs** → **Webhooks** → **Ajouter un endpoint**.
2. URL : `https://votre-domaine.com/stripe/webhook` (ou en local avec Stripe CLI : `stripe listen --forward-to localhost:8000/stripe/webhook`).
3. Événements à écouter : ceux par défaut de Laravel Cashier (customer.subscription.*, invoice.*, etc.).
4. Copiez le **Signing secret** (`whsec_xxx`) dans `STRIPE_WEBHOOK_SECRET`.

## 5. Migrations et seed

```bash
php artisan migrate
php artisan db:seed --class=SubscriptionPlanSeeder
```

Les offres (Essentiel, Pro, Pro Annuel) sont créées avec les montants d’affichage ; les IDs Stripe viennent de `.env`.

## 6. Accès dans l’app

- **Mon compte** → **Établissements & Abonnements** → page Abonnement.
- L’utilisateur peut voir les offres, cliquer sur **S’abonner par carte** (redirection Stripe Checkout), puis gérer sa facturation via le portail Stripe.

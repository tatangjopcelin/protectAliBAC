<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;

class SubscriptionController extends Controller
{
    /**
     * Liste des offres d'abonnement (format simple, paiement par carte uniquement).
     */
    public function plans(Request $request)
    {
        $plans = SubscriptionPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SubscriptionPlan $p) => [
                'type' => $p->slug,
                'name' => $p->name,
                'price' => (int) round($p->amount_cents / 100),
                'currency' => config('cashier.currency', 'eur'),
                'duration' => $p->interval === 'year' ? '1 an' : '1 mois',
                'price_formatted' => $p->price_formatted,
                'interval_label' => $p->interval_label,
                'features' => $p->features ?? [],
                'stripe_price_id' => $p->stripe_price_id,
            ]);

        return response()->json([
            'plans' => $plans,
            'currency' => config('cashier.currency', 'eur'),
        ]);
    }

    /**
     * S'abonner à un plan par carte bancaire (redirection Stripe Checkout).
     * Uniquement le mode carte : aucune donnée carte ne transite par l'app.
     */
    public function subscribe(Request $request)
    {
        $validated = $request->validate([
            'plan_slug' => 'required|string|exists:subscription_plans,slug',
            'success_url' => 'nullable|string|max:2048',
            'cancel_url' => 'nullable|string|max:2048',
        ]);

        $user = $request->user();
        $plan = SubscriptionPlan::where('slug', $validated['plan_slug'])->where('is_active', true)->firstOrFail();
        $stripePriceId = $plan->stripe_price_id;

        if (!$stripePriceId) {
            return response()->json(['message' => 'Cette offre n\'a pas de prix Stripe configuré.'], 422);
        }

        $baseSuccess = $validated['success_url'] ?? config('app.url') . '/tabs/account-settings';
        $successUrl = str_contains($baseSuccess, '?') ? $baseSuccess . '&session_id={CHECKOUT_SESSION_ID}' : $baseSuccess . '?checkout=success&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $validated['cancel_url'] ?? config('app.url') . '/tabs/subscription?checkout=cancelled';

        try {
            $checkout = $user->newSubscription('default', $stripePriceId)
                ->allowPromotionCodes()
                ->checkout([
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Impossible de créer la session de paiement.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 422);
        }

        return response()->json([
            'message' => 'Redirection vers le paiement sécurisé.',
            'checkout_url' => $checkout->url,
            'session_id' => $checkout->id,
        ], 201);
    }

    /**
     * Statut de l'abonnement : au niveau de l'établissement (store).
     * Si l'utilisateur n'a pas d'abonnement personnel, on regarde si un autre utilisateur du même store (ex. admin) a un abonnement actif.
     * Sinon : plan gratuit (essai 15 jours) si l'établissement est en période d'essai.
     */
    public function status(Request $request)
    {
        $user = $request->user();

        $subscription = $user->subscription('default');

        if (!$subscription || !$subscription->valid()) {
            // Pas d'abonnement perso valide : vérifier si un autre utilisateur du même établissement a un abonnement actif
            $storeSubscriber = User::where('store_id', $user->store_id)
                ->where('id', '!=', $user->id)
                ->get()
                ->first(fn (User $u) => $u->subscribed('default'));
            if ($storeSubscriber) {
                $subscription = $storeSubscriber->subscription('default');
            }
        }

        $subscribed = $subscription && $subscription->valid();

        $data = [
            'subscribed' => $subscribed,
            'plan_name' => null,
            'plan_slug' => null,
            'limits' => ['max_users' => null],
            'pro_features_allowed' => false,
            'stripe_status' => null,
            'starts_at' => null,
            'ends_at' => null,
            'trial_ends_at' => null,
        ];

        if ($subscription && $subscription->valid()) {
            $data['stripe_status'] = $subscription->stripe_status;
            $data['starts_at'] = $subscription->created_at?->toIso8601String();
            $data['ends_at'] = $subscription->ends_at?->toIso8601String()
                ?? $subscription->currentPeriodEnd()?->toIso8601String();
            $data['trial_ends_at'] = $subscription->trial_ends_at?->toIso8601String();
            $stripePrice = $subscription->stripe_price;
            $plan = null;
            if ($stripePrice) {
                $plan = SubscriptionPlan::where('stripe_price_id', $stripePrice)->first();
                $data['plan_name'] = $plan?->name ?? 'Abonnement actif';
                if ($plan) {
                    $data['plan_slug'] = $plan->slug;
                    $data['limits'] = SubscriptionPlan::getLimitsBySlug($plan->slug);
                    $data['pro_features_allowed'] = SubscriptionPlan::hasProFeatures($plan->slug);
                }
            } else {
                $data['plan_name'] = 'Abonnement actif';
            }
        } else {
            // Pas d'abonnement Stripe sur l'établissement : vérifier le plan gratuit (essai 15 jours)
            $user->load('store');
            $store = $user->store;
            if ($store && $store->trial_ends_at && $store->trial_ends_at->isFuture()) {
                $data['subscribed'] = true;
                $data['plan_name'] = 'Gratuit';
                $data['plan_slug'] = 'gratuit';
                $data['limits'] = SubscriptionPlan::getLimitsBySlug('gratuit');
                $data['pro_features_allowed'] = SubscriptionPlan::hasProFeatures('gratuit');
                $data['starts_at'] = $store->created_at?->toIso8601String();
                $data['ends_at'] = $store->trial_ends_at->toIso8601String();
            }
        }

        if ($data['plan_slug'] && !isset($data['pro_features_allowed'])) {
            $data['pro_features_allowed'] = SubscriptionPlan::hasProFeatures($data['plan_slug']);
        }
        $data['access_expired'] = !$data['subscribed'];

        return response()->json($data);
    }

    /**
     * Portail Stripe (gérer carte, factures, annuler).
     */
    public function billingPortal(Request $request)
    {
        $request->validate([
            'return_url' => 'nullable|string|url',
        ]);

        $user = $request->user();

        if (!$user->subscribed('default')) {
            return response()->json(['message' => 'Aucun abonnement actif.'], 422);
        }

        $returnUrl = $request->input('return_url') ?? config('app.url') . '/tabs/subscription';

        return response()->json([
            'portal_url' => $user->billingPortalUrl($returnUrl),
        ]);
    }

    /**
     * Synchronise l'abonnement après un retour réussi de Stripe Checkout (sans attendre le webhook).
     * À appeler par le frontend quand l'URL contient checkout=success et session_id.
     */
    public function verifyCheckoutSession(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'required|string|max:255',
        ]);

        $user = $request->user();

        Stripe::setApiKey(config('cashier.secret'));

        try {
            $session = StripeSession::retrieve($validated['session_id'], [
                'expand' => ['subscription', 'subscription.items.data.price'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Session de paiement invalide ou expirée.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 422);
        }

        if ($session->mode !== 'subscription' || empty($session->subscription)) {
            return response()->json(['message' => 'Cette session n\'est pas un abonnement.'], 422);
        }

        $customerId = is_string($session->customer) ? $session->customer : $session->customer->id;
        $subscriptionId = is_string($session->subscription) ? $session->subscription : $session->subscription->id;

        if ($user->stripe_id !== $customerId) {
            $user->stripe_id = $customerId;
            $user->save();
        }

        $stripeSubscription = $session->subscription;
        if (is_string($stripeSubscription)) {
            $stripeSubscription = StripeSubscription::retrieve($stripeSubscription, ['expand' => ['items.data.price']]);
        }

        $data = $stripeSubscription->toArray();
        $type = $data['metadata']['type'] ?? $data['metadata']['name'] ?? 'default';

        if (isset($data['trial_end'])) {
            $trialEndsAt = Carbon::createFromTimestamp($data['trial_end']);
        } else {
            $trialEndsAt = null;
        }

        $firstItem = $data['items']['data'][0] ?? null;
        $isSinglePrice = count($data['items']['data']) === 1;

        $subscription = $user->subscriptions()->updateOrCreate(
            ['stripe_id' => $data['id']],
            [
                'type' => $type,
                'stripe_status' => $data['status'],
                'stripe_price' => $firstItem && $isSinglePrice ? $firstItem['price']['id'] : null,
                'quantity' => $firstItem && $isSinglePrice && isset($firstItem['quantity']) ? $firstItem['quantity'] : null,
                'trial_ends_at' => $trialEndsAt,
                'ends_at' => null,
            ]
        );

        foreach ($data['items']['data'] as $item) {
            $subscription->items()->updateOrCreate(
                ['stripe_id' => $item['id']],
                [
                    'stripe_product' => $item['price']['product'],
                    'stripe_price' => $item['price']['id'],
                    'quantity' => $item['quantity'] ?? null,
                ]
            );
        }

        return response()->json([
            'message' => 'Abonnement synchronisé.',
            'subscribed' => true,
        ]);
    }

    /**
     * Synchronise les abonnements Stripe de l'utilisateur connecté (secours si pas de session_id).
     */
    public function syncFromStripe(Request $request)
    {
        $user = $request->user();

        if (empty($user->stripe_id)) {
            return response()->json(['message' => 'Aucun client Stripe associé.'], 422);
        }

        Stripe::setApiKey(config('cashier.secret'));

        try {
            $stripeSubscriptions = StripeSubscription::all([
                'customer' => $user->stripe_id,
                'status' => 'active',
                'expand' => ['data.items.data.price'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Impossible de récupérer les abonnements.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 422);
        }

        foreach ($stripeSubscriptions->data as $data) {
            $data = $data->toArray();
            $type = $data['metadata']['type'] ?? $data['metadata']['name'] ?? 'default';
            $trialEndsAt = isset($data['trial_end']) ? Carbon::createFromTimestamp($data['trial_end']) : null;
            $firstItem = $data['items']['data'][0] ?? null;
            $isSinglePrice = count($data['items']['data']) === 1;

            $subscription = $user->subscriptions()->updateOrCreate(
                ['stripe_id' => $data['id']],
                [
                    'type' => $type,
                    'stripe_status' => $data['status'],
                    'stripe_price' => $firstItem && $isSinglePrice ? $firstItem['price']['id'] : null,
                    'quantity' => $firstItem && $isSinglePrice && isset($firstItem['quantity']) ? $firstItem['quantity'] : null,
                    'trial_ends_at' => $trialEndsAt,
                    'ends_at' => null,
                ]
            );

            foreach ($data['items']['data'] as $item) {
                $subscription->items()->updateOrCreate(
                    ['stripe_id' => $item['id']],
                    [
                        'stripe_product' => $item['price']['product'],
                        'stripe_price' => $item['price']['id'],
                        'quantity' => $item['quantity'] ?? null,
                    ]
                );
            }
        }

        return response()->json([
            'message' => 'Abonnements synchronisés.',
            'subscribed' => $user->subscribed('default'),
        ]);
    }
}

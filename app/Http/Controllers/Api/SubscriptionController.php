<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;

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

        $successUrl = $validated['success_url'] ?? config('app.url') . '/tabs/account-settings?checkout=success';
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
     * Statut de l'abonnement de l'utilisateur connecté.
     */
    public function status(Request $request)
    {
        $user = $request->user();

        $subscribed = $user->subscribed('default');
        $subscription = $user->subscription('default');

        $data = [
            'subscribed' => $subscribed,
            'plan_name' => null,
            'stripe_status' => null,
            'ends_at' => null,
            'trial_ends_at' => null,
        ];

        if ($subscription) {
            $data['stripe_status'] = $subscription->stripe_status;
            $data['ends_at'] = $subscription->ends_at?->toIso8601String();
            $data['trial_ends_at'] = $subscription->trial_ends_at?->toIso8601String();
            $stripePrice = $subscription->stripe_price;
            if ($stripePrice) {
                $plan = SubscriptionPlan::where('stripe_price_id', $stripePrice)->first();
                $data['plan_name'] = $plan?->name ?? 'Abonnement actif';
            } else {
                $data['plan_name'] = 'Abonnement actif';
            }
        }

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
}

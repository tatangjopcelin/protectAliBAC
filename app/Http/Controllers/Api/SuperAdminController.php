<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\SuperAdminBroadcast;
use Stripe\Stripe;

class SuperAdminController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {}

    /**
     * Liste des établissements avec plan d'abonnement et nombre d'utilisateurs.
     * Réservé au rôle super_admin, n'affecte pas la logique existante.
     */
    public function storesOverview(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'super_admin') {
            return response()->json(['message' => 'Accès réservé au super administrateur'], 403);
        }

        $stores = Store::withCount('users')
            ->with('creator:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get();

        $result = $stores->map(function (Store $store) {
            // Chercher un admin de l'établissement (supposé porteur de l'abonnement)
            $admin = User::where('store_id', $store->id)
                ->where('role', 'admin')
                ->first();

            $subscription = null;

            if ($admin && $admin->subscribed('default')) {
                $sub = $admin->subscription('default');
                $stripePrice = $sub->stripe_price;
                $plan = $stripePrice
                    ? SubscriptionPlan::where('stripe_price_id', $stripePrice)->first()
                    : null;

                $subscription = [
                    'type' => 'paid',
                    'plan_name' => $plan?->name ?? 'Abonnement actif',
                    'plan_slug' => $plan?->slug,
                    'status' => $sub->ended() ? 'ended' : 'active',
                    'trial_ends_at' => optional($sub->trial_ends_at)->toIso8601String(),
                    'ends_at' => optional($sub->ends_at)->toIso8601String(),
                ];
            } else {
                // Pas d'abonnement Stripe : accès libre (super admin), essai, ou aucun
                if ($store->free_access_granted_at) {
                    $subscription = [
                        'type' => 'free_access',
                        'plan_name' => 'Accès libre',
                        'plan_slug' => 'gratuit',
                        'status' => 'free_access',
                        'trial_ends_at' => null,
                        'ends_at' => null,
                    ];
                } elseif ($store->trial_ends_at && $store->trial_ends_at->isFuture()) {
                    $plan = SubscriptionPlan::where('slug', 'gratuit')->first();
                    $subscription = [
                        'type' => 'trial',
                        'plan_name' => $plan?->name ?? 'Gratuit',
                        'plan_slug' => $plan?->slug ?? 'gratuit',
                        'status' => 'trial',
                        'trial_ends_at' => $store->trial_ends_at->toIso8601String(),
                        'ends_at' => null,
                    ];
                } else {
                    $subscription = [
                        'type' => 'none',
                        'plan_name' => 'Aucun',
                        'plan_slug' => null,
                        'status' => 'none',
                        'trial_ends_at' => optional($store->trial_ends_at)->toIso8601String(),
                        'ends_at' => null,
                    ];
                }
            }

            return [
                'id' => $store->id,
                'name' => $store->name,
                'establishment_code' => $store->establishment_code,
                'is_active' => $store->is_active,
                'users_count' => $store->users_count,
                'subscription' => $subscription,
                'free_access_granted_at' => $store->free_access_granted_at?->toIso8601String(),
                'created_at' => optional($store->created_at)->toIso8601String(),
                'trial_ends_at' => optional($store->trial_ends_at)->toIso8601String(),
                'creator' => $store->creator,
            ];
        });

        return response()->json($result->values());
    }

    /**
     * Activer ou arrêter l'accès libre pour un établissement (super admin).
     * Body: { "granted": true|false }
     */
    public function setFreeAccess(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'super_admin') {
            return response()->json(['message' => 'Accès réservé au super administrateur'], 403);
        }

        $validated = $request->validate([
            'granted' => 'required|boolean',
        ]);

        $store = Store::findOrFail($id);
        $store->free_access_granted_at = $validated['granted'] ? now() : null;
        $store->save();

        return response()->json([
            'message' => $validated['granted']
                ? 'Accès libre activé pour cet établissement.'
                : 'Accès libre arrêté. L\'établissement doit avoir un abonnement actif pour continuer.',
            'free_access_granted_at' => $store->free_access_granted_at?->toIso8601String(),
        ]);
    }

    /**
     * Solde des abonnements : somme totale des factures payées (abonnements) depuis le début.
     * Réservé au rôle super_admin.
     */
    public function subscriptionBalance(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'super_admin') {
            return response()->json(['message' => 'Accès réservé au super administrateur'], 403);
        }

        $currency = config('cashier.currency', 'eur');
        $currencyUpper = strtoupper($currency);
        $symbol = $currencyUpper === 'EUR' ? '€' : $currencyUpper;

        $secret = config('cashier.secret');
        $totalCents = 0;
        $isEstimate = false;

        if (!empty($secret)) {
            try {
                Stripe::setApiKey($secret);
                $lastId = null;
                do {
                    $params = ['status' => 'paid', 'limit' => 100];
                    if ($lastId) {
                        $params['starting_after'] = $lastId;
                    }
                    $invoices = \Stripe\Invoice::all($params);
                    foreach ($invoices->data as $invoice) {
                        $sub = $invoice->subscription ?? null;
                        if ($sub !== null && $sub !== '') {
                            $totalCents += (int) ($invoice->amount_paid ?? 0);
                        }
                        $lastId = $invoice->id;
                    }
                } while ($invoices->has_more ?? false);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Si Stripe n'a rien renvoyé (0 ou erreur), estimation à partir des abonnements actifs en base
        if ($totalCents === 0) {
            $totalCents = (int) DB::table('subscriptions')
                ->join('subscription_plans', 'subscription_plans.stripe_price_id', '=', 'subscriptions.stripe_price')
                ->whereIn('subscriptions.stripe_status', ['active', 'trialing'])
                ->sum('subscription_plans.amount_cents');
            $isEstimate = $totalCents > 0;
        }

        $totalFormatted = number_format($totalCents / 100, 2, ',', ' ') . ' ' . $symbol;
        return response()->json([
            'total_cents' => $totalCents,
            'total_formatted' => $totalFormatted,
            'currency' => $currency,
            'is_estimate' => $isEstimate,
        ]);
    }

    /**
     * Liste simplifiée des établissements avec email de l'admin pour l'envoi d'emails.
     * Réservé au rôle super_admin.
     */
    public function storesForEmail(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'super_admin') {
            return response()->json(['message' => 'Accès réservé au super administrateur'], 403);
        }

        $stores = Store::with(['users' => function ($q) {
            $q->where('role', 'admin')->select('id', 'name', 'email', 'store_id');
        }])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $result = $stores->map(function (Store $store) {
            $admin = $store->users->first();
            return [
                'id' => $store->id,
                'name' => $store->name,
                'admin_email' => $admin?->email,
                'admin_name' => $admin?->name,
            ];
        });

        return response()->json(['data' => $result->values()]);
    }

    /**
     * Envoi d'un email à un ou tous les établissements.
     * Réservé au rôle super_admin.
     */
    public function sendEmail(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'super_admin') {
            return response()->json(['message' => 'Accès réservé au super administrateur'], 403);
        }

        $validated = $request->validate([
            'recipient_type' => 'required|in:single,all',
            'store_id' => 'nullable|integer|exists:stores,id',
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:10000',
            'notify_all_employees' => 'sometimes|boolean',
        ]);
        $notifyAllEmployees = !empty($validated['notify_all_employees']);

        $sentCount = 0;
        $errors = [];

        if ($validated['recipient_type'] === 'single') {
            if (empty($validated['store_id'])) {
                return response()->json(['message' => 'store_id requis pour un envoi individuel'], 422);
            }

            $store = $notifyAllEmployees
                ? Store::with('users')->find($validated['store_id'])
                : Store::with(['users' => function ($q) {
                    $q->where('role', 'admin');
                }])->find($validated['store_id']);

            if (!$store) {
                return response()->json(['message' => 'Établissement introuvable'], 404);
            }

            if ($notifyAllEmployees) {
                $allUsers = $store->users;
                if ($allUsers->isEmpty()) {
                    return response()->json(['message' => 'Aucun utilisateur dans cet établissement'], 422);
                }
                try {
                    foreach ($allUsers as $recipient) {
                        if (!empty($recipient->email)) {
                            Mail::to($recipient->email)->send(new SuperAdminBroadcast(
                                $validated['subject'],
                                $validated['body'],
                                $store->name,
                                $recipient->name
                            ));
                        }
                        $this->notifyBroadcastReceived($recipient, $validated['subject'], true);
                    }
                    $sentCount = $allUsers->count();
                } catch (\Throwable $e) {
                    report($e);
                    return response()->json(['message' => 'Erreur lors de l\'envoi: ' . $e->getMessage()], 500);
                }
            } else {
                $admin = $store->users->first();
                if (!$admin || !$admin->email) {
                    return response()->json(['message' => 'Aucun admin avec email pour cet établissement'], 422);
                }
                try {
                    Mail::to($admin->email)->send(new SuperAdminBroadcast(
                        $validated['subject'],
                        $validated['body'],
                        $store->name,
                        $admin->name
                    ));
                    $sentCount = 1;
                    $this->notifyBroadcastReceived($admin, $validated['subject'], false);
                } catch (\Throwable $e) {
                    report($e);
                    return response()->json(['message' => 'Erreur lors de l\'envoi de l\'email: ' . $e->getMessage()], 500);
                }
            }
        } else {
            $stores = Store::with('users')->where('is_active', true)->get();
            $notifiedUserIds = [];

            foreach ($stores as $store) {
                $recipients = $notifyAllEmployees
                    ? $store->users
                    : $store->users->where('role', 'admin');

                if ($recipients->isEmpty()) {
                    continue;
                }

                try {
                    foreach ($recipients as $recipient) {
                        if (isset($notifiedUserIds[$recipient->id])) {
                            continue;
                        }
                        $notifiedUserIds[$recipient->id] = true;

                        if (!empty($recipient->email)) {
                            Mail::to($recipient->email)->send(new SuperAdminBroadcast(
                                $validated['subject'],
                                $validated['body'],
                                $store->name,
                                $recipient->name
                            ));
                        }
                        $this->notifyBroadcastReceived($recipient, $validated['subject'], $notifyAllEmployees);
                        $sentCount++;
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $errors[] = $store->name;
                }
            }
        }

        $response = [
            'success' => $sentCount > 0,
            'message' => $sentCount > 0
                ? "Email envoyé à {$sentCount} établissement(s)"
                : 'Aucun email envoyé',
            'sent_count' => $sentCount,
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response);
    }

    /**
     * Notifie un utilisateur qu'il a reçu un message de l'équipe Brole (email broadcast).
     * @param bool $allEmployees si true, canal super_admin_broadcast_all (push pour tous) ; sinon admin uniquement.
     */
    private function notifyBroadcastReceived(User $user, string $subject, bool $allEmployees): void
    {
        $subjectShort = \Illuminate\Support\Str::limit($subject, 40);
        $title = 'Message de l\'équipe Brole';
        $message = "L'équipe Brole vous a envoyé un message : « {$subjectShort} ». Consultez votre email ou l'app Brole.";
        $data = [
            'screen' => 'dashboard',
            'route' => '/tabs/dashboard',
            'tag' => 'brole_broadcast',
        ];
        $channel = $allEmployees ? 'super_admin_broadcast_all' : 'super_admin_broadcast';

        try {
            $this->notificationService->sendNotification(
                $user,
                $channel,
                $title,
                $message,
                $data,
                'all'
            );
        } catch (\Throwable $e) {
            Log::warning('Notification broadcast', [
                'user_id' => $user->id,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }
}


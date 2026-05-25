<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\InternalFeedRead;
use App\Models\InternalMessage;
use App\Models\Leave;
use App\Models\Order;
use App\Models\PayrollReportToken;
use App\Models\Product;
use App\Models\SupportTicket;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BadgeController extends Controller
{
    /**
     * Retourne tous les compteurs de badges en une seule requête.
     * Remplace les 8 appels séparés faits par le frontend toutes les 30 secondes.
     */
    public function getBadges(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $role    = $user->role;
        $storeId = $user->store_id;

        // 1. Alertes non lues (produits périmés, stock faible...)
        $alerts = Alert::where('is_read', false)
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
            ->count();

        // 2. Notifications non lues (in-app)
        $notifications = \App\Models\Notification::where('user_id', $user->id)
            ->where('status', 'sent')
            ->whereNull('read_at')
            ->count();

        // 3. Tâches assignées à moi (pending + in_progress)
        $tasks = Task::where('assigned_to', $user->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->count();

        // 4. Congés — admin/chef/directeur : demandes à approuver
        //           autres : mes réponses non vues
        $leaves          = 0;
        $myDecidedLeaves = 0;
        if ($user->hasSharedPermission('leaves')) {
            $leaves = Leave::where('status', 'pending')
                ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                ->count();
        } else {
            $myDecidedLeaves = Leave::where('user_id', $user->id)
                ->whereIn('status', ['approved', 'rejected'])
                ->whereNull('seen_by_user_at')
                ->count();
        }

        // 5. Rapports de paie confirmés/rejetés non vus (admin/directeur uniquement)
        $payrollReports = 0;
        if (in_array($role, ['admin', 'director'])) {
            $payrollReports = PayrollReportToken::whereIn('status', ['confirmed', 'rejected'])
                ->whereNull('admin_viewed_at')
                ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                ->count();
        }

        // 6. Support tickets non vus
        $supportTickets = 0;
        if ($role === 'super_admin') {
            $supportTickets = SupportTicket::whereNull('super_admin_seen_at')->count();
        } elseif ($role === 'admin' && $storeId) {
            $supportTickets = SupportTicket::where('store_id', $storeId)
                ->whereNotNull('admin_note')
                ->whereRaw('TRIM(admin_note) != ?', [''])
                ->where(function ($q) {
                    $q->whereNull('store_seen_at')
                      ->orWhereColumn('store_seen_at', '<', 'updated_at');
                })
                ->count();
        }

        // 7. Produits périmés
        $expiredProducts = 0;
        if ($storeId) {
            $expiredProducts = Product::where('is_active', true)
                ->where(function ($q) {
                    $q->where('status', 'expired')
                      ->orWhere('expiration_date', '<=', Carbon::today());
                })
                ->whereHas('zone', fn($q) => $q->where('store_id', $storeId))
                ->count();
        }

        // 8. Commandes fournisseurs avec réponse non vue (admin/chef/directeur)
        $orders = 0;
        if ($storeId && in_array($role, ['admin', 'chef', 'director'])) {
            $orders = Order::where('store_id', $storeId)
                ->whereNotNull('supplier_responded_at')
                ->whereNull('supplier_response_seen_at')
                ->whereIn('status', ['confirmed', 'cancelled'])
                ->count();
        }

        // 9. Messages internes non lus
        $internalMessages = 0;
        if ($storeId) {
            try {
                $lastRead = InternalFeedRead::where('user_id', $user->id)
                    ->where('store_id', $storeId)
                    ->first();
                $query = InternalMessage::where('store_id', $storeId)->whereNull('receiver_id');
                $threshold = $lastRead ? ($lastRead->last_opened_at ?? $lastRead->last_read_at) : null;
                if ($threshold) {
                    $query->where('created_at', '>', $threshold);
                }
                $internalMessages = $query->count();
            } catch (\Throwable) {
                $internalMessages = 0;
            }
        }

        return response()->json([
            'alerts'            => $alerts,
            'notifications'     => $notifications,
            'tasks'             => $tasks,
            'leaves'            => $leaves,
            'my_decided_leaves' => $myDecidedLeaves,
            'payroll_reports'   => $payrollReports,
            'support_tickets'   => $supportTickets,
            'expired_products'  => $expiredProducts,
            'orders'            => $orders,
            'internal_messages' => $internalMessages,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SupportTicketController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {}

    /**
     * Créer un ticket de support (côté établissement).
     * Tout utilisateur lié à un store peut créer un ticket.
     * Notifie le(s) super admin par SMS + push.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        // Seul l'admin de l'établissement peut créer un ticket pour contacter le super admin.
        if (!$user || !$user->store_id || $user->role !== 'admin') {
            return response()->json(['message' => 'Seul l\'admin de l\'établissement peut créer un ticket de support'], 403);
        }

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
        ]);

        $ticket = SupportTicket::create([
            'store_id' => $user->store_id,
            'user_id' => $user->id,
            'subject' => $validated['subject'],
            'message' => $validated['message'],
            'status' => 'open',
        ]);

        $ticket->load('store:id,name,establishment_code', 'user:id,name,email');

        $this->notifySuperAdminsNewTicket($ticket, $user);

        return response()->json($ticket, 201);
    }

    /**
     * Liste des tickets.
     * - super_admin : voit tous les tickets (avec filtres optionnels)
     * - autres utilisateurs : ne voient que les tickets de leur établissement.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $query = SupportTicket::with(['store:id,name,establishment_code', 'user:id,name,email']);

        if ($user->role !== 'super_admin') {
            if (!$user->store_id) {
                return response()->json([]);
            }
            $query->where('store_id', $user->store_id);
        } else {
            if ($request->filled('store_id')) {
                $query->where('store_id', $request->input('store_id'));
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $tickets = $query->orderBy('created_at', 'desc')->get();

        return response()->json($tickets);
    }

    /**
     * Nombre de tickets ayant une réponse du super admin non encore "vue" par l'admin (store_seen_at < updated_at ou null).
     * Utilisé pour le badge "Contact" sur le dashboard (admin uniquement).
     */
    public function repliesCount(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->store_id || $user->role !== 'admin') {
            return response()->json(['count' => 0]);
        }
        $count = SupportTicket::where('store_id', $user->store_id)
            ->whereNotNull('admin_note')
            ->whereRaw('TRIM(admin_note) != ?', [''])
            ->where(function ($q) {
                $q->whereNull('store_seen_at')
                    ->orWhereColumn('store_seen_at', '<', 'updated_at');
            })
            ->count();
        return response()->json(['count' => $count]);
    }

    /**
     * Marquer les réponses comme vues par l'admin (appelé à l'entrée sur la page Contact).
     * Remet le compteur badge à 0 une fois l'admin ressorti et le dashboard rechargé.
     */
    public function markRepliesSeen(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->store_id || $user->role !== 'admin') {
            return response()->json(['message' => 'Non autorisé'], 403);
        }
        SupportTicket::where('store_id', $user->store_id)->update(['store_seen_at' => now()]);
        return response()->json(['message' => 'ok']);
    }

    /**
     * Mettre à jour un ticket (super admin).
     * Si admin_note est renseigné, notifie l'auteur du ticket par SMS + push.
     */
    public function update(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'super_admin') {
            return response()->json(['message' => 'Accès réservé au super administrateur'], 403);
        }

        $validated = $request->validate([
            'status' => 'sometimes|in:open,in_progress,resolved,closed',
            'admin_note' => 'nullable|string|max:5000',
        ]);

        $ticket = SupportTicket::findOrFail($id);
        $ticket->fill($validated);
        $ticket->save();

        $newAdminNote = trim((string) ($validated['admin_note'] ?? ''));
        if ($newAdminNote !== '') {
            $this->notifyUserTicketReply($ticket);
        }

        return response()->json($ticket->fresh()->load('store:id,name,establishment_code', 'user:id,name,email'));
    }

    /**
     * Nombre de tickets non lus par le super admin (super_admin_seen_at null).
     */
    public function unreadCount(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'super_admin') {
            return response()->json(['count' => 0]);
        }
        $count = SupportTicket::whereNull('super_admin_seen_at')->count();
        return response()->json(['count' => $count]);
    }

    /**
     * Marquer tous les tickets comme lus par le super admin.
     */
    public function markSeen(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'super_admin') {
            return response()->json(['message' => 'Accès réservé au super administrateur'], 403);
        }
        SupportTicket::whereNull('super_admin_seen_at')->update(['super_admin_seen_at' => now()]);
        return response()->json(['message' => 'ok']);
    }

    /** Notifie tous les super admins qu'un nouvel établissement a envoyé un message. */
    private function notifySuperAdminsNewTicket(SupportTicket $ticket, User $author): void
    {
        $storeName = $ticket->store?->name ?? 'Un établissement';
        $subject = \Illuminate\Support\Str::limit($ticket->subject, 50);
        $title = 'Nouveau message de support';
        $message = "{$storeName} a envoyé un message : « {$subject } ». Consultez l'app Brole dans Messages.";
        $data = [
            'support_ticket_id' => (string) $ticket->id,
            'screen' => 'support-tickets',
            'route' => '/tabs/support-tickets',
        ];

        $superAdmins = User::where('role', 'super_admin')->get();
        foreach ($superAdmins as $superAdmin) {
            try {
                $this->notificationService->sendNotification(
                    $superAdmin,
                    'support_ticket_new',
                    $title,
                    $message,
                    $data,
                    'all'
                );
            } catch (\Throwable $e) {
                Log::error('Erreur notification support (super admin)', [
                    'ticket_id' => $ticket->id,
                    'user_id' => $superAdmin->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** Notifie l'auteur du ticket que l'équipe technique a répondu. Uniquement si l'auteur est admin ou super_admin. */
    private function notifyUserTicketReply(SupportTicket $ticket): void
    {
        $author = User::find($ticket->user_id);
        if (!$author) {
            return;
        }

        // Rôle rechargé depuis la BDD : les employés (cuisinier, serveur, etc.) ne reçoivent aucune notification.
        if (!in_array($author->role, ['admin', 'super_admin'], true)) {
            Log::info('Support: notification de réponse non envoyée (auteur employé)', [
                'ticket_id' => $ticket->id,
                'user_id' => $author->id,
                'email' => $author->email,
                'role' => $author->role,
            ]);
            return;
        }

        $title = 'Réponse de l\'équipe technique';
        $message = "L'équipe technique a répondu à votre demande « " . \Illuminate\Support\Str::limit($ticket->subject, 40) . " ». Consultez l'app Brole dans Contact.";
        $data = [
            'support_ticket_id' => (string) $ticket->id,
            'screen' => 'support-contact',
            'route' => '/tabs/support-contact',
        ];

        try {
            $this->notificationService->sendNotification(
                $author,
                'support_ticket_reply',
                $title,
                $message,
                $data,
                'all'
            );
        } catch (\Throwable $e) {
            Log::error('Erreur notification support (réponse)', [
                'ticket_id' => $ticket->id,
                'user_id' => $author->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}


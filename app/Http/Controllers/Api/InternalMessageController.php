<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternalFeedRead;
use App\Models\InternalMessage;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;

class InternalMessageController extends Controller
{
    /**
     * Retourne le store_id effectif pour la messagerie (établissement).
     */
    private function effectiveStoreIdForUser(User $user): ?int
    {
        $storeId = $user->store_id;
        if ($storeId !== null) {
            return (int) $storeId;
        }
        if (Store::count() === 1) {
            return (int) Store::first()->id;
        }
        return null;
    }

    /**
     * Fil d'actualité de l'établissement : tous les messages visibles par tout le monde (receiver_id null).
     */
    public function feed(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([]);
        }
        $user = User::select('id', 'store_id')->find($user->id);
        $storeId = $this->effectiveStoreIdForUser($user);
        if ($storeId === null) {
            return response()->json([]);
        }

        $lastRead = InternalFeedRead::where('user_id', $user->id)
            ->where('store_id', $storeId)
            ->first();

        $messages = InternalMessage::with('sender:id,name,role')
            ->where('store_id', $storeId)
            ->whereNull('receiver_id')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($messages->map(function (InternalMessage $m) use ($lastRead, $user) {
            $isUnread = $lastRead && $lastRead->last_read_at
                ? $m->created_at > $lastRead->last_read_at
                : true;
            return [
                'id' => $m->id,
                'body' => $m->body,
                'sender_id' => $m->sender_id,
                'receiver_id' => $m->receiver_id,
                'sender' => $m->sender ? [
                    'id' => $m->sender->id,
                    'name' => $m->sender->name,
                    'role' => $m->sender->role,
                ] : null,
                'created_at' => $m->created_at?->toIso8601String(),
                'read_at' => $m->read_at?->toIso8601String(),
                'is_unread' => $isUnread,
            ];
        })->values()->all());
    }

    /**
     * Envoyer un message à tout l'établissement (visible par tous les employés).
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }
        $user = User::select('id', 'store_id')->find($user->id);
        $storeId = $this->effectiveStoreIdForUser($user);
        if ($storeId === null) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $message = InternalMessage::create([
            'store_id' => $storeId,
            'sender_id' => $user->id,
            'receiver_id' => null,
            'body' => $validated['body'],
        ]);
        $message->load('sender:id,name,role');

        return response()->json([
            'id' => $message->id,
            'body' => $message->body,
            'sender_id' => $message->sender_id,
            'receiver_id' => null,
            'sender' => [
                'id' => $message->sender->id,
                'name' => $message->sender->name,
                'role' => $message->sender->role,
            ],
            'created_at' => $message->created_at?->toIso8601String(),
            'read_at' => null,
        ], 201);
    }

    /**
     * Nombre de messages du fil non lus par l'utilisateur (créés après sa dernière lecture).
     */
    public function unreadCount(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['count' => 0]);
        }
        $user = User::select('id', 'store_id')->find($user->id);
        $storeId = $this->effectiveStoreIdForUser($user);
        if ($storeId === null) {
            return response()->json(['count' => 0]);
        }

        try {
            $lastRead = InternalFeedRead::where('user_id', $user->id)
                ->where('store_id', $storeId)
                ->first();

            $query = InternalMessage::where('store_id', $storeId)
                ->whereNull('receiver_id');
            if ($lastRead && $lastRead->last_read_at) {
                $query->where('created_at', '>', $lastRead->last_read_at);
            }
            $count = $query->count();
        } catch (\Throwable $e) {
            // Table internal_feed_reads absente ou erreur : on renvoie 0 pour que le badge puisse se réinitialiser
            $count = 0;
        }

        return response()->json(['count' => $count]);
    }

    /**
     * Marquer le fil comme lu (appelé quand l'utilisateur ouvre la page messages).
     */
    public function markAsRead(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }
        $user = User::select('id', 'store_id')->find($user->id);
        $storeId = $this->effectiveStoreIdForUser($user);
        if ($storeId === null) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        try {
            InternalFeedRead::updateOrCreate(
                ['user_id' => $user->id, 'store_id' => $storeId],
                ['last_read_at' => now()]
            );
        } catch (\Throwable $e) {
            // Table internal_feed_reads absente si migration non exécutée : on répond quand même succès
        }

        return response()->json(['count' => 0]);
    }
}

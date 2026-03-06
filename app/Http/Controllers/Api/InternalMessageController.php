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

        $messages = InternalMessage::with(['sender:id,name,role', 'parent.sender:id,name'])
            ->where('store_id', $storeId)
            ->whereNull('receiver_id')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($messages->map(function (InternalMessage $m) use ($lastRead, $user) {
            $isUnread = $lastRead && $lastRead->last_read_at
                ? $m->created_at > $lastRead->last_read_at
                : true;
            $replyTo = null;
            if ($m->parent_id && $m->relationLoaded('parent') && $m->parent) {
                $replyTo = [
                    'id' => $m->parent->id,
                    'body' => \Illuminate\Support\Str::limit($m->parent->body, 120),
                    'sender_name' => $m->parent->sender ? $m->parent->sender->name : null,
                ];
            }
            return [
                'id' => $m->id,
                'body' => $m->body,
                'sender_id' => $m->sender_id,
                'receiver_id' => $m->receiver_id,
                'parent_id' => $m->parent_id,
                'reply_to' => $replyTo,
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
            'parent_id' => 'nullable|integer|exists:internal_messages,id',
        ]);

        $parentId = isset($validated['parent_id']) ? (int) $validated['parent_id'] : null;
        if ($parentId) {
            $parent = InternalMessage::where('id', $parentId)->where('store_id', $storeId)->whereNull('receiver_id')->first();
            if (!$parent) {
                $parentId = null;
            }
        }

        $message = InternalMessage::create([
            'store_id' => $storeId,
            'sender_id' => $user->id,
            'receiver_id' => null,
            'parent_id' => $parentId,
            'body' => $validated['body'],
        ]);
        $message->load(['sender:id,name,role', 'parent.sender:id,name']);

        $replyTo = null;
        if ($message->parent_id && $message->parent) {
            $replyTo = [
                'id' => $message->parent->id,
                'body' => \Illuminate\Support\Str::limit($message->parent->body, 120),
                'sender_name' => $message->parent->sender ? $message->parent->sender->name : null,
            ];
        }

        return response()->json([
            'id' => $message->id,
            'body' => $message->body,
            'sender_id' => $message->sender_id,
            'receiver_id' => null,
            'parent_id' => $message->parent_id,
            'reply_to' => $replyTo,
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
            $badgeThreshold = $lastRead ? ($lastRead->last_opened_at ?? $lastRead->last_read_at) : null;
            if ($badgeThreshold) {
                $query->where('created_at', '>', $badgeThreshold);
            }
            $count = $query->count();
        } catch (\Throwable $e) {
            // Table internal_feed_reads absente ou erreur : on renvoie 0 pour que le badge puisse se réinitialiser
            $count = 0;
        }

        return response()->json(['count' => $count]);
    }

    /**
     * Marquer comme "page ouverte" : le badge sur le bouton Messages passe à 0 (sans toucher au séparateur dans le fil).
     */
    public function markAsOpened(Request $request)
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
            $row = InternalFeedRead::firstOrNew(
                ['user_id' => $user->id, 'store_id' => $storeId],
                ['last_read_at' => now()->subYear()]
            );
            $row->last_opened_at = now();
            $row->save();
        } catch (\Throwable $e) {
            // Table internal_feed_reads absente si migration non exécutée
        }

        return response()->json(['count' => 0]);
    }

    /**
     * Marquer le fil comme lu (appelé quand l'utilisateur envoie un message → le séparateur "non lu" disparaît).
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
                ['last_read_at' => now(), 'last_opened_at' => now()]
            );
        } catch (\Throwable $e) {
            // Table internal_feed_reads absente si migration non exécutée : on répond quand même succès
        }

        return response()->json(['count' => 0]);
    }

    /**
     * Modifier un message (uniquement l'expéditeur).
     */
    public function update(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $message = InternalMessage::whereNull('receiver_id')->find($id);
        if (!$message || $message->sender_id != $user->id) {
            return response()->json(['message' => 'Message introuvable ou non autorisé'], 404);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $message->update(['body' => $validated['body']]);
        $message->load('sender:id,name,role');

        return response()->json([
            'id' => $message->id,
            'body' => $message->body,
            'sender_id' => $message->sender_id,
            'receiver_id' => $message->receiver_id,
            'sender' => $message->sender ? [
                'id' => $message->sender->id,
                'name' => $message->sender->name,
                'role' => $message->sender->role,
            ] : null,
            'created_at' => $message->created_at?->toIso8601String(),
            'read_at' => $message->read_at?->toIso8601String(),
        ]);
    }

    /**
     * Supprimer un message (uniquement l'expéditeur).
     */
    public function destroy(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $message = InternalMessage::whereNull('receiver_id')->find($id);
        if (!$message || $message->sender_id != $user->id) {
            return response()->json(['message' => 'Message introuvable ou non autorisé'], 404);
        }

        $message->delete();
        return response()->json(null, 204);
    }
}

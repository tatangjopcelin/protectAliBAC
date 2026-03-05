<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternalMessage;
use App\Models\User;
use Illuminate\Http\Request;

class InternalMessageController extends Controller
{
    /**
     * Liste des destinataires possibles pour la messagerie (collègues du même établissement).
     * Accessible à tout utilisateur ayant un store_id.
     */
    public function recipients(Request $request)
    {
        $authUser = $request->user();
        if (!$authUser) {
            return response()->json([]);
        }
        // Charger l'utilisateur depuis la BDD pour avoir store_id à jour
        $user = User::select('id', 'store_id')->find($authUser->id);
        if (!$user || $user->store_id === null) {
            return response()->json([]);
        }

        $users = User::where('store_id', $user->store_id)
            ->where('id', '!=', $user->id)
            ->select('id', 'name', 'role')
            ->orderBy('name')
            ->get();

        return response()->json($users->map(fn ($u) => [
            'id' => (int) $u->id,
            'name' => $u->name ?? '',
            'role' => $u->role ?? '',
        ])->values()->all());
    }

    /**
     * Liste des fils de discussion de l'utilisateur courant (un fil par autre utilisateur).
     */
    public function threads(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->store_id) {
            return response()->json([]);
        }

        $messages = InternalMessage::with(['sender:id,name,role', 'receiver:id,name,role'])
            ->where('store_id', $user->store_id)
            ->where(function ($q) use ($user) {
                $q->where('sender_id', $user->id)
                  ->orWhere('receiver_id', $user->id);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        $threads = [];

        foreach ($messages as $message) {
            $otherUser = $message->sender_id === $user->id ? $message->receiver : $message->sender;
            if (!$otherUser) {
                continue;
            }
            $key = $otherUser->id;
            if (!isset($threads[$key])) {
                $threads[$key] = [
                    'user' => [
                        'id' => $otherUser->id,
                        'name' => $otherUser->name,
                        'role' => $otherUser->role,
                    ],
                    'last_message' => [
                        'id' => $message->id,
                        'body' => $message->body,
                        'created_at' => $message->created_at?->toIso8601String(),
                        'sender_id' => $message->sender_id,
                    ],
                    'unread_count' => 0,
                ];
            }

            // Compter les messages non lus dans ce fil
            if ($message->receiver_id === $user->id && $message->read_at === null) {
                $threads[$key]['unread_count']++;
            }
        }

        // Retourner la liste triée par date du dernier message
        $result = collect($threads)
            ->sortByDesc(fn ($t) => $t['last_message']['created_at'] ?? null)
            ->values()
            ->all();

        return response()->json($result);
    }

    /**
     * Conversation complète avec un autre utilisateur (même établissement).
     * Marque les messages reçus comme lus.
     */
    public function conversationWith(Request $request, int $otherUserId)
    {
        $user = $request->user();
        if (!$user || !$user->store_id) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $other = User::where('id', $otherUserId)
            ->where('store_id', $user->store_id)
            ->firstOrFail();

        $messages = InternalMessage::with(['sender:id,name', 'receiver:id,name'])
            ->where('store_id', $user->store_id)
            ->where(function ($q) use ($user, $other) {
                $q->where(function ($q2) use ($user, $other) {
                    $q2->where('sender_id', $user->id)->where('receiver_id', $other->id);
                })->orWhere(function ($q2) use ($user, $other) {
                    $q2->where('sender_id', $other->id)->where('receiver_id', $user->id);
                });
            })
            ->orderBy('created_at')
            ->get();

        // Marquer comme lus les messages reçus
        InternalMessage::where('store_id', $user->store_id)
            ->where('sender_id', $other->id)
            ->where('receiver_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'user' => [
                'id' => $other->id,
                'name' => $other->name,
                'role' => $other->role,
            ],
            'messages' => $messages->map(function (InternalMessage $m) {
                return [
                    'id' => $m->id,
                    'body' => $m->body,
                    'sender_id' => $m->sender_id,
                    'receiver_id' => $m->receiver_id,
                    'created_at' => $m->created_at?->toIso8601String(),
                    'read_at' => $m->read_at?->toIso8601String(),
                ];
            })->all(),
        ]);
    }

    /**
     * Envoyer un message à un autre utilisateur du même établissement.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->store_id) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $validated = $request->validate([
            'receiver_id' => 'required|integer|exists:users,id',
            'body' => 'required|string|max:5000',
        ]);

        $receiver = User::where('id', $validated['receiver_id'])
            ->where('store_id', $user->store_id)
            ->first();

        if (!$receiver) {
            return response()->json(['message' => 'L’utilisateur doit appartenir au même établissement'], 422);
        }

        $message = InternalMessage::create([
            'store_id' => $user->store_id,
            'sender_id' => $user->id,
            'receiver_id' => $receiver->id,
            'body' => $validated['body'],
        ]);

        return response()->json($message, 201);
    }
}


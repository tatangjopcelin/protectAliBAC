<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $query = Notification::where('user_id', $request->user()?->id);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        return response()->json($query->orderBy('created_at', 'desc')->get());
    }

    public function unread(Request $request)
    {
        $notifications = Notification::where('user_id', $request->user()?->id)
            ->where('status', 'sent')
            ->whereNull('read_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($notifications);
    }

    public function markAsRead(Request $request, string $id)
    {
        $notification = Notification::where('user_id', $request->user()?->id)
            ->findOrFail($id);

        $notification->update([
            'read_at' => now(),
        ]);

        return response()->json($notification);
    }

    public function markAllAsRead(Request $request)
    {
        Notification::where('user_id', $request->user()?->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Toutes les notifications ont été marquées comme lues']);
    }
}

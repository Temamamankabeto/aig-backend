<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $kind = $request->filled('kind') ? (string) $request->string('kind') : null;

        $query = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->when($request->boolean('unread_only'), fn ($q) => $q->whereNull('read_at'))
            ->when($kind, fn ($q) => $q->where('data', 'like', '%"kind":"'.$kind.'"%'))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('date_to')))
            ->orderByDesc('created_at');

        $rows = $query->paginate((int) $request->get('per_page', 20));

        $rows->getCollection()->transform(function ($row) {
            $row->data = json_decode($row->data ?? '{}', true) ?? [];
            return $row;
        });

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    public function unreadCount(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $kind = $request->filled('kind') ? (string) $request->string('kind') : null;

        $count = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->whereNull('read_at')
            ->when($kind, fn ($q) => $q->where('data', 'like', '%"kind":"'.$kind.'"%'))
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => $count,
            ],
        ]);
    }

    public function markRead(Request $request, string $id)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $notification = DB::table('notifications')
            ->where('id', $id)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.',
            ], 404);
        }

        if ($notification->read_at) {
            return response()->json([
                'success' => true,
                'message' => 'Notification already marked as read.',
            ]);
        }

        DB::table('notifications')
            ->where('id', $id)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
        ]);
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $kind = $request->filled('kind') ? (string) $request->string('kind') : null;

        $updated = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->whereNull('read_at')
            ->when($kind, fn ($q) => $q->where('data', 'like', '%"kind":"'.$kind.'"%'))
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => $updated > 0
                ? 'All notifications marked as read.'
                : 'No unread notifications found.',
            'data' => [
                'updated_count' => $updated,
            ],
        ]);
    }
}
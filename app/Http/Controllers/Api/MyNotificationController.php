<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyNotificationController extends Controller
{
    /** The signed-in customer's latest notifications with the unread count. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $user->notifications()->limit(30)->get()->map(fn ($notification): array => [
                'id' => $notification->id,
                'order_id' => $notification->data['order_id'] ?? null,
                'status' => $notification->data['status'] ?? null,
                'title' => $notification->data['title'] ?? '',
                'message' => $notification->data['message'] ?? null,
                'is_read' => $notification->read_at !== null,
                'created_at' => $notification->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['unread_count' => $user->unreadNotifications()->count()],
        ]);
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return response()->json(['success' => true]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }
}

<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller {
    public function index(Request $request): JsonResponse {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->latest()->paginate(20);
        return response()->json($notifications);
    }
    public function markRead(Request $request, string $id): JsonResponse {
        Notification::where('user_id', $request->user()->id)->where('id', $id)
            ->update(['read_at' => now()]);
        return response()->json(['message' => 'Marked as read']);
    }
    public function markAllRead(Request $request): JsonResponse {
        Notification::where('user_id', $request->user()->id)->whereNull('read_at')
            ->update(['read_at' => now()]);
        return response()->json(['message' => 'All notifications marked as read']);
    }
}

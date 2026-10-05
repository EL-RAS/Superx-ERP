<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Per-user notification inbox for the header dropdown.
 *
 * Every query is scoped to `$request->user()` — there is no notion of a shared
 * notification feed, and a notification id belonging to somebody else simply
 * resolves to 404.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->business_id) {
            // Re-evaluate conditions that may have drifted while the panel was
            // closed (a batch creeping into its warning window, a min_stock
            // edit) so alerts do not depend on a cron job that may not exist.
            $this->notifications->sweep((string) $user->business_id);
        }

        $perPage = max(1, min(50, $request->integer('per_page', 15)));

        $page = $user->notifications()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (DatabaseNotification $n) => $this->shape($n))->values(),
            'unread_count' => $user->unreadNotifications()->count(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Lightweight badge poll — deliberately does NOT sweep. */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->resolve($request, $id);

        $notification->markAsRead();

        return response()->json([
            'notification' => $this->shape($notification),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }

    public function clear(Request $request): JsonResponse
    {
        $request->user()->notifications()->delete();

        return response()->json(['unread_count' => 0]);
    }

    private function resolve(Request $request, string $id): DatabaseNotification
    {
        // Notification ids are uuids; short-circuit anything else so Postgres
        // never sees a bad cast on the primary key (that would 500, not 404).
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            abort(404, 'Notification not found.');
        }

        $notification = $request->user()->notifications()->where('id', $id)->first();

        abort_if(! $notification, 404, 'Notification not found.');

        return $notification;
    }

    /** @return array<string, mixed> */
    private function shape(DatabaseNotification $notification): array
    {
        $data = $notification->data ?? [];

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'tag' => $data['tag'] ?? null,
            'severity' => $data['severity'] ?? 'info',
            'title' => $data['title'] ?? [],
            'message' => $data['message'] ?? [],
            'action_url' => $data['action_url'] ?? null,
            'read' => $notification->read_at !== null,
            'read_at' => $notification->read_at,
            'created_at' => $notification->created_at,
        ];
    }
}

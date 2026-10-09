<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notificaciones en la app del usuario autenticado (O1).
 *
 * Todo se resuelve por `$request->user()->notifications()`: no hay id de otro
 * usuario que se pueda pedir. Un id ajeno no se encuentra y da 404, igual que
 * uno inexistente, para no confirmar que existe.
 */
class NotificationController extends Controller
{
    /**
     * GET /api/notifications?unread=1
     *
     * Más recientes primero. `meta.unread_count` es el número para la campanita,
     * independiente del filtro y de la página.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $user->notifications();

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $pagina = $this->paginar($query, $request);
        $pagina->getCollection()->transform(fn ($n) => [
            'id'         => $n->id,
            'type'       => $n->type,
            'data'       => $n->data,
            'read_at'    => $n->read_at,
            'created_at' => $n->created_at,
        ]);

        return response()->json([
            'data' => $pagina,
            'meta' => [
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    /** PATCH /api/notifications/{id}/read — idempotente. */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notificacion = $request->user()->notifications()->whereKey($id)->first();

        if (!$notificacion) {
            return response()->json(['message' => 'Notificación no encontrada'], 404);
        }

        $notificacion->markAsRead();

        return response()->json(['message' => 'Notificación marcada como leída']);
    }

    /** POST /api/notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        $marcadas = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Notificaciones marcadas como leídas',
            'data'    => ['marked' => $marcadas],
        ]);
    }
}

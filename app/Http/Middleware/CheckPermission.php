<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (!$user) {
            abort(403, 'Usuário não autenticado.');
        }
        [$module, $action] = array_pad(explode('.', $permission, 2), 2, null);
        if (!$module || !$action) {
            abort(403, 'Permissão inválida.');
        }
        if (!$user->hasPermission($module, $action)) {
            abort(403, 'Você não possui permissão para realizar esta ação.');
        }
        return $next($request);
    }
}


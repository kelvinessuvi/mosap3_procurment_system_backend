<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMenuPermission
{
    public function handle(Request $request, Closure $next, string $menuSlug, string $permissionType = 'read'): Response
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            return $next($request);
        }

        if (!$user->canAccessMenu($menuSlug, $permissionType)) {
            abort(403, 'Acesso negado a este menu.');
        }

        return $next($request);
    }
}

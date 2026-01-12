<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if (! $request->user() || $request->user()->role !== $role) {
            // Allow admin to access everything? Maybe, but strictly following role for now.
            // If stricter hierarchy is needed: if ($role === 'technician' && $user->role === 'admin') return $next($request);
            
            // For now, exact match or if admin tries to access technician routes?
            // Usually Admin can do everything. Let's make Admin a superset if implied, but specific requires careful logic.
            // Requirement: "Gerenciamento de usuários (apenas Admin)". "Gestão de Fornecedores... (Admin e Técnico)".
            // So if route requires 'procurement_technician', Admin should probably also pass?
            // Let's implement: if user is admin, they pass. If user is role, they pass.
            
            if ($request->user()->role === 'admin') {
                return $next($request);
            }
            
            if ($request->user()->role !== $role) {
                abort(403, 'Unauthorized action.');
            }
        }

        return $next($request);
    }
}

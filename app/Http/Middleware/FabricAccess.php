<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FabricAccess
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if ($user === null) {
            abort(403);
        }
        $role = $user->is_owner ? 'owner' : (string) ($user->fabric_role ?? '');
        $allowed = $roles ?: ['owner', 'manager', 'cashier'];
        if (! in_array($role, $allowed, true)) {
            abort(403);
        }
        return $next($request);
    }
}

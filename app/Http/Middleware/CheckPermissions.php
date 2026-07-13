<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPermissions
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$permissions  // The permission names required for this route
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ...$permissions)
    {
        $user = $request->user();

        // If no user is authenticated, return 401
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        // If no permissions are specified, allow access (fallback)
        if (empty($permissions)) {
            return $next($request);
        }

        // Check if the user has ANY of the required permissions
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                // User has at least one required permission – allow access
                return $next($request);
            }
        }

        // User has none of the required permissions – deny access
        return response()->json([
            'message' => 'Unauthorized. You do not have the required permissions to access this resource.'
        ], 403);
    }
}
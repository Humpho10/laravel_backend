<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ManagerMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() || !$request->user()->hasRole('Admin')) {
            return response()->json([
                'message' => 'Access denied. Admin only.'
            ], 403);
        }

        return $next($request);
    }
}
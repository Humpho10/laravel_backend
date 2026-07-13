<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() || !$request->user()->hasRole('Super-Admin')) {
            return response()->json([
                'message' => 'Access denied. Super Admin only.'
            ], 403);
        }

        return $next($request);
    }
}
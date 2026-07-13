<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ProductManagerMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() || !$request->user()->hasRole('Product-Manager')) {
            return response()->json([
                'message' => 'Access denied. Product Managers only.'
            ], 403);
        }

        return $next($request);
    }
}
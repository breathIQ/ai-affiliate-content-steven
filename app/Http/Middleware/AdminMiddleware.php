<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Config;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
    
        // First, check if the user is authenticated
        if (!$request->user()) {
            return response()->json(['error' => 'Unauthorized. Token is missing or invalid.'], 401);
        }
        
        if (!$user || $user->role_id != Config::get('constant.role.Admin')) {
            return response()->json(['error' => 'Unauthorized. Admins only.'], 401); 
        }

        return $next($request);
    }
}

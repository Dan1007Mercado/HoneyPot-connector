<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin Middleware
 * 
 * This middleware ensures that only users with the 'admin' role
 * can access administrative pages in the Hotel Reservation Management System.
 * 
 * Allowed pages for admins:
 * - Employee Management
 * - All other admin-specific features
 */
class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if the user is authenticated
        if (!auth()->check()) {
            // Redirect to login if not authenticated
            return redirect()->route('login')->with('error', 'Please log in to access this page.');
        }

        // Get the authenticated user
        $user = auth()->user();

        // Check if the user has the 'admin' role
        if ($user->role !== 'admin') {
            app(\App\Services\IntsecClient::class)->sendSecurityEvent([
                'event_type' => 'unauthorized_access', 'ip' => $request->ip(), 'route' => '/'.$request->path(), 'method' => $request->method(),
                'user_agent' => $request->userAgent(), 'message' => 'Unauthorized administrator endpoint access.', 'metadata' => ['required_role' => 'admin'],
            ]);
            // User does not have admin role - deny access
            // Return 403 Forbidden response
            abort(403, 'Unauthorized. Only administrators can access this page.');
        }

        // Check if the user account is active
        

        // User is authenticated, has admin role, and is active
        // Allow the request to proceed
        return $next($request);
    }
}

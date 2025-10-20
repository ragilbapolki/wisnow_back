<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class HandleCorsManually
{
    public function handle(Request $request, Closure $next): Response
    {
        Log::info('=== CORS MIDDLEWARE RUNNING ===');
        Log::info('Method: ' . $request->getMethod());
        Log::info('Origin: ' . $request->headers->get('Origin'));
        Log::info('Path: ' . $request->path());

        $origin = $request->headers->get('Origin');

        // Daftar origin yang diizinkan
        $allowedOrigins = [
            'http://localhost:5173',
            'http://localhost:3000',
            'http://127.0.0.1:5173',
            'http://127.0.0.1:3000',
        ];

        // Tentukan origin yang akan digunakan
        $allowOrigin = in_array($origin, $allowedOrigins) ? $origin : 'http://localhost:5173';

        Log::info('Allow Origin: ' . $allowOrigin);

        // Handle preflight OPTIONS request
        if ($request->getMethod() === "OPTIONS") {
            Log::info('Handling OPTIONS request');

            return response('', 200)
                ->header('Access-Control-Allow-Origin', $allowOrigin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, X-XSRF-TOKEN, X-CSRF-TOKEN')
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Max-Age', '86400');
        }

        // Process request
        $response = $next($request);

        Log::info('Adding CORS headers to response');

        // Add CORS headers to response
        $response->headers->set('Access-Control-Allow-Origin', $allowOrigin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, X-XSRF-TOKEN, X-CSRF-TOKEN');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');

        return $response;
    }
}

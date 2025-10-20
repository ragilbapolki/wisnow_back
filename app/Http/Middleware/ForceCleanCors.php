<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceCleanCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedOrigin = 'http://localhost:5173';

        // Handle preflight
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 200);

            // Hapus semua header yang mungkin ada
            foreach ($response->headers->all() as $key => $value) {
                if (stripos($key, 'access-control') !== false) {
                    $response->headers->remove($key);
                }
            }

            // Set header baru
            $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Max-Age', '86400');

            return $response;
        }

        $response = $next($request);

        // AGGRESSIVE: Hapus SEMUA header Access-Control-*
        $headersToRemove = [];
        foreach ($response->headers->all() as $key => $value) {
            if (stripos($key, 'access-control') !== false) {
                $headersToRemove[] = $key;
            }
        }

        foreach ($headersToRemove as $header) {
            $response->headers->remove($header);
        }

        // Set header CORS yang benar
        $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');

        return $response;
    }
}

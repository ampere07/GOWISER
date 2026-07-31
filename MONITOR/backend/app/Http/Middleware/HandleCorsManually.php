<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class HandleCorsManually
{
    public function handle(Request $request, Closure $next)
    {
        $origin = $request->header('Origin');

        // Driven by CORS_ALLOWED_ORIGINS in .env so dev (localhost:3000) and
        // production (monitor.gowiser.ph) do not need separate code paths.
        $allowedOrigins = array_values(array_filter(array_map(
            'trim',
            explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000'))
        )));

        $resolvedOrigin = ($origin && in_array($origin, $allowedOrigins, true))
            ? $origin
            : ($allowedOrigins[0] ?? 'http://localhost:3000');

        $headers = [
            'Access-Control-Allow-Origin' => $resolvedOrigin,
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-XSRF-TOKEN',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Max-Age' => '86400',
        ];

        if ($request->getMethod() === 'OPTIONS') {
            return response('', 200, $headers);
        }

        $response = $next($request);

        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }
}

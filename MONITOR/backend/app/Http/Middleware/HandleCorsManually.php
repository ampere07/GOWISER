<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Throwable;

/**
 * Attaches the CORS headers to every response, including error responses.
 *
 * Note this middleware, not config/cors.php, is what actually runs — it is
 * registered in the global stack in Kernel.php while Laravel's own HandleCors is
 * not. Edit the headers here.
 *
 * The exception handling below is the important part. Laravel renders an
 * unhandled exception in Kernel::handle(), which sits *outside* the global
 * middleware pipeline — so the code after $next() never runs and a 500 goes back
 * with no CORS headers at all. The browser then reports
 *
 *     "No 'Access-Control-Allow-Origin' header is present"
 *
 * which is true but useless: it hides the real status and body, and sends whoever
 * is debugging after a CORS misconfiguration that does not exist. Rendering the
 * exception here instead means the browser sees the actual 500 and its message.
 */
class HandleCorsManually
{
    public function handle(Request $request, Closure $next)
    {
        $origin = $request->header('Origin');

        // Driven by CORS_ALLOWED_ORIGINS in .env so dev (localhost:3000) and
        // production (exec.gowiser.ph) do not need separate code paths.
        $allowedOrigins = array_values(array_filter(array_map(
            'trim',
            explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000'))
        )));

        $resolvedOrigin = ($origin && in_array($origin, $allowedOrigins, true))
            ? $origin
            : ($allowedOrigins[0] ?? 'http://localhost:3000');

        $headers = [
            'Access-Control-Allow-Origin' => $resolvedOrigin,
            // PUT and DELETE are for the Databases configuration page, the only
            // part of the app that writes. Omit them and the browser's preflight
            // blocks an edit or a removal with a bare CORS error that names
            // nothing about the cause.
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-XSRF-TOKEN',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Max-Age' => '86400',
        ];

        if ($request->getMethod() === 'OPTIONS') {
            return response('', 200, $headers);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // Report and render exactly as Kernel::handle() would, then fall
            // through so the headers below are attached. Returning a response
            // rather than rethrowing means the kernel never sees the exception,
            // so it is not reported twice.
            $handler = app(ExceptionHandler::class);
            $handler->report($e);
            $response = $handler->render($request, $e);
        }

        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }
}

<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;
use Symfony\Component\HttpFoundation\Cookie;

class VerifyCsrfToken extends Middleware
{
    /**
     * URIs excluded from CSRF verification.
     *
     * Nothing is excluded. This app authenticates with a cookie session, and
     * the frontend already fetches /sanctum/csrf-cookie and returns the token
     * as X-XSRF-TOKEN — so there is no reason to exempt api/* the way GOWISER
     * does.
     *
     * @var array<int, string>
     */
    protected $except = [
        //
    ];

    /**
     * Issues the CSRF cookie under a configurable name.
     *
     * Laravel hardcodes 'XSRF-TOKEN'. That is fine for one app on a host, but
     * MONITOR and GOWISER both live on gowiser.ph subdomains and MONITOR's
     * session cookie is scoped to the parent domain — which is what makes
     * exec.gowiser.ph → backend3.gowiser.ph a *same-site* request instead of a
     * cross-site one, and so keeps working as browsers phase out third-party
     * cookies.
     *
     * A parent-domain cookie is visible to both apps, so two apps issuing
     * 'XSRF-TOKEN' would overwrite each other and logging into one would break
     * the other. The session cookie is already namespaced via SESSION_COOKIE;
     * this does the same for the CSRF cookie.
     *
     * The *header* stays X-XSRF-TOKEN, because that is the name Laravel reads
     * and decrypts on the way back in. Only the cookie is renamed.
     */
    protected function newCookie($request, $config): Cookie
    {
        return new Cookie(
            config('session.xsrf_cookie', 'XSRF-TOKEN'),
            $request->session()->token(),
            $this->availableAt(60 * $config['lifetime']),
            $config['path'],
            $config['domain'],
            $config['secure'],
            // Readable by JavaScript: the SPA has to send it back as a header.
            false,
            false,
            $config['same_site'] ?? null
        );
    }
}

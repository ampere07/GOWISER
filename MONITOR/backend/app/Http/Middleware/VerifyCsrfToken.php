<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * URIs excluded from CSRF verification.
     *
     * Nothing is excluded. This app authenticates with a cookie session, and
     * the frontend already fetches /sanctum/csrf-cookie and returns the token
     * as X-XSRF-TOKEN — so there is no reason to exempt api/* the way GOWISER
     * does. Only /api/login and /api/logout are POSTs anyway.
     *
     * @var array<int, string>
     */
    protected $except = [
        //
    ];
}

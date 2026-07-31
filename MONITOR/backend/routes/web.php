<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The portal is a separate React app; this backend serves JSON only. The one
| web route that matters is Sanctum's CSRF cookie, registered by the package.
|
*/

Route::get('/', function () {
    return response()->json([
        'status' => 'success',
        'message' => config('app.name') . ' API',
    ]);
});

// Named so Authenticate::redirectTo() has a route to point at.
Route::get('/login', function () {
    return response()->json([
        'status' => 'error',
        'message' => 'Unauthenticated.',
    ], 401);
})->name('login');

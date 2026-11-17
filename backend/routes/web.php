<?php

use Illuminate\Support\Facades\Route;

// The built SPA is a static file, so nginx normally answers from disk. A hard refresh on a
// client-side route such as /gate has no file behind it and lands here. public/index.html
// only exists in the deploy image, so a local checkout keeps the framework welcome page.
$shell = fn () => is_file(public_path('index.html'))
    ? response()->file(public_path('index.html'), ['Cache-Control' => 'no-cache'])
    : view('welcome');

Route::get('/', $shell);

// Guarded rather than a bare ".*": web routes register ahead of api ones, so an
// unrestricted catch-all would swallow GET /api/v1/... before the API ever sees it.
// No SPA route begins with these prefixes, so a plain prefix guard is enough.
Route::get('{any}', $shell)->where('any', '^(?!api|up|sanctum|storage).*$');

<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

Route::get('sitemap.xml', [SpaController::class, 'sitemap']);
Route::get('robots.txt', [SpaController::class, 'robots']);

// Toutes les routes non-API servent le SPA avec métadonnées SEO pré-injectées.
Route::get('{path?}', SpaController::class)
    ->where('path', '^(?!api/|sanctum/|storage/|up$|build/|assets/).*$');

<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

Route::get('sitemap.xml', [SpaController::class, 'sitemap']);
Route::get('robots.txt', [SpaController::class, 'robots']);
// Supervision externe (audit E9) : 200 si la boutique fonctionne, 503 sinon.
Route::get('health', HealthController::class)->middleware('throttle:60,1');

// Toutes les routes non-API servent le SPA avec métadonnées SEO pré-injectées.
Route::get('{path?}', SpaController::class)
    ->where('path', '^(?!api/|sanctum/|storage/|up$|health$|build/|assets/).*$');

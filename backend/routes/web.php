<?php

use App\Http\Controllers\Api\DocsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/api/docs');
Route::get('/api/docs', [DocsController::class, 'ui']);
Route::get('/api/openapi.json', [DocsController::class, 'json']);

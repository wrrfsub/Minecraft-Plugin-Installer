<?php

use Illuminate\Support\Facades\Route;
use PluginInstaller\Http\Controllers\PluginController;

Route::get('/context', [PluginController::class, 'context']);
Route::get('/search', [PluginController::class, 'search']);
Route::get('/versions', [PluginController::class, 'versions']);
Route::post('/install', [PluginController::class, 'install']);
Route::get('/installed', [PluginController::class, 'installed']);
Route::post('/delete', [PluginController::class, 'delete']);

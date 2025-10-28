<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BetController;
use App\Http\Controllers\SstatsController;
use App\Http\Controllers\StatsController;

Route::get('/', [BetController::class, 'index'])->name('home');

// Страница документации о данных событий
Route::view('/docs', 'docs')->name('docs');
Route::post('/bets', [BetController::class, 'store'])->name('bets.store');
Route::post('/events/{event}/settle', [BetController::class, 'settle'])->name('events.settle');
Route::get('/events/sync-results', [BetController::class, 'syncResults'])->name('events.sync');
// Debug page removed per request

// sstats.net explorer
Route::get('/sstats', [SstatsController::class, 'index'])->name('sstats.index');
// Statistics page
Route::get('/stats', [StatsController::class, 'index'])->name('stats.index');

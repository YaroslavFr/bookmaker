<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BetController;
use App\Http\Controllers\SstatsController;

Route::get('/', [BetController::class, 'index'])->name('home');
Route::post('/bets', [BetController::class, 'store'])->name('bets.store');
Route::post('/events/{event}/settle', [BetController::class, 'settle'])->name('events.settle');
Route::get('/events/sync-results', [BetController::class, 'syncResults'])->name('events.sync');
Route::get('/events/debug-results', [BetController::class, 'debugResults'])->name('events.debug');

// sstats.net explorer
Route::get('/sstats', [SstatsController::class, 'index'])->name('sstats.index');

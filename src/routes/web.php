<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BetController;

Route::get('/', [BetController::class, 'index'])->name('home');
Route::post('/bets', [BetController::class, 'store'])->name('bets.store');
Route::post('/events/{event}/settle', [BetController::class, 'settle'])->name('events.settle');

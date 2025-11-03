<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BetController;
use App\Http\Controllers\SstatsController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;

Route::get('/', [BetController::class, 'index'])->name('home');

// Страница документации: авторизация по переменной окружения DOCS_AUTH_MODE
// test — публично; prod — только админ (AdminOnly)
$docsRoute = Route::view('/docs', 'docs')->name('docs');
if (env('DOCS_AUTH_MODE', 'prod') === 'prod') {
    $docsRoute->middleware(\App\Http\Middleware\AdminOnly::class);
}
Route::post('/bets', [BetController::class, 'store'])->name('bets.store');
Route::post('/events/{event}/settle', [BetController::class, 'settle'])->name('events.settle');
Route::get('/events/sync-results', [BetController::class, 'syncResults'])->name('events.sync');
// Debug page removed per request

// sstats.net explorer
Route::get('/sstats', [SstatsController::class, 'index'])->name('sstats.index')->middleware('auth');
// Statistics page (public access)
Route::get('/stats', [StatsController::class, 'index'])->name('stats.index');

// Authentication
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
    // Registration
    Route::get('/register', [RegisterController::class, 'showRegistrationForm'])->name('register');
    Route::post('/register', [RegisterController::class, 'register']);
    // Password reset
    Route::get('/forgot-password', [ForgotPasswordController::class, 'showLinkRequestForm'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail'])->name('password.email');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'reset'])->name('password.update');
});
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

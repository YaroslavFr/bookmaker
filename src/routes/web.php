<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BetController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\OddsController;
use App\Http\Controllers\AdminController;

Route::get('/', [BetController::class, 'index'])->name('home');
// Public odds endpoint for homepage auto-refresh panel
Route::get('/odds', [OddsController::class, 'odds'])->name('odds.index');
// Extra markets for an event (JSON)
Route::get('/events/{event}/markets', [OddsController::class, 'markets'])->name('events.markets');
// Extra markets by direct gameId (JSON)
Route::get('/odds/game/{gameId}', [OddsController::class, 'marketsByGame'])->name('odds.byGame');

// Страница документации: авторизация по переменной окружения DOCS_AUTH_MODE
// test — публично; prod — только админ (AdminOnly)
$docsRoute = Route::view('/docs', 'docs')->name('docs');
if (env('DOCS_AUTH_MODE', 'prod') === 'prod') {
    $docsRoute->middleware(\App\Http\Middleware\AdminOnly::class);
}
Route::post('/bets', [BetController::class, 'store'])->name('bets.store');
Route::post('/events/{event}/settle', [BetController::class, 'settle'])->name('events.settle');
Route::get('/events/sync-results', [BetController::class, 'syncResults'])->name('events.sync');
Route::get('/events/{event}/settle-test', [BetController::class, 'settleByTest'])->name('events.settle_test');
Route::get('/events/settle-test/{externalId}', [BetController::class, 'settleByTestExternal'])->name('events.settle_test_external');
Route::get('/events/process-due', [BetController::class, 'processDueScheduled100'])->name('events.process_due');
Route::get('/bets/settle-unsettled', [BetController::class, 'settleUnsettledBets'])->name('bets.settle_unsettled');
// Page removed per request

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

Route::middleware([\App\Http\Middleware\AdminPanelOnly::class])->group(function () {
    Route::get('/admin', [AdminController::class, 'index'])->name('admin.index');
    Route::get('/admin/users/create', [AdminController::class, 'create'])->name('admin.users.create');
    Route::post('/admin/users', [AdminController::class, 'store'])->name('admin.users.store');
});

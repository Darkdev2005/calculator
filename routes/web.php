<?php

use App\Http\Controllers\ProfileController;
use App\Services\WebSocketAuthTokenService;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    $webSocketConfig = config('calculator.websocket');

    $clientUrl = $webSocketConfig['client_url']
        ?: sprintf(
            '%s://%s:%d%s',
            $webSocketConfig['scheme'],
            $webSocketConfig['host'],
            $webSocketConfig['port'],
            $webSocketConfig['path'],
        );

    return Inertia::render('Dashboard', [
        'wsUrl' => $clientUrl,
        'wsToken' => app(WebSocketAuthTokenService::class)->generateForUser(request()->user()),
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

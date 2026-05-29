<?php

use App\Http\Controllers\ImportController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin'       => Route::has('login'),
        'canRegister'    => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion'     => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // DEGIRO CSV imports
    Route::get('/accounts/{account}/import', [ImportController::class, 'show'])
        ->name('accounts.import.show');
    Route::post('/accounts/{account}/import/transactions', [ImportController::class, 'transactions'])
        ->name('accounts.import.transactions');
    Route::post('/accounts/{account}/import/account', [ImportController::class, 'account'])
        ->name('accounts.import.account');
});

require __DIR__.'/auth.php';

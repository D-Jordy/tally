<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\DividendController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectionController;
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

Route::redirect('/dashboard', '/portfolio')->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Portfolio
    Route::get('/portfolio', [PortfolioController::class, 'index'])->name('portfolio');

    // Dividend income forecast
    Route::get('/dividends', [DividendController::class, 'index'])->name('dividends');

    // Portfolio projections
    Route::get('/projections', [ProjectionController::class, 'index'])->name('projections');
    Route::patch('/projections/settings', [ProjectionController::class, 'updateSettings'])->name('projections.settings');

    // Accounts
    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/create', [AccountController::class, 'create'])->name('accounts.create');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');

    // DEGIRO CSV imports
    Route::get('/accounts/{account}/import', [ImportController::class, 'show'])
        ->name('accounts.import.show');
    Route::post('/accounts/{account}/import/transactions', [ImportController::class, 'transactions'])
        ->name('accounts.import.transactions');
    Route::post('/accounts/{account}/import/account', [ImportController::class, 'account'])
        ->name('accounts.import.account');
});

require __DIR__.'/auth.php';

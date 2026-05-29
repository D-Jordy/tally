<?php

namespace App\Providers;

use App\Models\Account;
use App\Policies\AccountPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Gate::policy(Account::class, AccountPolicy::class);
    }
}

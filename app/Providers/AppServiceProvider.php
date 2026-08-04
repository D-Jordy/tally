<?php

namespace App\Providers;

use App\Models\Account;
use App\Policies\AccountPolicy;
use App\Support\NumberFormat;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Euro notation everywhere, independent of the UI language — see NumberFormat.
        Number::useLocale(NumberFormat::LOCALE);

        Gate::policy(Account::class, AccountPolicy::class);
    }
}

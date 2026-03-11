<?php

namespace App\Providers;

use App\Models\Repository;
use App\Models\Ruleset;
use App\Policies\RepositoryPolicy;
use App\Policies\RulesetPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServicesProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Repository::class => RepositoryPolicy::class,
        Ruleset::class    => RulesetPolicy::class,
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}

<?php

namespace App\Providers;

use App\Models\Role;
use App\Policies\RolePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Role::class => RolePolicy::class,
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
        // Регистрируем политики
        $this->registerPolicies();

        // Определяем Gate для управления ролями
        Gate::define('manage-roles', function ($user) {
            return $user->hasRole('admin');
        });

        // Определяем Gate для доступа к админке
        Gate::define('access-admin', function ($user) {
            return $user->hasAnyRole(['admin', 'manager', 'editor']);
        });
    }
}

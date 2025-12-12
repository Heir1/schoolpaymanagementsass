<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Auth;
use App\Modules\Users\Models\User;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Configuration pour utiliser phone_or_email comme champ d'identification
        Auth::provider('custom-user', function ($app, array $config) {
            return new class($app['hash'], $config['model']) extends \Illuminate\Auth\EloquentUserProvider {
                public function retrieveByCredentials(array $credentials)
                {
                    if (empty($credentials) || 
                       (count($credentials) === 1 && 
                        array_key_exists('password', $credentials))) {
                        return null;
                    }

                    // Rechercher par phone_or_email
                    if (isset($credentials['phone_or_email'])) {
                        return $this->createModel()->newQuery()
                            ->where('phone_or_email', $credentials['phone_or_email'])
                            ->first();
                    }

                    return null;
                }
            };
        });
    }
}
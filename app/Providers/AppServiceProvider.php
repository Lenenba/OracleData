<?php

namespace App\Providers;

use App\Contracts\Catalog\ResourceCatalog;
use App\Models\User;
use App\Services\Catalog\HybridResourceCatalog;
use App\Services\Catalog\LegacyOracleCatalogAdapter;
use App\Services\FusionManager;
use App\Services\OracleResourceCatalog;
use App\Services\ResourceDefinitionRegistry;
use App\Services\Workers\WorkersOverrideProvider;
use App\Services\Workers\WorkersResourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(FusionManager::class);

        // WorkersResourceRegistry reste scopé : il porte les overrides HCM Workers.
        $this->app->scoped(WorkersResourceRegistry::class);

        // ResourceDefinitionRegistry découplé de WorkersResourceRegistry.
        // Les définitions Workers y sont injectées explicitement via registerAll().
        $this->app->scoped(ResourceDefinitionRegistry::class, function () {
            $registry = new ResourceDefinitionRegistry;
            $workers = $this->app->make(WorkersResourceRegistry::class);
            $registry->registerAll($workers->all());

            return $registry;
        });

        // Catalogue canonique hybride (Phase 2).
        // Le HybridResourceCatalog est scopé et construit une fois par requête.
        // Ordre d'autorité : Workers overrides (1er) → legacy fallback (2e).
        // Le catalogue sémantique et les snapshots /describe seront ajoutés
        // comme providers supplémentaires dans une phase ultérieure.
        $this->app->scoped(HybridResourceCatalog::class, function () {
            $catalog = new HybridResourceCatalog;

            // Provider 1 : overrides techniques Workers (chemins, identifiants, bindings).
            $workers = $this->app->make(WorkersResourceRegistry::class);
            $catalog->addProvider(new WorkersOverrideProvider($workers));

            // Provider 2 : catalogue historique en fallback de compatibilité.
            $legacy = $this->app->make(OracleResourceCatalog::class);
            $catalog->addProvider(new LegacyOracleCatalogAdapter($legacy));

            return $catalog;
        });

        // Liaison interface → implémentation.
        // ResourceCatalog est l'interface que le moteur et le Query Builder doivent utiliser.
        $this->app->scoped(ResourceCatalog::class, fn () => $this->app->make(HybridResourceCatalog::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('manage-categories', fn (User $user): bool => $user->isSuperAdmin());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

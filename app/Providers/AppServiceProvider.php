<?php

namespace App\Providers;

use App\Models\PaiementLicence;
use App\Models\Role;
use App\Models\User;
use App\Support\BoutiqueCourante;
use App\Support\Plateforme;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(BoutiqueCourante::class);
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();
        // En ligne derrière HTTPS : tous les liens générés (e-mails, redirections, formulaires) restent en HTTPS
        if ($this->app->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
        Route::model('paiement', PaiementLicence::class); // /admin/paiements/{paiement}
        Route::model('cloture', \App\Models\ClotureCaisse::class);
        \Carbon\Carbon::setLocale(config('app.locale'));

        // Le nom du logiciel choisi par le propriétaire remplace APP_NAME partout (titres, documents, messages)
        try {
            if ($nom = Plateforme::get('nom_logiciel')) {
                config(['app.name' => $nom]);
            }
            if (($essai = Plateforme::get('jours_essai')) !== null) {
                config(['gestion.jours_essai' => (int) $essai]);
            }
            // Serveur d'envoi des e-mails et expéditeur réglés par le propriétaire
            \App\Support\Courrier::configurer();
        } catch (\Throwable) {
            // base pas encore installée (première migration) : on garde APP_NAME
        }

        // Chaque permission devient une « gate » utilisable avec @can et le middleware can:
        foreach (Role::toutesLesPermissions() as $permission) {
            Gate::define($permission, fn (User $user) => $user->aPermission($permission));
        }
    }
}

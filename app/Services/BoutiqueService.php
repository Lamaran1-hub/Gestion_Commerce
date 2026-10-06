<?php

namespace App\Services;

use App\Models\Boutique;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Support\BoutiqueCourante;
use Illuminate\Support\Facades\DB;

class BoutiqueService
{
    /**
     * Crée une boutique cliente avec ses rôles par défaut et son premier administrateur.
     *
     * @param  array{nom:string, telephone?:string, email?:string, ville?:string, plan_id?:int|null, statut?:string, abonnement_expire_le?:string|null}  $boutique
     * @param  array{prenom:string, nom:string, email:string, telephone?:string, password:string}  $admin
     */
    public function creer(array $boutique, array $admin, bool $doitChangerMotDePasse = false): Boutique
    {
        $b = $this->creerSansEmail($boutique, $admin, $doitChangerMotDePasse);
        // E-mail de bienvenue une fois tout enregistré (un échec d'envoi n'empêche pas la création)
        \App\Support\Courrier::envoyer(User::where('boutique_id', $b->id)->get(), new \App\Notifications\Bienvenue($b), 'bienvenue', $b->id);

        return $b;
    }

    private function creerSansEmail(array $boutique, array $admin, bool $doitChangerMotDePasse): Boutique
    {
        return DB::transaction(function () use ($boutique, $admin, $doitChangerMotDePasse) {
            $b = Boutique::create($boutique + [
                'statut' => 'essai',
                'plan_id' => Plan::where('actif', true)->orderBy('prix_mensuel')->value('id'),
                'abonnement_expire_le' => now()->addDays(config('gestion.jours_essai'))->toDateString(),
            ]);

            $contexte = app(BoutiqueCourante::class);
            $precedente = $contexte->get();
            $contexte->definir($b);

            $roleAdmin = Role::creerPourBoutique($b);
            User::create($admin + [
                'boutique_id' => $b->id,
                'role_id' => $roleAdmin->id,
                'doit_changer_mot_de_passe' => $doitChangerMotDePasse,
            ]);

            $contexte->definir($precedente);

            return $b;
        });
    }
}

<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        User::firstOrCreate(['email' => config('gestion.super_admin.email')], [
            'prenom' => 'Administrateur',
            'nom' => 'Plateforme',
            'password' => config('gestion.super_admin.password'),
            'est_super_admin' => true,
        ]);

        // Données de démonstration : php artisan db:seed --class=DemoSeeder (ou automatiquement en local)
        if (app()->environment('local', 'testing') && Plan::exists()) {
            $this->call(DemoSeeder::class);
        }
    }
}

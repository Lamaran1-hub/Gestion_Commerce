<?php

namespace Tests;

use App\Models\Boutique;
use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueService;
use App\Services\StockService;
use App\Support\BoutiqueCourante;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /**
     * Boutique de test sur la formule la moins chère (Démarrage). Par défaut, toutes les fonctions lui sont
     * accordées (dérogation) pour tester chaque fonction ; les limites chiffrées restent celles de la formule.
     *
     * @return array{0:Boutique,1:User}
     */
    protected function creerBoutique(string $nom = 'Boutique Test', string $email = 'admin@test.gn', bool $toutesFonctions = true): array
    {
        $b = app(BoutiqueService::class)->creer(
            ['nom' => $nom],
            ['prenom' => 'Admin', 'nom' => $nom, 'email' => $email, 'password' => 'secret123'],
        );
        $b->update(['statut' => 'actif', 'abonnement_expire_le' => now()->addMonth(),
            'derogations' => $toutesFonctions ? ['fonctions' => array_keys(config('gestion.fonctions'))] : null]);

        return [$b->fresh(), User::where('email', $email)->first()];
    }

    /** Champs anti-robot d'un formulaire public rempli par un humain (affiché il y a 10 secondes). */
    protected function humain(): array
    {
        return [\App\Support\AntiRobot::CHAMP_HEURE => \Illuminate\Support\Facades\Crypt::encryptString((string) (time() - 10))];
    }

    /** Exécute du code « dans » une boutique, comme si l'utilisateur était connecté. */
    protected function dans(Boutique $b, callable $fn, ?User $user = null): mixed
    {
        $ctx = app(BoutiqueCourante::class);
        $ctx->definir($b);
        if ($user) {
            $this->actingAs($user);
        }
        try {
            return $fn();
        } finally {
            $ctx->definir(null);
        }
    }

    protected function produit(Boutique $b, array $attrs = [], float $stock = 10): Produit
    {
        return $this->dans($b, function () use ($attrs, $stock) {
            $p = Produit::create($attrs + ['designation' => 'Riz 25 kg', 'unite' => 'sac', 'prix_achat' => 250_000, 'prix_vente' => 300_000]);
            if ($stock > 0) {
                app(StockService::class)->mouvement($p, 'stock_initial', $stock);
            }

            return $p->fresh();
        });
    }
}

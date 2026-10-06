<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Produit;
use Tests\TestCase;

class ImportWindevTest extends TestCase
{
    public function test_import_des_csv_exportes_de_hfsql(): void
    {
        [$b] = $this->creerBoutique();
        $dir = sys_get_temp_dir().'/import-'.uniqid();
        mkdir($dir);
        file_put_contents("$dir/Fournisseur.csv", "IDFournisseur;nom;prenom;Adresse;telephone\n7;SOGUICOM;;Madina;620000000\n");
        file_put_contents("$dir/Client.csv", "IDClient;nom;prenom;telephone\n1;Diallo;Aminata;621111111\n");
        file_put_contents("$dir/Produit.csv", mb_convert_encoding("IDProduit;designation;prix;IDFournisseur;Qte;CodeBarre\n1;Pâte dentifrice;8 000;7;10;123\n", 'Windows-1252', 'UTF-8'));

        $this->artisan('gestion:import-windev', ['boutique' => $b->slug, 'dossier' => $dir])->assertSuccessful();

        $p = Produit::withoutGlobalScopes()->where('boutique_id', $b->id)->first();
        $this->assertSame('Pâte dentifrice', $p->designation);
        $this->assertSame(8000, $p->prix_vente);
        $this->assertEquals(10, $p->stock);
        $this->assertSame('SOGUICOM', $p->fournisseur->nom);
        $this->assertSame(1, Client::withoutGlobalScopes()->where('boutique_id', $b->id)->count());
    }
}

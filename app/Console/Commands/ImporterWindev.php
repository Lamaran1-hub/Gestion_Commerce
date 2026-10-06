<?php

namespace App\Console\Commands;

use App\Models\Boutique;
use App\Models\Client;
use App\Models\Fournisseur;
use App\Models\Produit;
use App\Services\StockService;
use App\Support\BoutiqueCourante;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reprise des données d'une ancienne installation WinDev (Gestion de vente).
 * Exportez les fichiers HFSQL en CSV (Centre de Contrôle HFSQL > Exporter, ou WDMap),
 * avec la ligne d'en-tête, puis :  php artisan gestion:import-windev {boutique} {dossier}
 * Fichiers lus s'ils existent : Fournisseur.csv, Client.csv, Produit.csv
 */
class ImporterWindev extends Command
{
    protected $signature = 'gestion:import-windev {boutique : identifiant ou slug de la boutique} {dossier : dossier contenant les CSV} {--simuler : affiche le résultat sans rien enregistrer}';

    protected $description = 'Importe fournisseurs, clients et produits exportés depuis l\'application WinDev';

    public function handle(StockService $stock): int
    {
        $boutique = Boutique::where('id', $this->argument('boutique'))->orWhere('slug', $this->argument('boutique'))->first();
        if (! $boutique) {
            $this->error('Boutique introuvable.');

            return self::FAILURE;
        }
        $dossier = rtrim($this->argument('dossier'), '/');
        app(BoutiqueCourante::class)->definir($boutique);

        DB::beginTransaction();
        try {
            $fournisseurs = [];
            foreach ($this->lire("$dossier/Fournisseur.csv") as $l) {
                $nom = trim(($l['nom'] ?? $l['nomfournisseur'] ?? '').' '.($l['prenom'] ?? ''));
                if ($nom === '') {
                    continue;
                }
                $f = Fournisseur::firstOrCreate(['nom' => $nom], [
                    'telephone' => $l['telephone'] ?? null, 'adresse' => $l['adresse'] ?? $l['adressefour'] ?? null,
                ]);
                $fournisseurs[$l['idfournisseur'] ?? ''] = $f->id;
            }

            $nbClients = 0;
            foreach ($this->lire("$dossier/Client.csv") ?: $this->lire("$dossier/Clients.csv") as $l) {
                $nom = $l['nom'] ?? $l['nomclient'] ?? '';
                if (trim($nom) === '') {
                    continue;
                }
                Client::create(['nom' => trim($nom), 'prenom' => $l['prenom'] ?? $l['prenomcli'] ?? null, 'telephone' => $l['telephone'] ?? $l['telephonecli'] ?? null]);
                $nbClients++;
            }

            $nbProduits = 0;
            foreach ($this->lire("$dossier/Produit.csv") as $l) {
                $designation = trim($l['designation'] ?? $l['nomprod'] ?? '');
                if ($designation === '') {
                    continue;
                }
                $prix = montant_saisi(explode(',', str_replace('.', ',', (string) ($l['prix'] ?? $l['prixprod'] ?? '0')))[0]);
                $code = trim($l['codebarre'] ?? '') ?: null;
                if ($code && Produit::where('code_barre', $code)->exists()) {
                    $code = null; // doublon : on garde le produit sans code
                }
                $produit = Produit::create([
                    'designation' => $designation, 'code_barre' => $code, 'prix_vente' => $prix, 'prix_achat' => 0, 'unite' => 'pièce',
                    'fournisseur_id' => $fournisseurs[$l['idfournisseur'] ?? ''] ?? null,
                ]);
                $qte = (float) str_replace(',', '.', $l['qte'] ?? $l['qteprod'] ?? 0);
                if ($qte > 0) {
                    $stock->mouvement($produit, 'stock_initial', $qte, null, 'Reprise des données WinDev');
                }
                $nbProduits++;
            }

            $this->table(['Élément', 'Importés'], [['Fournisseurs', count($fournisseurs)], ['Clients', $nbClients], ['Produits', $nbProduits]]);
            if ($this->option('simuler')) {
                DB::rollBack();
                $this->warn('Simulation : rien n\'a été enregistré.');
            } else {
                DB::commit();
                $this->info("Import terminé pour « {$boutique->nom} ». Pensez à saisir les prix d'achat des produits.");
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Import annulé : '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Lit un CSV (séparateur ; ou , détecté, encodage Windows ou UTF-8) en tableaux associatifs aux clés en minuscules. */
    private function lire(string $fichier): array
    {
        if (! is_file($fichier)) {
            return [];
        }
        $contenu = file_get_contents($fichier);
        if (! mb_check_encoding($contenu, 'UTF-8')) {
            $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
        }
        $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu);
        $lignes = preg_split('/\r\n|\n|\r/', trim($contenu));
        $sep = substr_count($lignes[0], ';') >= substr_count($lignes[0], ',') ? ';' : ',';
        $entetes = array_map(fn ($e) => mb_strtolower(trim($e, " \"\t")), str_getcsv(array_shift($lignes), $sep));

        return array_values(array_filter(array_map(function ($ligne) use ($sep, $entetes) {
            $valeurs = str_getcsv($ligne, $sep);

            return count($valeurs) === count($entetes) ? array_combine($entetes, array_map('trim', $valeurs)) : null;
        }, $lignes)));
    }
}

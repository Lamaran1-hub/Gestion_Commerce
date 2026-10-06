<?php

namespace App\Services;

use App\Models\Categorie;
use App\Models\Fournisseur;
use App\Models\JournalActivite;
use App\Models\Produit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import du catalogue depuis un fichier Excel ou CSV.
 *
 * Règles :
 * - Un produit existant est reconnu par son code-barres, sinon par sa désignation : il est mis à jour.
 * - Le stock d'un produit existant n'est jamais écrasé (il se corrige par l'inventaire, avec trace) ;
 *   pour un nouveau produit, la quantité devient le stock initial.
 * - Mêmes contrôles que la saisie manuelle : prix de vente obligatoire, pas de vente à perte si interdite,
 *   code-barres unique, limite de produits de la formule.
 * - Catégories et fournisseurs inconnus sont créés.
 */
class ImportCatalogue
{
    public const COLONNES = [
        'designation' => 'Désignation', 'code_barre' => 'Code-barres', 'categorie' => 'Catégorie', 'unite' => 'Unité',
        'prix_achat' => "Prix d'achat", 'prix_vente' => 'Prix de vente', 'prix_gros' => 'Prix de gros',
        'seuil_alerte' => "Seuil d'alerte", 'stock' => 'Stock', 'fournisseur' => 'Fournisseur',
    ];

    /** En-têtes acceptés (après normalisation), y compris ceux de nos propres exports. */
    private const ALIAS = [
        'designation' => ['designation', 'produit', 'nom', 'article', 'libelle', 'nom_du_produit'],
        'code_barre' => ['code_barre', 'code_barres', 'code', 'codebarre', 'ean', 'reference', 'ref'],
        'categorie' => ['categorie', 'famille', 'rayon'],
        'unite' => ['unite', 'unite_de_vente'],
        'prix_achat' => ['prix_d_achat', 'prix_achat', 'pa', 'cout', 'cout_d_achat'],
        'prix_vente' => ['prix_de_vente', 'prix_vente', 'pv', 'prix', 'prix_detail', 'prix_de_detail'],
        'prix_gros' => ['prix_de_gros', 'prix_gros'],
        'seuil_alerte' => ['seuil_d_alerte', 'seuil_alerte', 'seuil', 'stock_minimum', 'stock_min'],
        'stock' => ['stock', 'quantite', 'qte', 'stock_initial', 'quantite_en_stock'],
        'fournisseur' => ['fournisseur'],
    ];

    /** @return array<int, array<string, mixed>> lignes indexées par leur numéro dans le fichier */
    public function lire(string $chemin): array
    {
        $feuille = IOFactory::load($chemin)->getActiveSheet()->toArray(null, true, false, false);

        // L'en-tête est la première ligne (parmi les 10 premières) qui contient une colonne « désignation »
        foreach (array_slice($feuille, 0, 10, true) as $i => $ligne) {
            $colonnes = [];
            foreach ($ligne as $j => $entete) {
                $cle = $this->champ((string) $entete);
                if ($cle && ! in_array($cle, $colonnes, true)) {
                    $colonnes[$j] = $cle;
                }
            }
            if (in_array('designation', $colonnes, true)) {
                $lignes = [];
                foreach (array_slice($feuille, $i + 1, null, true) as $n => $valeurs) {
                    $l = [];
                    foreach ($colonnes as $j => $cle) {
                        $l[$cle] = is_string($valeurs[$j] ?? null) ? trim($valeurs[$j]) : ($valeurs[$j] ?? null);
                    }
                    if (collect($l)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty()) {
                        $lignes[$n + 1] = $l;
                    }
                }

                return $lignes;
            }
        }

        throw new \App\Exceptions\OperationRefusee('Colonne « Désignation » introuvable : utilisez le modèle proposé (la première ligne doit contenir les titres des colonnes).');
    }

    /**
     * Contrôle chaque ligne sans rien enregistrer.
     *
     * @return array<int, array{ligne:int, donnees:array, action:string, erreurs:string[], produit:?Produit}>
     */
    public function analyser(array $lignes): array
    {
        $existants = Produit::all();
        $parCode = $existants->filter(fn ($p) => $p->code_barre)->keyBy(fn ($p) => mb_strtolower($p->code_barre));
        $parNom = $existants->keyBy(fn ($p) => mb_strtolower($p->designation));
        $vus = [];
        $venteAPerte = (bool) boutique()->vente_a_perte;
        $resultat = [];

        foreach ($lignes as $n => $l) {
            $erreurs = [];
            $d = [
                'designation' => Str::limit((string) ($l['designation'] ?? ''), 150, ''),
                'code_barre' => ($c = trim((string) ($l['code_barre'] ?? ''))) !== '' ? $c : null,
                'categorie' => trim((string) ($l['categorie'] ?? '')) ?: null,
                'unite' => trim((string) ($l['unite'] ?? '')) ?: 'pièce',
                'prix_achat' => $this->montant($l['prix_achat'] ?? null) ?? 0,
                'prix_vente' => $this->montant($l['prix_vente'] ?? null),
                'prix_gros' => $this->montant($l['prix_gros'] ?? null),
                'seuil_alerte' => $this->nombre($l['seuil_alerte'] ?? null) ?? 0,
                'stock' => $this->nombre($l['stock'] ?? null) ?? 0,
                'fournisseur' => trim((string) ($l['fournisseur'] ?? '')) ?: null,
            ];
            if ($d['designation'] === '') {
                $erreurs[] = 'Désignation manquante.';
            }
            if ($d['prix_vente'] === null || $d['prix_vente'] <= 0) {
                $erreurs[] = 'Prix de vente manquant ou invalide.';
            } elseif (! $venteAPerte && $d['prix_achat'] > 0 && $d['prix_vente'] < $d['prix_achat']) {
                $erreurs[] = 'Prix de vente ('.gnf($d['prix_vente']).') inférieur au prix d\'achat ('.gnf($d['prix_achat']).').';
            }
            if ($d['prix_gros'] !== null && $d['prix_vente'] && $d['prix_gros'] >= $d['prix_vente']) {
                $erreurs[] = 'Le prix de gros doit être inférieur au prix de vente.';
            }
            if ($d['stock'] < 0 || $d['seuil_alerte'] < 0) {
                $erreurs[] = 'Stock et seuil ne peuvent pas être négatifs.';
            }

            $produit = ($d['code_barre'] ? $parCode->get(mb_strtolower($d['code_barre'])) : null) ?? $parNom->get(mb_strtolower($d['designation']));
            if ($produit && $d['code_barre'] && $produit->code_barre && mb_strtolower($produit->code_barre) !== mb_strtolower($d['code_barre'])) {
                $erreurs[] = "« {$d['designation']} » existe déjà avec un autre code-barres ({$produit->code_barre}).";
            }
            $cle = $d['code_barre'] ? 'c:'.mb_strtolower($d['code_barre']) : 'n:'.mb_strtolower($d['designation']);
            if (isset($vus[$cle])) {
                $erreurs[] = 'Doublon : déjà présent ligne '.$vus[$cle].' du fichier.';
            }
            $vus[$cle] = $n;

            $resultat[$n] = ['ligne' => $n, 'donnees' => $d, 'produit' => $produit,
                'action' => $erreurs ? 'erreur' : ($produit ? 'maj' : 'creer'), 'erreurs' => $erreurs];
        }

        // Limite de la formule : on ne crée pas plus de produits que permis
        $max = boutique()->limite('produits');
        if ($max) {
            $place = max(0, $max - $existants->count());
            foreach ($resultat as $n => $r) {
                if ($r['action'] === 'creer' && $place-- <= 0) {
                    $resultat[$n]['action'] = 'erreur';
                    $resultat[$n]['erreurs'][] = "Limite de votre formule atteinte ({$max} produits).";
                }
            }
        }

        return $resultat;
    }

    /** @return array{crees:int, maj:int, ignores:int} */
    public function importer(array $analyse, StockService $stock): array
    {
        $compte = ['crees' => 0, 'maj' => 0, 'ignores' => 0];

        DB::transaction(function () use ($analyse, $stock, &$compte) {
            $categories = Categorie::all()->keyBy(fn ($c) => mb_strtolower($c->nom));
            $fournisseurs = Fournisseur::all()->keyBy(fn ($f) => mb_strtolower($f->nom));

            foreach ($analyse as $r) {
                if ($r['action'] === 'erreur') {
                    $compte['ignores']++;

                    continue;
                }
                $d = $r['donnees'];
                $attrs = [
                    'designation' => $d['designation'], 'unite' => $d['unite'], 'prix_achat' => $d['prix_achat'], 'prix_vente' => $d['prix_vente'],
                    'prix_gros' => $d['prix_gros'], 'seuil_alerte' => $d['seuil_alerte'],
                ];
                if ($d['code_barre']) {
                    $attrs['code_barre'] = $d['code_barre'];
                }
                if ($d['categorie']) {
                    $attrs['categorie_id'] = ($categories[mb_strtolower($d['categorie'])] ??= Categorie::create(['nom' => $d['categorie']]))->id;
                }
                if ($d['fournisseur']) {
                    $attrs['fournisseur_id'] = ($fournisseurs[mb_strtolower($d['fournisseur'])] ??= Fournisseur::create(['nom' => $d['fournisseur']]))->id;
                }

                if ($r['action'] === 'maj') {
                    \App\Models\HistoriquePrix::depuis('Import Excel', fn () => Produit::findOrFail($r['produit']->id)->update($attrs));
                    $compte['maj']++;
                } else {
                    $p = Produit::create($attrs + ['actif' => true]);
                    if ($d['stock'] > 0) {
                        $stock->mouvement($p, 'stock_initial', (float) $d['stock'], null, 'Stock initial (import)');
                    }
                    $compte['crees']++;
                }
            }
        });
        JournalActivite::noter('produit', "Import du catalogue : {$compte['crees']} créé(s), {$compte['maj']} mis à jour, {$compte['ignores']} ignoré(s)");

        return $compte;
    }

    private function champ(string $entete): ?string
    {
        $n = trim(preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii($entete))), '_');
        foreach (self::ALIAS as $champ => $alias) {
            if (in_array($n, $alias, true)) {
                return $champ;
            }
        }

        return null;
    }

    private function montant(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return (int) round((float) $v);
        }
        $chiffres = preg_replace('/[^\d]/', '', (string) $v);

        return $chiffres === '' ? null : (int) $chiffres;
    }

    private function nombre(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], (string) $v);

        return is_numeric($v) ? round((float) $v, 2) : null;
    }
}

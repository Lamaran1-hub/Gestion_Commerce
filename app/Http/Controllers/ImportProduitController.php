<?php

namespace App\Http\Controllers;

use App\Services\ImportCatalogue;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Import du catalogue : modèle Excel, aperçu ligne par ligne, puis confirmation. */
class ImportProduitController extends Controller
{
    private const SESSION = 'import_catalogue';

    public function create()
    {
        return view('produits.import');
    }

    /** Modèle Excel prêt à remplir, avec deux exemples. */
    public function modele()
    {
        $classeur = new Spreadsheet;
        $f = $classeur->getActiveSheet()->setTitle('Produits');
        $f->fromArray([array_values(ImportCatalogue::COLONNES),
            ['Riz parfumé 25 kg', '6001234567890', 'Alimentation', 'sac', 250000, 300000, 290000, 5, 40, 'Sodeci'],
            ['Huile 5 L', '', 'Alimentation', 'bidon', 80000, 95000, '', 10, 24, ''],
        ]);
        $f->getStyle('A1:J1')->getFont()->setBold(true);
        foreach (range('A', 'J') as $col) {
            $f->getColumnDimension($col)->setAutoSize(true);
        }
        $f->getStyle('B:B')->getNumberFormat()->setFormatCode('@'); // code-barres gardé en texte (zéros en tête)

        return response()->streamDownload(fn () => (new Xlsx($classeur))->save('php://output'), 'modele-import-produits.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function apercu(Request $request, ImportCatalogue $import)
    {
        $request->validate(['fichier' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120']], [
            'fichier.mimes' => 'Choisissez un fichier Excel (.xlsx, .xls) ou CSV.',
        ]);
        $ancien = session(self::SESSION);
        if ($ancien) {
            Storage::delete($ancien);
        }
        $chemin = $request->file('fichier')->storeAs('imports/'.boutique()->id,
            now()->format('YmdHis').'-'.\Illuminate\Support\Str::random(8).'.'.$request->file('fichier')->getClientOriginalExtension());
        session([self::SESSION => $chemin]);

        $analyse = $import->analyser($import->lire(Storage::path($chemin)));
        if (! $analyse) {
            return back()->with('erreur', 'Le fichier ne contient aucun produit.');
        }

        return view('produits.import', ['analyse' => collect($analyse), 'nomFichier' => $request->file('fichier')->getClientOriginalName()]);
    }

    public function store(ImportCatalogue $import, StockService $stock)
    {
        $chemin = session(self::SESSION);
        if (! $chemin || ! Storage::exists($chemin)) {
            return redirect()->route('produits.import')->with('erreur', 'Fichier expiré : chargez-le de nouveau.');
        }
        // Nouvelle analyse au moment de valider : le catalogue a pu changer entre-temps
        $c = $import->importer($import->analyser($import->lire(Storage::path($chemin))), $stock);
        Storage::delete($chemin);
        session()->forget(self::SESSION);

        return redirect()->route('produits.index')->with('succes', "Import terminé : {$c['crees']} produit(s) créé(s), {$c['maj']} mis à jour"
            .($c['ignores'] ? ", {$c['ignores']} ligne(s) en erreur ignorée(s)" : '').'.');
    }
}

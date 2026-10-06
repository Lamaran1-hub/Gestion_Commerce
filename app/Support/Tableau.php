<?php

namespace App\Support;

use App\Exports\TableauExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/** Produit un même tableau en Excel ou en PDF (listes, rapports). */
class Tableau
{
    public static function telecharger(string $format, string $titre, array $colonnes, array $lignes, array $montants = [], ?string $sousTitre = null, array $totaux = [])
    {
        $fichier = Str::slug($titre).'-'.now()->format('Y-m-d');
        $boutique = boutique(); // logo, coordonnées et couleurs en tête de chaque document

        if ($format === 'ecran') {
            return view('rapports.ecran', compact('titre', 'sousTitre', 'colonnes', 'lignes', 'montants', 'totaux'));
        }
        if ($format === 'pdf') {
            return Pdf::loadView('pdf.tableau', compact('titre', 'sousTitre', 'colonnes', 'lignes', 'montants', 'totaux', 'boutique'))
                ->setPaper('a4', count($colonnes) > 6 ? 'landscape' : 'portrait')
                ->download($fichier.'.pdf');
        }

        return Excel::download(new TableauExport($titre, $colonnes, $lignes, $montants, $boutique, $sousTitre, $totaux), $fichier.'.xlsx');
    }
}

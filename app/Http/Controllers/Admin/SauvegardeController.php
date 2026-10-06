<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Sauvegarde;
use Illuminate\Support\Facades\Log;

/** Sauvegardes de la base : liste, création immédiate, téléchargement, suppression. */
class SauvegardeController extends Controller
{
    public function __construct(private Sauvegarde $sauvegarde)
    {
    }

    public function index()
    {
        return view('admin.sauvegardes', [
            'sauvegardes' => $this->sauvegarde->lister(),
            'garder' => config('gestion.sauvegardes_a_garder'),
        ]);
    }

    public function store()
    {
        $nom = $this->sauvegarde->creer();
        $this->sauvegarde->purger(config('gestion.sauvegardes_a_garder'));
        Log::info('Sauvegarde manuelle créée', ['fichier' => $nom, 'par' => auth()->id()]);

        return back()->with('succes', "Sauvegarde {$nom} créée.");
    }

    public function telecharger(string $nom)
    {
        Log::info('Sauvegarde téléchargée', ['fichier' => $nom, 'par' => auth()->id(), 'ip' => request()->ip()]);

        return response()->download($this->sauvegarde->chemin($nom), $nom, ['Content-Type' => 'application/gzip']);
    }

    public function destroy(string $nom)
    {
        unlink($this->sauvegarde->chemin($nom));

        return back()->with('succes', "Sauvegarde {$nom} supprimée.");
    }
}

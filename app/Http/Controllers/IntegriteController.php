<?php

namespace App\Http\Controllers;

use App\Services\ArchivesFiscales;
use App\Services\Registre;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Contrôle d'intégrité des encaissements et archives fiscales mensuelles signées. */
class IntegriteController extends Controller
{
    public function index(Registre $registre, ArchivesFiscales $archives)
    {
        $b = boutique();

        return view('integrite', [
            'resultat' => $registre->verifier($b),
            'dernieres' => DB::table('registre_caisse')->where('boutique_id', $b->id)->orderByDesc('sequence')->limit(15)->get(),
            // Chaque archive est revérifiée à l'affichage : le fichier conservé correspond-il toujours à son empreinte ?
            'archives' => DB::table('archives_fiscales')->where('boutique_id', $b->id)->orderByDesc('periode_du')->get()
                ->each(function ($a) use ($archives) {
                    $a->intacte = $archives->verifier($a);
                }),
        ]);
    }

    public function archiver(Request $request, ArchivesFiscales $archives)
    {
        $d = $request->validate(['mois' => ['required', 'date_format:Y-m']]);
        $a = $archives->generer(boutique(), Carbon::createFromFormat('Y-m', $d['mois'])->startOfMonth(), $request->user());

        return back()->with('succes', 'Archive de '.Carbon::parse($a->periode_du)->translatedFormat('F Y').' générée et signée.');
    }

    public function telecharger(int $archive, ArchivesFiscales $archives)
    {
        $a = DB::table('archives_fiscales')->where('boutique_id', boutique()->id)->where('id', $archive)->first();
        abort_unless($a && Storage::exists($a->fichier), 404);
        abort_unless($archives->verifier($a), 409, "Ce fichier d'archive ne correspond plus à son empreinte : il a été modifié.");

        return Storage::download($a->fichier, basename($a->fichier), ['X-Empreinte-SHA256' => $a->empreinte]);
    }
}

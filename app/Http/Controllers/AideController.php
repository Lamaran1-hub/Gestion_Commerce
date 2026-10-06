<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/** Centre d'aide : guides pas à pas (config/aide.php), adaptés à la formule et aux droits de l'utilisateur. */
class AideController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $guides = collect(config('aide.guides'))->map(function (array $g, string $cle) use ($user) {
            $inclus = empty($g['fonction']) || fonction($g['fonction']);
            $autorise = empty($g['permission']) || $user->aPermission($g['permission']);

            return $g + [
                'cle' => $cle,
                'inclus' => $inclus,
                // Lien vers l'écran seulement s'il est utilisable (formule et droits)
                'url' => $inclus && $autorise && ! empty($g['lien']) ? route($g['lien']) : null,
            ];
        });

        return view('aide.index', [
            'rubriques' => config('aide.rubriques'),
            'guides' => $guides->groupBy('rubrique'),
            'nbGuides' => $guides->count(),
        ]);
    }
}

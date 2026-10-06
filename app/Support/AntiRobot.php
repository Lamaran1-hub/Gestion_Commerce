<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Protection des formulaires publics (création de boutique, commande sur la vitrine) contre les robots de spam,
 * sans captcha pour les humains :
 * - un champ leurre invisible : un humain le laisse vide, un robot le remplit ;
 * - l'heure d'affichage du formulaire, chiffrée : un envoi trop rapide (ou sans cette heure) vient d'un robot.
 */
class AntiRobot
{
    public const CHAMP_LEURRE = 'site_web';

    public const CHAMP_HEURE = '_affiche';

    public const DELAI_MINIMUM = 2;   // secondes

    /** Champs cachés à placer dans le formulaire. */
    public static function champs(): string
    {
        return '<div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden">'
            .'<label>Ne pas remplir<input type="text" name="'.self::CHAMP_LEURRE.'" value="" tabindex="-1" autocomplete="off"></label></div>'
            .'<input type="hidden" name="'.self::CHAMP_HEURE.'" value="'.e(Crypt::encryptString((string) time())).'">';
    }

    /** Refuse l'envoi s'il vient manifestement d'un robot. */
    public static function verifier(Request $request, string $formulaire): void
    {
        $affiche = null;
        try {
            $affiche = (int) Crypt::decryptString((string) $request->input(self::CHAMP_HEURE));
        } catch (\Throwable) {
        }
        $robot = filled($request->input(self::CHAMP_LEURRE)) || ! $affiche || time() - $affiche < self::DELAI_MINIMUM;
        if ($robot) {
            Log::notice('Formulaire public refusé (robot probable)', ['formulaire' => $formulaire, 'ip' => $request->ip()]);
            throw ValidationException::withMessages(['formulaire' => 'Envoi refusé : rechargez la page et réessayez.']);
        }
    }
}

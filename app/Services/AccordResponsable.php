<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\JournalActivite;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Accord du responsable à la caisse : une remise au-delà du maximum de la boutique, ou accordée par un vendeur
 * qui n'a pas le droit de remise, passe si un responsable tape son code personnel sur la caisse.
 *
 * - Responsable = administrateur de la boutique ou personne qui gère ses paramètres.
 * - On choisit le responsable puis il tape son code (4 à 6 chiffres, haché comme un mot de passe).
 * - 5 codes faux de suite bloquent la saisie 10 minutes pour ce caissier.
 * - Le prix d'achat reste une limite : l'accord ne permet pas de vendre à perte.
 */
class AccordResponsable
{
    public const ESSAIS = 5;

    public const BLOCAGE_SECONDES = 600;

    public static function estResponsable(User $user): bool
    {
        return $user->actif && ! $user->est_super_admin && ($user->role?->systeme || $user->aPermission('parametres.gerer'));
    }

    /** Responsables de la boutique qui ont choisi un code. */
    public function responsables(): Collection
    {
        $boutique = boutique();
        if (! $boutique) {
            return collect();
        }

        return User::with('role')->where('boutique_id', $boutique->id)->where('actif', true)->whereNotNull('code_accord')
            ->orderBy('prenom')->get()->filter(fn (User $u) => self::estResponsable($u))->values();
    }

    /** Le responsable choisi, si son code est juste ; sinon un refus (code faux, trop d'essais). */
    public function verifier(int $responsableId, string $code, User $demandeur): User
    {
        $cle = 'accord-responsable:'.$demandeur->id;
        if (RateLimiter::tooManyAttempts($cle, self::ESSAIS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($cle) / 60);
            throw new OperationRefusee("Trop de codes faux : réessayez dans {$minutes} min.");
        }
        $responsable = $this->responsables()->firstWhere('id', $responsableId);
        if (! $responsable) {
            throw new OperationRefusee('Choisissez le responsable qui accorde la remise.');
        }
        $code = trim($code);
        if ($code === '' || ! Hash::check($code, $responsable->code_accord)) {
            RateLimiter::hit($cle, self::BLOCAGE_SECONDES);
            JournalActivite::noter('securite', "Code faux de {$responsable->nomComplet()} saisi à la caisse de {$demandeur->nomComplet()}");
            $reste = self::ESSAIS - RateLimiter::attempts($cle);
            throw new OperationRefusee('Code du responsable incorrect.'.($reste > 0 ? " Encore {$reste} essai(s)." : ' Saisie bloquée 10 min.'));
        }
        RateLimiter::clear($cle);

        return $responsable;
    }

    /** Choisit, change ou retire (null) le code d'un responsable. */
    public function definir(User $user, ?string $code): void
    {
        if (! self::estResponsable($user)) {
            throw new OperationRefusee('Seuls l\'administrateur et les gestionnaires des paramètres peuvent avoir un code d\'accord.');
        }
        if ($code !== null) {
            if (! preg_match('/^\d{4,6}$/', $code)) {
                throw new OperationRefusee('Le code doit faire de 4 à 6 chiffres.');
            }
            if (preg_match('/^(\d)\1+$/', $code) || str_contains('0123456789', $code) || str_contains('9876543210', $code)) {
                throw new OperationRefusee('Code trop facile à deviner (1111, 1234…) : choisissez-en un autre.');
            }
        }
        $user->forceFill(['code_accord' => $code === null ? null : Hash::make($code)])->save();
    }
}

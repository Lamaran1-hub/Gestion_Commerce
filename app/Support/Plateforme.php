<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coordonnées et réglages de l'éditeur du logiciel (le propriétaire),
 * affichés aux clients : page abonnement, assistance, reçus de licence.
 */
class Plateforme
{
    public const CHAMPS = [
        'societe' => 'Nom de votre société',
        'telephone' => 'Téléphone',
        'whatsapp' => 'WhatsApp',
        'email' => 'E-mail',
        'adresse' => 'Adresse',
        'site' => 'Site web',
        'infos_paiement' => 'Comment payer la licence (Orange Money, compte bancaire…)',
    ];

    public static function tout(): array
    {
        return Cache::rememberForever('parametres_plateforme', function () {
            if (! Schema::hasTable('parametres_plateforme')) {
                return [];
            }

            return DB::table('parametres_plateforme')->pluck('valeur', 'cle')->all();
        });
    }

    public static function get(string $cle, ?string $defaut = null): ?string
    {
        $v = self::tout()[$cle] ?? null;

        return $v !== null && $v !== '' ? $v : $defaut;
    }

    public static function enregistrer(array $valeurs): void
    {
        foreach ($valeurs as $cle => $valeur) {
            DB::table('parametres_plateforme')->updateOrInsert(['cle' => $cle], ['valeur' => $valeur]);
        }
        Cache::forget('parametres_plateforme');
    }

    /** Un propriétaire (super-administrateur) existe-t-il ? Sinon l'installation est proposée. */
    public static function estInstallee(): bool
    {
        return Schema::hasTable('users') && \App\Models\User::where('est_super_admin', true)->exists();
    }
}

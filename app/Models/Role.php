<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use AppartientABoutique;

    public const ADMINISTRATEUR = 'Administrateur';

    protected $fillable = ['boutique_id', 'nom', 'permissions', 'systeme'];

    protected $casts = ['permissions' => 'array', 'systeme' => 'boolean'];

    public function utilisateurs(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function autorise(string $permission): bool
    {
        return $this->systeme || in_array($permission, $this->permissions ?? [], true);
    }

    /** Crée le rôle Administrateur et les rôles par défaut d'une nouvelle boutique. */
    public static function creerPourBoutique(Boutique $boutique): Role
    {
        $admin = static::create([
            'boutique_id' => $boutique->id,
            'nom' => self::ADMINISTRATEUR,
            'permissions' => [],
            'systeme' => true,
        ]);

        foreach (config('gestion.roles_par_defaut') as $nom => $permissions) {
            static::create(['boutique_id' => $boutique->id, 'nom' => $nom, 'permissions' => $permissions]);
        }

        return $admin;
    }

    public static function toutesLesPermissions(): array
    {
        return collect(config('gestion.permissions'))->flatMap(fn ($g) => array_keys($g))->all();
    }
}

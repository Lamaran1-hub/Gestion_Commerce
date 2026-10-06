<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationTresorerie extends Model
{
    use AppartientABoutique;

    protected $table = 'operations_tresorerie';

    protected $fillable = ['type', 'compte_source', 'compte_destination', 'montant', 'frais', 'ecart', 'motif', 'reference', 'date_operation', 'user_id'];

    protected $casts = ['date_operation' => 'datetime', 'montant' => 'integer', 'frais' => 'integer', 'ecart' => 'integer'];

    public const TYPES = [
        'transfert' => 'Transfert',
        'apport' => "Apport de l'exploitant",
        'retrait' => "Retrait de l'exploitant",
        'constat' => 'Constat de solde',
    ];

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function libelleCompte(?string $compte): string
    {
        return config("gestion.comptes_tresorerie.{$compte}.0") ?? (string) $compte;
    }

    public function libelle(): string
    {
        return match ($this->type) {
            'transfert' => self::libelleCompte($this->compte_source).' → '.self::libelleCompte($this->compte_destination),
            'apport' => "Apport de l'exploitant → ".self::libelleCompte($this->compte_destination),
            'retrait' => "Retrait de l'exploitant ← ".self::libelleCompte($this->compte_source),
            'constat' => 'Constat de solde : '.self::libelleCompte($this->compte_destination),
            default => $this->type,
        };
    }
}

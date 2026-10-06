<?php

namespace App\Support;

use App\Models\Boutique;

/**
 * Mémorise la boutique de l'utilisateur connecté pour la durée de la requête.
 * Toutes les données « métier » sont filtrées sur cette boutique.
 */
class BoutiqueCourante
{
    private ?Boutique $boutique = null;

    public function definir(?Boutique $boutique): void
    {
        $this->boutique = $boutique;
    }

    public function get(): ?Boutique
    {
        return $this->boutique;
    }

    public function id(): ?int
    {
        return $this->boutique?->id;
    }
}

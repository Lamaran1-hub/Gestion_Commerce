<?php

namespace App\Exceptions;

use RuntimeException;

/** Erreur métier affichée telle quelle à l'utilisateur (stock insuffisant, montant invalide...). */
class OperationRefusee extends RuntimeException
{
}

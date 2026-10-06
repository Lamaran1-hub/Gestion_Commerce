<?php

namespace App\Services\Paiement;

use App\Exceptions\OperationRefusee;

/** Erreur du prestataire de paiement, affichée à l'utilisateur sans détail technique. */
class ErreurPaiement extends OperationRefusee
{
}

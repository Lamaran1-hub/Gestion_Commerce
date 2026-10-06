<?php

namespace App\Support;

use App\Models\Boutique;
use App\Models\PaiementLicence;

/** Messages prêts à envoyer aux clients (WhatsApp) : relance, confirmation de paiement, identifiants. */
class MessagesClient
{
    private static function signature(): string
    {
        return Plateforme::get('societe', config('app.name'))
            .(Plateforme::get('telephone') ? ' — '.Plateforme::get('telephone') : '');
    }

    private static function salutation(Boutique $b): string
    {
        return 'Bonjour'.($b->responsable_nom ? ' '.$b->responsable_nom : '').',';
    }

    /** Relance avant ou après l'échéance de la licence. */
    public static function relance(Boutique $b): string
    {
        $j = $b->joursRestants();
        $etat = match (true) {
            $b->statut === 'suspendu' => 'est actuellement suspendue',
            $j === null => 'est active',
            $j < 0 => 'a expiré le '.$b->abonnement_expire_le->format('d/m/Y').' : vos données sont conservées mais les ventes sont bloquées',
            $j === 0 => "expire aujourd'hui",
            default => 'expire le '.$b->abonnement_expire_le->format('d/m/Y').' (dans '.$j.' jour'.($j > 1 ? 's' : '').')',
        };

        return self::salutation($b)."\n\nLa licence du logiciel ".config('app.name')." de « {$b->nom} » {$etat}."
            .($b->plan ? "\nFormule {$b->plan->nom} : ".gnf($b->plan->prix_mensuel).' par mois.' : '')
            .(Plateforme::get('infos_paiement') ? "\n\nPour renouveler : ".Plateforme::get('infos_paiement') : "\n\nContactez-nous pour renouveler.")
            ."\n\nMerci de votre confiance.\n".self::signature();
    }

    /** Confirmation d'un paiement reçu. */
    public static function paiement(PaiementLicence $p): string
    {
        return self::salutation($p->boutique)."\n\nNous confirmons la réception de votre paiement de ".gnf($p->montant)
            ." (reçu {$p->numero}).\nVotre licence ".config('app.name').' est active '.$p->libellePeriode().'.'
            ."\n\nMerci de votre confiance.\n".self::signature();
    }

    /** Identifiants de connexion d'un nouvel utilisateur ou après réinitialisation. */
    public static function identifiants(string $nom, string $email, string $motDePasse): string
    {
        return "Bonjour {$nom},\n\nVoici vos accès au logiciel ".config('app.name')." :\nAdresse : ".url('/connexion')
            ."\nE-mail : {$email}\nMot de passe provisoire : {$motDePasse}\n\nVous pourrez le changer dans « Mon profil ».\n".self::signature();
    }
}

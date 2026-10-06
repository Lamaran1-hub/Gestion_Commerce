<?php

namespace App\Services\Paiement;

/**
 * Vérifie pas à pas l'intégration Djomy avant d'accepter de vrais paiements.
 * Chaque étape : [libellé, true (OK) | false (bloquant) | null (avertissement), détail].
 */
class DiagnosticDjomy
{
    public function __construct(private DjomyClient $djomy)
    {
    }

    /** @return array<int, array{0:string, 1:?bool, 2:string}> */
    public function executer(): array
    {
        $c = config('services.djomy');
        $etapes = [];

        $etapes[] = ['Mode', $c['mode'] === 'sandbox' ? null : true, $c['mode'] === 'sandbox'
            ? 'TEST (sandbox) : aucun argent réel ne circule. Passez DJOMY_MODE=production seulement après tous les essais.'
            : 'PRODUCTION : paiements réels.'];
        $etapes[] = ['Paiement en ligne activé', (bool) $c['actif'], $c['actif'] ? 'DJOMY_ACTIF=true' : 'Mettez DJOMY_ACTIF=true dans le fichier .env.'];
        $etapes[] = ['Adresse de l\'API', str_starts_with($c['url'], 'https://'), $c['url']];

        $numero = numero_guinee($c['numero_marchand']);
        $etapes[] = ['Numéro marchand (réception des fonds)', $numero ? true : false, $numero
            ? numero_affiche($numero).' — vérifiez dans votre espace marchand Djomy que le reversement se fait bien sur ce numéro.'
            : 'DJOMY_NUMERO_MARCHAND invalide.'];

        $moyens = \App\Support\MoyensDjomy::actifs();
        $etapes[] = ['Moyens de paiement proposés', $moyens ? true : false, $moyens
            ? implode(', ', array_map(fn ($m) => \App\Support\MoyensDjomy::libelle($m)." ({$m})", $moyens)).' — chacun doit être ouvert sur votre compte marchand Djomy.'
            : 'Aucun moyen actif : cochez-en au moins un dans « Ma société ».'];

        try {
            $this->djomy->testerAuthentification();
            $etapes[] = ['Identifiants (authentification)', true, 'Djomy a accepté vos identifiants et délivré un jeton d\'accès.'];
        } catch (ErreurPaiement $e) {
            $etapes[] = ['Identifiants (authentification)', false, $e->getMessage()];
        }

        $webhook = route('webhooks.djomy');
        $etapes[] = ['URL du webhook', str_starts_with($webhook, 'https://') ? true : null, str_starts_with($webhook, 'https://')
            ? "Déclarez {$webhook} dans l'espace développeur Djomy."
            : "{$webhook} n'est pas en HTTPS : Djomy ne l'appellera pas. En local, utilisez un tunnel HTTPS (ngrok) ; "
                .'les paiements restent confirmés par le retour navigateur et la vérification toutes les 5 minutes.'];

        $etapes[] = ['Planificateur (rattrapage)', null, 'Sur le serveur, la tâche cron « * * * * * php artisan schedule:run » doit être active.'];

        return $etapes;
    }

    public static function bloquant(array $etapes): bool
    {
        return collect($etapes)->contains(fn ($e) => $e[1] === false);
    }
}

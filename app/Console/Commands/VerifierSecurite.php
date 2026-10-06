<?php

namespace App\Console\Commands;

use App\Support\VerificationSecurite;
use Illuminate\Console\Command;

/** Contrôle de sécurité de la configuration, à lancer avant chaque mise en ligne. */
class VerifierSecurite extends Command
{
    protected $signature = 'securite:verifier';

    protected $description = 'Vérifie la configuration de sécurité (débogage, HTTPS, cookies, mots de passe connus, fichiers exposés…)';

    public function handle(): int
    {
        $controles = VerificationSecurite::controles();
        cache()->forget('securite.a_corriger');   // le tableau de bord du propriétaire repart du dernier état
        $symboles = ['ok' => '<fg=green>✓</>', 'attention' => '<fg=yellow>!</>', 'critique' => '<fg=red>✗</>'];
        foreach ($controles as $c) {
            $this->line(' '.$symboles[$c['niveau']].' '.$c['titre'].($c['conseil'] ? "\n     → ".$c['conseil'] : ''));
        }
        $critiques = count(array_filter($controles, fn ($c) => $c['niveau'] === 'critique'));
        $attentions = count(array_filter($controles, fn ($c) => $c['niveau'] === 'attention'));
        $this->newLine();
        if ($critiques) {
            $this->error("{$critiques} point(s) critique(s) à corriger avant d'ouvrir le site.");

            return self::FAILURE;
        }
        $attentions ? $this->warn("Aucun point critique ; {$attentions} recommandation(s).") : $this->info('Configuration de sécurité correcte.');

        return self::SUCCESS;
    }
}

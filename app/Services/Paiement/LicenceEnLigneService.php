<?php

namespace App\Services\Paiement;

use App\Exceptions\OperationRefusee;
use App\Models\Annonce;
use App\Models\Boutique;
use App\Models\CommandeLicence;
use App\Models\JournalActivite;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\LicenceActivee;
use App\Notifications\PaiementLicenceRecu;
use App\Support\MoyensDjomy;
use App\Support\Plateforme;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Achat de licence en ligne.
 *
 * Règles :
 * - le montant est calculé ici (prix de la formule × durée), jamais repris du navigateur ;
 * - une licence n'est activée qu'après un statut SUCCESS lu directement chez Djomy,
 *   jamais sur la seule foi d'un retour navigateur ou d'un webhook ;
 * - l'activation est idempotente : un paiement ne prolonge la licence qu'une seule fois ;
 * - un montant reçu inférieur au montant dû met la commande en anomalie (vérification manuelle).
 */
class LicenceEnLigneService
{
    public const DUREES = [1, 3, 6, 12];

    /** Une commande non payée au-delà de ce délai est considérée comme abandonnée. */
    public const EXPIRATION_HEURES = 24;

    public function __construct(private DjomyClient $djomy)
    {
    }

    public function disponible(): bool
    {
        return $this->djomy->estConfigure();
    }

    public static function montant(Plan $plan, int $mois): int
    {
        return (int) $plan->prix_mensuel * $mois;
    }

    /** Crée la commande et le paiement chez Djomy ; renvoie la commande avec l'URL de paiement. */
    public function commander(Boutique $boutique, Plan $plan, int $mois, string $numeroPayeur, User $acheteur, ?string $moyen = null): CommandeLicence
    {
        $actifs = MoyensDjomy::actifs();
        if (! $actifs) {
            throw new OperationRefusee("Aucun moyen de paiement en ligne n'est activé. Contactez l'éditeur.");
        }
        $moyen = MoyensDjomy::normaliser($moyen);
        if ($moyen && ! in_array($moyen, $actifs, true)) {
            throw new OperationRefusee("Ce moyen de paiement n'est pas proposé.");
        }
        if (! in_array($mois, self::DUREES, true)) {
            throw new OperationRefusee('Durée de licence non proposée.');
        }
        if (! $plan->actif) {
            throw new OperationRefusee("La formule {$plan->nom} n'est plus proposée.");
        }
        if ($depassements = $boutique->depassementsFormule($plan)) {
            throw new OperationRefusee("La formule {$plan->nom} est trop petite pour votre boutique : ".implode(' et ', $depassements)
                .'. Choisissez une formule supérieure.');
        }
        $montant = self::montant($plan, $mois);
        if ($montant <= 0) {
            throw new OperationRefusee("Cette formule est gratuite : contactez l'éditeur pour l'activer.");
        }

        $commande = CommandeLicence::create([
            'boutique_id' => $boutique->id, 'plan_id' => $plan->id, 'mois' => $mois, 'montant' => $montant,
            'numero_payeur' => $numeroPayeur, 'user_id' => $acheteur->id,
            'environnement' => config('services.djomy.mode'),
            'moyen' => $moyen, // null = le client choisit sur la page Djomy
        ]);

        try {
            $paiement = $this->djomy->creerPaiement(
                $montant,
                $numeroPayeur,
                $commande->reference,
                'Licence '.config('app.name')." {$plan->nom} — {$mois} mois — {$boutique->nom}",
                route('licence.retour', $commande->reference),
                route('licence.retour', ['commande' => $commande->reference, 'annule' => 1]),
                ['boutique' => $boutique->id, 'commande' => $commande->reference],
                $moyen ? [$moyen] : $actifs,
            );
        } catch (ErreurPaiement $e) {
            $commande->update(['statut' => 'echouee']);
            throw $e;
        }

        if (! $paiement['url']) {
            $commande->update(['statut' => 'echouee', 'reponse_fournisseur' => $paiement['brut']]);
            throw new ErreurPaiement("Le service de paiement n'a pas fourni de page de paiement. Réessayez plus tard.");
        }
        $commande->update([
            'transaction_id' => $paiement['transaction_id'],
            'url_paiement' => $paiement['url'],
            'statut_fournisseur' => $paiement['statut'],
            'reponse_fournisseur' => $paiement['brut'],
        ]);
        JournalActivite::noterPour($boutique->id, 'licence', "Commande de licence {$commande->reference} : {$plan->nom}, {$mois} mois, ".gnf($montant));

        return $commande;
    }

    /**
     * Relit le statut chez Djomy et fait avancer la commande. Sans danger à appeler plusieurs fois
     * (retour navigateur, webhook, tâche planifiée, bouton du propriétaire).
     */
    public function synchroniser(CommandeLicence $commande): CommandeLicence
    {
        if ($commande->estFinale() || $commande->statut === 'a_valider' || ! $commande->transaction_id) {
            return $this->expirerSiAbandonnee($commande);
        }
        // Une commande de test n'est jamais traitée en production (et inversement) :
        // ses identifiants de transaction n'existent pas dans l'autre environnement
        if ($commande->environnement !== config('services.djomy.mode')) {
            return $commande;
        }

        try {
            $etat = $this->djomy->statut($commande->transaction_id);
        } catch (ErreurPaiement $e) {
            Log::info('Vérification Djomy impossible pour le moment', ['commande' => $commande->reference, 'erreur' => $e->getMessage()]);

            return $commande;
        }

        return DB::transaction(function () use ($commande, $etat) {
            // Verrou : un seul processus traite la commande à la fois (webhook et retour peuvent arriver ensemble)
            $c = CommandeLicence::whereKey($commande->id)->lockForUpdate()->firstOrFail();
            if ($c->estFinale() || $c->statut === 'a_valider') {
                return $c;
            }
            $c->forceFill(['statut_fournisseur' => $etat['statut'], 'verifiee_le' => now(), 'reponse_fournisseur' => $etat['brut']]);

            match ($etat['statut']) {
                'SUCCESS' => $this->enregistrerSucces($c, $etat['montant'], $etat['moyen'] ?? null),
                'FAILED', 'TIMEOUT', 'REVOKED' => $c->forceFill(['statut' => 'echouee'])->save(),
                'CANCELLED' => $c->forceFill(['statut' => 'annulee'])->save(),
                default => $c->save(), // PENDING, CREATED… : on attend
            };

            return $this->expirerSiAbandonnee($c->fresh());
        });
    }

    /** Activation manuelle par le propriétaire (commande « à valider » ou en anomalie contrôlée). */
    public function activer(CommandeLicence $commande, User $proprietaire): CommandeLicence
    {
        return DB::transaction(function () use ($commande, $proprietaire) {
            $c = CommandeLicence::whereKey($commande->id)->lockForUpdate()->firstOrFail();
            if (! in_array($c->statut, ['a_valider', 'anomalie'], true)) {
                throw new OperationRefusee("Cette commande n'est pas en attente de validation.");
            }
            $this->activerLicence($c, $proprietaire);

            return $c->fresh();
        });
    }

    private function enregistrerSucces(CommandeLicence $c, ?int $montantRecu, ?string $moyenUtilise = null): void
    {
        $c->forceFill(['montant_recu' => $montantRecu, 'payee_le' => now(), 'moyen_utilise' => $moyenUtilise ?? $c->moyen]);

        // Contrôle anti-fraude : le montant encaissé doit couvrir le montant dû
        if ($montantRecu !== null && $montantRecu < $c->montant) {
            $c->forceFill(['statut' => 'anomalie'])->save();
            JournalActivite::noterPour($c->boutique_id, 'licence', "Paiement {$c->reference} en anomalie : reçu ".gnf($montantRecu).' pour '.gnf($c->montant).' dus');
            $this->notifierProprietaires($c);

            return;
        }

        if (Plateforme::get('activation_auto', '1') === '1') {
            $this->activerLicence($c, null);
        } else {
            $c->forceFill(['statut' => 'a_valider'])->save();
            JournalActivite::noterPour($c->boutique_id, 'licence', "Paiement en ligne {$c->reference} reçu : licence à valider par l'éditeur");
        }
        $this->notifierProprietaires($c->fresh());
    }

    private function activerLicence(CommandeLicence $c, ?User $proprietaire): void
    {
        $boutique = $c->boutique;
        $paiement = $boutique->enregistrerPaiementLicence([
            'plan_id' => $c->plan_id, 'mois' => $c->mois, 'montant' => $c->montant_recu ?? $c->montant,
            'mode' => $c->estTest() ? 'djomy_test' : 'djomy', 'reference' => $c->transaction_id, 'paye_le' => ($c->payee_le ?? now())->toDateString(),
            'note' => ($c->estTest() ? 'TEST (sandbox, aucun argent réel) — ' : '')."Paiement en ligne {$c->reference}"
                .(($c->moyen_utilise ?? $c->moyen) ? ' via '.MoyensDjomy::libelle($c->moyen_utilise ?? $c->moyen) : ''),
        ], $proprietaire);
        $c->forceFill(['statut' => 'payee', 'paiement_licence_id' => $paiement->id])->save();
        $boutique->refresh();

        JournalActivite::noterPour($boutique->id, 'licence', "Licence activée par paiement en ligne {$c->reference} ({$paiement->numero}) : ".$paiement->libellePeriode());

        // Le client est prévenu dans l'application (Nouveautés) et par e-mail
        Annonce::create([
            'titre' => 'Votre licence est activée',
            'contenu' => "Merci ! Votre paiement de ".gnf($paiement->montant)." a bien été reçu (reçu {$paiement->numero}).\n"
                ."Formule {$c->plan->nom} — licence active ".$paiement->libellePeriode().'.',
            'type' => 'info', 'boutique_id' => $boutique->id, 'publiee_le' => now(),
        ]);
        $admins = User::where('boutique_id', $boutique->id)->where('actif', true)->whereHas('role', fn ($q) => $q->where('systeme', true))->get();
        $this->envoyer($admins, new LicenceActivee($paiement));
    }

    private function expirerSiAbandonnee(CommandeLicence $c): CommandeLicence
    {
        if ($c->statut === 'en_attente' && $c->created_at->lt(now()->subHours(self::EXPIRATION_HEURES))) {
            $c->update(['statut' => 'expiree']);
        }

        return $c;
    }

    private function notifierProprietaires(CommandeLicence $c): void
    {
        $this->envoyer(User::where('est_super_admin', true)->where('actif', true)->get(), new PaiementLicenceRecu($c), 'proprietaire');
    }

    /**
     * Notification dans l'application d'abord (toujours), puis e-mail : l'échec d'un e-mail
     * (SMTP non configuré, coupure…) ne doit jamais bloquer l'activation d'une licence payée.
     */
    private function envoyer($destinataires, $notification, string $type = 'licence'): void
    {
        Notification::sendNow($destinataires, $notification, ['database']);
        \App\Support\Courrier::envoyer($destinataires, $notification, $type); // tracé, jamais bloquant
    }
}

<?php

use App\Support\BoutiqueCourante;
use App\Support\NombreEnLettres;

if (! function_exists('gnf')) {
    /** Formate un montant en francs guinéens : 1 250 000 GNF */
    function gnf(int|float|string|null $montant, bool $symbole = true): string
    {
        $texte = number_format((int) round((float) $montant), 0, ',', ' ');

        return $symbole ? $texte.' GNF' : $texte;
    }
}

if (! function_exists('qte')) {
    /** Affiche une quantité sans décimales inutiles : 12 ou 2,5 */
    function qte(int|float|string|null $valeur): string
    {
        $v = (float) $valeur;

        return fmod($v, 1.0) == 0.0 ? number_format($v, 0, ',', ' ') : rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',');
    }
}

if (! function_exists('montant_saisi')) {
    /** Convertit une saisie « 1 250 000 » ou « 1.250.000 » en entier. */
    function montant_saisi(mixed $valeur): int
    {
        return (int) preg_replace('/[^\d]/', '', (string) $valeur);
    }
}

if (! function_exists('montant_en_lettres')) {
    function montant_en_lettres(int $montant): string
    {
        return NombreEnLettres::convertir($montant).' franc'.($montant > 1 ? 's' : '').' guinéen'.($montant > 1 ? 's' : '');
    }
}

if (! function_exists('boutique')) {
    function boutique(): ?\App\Models\Boutique
    {
        return app(BoutiqueCourante::class)->get();
    }
}

if (! function_exists('boutique_affichee')) {
    /**
     * Boutique dont on affiche l'identité : celle de l'utilisateur connecté,
     * sinon la dernière boutique connectée sur cet appareil (page de connexion).
     */
    function boutique_affichee(): ?\App\Models\Boutique
    {
        if (auth()->check()) {
            return boutique() ?? auth()->user()->boutique;
        }
        $slug = request()->cookie('gn_boutique');
        if (is_string($slug) && $slug !== '' && ($b = \App\Models\Boutique::where('slug', $slug)->first())) {
            return $b;
        }

        // Installation avec une seule boutique : c'est forcément la sienne
        return \App\Models\Boutique::count() === 1 ? \App\Models\Boutique::first() : null;
    }
}

if (! function_exists('logo_plateforme')) {
    /** Logo du logiciel : celui téléversé par le propriétaire, sinon le logo générique. */
    function logo_plateforme(): string
    {
        $logo = \App\Support\Plateforme::get('logo');

        return $logo && \Illuminate\Support\Facades\Storage::disk('public')->exists($logo)
            ? asset('storage/'.$logo) : asset(config('gestion.logo'));
    }
}

if (! function_exists('choix_autre')) {
    /**
     * Valeur d'un <select data-autre> : si « Autre » est choisi et qu'une précision
     * a été saisie (champ {nom}_autre), c'est la précision qui est retenue.
     */
    function choix_autre(string $champ, string $valeurAutre = 'Autre'): ?string
    {
        $valeur = request($champ);
        $precision = trim((string) request($champ.'_autre'));

        return $valeur === $valeurAutre && $precision !== '' ? $precision : $valeur;
    }
}

if (! function_exists('reference_paiement')) {
    /** Référence d'un paiement ; pour le mode « Autre », la précision saisie la précède. */
    function reference_paiement(): ?string
    {
        $reference = trim((string) request('reference'));
        $precision = request('mode') === 'autre' ? trim((string) request('mode_autre')) : '';
        $texte = collect([$precision, $reference])->filter()->implode(' — ');

        return $texte !== '' ? mb_substr($texte, 0, 190) : null;
    }
}

if (! function_exists('fonction')) {
    /** La fonction est-elle disponible dans la formule de la boutique courante ? */
    function fonction(string $cle): bool
    {
        return (bool) boutique()?->aFonction($cle);
    }
}

if (! function_exists('libelle_mode')) {
    function libelle_mode(?string $mode): string
    {
        return config('gestion.modes_paiement')[$mode] ?? match ($mode) { 'fidelite' => 'Points de fidélité', 'avoir' => 'Avoir client', 'acompte' => 'Acompte déjà versé', 'carte_cadeau' => 'Carte cadeau', 'avoir_fournisseur' => 'Avoir fournisseur', default => (string) $mode };
    }
}

if (! function_exists('lien_whatsapp')) {
    /**
     * Lien WhatsApp avec message prérempli. Les numéros guinéens à 9 chiffres (6XX…)
     * reçoivent l'indicatif 224. Renvoie null sans numéro exploitable.
     */
    function lien_whatsapp(?string $telephone, string $message = ''): ?string
    {
        $chiffres = preg_replace('/\D/', '', (string) $telephone);
        if (strlen($chiffres) === 9 && str_starts_with($chiffres, '6')) {
            $chiffres = '224'.$chiffres;
        }
        if (strlen($chiffres) < 8) {
            return null;
        }

        return 'https://wa.me/'.$chiffres.($message !== '' ? '?text='.rawurlencode($message) : '');
    }
}

if (! function_exists('numero_guinee')) {
    /**
     * Normalise un numéro mobile guinéen au format international sans « + » (224 6XX XX XX XX).
     * Renvoie null si le numéro n'est pas un mobile guinéen valide.
     */
    function numero_guinee(?string $saisie): ?string
    {
        $chiffres = preg_replace('/\D/', '', (string) $saisie);
        if (str_starts_with($chiffres, '00224')) {
            $chiffres = substr($chiffres, 5);
        } elseif (str_starts_with($chiffres, '224') && strlen($chiffres) === 12) {
            $chiffres = substr($chiffres, 3);
        }

        return preg_match('/^6\d{8}$/', $chiffres) ? '224'.$chiffres : null;
    }
}

if (! function_exists('numero_local')) {
    /** Partie locale d'un numéro guinéen, pour un champ précédé de « +224 » : 622 00 00 00 */
    function numero_local(?string $numero): string
    {
        $n = numero_guinee($numero);

        return $n ? substr($n, 3, 3).' '.implode(' ', str_split(substr($n, 6), 2)) : (string) $numero;
    }
}

if (! function_exists('numero_affiche')) {
    /** Affiche un numéro guinéen lisiblement : +224 627 40 68 34 */
    function numero_affiche(?string $numero): string
    {
        $n = numero_guinee($numero);

        return $n ? '+224 '.substr($n, 3, 3).' '.implode(' ', str_split(substr($n, 6), 2)) : (string) $numero;
    }
}

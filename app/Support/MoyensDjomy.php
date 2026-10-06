<?php

namespace App\Support;

/**
 * Moyens de paiement Djomy en Guinée.
 *
 * Codes relevés dans la page de paiement officielle de Djomy (table des fournisseurs, champ providerCode) :
 * OM, MOMO, CARD (processeur NGENIUS), PAYCARD, KULU, SOUTRA_MONEY, YMO.
 * YMO figure dans l'API mais n'est pas encore proposé sur la page Djomy : présent, désactivé par défaut.
 */
class MoyensDjomy
{
    /**
     * code => [libellé, icône, description, couleur, sigle, mode local (config gestion.modes_paiement), actif par défaut]
     */
    public const CATALOGUE = [
        'OM' => ['Orange Money', 'phone', 'Mobile money Orange', '#FF7900', 'OM', 'orange_money', true],
        'MOMO' => ['MTN MoMo', 'phone', 'Mobile money MTN', '#FFCB05', 'MTN', 'mtn_momo', true],
        'CARD' => ['Carte bancaire', 'credit-card', 'Visa, Mastercard', '#1A1F71', 'CB', 'carte', true],
        'PAYCARD' => ['PayCard', 'wallet2', 'Portefeuille PayCard', '#0B5ED7', 'PC', 'paycard', true],
        'KULU' => ['Kulu', 'wallet2', 'Portefeuille Kulu', '#6F42C1', 'KU', 'kulu', true],
        'SOUTRA_MONEY' => ['Soutra Money', 'wallet2', 'Portefeuille Soutra Money', '#198754', 'SM', 'soutra_money', true],
        'YMO' => ['YMO', 'wallet2', 'Nouveau moyen Djomy (bientôt)', '#6C757D', 'YMO', 'autre', false],
    ];

    /** Alias renvoyés par Djomy pour un même moyen. */
    private const ALIAS = ['NGENIUS' => 'CARD', 'ORANGE_MONEY' => 'OM', 'OM_GN' => 'OM', 'MTN' => 'MOMO', 'MTN_MOMO' => 'MOMO',
        'MTN_MOMO_GN' => 'MOMO', 'SOUTRA' => 'SOUTRA_MONEY'];

    /** Codes activés par défaut (avant tout réglage du propriétaire). */
    public static function parDefaut(): array
    {
        return array_keys(array_filter(self::CATALOGUE, fn ($m) => $m[6]));
    }

    /** Moyens activés, dans l'ordre du catalogue. */
    public static function actifs(): array
    {
        $choix = Plateforme::get('djomy_moyens');
        $codes = $choix !== null ? explode(',', $choix) : ((array) config('services.djomy.moyens') ?: self::parDefaut());
        $codes = array_map(fn ($c) => self::normaliser($c), $codes);

        return array_values(array_filter(array_keys(self::CATALOGUE), fn ($c) => in_array($c, $codes, true)));
    }

    public static function normaliser(?string $code): ?string
    {
        $c = strtoupper(trim((string) $code));

        return self::ALIAS[$c] ?? ($c !== '' ? $c : null);
    }

    public static function libelle(?string $code): string
    {
        $c = self::normaliser($code);

        return self::CATALOGUE[$c][0] ?? ($c ?: 'Djomy');
    }

    public static function icone(string $code): string
    {
        return self::CATALOGUE[$code][1] ?? 'wallet2';
    }

    public static function description(string $code): string
    {
        return self::CATALOGUE[$code][2] ?? '';
    }

    public static function couleur(string $code): string
    {
        return self::CATALOGUE[$code][3] ?? '#6C757D';
    }

    public static function sigle(string $code): string
    {
        return self::CATALOGUE[$code][4] ?? $code;
    }

    /** Mode de paiement local correspondant (caisse, rapports) : OM → orange_money… */
    public static function modeLocal(?string $code): string
    {
        return self::CATALOGUE[self::normaliser($code)][5] ?? 'autre';
    }
}

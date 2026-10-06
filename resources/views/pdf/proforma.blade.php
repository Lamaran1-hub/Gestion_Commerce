<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 18mm 15mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1C2622; }
    .titre { font-size: 20px; font-weight: bold; color: {{ $boutique->couleur }}; }
    table { width: 100%; border-collapse: collapse; }
    .lignes th { background: {{ $boutique->couleur }}; color: {{ \App\Models\Boutique::texteSur($boutique->couleur) }}; padding: 6px; text-align: left; font-size: 10px; }
    .lignes td { padding: 6px; border-bottom: 1px solid #DCE3DD; }
    .r, .lignes th.r { text-align: right; } .b { font-weight: bold; } .doux { color: #5E6B65; }
    .totaux td { padding: 4px 6px; }
    .bande { background: {{ \App\Models\Boutique::versionSombre($boutique->couleurSecondaire()) }}; color: #fff; }
    .cadre { border: 1px solid #DCE3DD; border-left: 3px solid {{ $boutique->couleurAccent() }}; padding: 8px 10px; }
    .avis { border: 1px dashed #B7790B; background: #FBF1DC; padding: 6px 10px; margin-top: 14px; font-size: 9.5px; }
    .pied { position: fixed; bottom: -8mm; left: 0; right: 0; text-align: center; font-size: 9px; color: #5E6B65; }
</style>
</head>
<body>
    @component('pdf.partials.entete', ['boutique' => $boutique])
        <div class="titre">FACTURE PROFORMA</div>
        <div class="b" style="font-size:12px">N° {{ $d->numero }}</div>
        <div>Date : {{ $d->date_devis->format('d/m/Y') }}</div>
        <div class="b">Valable jusqu'au {{ $d->valable_jusqu_au->format('d/m/Y') }}</div>
    @endcomponent

    <table style="margin:14px 0"><tr><td style="width:55%"></td>
        <td class="cadre"><div class="doux">Établie pour</div><div class="b">{{ $d->nomClient() }}</div>
            @if ($d->client?->telephone)<div>{{ $d->client->telephone }}</div>@endif
            @if ($d->client?->residence())<div>{{ $d->client->residence() }}</div>@endif</td></tr></table>

    <table class="lignes">
        <thead><tr><th>Désignation</th><th class="r">Quantité</th><th class="r">Prix unitaire</th><th class="r">Montant</th></tr></thead>
        <tbody>
        @foreach ($d->lignes as $l)
            <tr><td>{{ $l->designation }}</td><td class="r">{{ qte($l->quantite) }}</td><td class="r">{{ gnf($l->prix_unitaire) }}</td><td class="r">{{ gnf($l->total) }}</td></tr>
        @endforeach
        </tbody>
    </table>

    <table style="margin-top:10px"><tr>
        <td style="width:55%;vertical-align:top;padding-top:6px">
            <div class="doux">Arrêtée la présente proforma à la somme de :</div>
            <div class="b">{{ ucfirst(montant_en_lettres($d->total_ttc)) }}.</div>
        </td>
        <td><table class="totaux">
            <tr><td>Sous-total</td><td class="r">{{ gnf($d->sousTotal()) }}</td></tr>
            @if ($d->remise)<tr><td>Remise</td><td class="r">− {{ gnf($d->remise) }}</td></tr>@endif
            @if ($d->total_tva || collect($d->ventilationTva())->count() > 1)
                @foreach ($d->ventilationTva() as $v)
                    <tr><td>{{ $v['taux'] > 0 ? $d->libelleTva($v['taux']) : 'Exonéré de TVA' }} (base {{ gnf($v['base']) }})</td>
                        <td class="r">{{ $v['taux'] > 0 ? gnf($v['tva']) : '—' }}</td></tr>
                @endforeach
            @endif
            <tr class="bande b"><td>TOTAL</td><td class="r">{{ gnf($d->total_ttc) }}</td></tr>
            @if ($d->acompte)
                <tr><td>Acompte déjà versé</td><td class="r">− {{ gnf($d->acompte) }}</td></tr>
                <tr class="b"><td>Reste à payer</td><td class="r">{{ gnf($d->resteAPayer()) }}</td></tr>
            @endif
        </table></td>
    </tr></table>

    <div class="avis">Ce document est une <strong>facture proforma</strong> : il ne constitue ni une facture ni un reçu de paiement.
        Les prix sont garantis jusqu'au {{ $d->valable_jusqu_au->format('d/m/Y') }}, dans la limite du stock disponible.</div>

    <div class="pied">@if ($boutique->pied_facture){{ $boutique->pied_facture }}<br>@endif{{ $boutique->nom }} · {{ implode(' · ', $boutique->coordonnees()) }}<br>Document généré par {{ config('app.name') }}</div>
</body>
</html>

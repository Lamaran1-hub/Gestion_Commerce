<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 18mm 15mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1C2622; }
    .entete td { vertical-align: top; }
    .titre { font-size: 22px; font-weight: bold; color: {{ $boutique->couleur }}; }
    @php($fondTotal = \App\Models\Boutique::versionSombre($boutique->couleurSecondaire()))
    .bande { background: {{ $fondTotal }}; color: #fff; }
    table { width: 100%; border-collapse: collapse; }
    .lignes th { background: {{ $boutique->couleur }}; color: {{ \App\Models\Boutique::texteSur($boutique->couleur) }}; padding: 6px; text-align: left; font-size: 10px; }
    .lignes td { padding: 6px; border-bottom: 1px solid #DCE3DD; }
    .r, .lignes th.r { text-align: right; } .b { font-weight: bold; } .doux { color: #5E6B65; }
    .totaux td { padding: 4px 6px; }
    .cadre { border: 1px solid #DCE3DD; border-left: 3px solid {{ $boutique->couleurAccent() }}; padding: 8px 10px; }
    .pied { position: fixed; bottom: -8mm; left: 0; right: 0; text-align: center; font-size: 9px; color: #5E6B65; }
    .annulee { color: #B23A2E; font-size: 16px; font-weight: bold; border: 2px solid #B23A2E; padding: 4px 10px; display: inline-block; }
</style>
</head>
<body>
    @component('pdf.partials.entete', ['boutique' => $boutique])
        <div class="titre">FACTURE</div>
        <div class="b" style="font-size:12px">N° {{ $vente->numero }}</div>
        <div>Date : {{ $vente->date_vente->format('d/m/Y') }}</div>
        @if ($vente->statut === 'annulee')<div style="margin-top:6px"><span class="annulee">ANNULÉE</span></div>@endif
    @endcomponent

    <table style="margin:18px 0">
        <tr><td style="width:55%"></td>
            <td class="cadre">
                <div class="doux">Facturé à</div>
                <div class="b">{{ $vente->client?->nomComplet() ?? 'Client comptoir' }}</div>
                @if ($vente->client?->telephone)<div>{{ $vente->client->telephone }}</div>@endif
                @if ($vente->client?->residence())<div>{{ $vente->client->residence() }}</div>@endif
            </td>
        </tr>
    </table>

    <table class="lignes">
        <thead><tr><th>Désignation</th><th class="r">Quantité</th><th class="r">Prix unitaire</th><th class="r">Montant</th></tr></thead>
        <tbody>
        @foreach ($vente->lignes as $l)
            <tr><td>{{ $l->designation }}@if ($l->numerosSerie->isNotEmpty())<br><span class="doux" style="font-size:9px">N° série : {{ $l->numerosSerie->pluck('numero')->implode(', ') }}</span>@endif
                @if ($l->garantieJusquau())<br><span class="doux" style="font-size:9px">Garantie {{ $l->garantie_mois }} mois, jusqu'au {{ $l->garantieJusquau()->format('d/m/Y') }}</span>@endif</td><td class="r">{{ qte($l->quantite) }}</td><td class="r">{{ gnf($l->prix_unitaire) }}</td><td class="r">{{ gnf($l->total) }}</td></tr>
        @endforeach
        </tbody>
    </table>

    <table style="margin-top:10px">
        <tr>
            <td style="width:55%;vertical-align:top;padding-top:6px">
                <div class="doux">Arrêtée la présente facture à la somme de :</div>
                <div class="b">{{ ucfirst(montant_en_lettres($vente->total_ttc)) }}.</div>
            </td>
            <td>
                <table class="totaux">
                    @if ($vente->montant_retourne)
                        <tr><td class="doux">Montant initial</td><td class="r doux">{{ gnf($vente->totalInitial()) }}</td></tr>
                        <tr><td class="doux">Retours (avoirs)</td><td class="r doux">− {{ gnf($vente->montant_retourne) }}</td></tr>
                    @endif
                    <tr><td>Sous-total{{ $vente->montant_retourne ? ' (après retours)' : '' }}</td><td class="r">{{ gnf($vente->sousTotal()) }}</td></tr>
                    @if ($vente->remise)<tr><td>Remise</td><td class="r">− {{ gnf($vente->remise) }}</td></tr>@endif
                    @if ($vente->total_tva || collect($vente->ventilationTva())->count() > 1)
                        @foreach ($vente->ventilationTva() as $v)
                            <tr><td>{{ $v['taux'] > 0 ? $vente->libelleTva($v['taux']) : 'Exonéré de TVA' }} <span class="doux">(base {{ gnf($v['base']) }})</span></td>
                                <td class="r">{{ $v['taux'] > 0 ? gnf($v['tva']) : '—' }}</td></tr>
                        @endforeach
                    @endif
                    <tr class="bande b"><td>TOTAL</td><td class="r">{{ gnf($vente->total_ttc) }}</td></tr>
                    <tr><td>Déjà payé</td><td class="r">{{ gnf($vente->montant_paye) }}</td></tr>
                    <tr class="b"><td>Reste à payer</td><td class="r">{{ gnf($vente->resteAPayer()) }}</td></tr>
                    @if ($vente->echeance)<tr><td>À payer avant le</td><td class="r">{{ $vente->echeance->format('d/m/Y') }}</td></tr>@endif
                </table>
            </td>
        </tr>
    </table>

    @if ($vente->paiements->isNotEmpty())
        <div style="margin-top:14px" class="doux">Paiements reçus :
            {{ $vente->paiements->map(fn ($p) => $p->date_paiement->format('d/m/Y').' — '.$p->libelleMode().' — '.gnf($p->montant))->implode(' ; ') }}
        </div>
    @endif

    <div class="pied">{{ $boutique->pied_facture }}<br>{{ $boutique->nom }} · {{ implode(' · ', $boutique->coordonnees()) }}<br>Document généré par {{ config('app.name') }}</div>
</body>
</html>

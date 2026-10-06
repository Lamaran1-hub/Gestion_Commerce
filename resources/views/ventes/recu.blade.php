<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="darkreader-lock">
<title>Reçu {{ $vente->numero }} · {{ $boutique->nom }}</title>
<link rel="icon" href="{{ $boutique->logoUrl() ?? logo_plateforme() }}">
<style>
    /* Ticket pour imprimante thermique 80 mm */
    @page { size: 80mm auto; margin: 3mm; }
    body { font-family: "DejaVu Sans Mono", "Courier New", monospace; font-size: 12px; width: 72mm; margin: 0 auto; color: #000; }
    .c { text-align: center; } .r { text-align: right; } .b { font-weight: bold; }
    .sep { border-top: 1px dashed #000; margin: 6px 0; }
    table { width: 100%; border-collapse: collapse; } td { vertical-align: top; padding: 1px 0; }
    img { max-width: 40mm; max-height: 22mm; }
    .actions { margin: 12px 0; text-align: center; font-family: sans-serif; }
    @media print { .actions { display: none; } }
</style>
</head>
<body>
    <div class="actions"><button onclick="window.print()">Imprimer</button> <button onclick="window.close()">Fermer</button></div>
    <div class="c">
        @if ($boutique->logoUrl())<img src="{{ $boutique->logoUrl() }}" alt=""><br>@endif
        <div class="b" style="font-size:14px">{{ $boutique->nom }}</div>
        @if ($boutique->adresse || $boutique->ville)<div>{{ collect([$boutique->adresse, $boutique->ville])->filter()->implode(', ') }}</div>@endif
        @if ($boutique->telephone)<div>Tél : {{ $boutique->telephone }}</div>@endif
        @if ($boutique->email)<div>{{ $boutique->email }}</div>@endif
        @if ($boutique->rccm || $boutique->nif)<div>{{ collect([$boutique->rccm ? 'RCCM : '.$boutique->rccm : null, $boutique->nif ? 'NIF : '.$boutique->nif : null])->filter()->implode(' · ') }}</div>@endif
    </div>
    <div class="sep"></div>
    <div>Reçu : <span class="b">{{ $vente->numero }}</span></div>
    <div>Date : {{ $vente->date_vente->format('d/m/Y H:i') }}</div>
    <div>Client : {{ $vente->client?->nomComplet() ?? 'Comptoir' }}</div>
    <div>Vendeur : {{ $vente->vendeur?->prenom }}</div>
    <div class="sep"></div>
    <table>
        @foreach ($vente->lignes as $l)
            <tr><td colspan="2">{{ $l->designation }}</td></tr>
            <tr><td>{{ qte($l->quantite) }} x {{ gnf($l->prix_unitaire, false) }}</td><td class="r">{{ gnf($l->total, false) }}</td></tr>
            @if ($l->numerosSerie->isNotEmpty())<tr><td colspan="2" style="font-size:11px">N° série : {{ $l->numerosSerie->pluck('numero')->implode(', ') }}</td></tr>@endif
            @if ($l->garantieJusquau())<tr><td colspan="2" style="font-size:11px">Garantie {{ $l->garantie_mois }} mois, jusqu'au {{ $l->garantieJusquau()->format('d/m/Y') }}</td></tr>@endif
        @endforeach
    </table>
    <div class="sep"></div>
    <table>
        @if ($vente->remise)<tr><td>Remise</td><td class="r">-{{ gnf($vente->remise, false) }}</td></tr>@endif
        @if ($vente->total_tva || collect($vente->ventilationTva())->count() > 1)
            @foreach ($vente->ventilationTva() as $v)
                <tr><td>{{ $v['taux'] > 0 ? $vente->libelleTva($v['taux']) : 'Exonéré' }}</td><td class="r">{{ $v['taux'] > 0 ? gnf($v['tva'], false) : gnf($v['base'], false).' HT' }}</td></tr>
            @endforeach
        @endif
        <tr class="b" style="font-size:14px"><td>TOTAL</td><td class="r">{{ gnf($vente->total_ttc) }}</td></tr>
        @foreach ($vente->paiements as $p)<tr><td>{{ $p->libelleMode() }}</td><td class="r">{{ gnf($p->montant, false) }}</td></tr>@endforeach
        @if ($vente->resteAPayer())<tr class="b"><td>Reste à payer</td><td class="r">{{ gnf($vente->resteAPayer()) }}</td></tr>
            @if ($vente->echeance)<tr><td>À payer avant le</td><td class="r">{{ $vente->echeance->format('d/m/Y') }}</td></tr>@endif
        @endif
    </table>
    @if ($vente->client && ($boutique->fidelite_taux > 0 || $vente->client->points))
        @php($gagnes = (int) \App\Models\PointFidelite::where('vente_id', $vente->id)->where('points', '>', 0)->where('motif', 'Points gagnés')->sum('points'))
        <div class="sep"></div>
        <div class="c">Fidélité : {{ $gagnes ? '+'.number_format($gagnes, 0, ',', ' ').' points · ' : '' }}solde {{ number_format($vente->client->points, 0, ',', ' ') }} points</div>
    @endif
    @if ($vente->statut === 'annulee')<div class="sep"></div><div class="c b">*** VENTE ANNULÉE ***</div>@endif
    <div class="sep"></div>
    <div class="c">{{ $boutique->pied_facture ?: 'Merci de votre visite !' }}</div>
    @if ($vente->empreinte)<div class="c" style="font-size:9px;margin-top:4px">Empreinte : {{ strtoupper(substr($vente->empreinte, 0, 16)) }}</div>@endif
    <script>if (!location.search.includes('apercu')) window.addEventListener('load', () => window.print());</script>
</body>
</html>

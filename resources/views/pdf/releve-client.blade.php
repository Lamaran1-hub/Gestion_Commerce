<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 18mm 15mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1C2622; }
    .titre { font-size: 18px; font-weight: bold; color: {{ $boutique->couleur }}; }
    table { width: 100%; border-collapse: collapse; }
    .lignes th { background: {{ $boutique->couleur }}; color: {{ \App\Models\Boutique::texteSur($boutique->couleur) }}; padding: 5px; text-align: left; font-size: 9.5px; }
    .lignes td { padding: 5px; border-bottom: 1px solid #DCE3DD; }
    .r, .lignes th.r { text-align: right; } .b { font-weight: bold; } .doux { color: #5E6B65; } .rouge { color: #B42318; }
    .cadre { border: 1px solid #DCE3DD; border-left: 3px solid {{ $boutique->couleurAccent() }}; padding: 8px 10px; }
    .solde { font-size: 14px; font-weight: bold; }
</style>
</head>
<body>
    @component('pdf.partials.entete', ['boutique' => $boutique])
        <div class="titre">RELEVÉ DE COMPTE</div>
        <div>Du {{ $du->format('d/m/Y') }} au {{ $au->format('d/m/Y') }}</div>
        <div class="doux">Édité le {{ now()->format('d/m/Y à H:i') }}</div>
    @endcomponent
    <table style="margin:12px 0"><tr><td style="width:55%"></td>
        <td class="cadre"><div class="doux">Client {{ $client->code }}</div><div class="b">{{ $client->nomComplet() }}</div>
            @if ($client->telephone)<div>{{ $client->telephone }}</div>@endif
            @if ($client->residence())<div>{{ $client->residence() }}</div>@endif</td></tr></table>
    <table class="lignes">
        <thead><tr><th style="width:15%">Date</th><th>Opération</th><th class="r">Débit</th><th class="r">Crédit</th><th class="r">Solde</th></tr></thead>
        <tbody>
            <tr><td>{{ $du->format('d/m/Y') }}</td><td class="b">Solde au début de la période</td><td></td><td></td><td class="r b">{{ gnf($solde_initial) }}</td></tr>
            @foreach ($lignes as $l)
                <tr><td>{{ $l['date']->format('d/m/Y') }}</td><td>{{ $l['libelle'] }}</td>
                    <td class="r">{{ $l['debit'] ? gnf($l['debit']) : '' }}</td><td class="r">{{ $l['credit'] ? gnf($l['credit']) : '' }}</td>
                    <td class="r">{{ gnf($l['solde']) }}</td></tr>
            @endforeach
        </tbody>
        <tfoot><tr><td></td><td class="r b">Totaux de la période</td><td class="r b">{{ gnf($total_debit) }}</td><td class="r b">{{ gnf($total_credit) }}</td><td></td></tr></tfoot>
    </table>
    <table style="margin-top:14px"><tr><td style="width:55%" class="doux">Débit : achats (et remboursements reçus). Crédit : versements et marchandises retournées.</td>
        <td class="cadre r"><div class="doux">{{ $solde_final > 0 ? 'Reste à payer au '.$au->format('d/m/Y') : 'Solde au '.$au->format('d/m/Y') }}</div>
            <div class="solde {{ $solde_final > 0 ? 'rouge' : '' }}">{{ gnf($solde_final) }}</div></td></tr></table>
    <p class="doux" style="margin-top:16px">Merci de nous signaler toute différence dans les 8 jours. {{ $boutique->pied_facture }}</p>
</body>
</html>

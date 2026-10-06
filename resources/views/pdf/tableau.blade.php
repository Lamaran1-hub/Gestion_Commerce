<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 14mm 12mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1C2622; }
    h1 { font-size: 16px; margin: 0; color: {{ $boutique->couleur }}; }
    table { width: 100%; border-collapse: collapse; margin-top: 12px; }
    th { background: {{ $boutique->couleur }}; color: {{ \App\Models\Boutique::texteSur($boutique->couleur) }}; text-align: left; padding: 5px; font-size: 9px; }
    tbody tr:nth-child(even) td { background: #F7F9F7; }
    td { padding: 4px 5px; border-bottom: 1px solid #E3E8E4; }
    .r, th.r { text-align: right; } .doux { color: #5E6B65; }
    tfoot td { font-weight: bold; border-top: 2px solid {{ $boutique->couleurAccent() }}; }
    .pied { position: fixed; bottom: -8mm; left: 0; right: 0; text-align: center; font-size: 8px; color: #5E6B65; }
</style>
</head>
<body>
    @component('pdf.partials.entete', ['boutique' => $boutique])
        <div style="color:#5E6B65;font-size:9px">Édité le {{ now()->format('d/m/Y à H:i') }}</div>
        @if (auth()->user())<div style="color:#5E6B65;font-size:9px">par {{ auth()->user()->nomComplet() }}</div>@endif
    @endcomponent
    <h1>{{ $titre }}</h1>
    @if ($sousTitre)<div class="doux">{{ $sousTitre }}</div>@endif
    <table>
        <thead><tr>@foreach ($colonnes as $cle => $lib)<th class="{{ in_array($cle, $montants) ? 'r' : '' }}">{{ $lib }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse ($lignes as $l)
            <tr>@foreach ($colonnes as $cle => $lib)
                <td class="{{ in_array($cle, $montants) || is_float($l[$cle] ?? null) ? 'r' : '' }}">
                    {{ in_array($cle, $montants) ? gnf($l[$cle] ?? 0) : (is_float($l[$cle] ?? null) ? qte($l[$cle]) : ($l[$cle] ?? '')) }}</td>
            @endforeach</tr>
        @empty
            <tr><td colspan="{{ count($colonnes) }}" class="doux">Aucune donnée sur cette période.</td></tr>
        @endforelse
        </tbody>
        @if ($totaux)
            <tfoot><tr>@foreach ($colonnes as $cle => $lib)
                <td class="r">{{ $loop->first ? 'Total' : (isset($totaux[$cle]) ? gnf($totaux[$cle]) : '') }}</td>
            @endforeach</tr></tfoot>
        @endif
    </table>
    <div class="pied">{{ $boutique->nom }}{{ $boutique->telephone ? ' · '.$boutique->telephone : '' }} — Document généré par {{ config('app.name') }}</div>
</body>
</html>

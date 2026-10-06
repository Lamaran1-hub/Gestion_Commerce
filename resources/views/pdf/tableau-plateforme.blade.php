@php($ed = \App\Support\Plateforme::tout())
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 14mm 12mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1C2622; }
    h1 { font-size: 16px; margin: 8px 0 0; color: #1F6F54; }
    table { width: 100%; border-collapse: collapse; margin-top: 12px; }
    th { background: #1F6F54; color: #fff; text-align: left; padding: 5px; font-size: 9px; }
    td { padding: 4px 5px; border-bottom: 1px solid #E3E8E4; }
    .r, th.r { text-align: right; } .doux { color: #5E6B65; }
    tfoot td { font-weight: bold; border-top: 2px solid #1C2622; }
</style>
</head>
<body>
    <div style="font-weight:bold;font-size:14px">{{ $ed['societe'] ?? config('app.name') }}</div>
    <div class="doux">{{ collect([$ed['adresse'] ?? null, $ed['telephone'] ?? null, $ed['email'] ?? null])->filter()->implode(' · ') }}</div>
    <h1>{{ $titre }}</h1>
    <div class="doux">{{ $sousTitre }} — édité le {{ now()->format('d/m/Y à H:i') }}</div>
    <table>
        <thead><tr>@foreach ($colonnes as $cle => $lib)<th class="{{ in_array($cle, $montants) ? 'r' : '' }}">{{ $lib }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse ($lignes as $l)
            <tr>@foreach ($colonnes as $cle => $lib)<td class="{{ in_array($cle, $montants) ? 'r' : '' }}">{{ in_array($cle, $montants) ? gnf($l[$cle] ?? 0) : ($l[$cle] ?? '') }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ count($colonnes) }}" class="doux">Aucune donnée sur cette période.</td></tr>
        @endforelse
        </tbody>
        @if ($totaux)
            <tfoot><tr>@foreach ($colonnes as $cle => $lib)<td class="r">{{ $loop->first ? 'Total' : (isset($totaux[$cle]) ? gnf($totaux[$cle]) : '') }}</td>@endforeach</tr></tfoot>
        @endif
    </table>
</body>
</html>

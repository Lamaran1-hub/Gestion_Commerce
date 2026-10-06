@extends('layouts.app')
@section('titre', 'Garanties')
@section('contenu')
    <div class="entete-page">
        <div><h1>Garanties et numéros de série</h1>
            <div class="text-doux">Un client revient avec un appareil : retrouvez la vente et vérifiez la garantie.</div></div>
    </div>
    <form method="get" class="bloc bloc-corps mb-3 d-flex gap-2" role="search">
        <input type="search" name="q" value="{{ $q }}" autofocus class="form-control" minlength="3"
               placeholder="Numéro de série / IMEI (les 6 derniers chiffres suffisent), nom ou téléphone du client" aria-label="Rechercher">
        <button class="btn btn-primary text-nowrap"><i class="bi bi-search"></i><span class="d-none d-sm-inline ms-1">Rechercher</span></button>
    </form>
    @if (mb_strlen($q) >= 3)
        <div class="bloc">
            <table class="table mb-0">
                <thead><tr><th>Article</th><th>Vente</th><th class="d-none d-md-table-cell">Client</th><th>Garantie</th></tr></thead>
                <tbody>
                @forelse ($resultats as $r)
                    @php
                        $l = $r['ligne']; $v = $r['vente']; $fin = $l?->garantieJusquau();
                    @endphp
                    <tr>
                        <td class="fw-semibold">{{ $l?->designation }}@if ($r['numero'])<div class="small font-monospace text-doux text-break">{{ $r['numero'] }}</div>@endif</td>
                        <td><a href="{{ route('ventes.show', $v) }}#series">{{ $v->numero }}</a><div class="small text-doux">{{ $v->date_vente->format('d/m/Y') }}<span class="d-md-none"> · {{ $v->client?->nomComplet() ?? 'comptoir' }}</span></div>
                            @if ($v->statut === 'annulee')<span class="etat etat-neutre">Annulée</span>@endif</td>
                        <td class="d-none d-md-table-cell">{{ $v->client?->nomComplet() ?? 'Client comptoir' }}@if ($v->client?->telephone)<div class="small text-doux">{{ numero_affiche($v->client->telephone) }}</div>@endif</td>
                        <td>
                            @if ($r['rapporte'] ?? null)<span class="etat etat-neutre">Rapporté ({{ $r['rapporte'] }})</span><div class="small text-doux">revendable</div>
                            @elseif ($v->statut === 'annulee')<span class="text-doux">—</span>
                            @elseif (! $fin)<span class="etat etat-neutre">Sans garantie</span>
                            @elseif ($fin->isPast())<span class="etat etat-rupture">Expirée le {{ $fin->format('d/m/Y') }}</span>
                            @else<span class="etat etat-ok">Garanti jusqu'au {{ $fin->format('d/m/Y') }}</span><div class="small text-doux">encore {{ (int) now()->startOfDay()->diffInDays($fin) }} jour(s)</div>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="vide"><i class="bi bi-search"></i>Aucun article trouvé pour « {{ $q }} ».</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif
@endsection

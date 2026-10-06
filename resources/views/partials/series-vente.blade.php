{{-- Garantie et numéros de série des articles de la vente --}}
@php
    $lignesSerie = $vente->lignes->filter(fn ($l) => $l->nombreSeriesAttendues() > 0 || $l->garantie_mois);
    $manquants = app(\App\Services\NumerosSerie::class)->manquants($vente);
@endphp
@if ($lignesSerie->isNotEmpty())
    <div class="bloc mt-3" id="series">
        <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-shield-check me-1"></i>Garantie et numéros de série</h2>
            @if ($manquants && $vente->statut === 'validee')<span class="etat etat-alerte">{{ $manquants }} numéro(s) à saisir</span>@endif</div>
        @php($peutSaisir = $vente->statut === 'validee' && auth()->user()->can('ventes.creer'))
        <form method="post" action="{{ route('ventes.series', $vente) }}" class="bloc-corps" data-sans-confirmation>
            @csrf
            @foreach ($lignesSerie as $l)
                <div class="mb-3">
                    <div class="fw-semibold">{{ $l->designation }}
                        @if ($l->garantieJusquau())
                            <span class="small {{ $l->garantieJusquau()->isPast() ? 'text-danger' : 'text-success' }}">· garantie {{ $l->garantie_mois }} mois, jusqu'au {{ $l->garantieJusquau()->format('d/m/Y') }}{{ $l->garantieJusquau()->isPast() ? ' (expirée)' : '' }}</span>
                        @endif</div>
                    @if ($l->nombreSeriesAttendues() > 0)
                        <div class="row g-2 mt-1">
                            @for ($i = 0; $i < min($l->nombreSeriesAttendues(), 50); $i++)
                                <div class="col-sm-6 col-lg-4"><input name="series[{{ $l->id }}][]" value="{{ old('series.'.$l->id.'.'.$i, $l->numerosSerie[$i]->numero ?? '') }}"
                                    maxlength="60" autocomplete="off" class="form-control form-control-sm font-monospace" placeholder="N° de série / IMEI {{ $i + 1 }}"
                                    aria-label="Numéro de série {{ $i + 1 }} de {{ $l->designation }}" @disabled(! $peutSaisir)></div>
                            @endfor
                        </div>
                    @endif
                </div>
            @endforeach
            @php($rapportes = \App\Models\NumeroSerie::with('retour:id,numero')->where('vente_id', $vente->id)->whereNotNull('retour_id')->get())
            @if ($rapportes->isNotEmpty())
                <div class="small text-doux mb-2"><i class="bi bi-arrow-return-left"></i> Rapportés :
                    {{ $rapportes->map(fn ($n) => $n->numero.' ('.$n->retour?->numero.')')->implode(', ') }}</div>
            @endif
            @if ($peutSaisir && $lignesSerie->contains(fn ($l) => $l->nombreSeriesAttendues() > 0))
                <button class="btn btn-sm btn-primary"><i class="bi bi-upc-scan me-1"></i>Enregistrer les numéros</button>
                <span class="small text-doux ms-2">Astuce : une douchette code-barres remplit le champ directement.</span>
            @endif
        </form>
    </div>
@endif

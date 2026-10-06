@extends('layouts.app')
@section('titre', 'Rapport Z du '.$c->jour->format('d/m/Y'))
@section('contenu')
    @php($modes = config('gestion.modes_paiement') + ['fidelite' => 'Points de fidélité'])
    <div class="entete-page no-print">
        <h1>Rapport Z — {{ $c->jour->format('d/m/Y') }}</h1>
        <div class="d-flex gap-2"><button onclick="window.print()" class="btn btn-outline-primary"><i class="bi bi-printer me-1"></i>Imprimer</button>
            <a href="{{ route('clotures.index') }}" class="btn btn-light">Toutes les clôtures</a></div>
    </div>
    <div class="bloc bloc-corps mx-auto" style="max-width:560px">
        <div class="text-center mb-3">
            <div class="fw-bold fs-5">RAPPORT Z — CLÔTURE DE CAISSE</div>
            <div class="text-doux">{{ boutique()->nom }} · {{ $c->jour->translatedFormat('l d F Y') }}</div>
        </div>
        <div class="d-flex justify-content-between"><span class="text-doux">Caissier</span><strong>{{ $c->caissier?->nomComplet() }}</strong></div>
        <div class="d-flex justify-content-between"><span class="text-doux">Clôturée le</span><span>{{ $c->created_at->format('d/m/Y à H:i') }} par {{ $c->auteur?->nomComplet() }}</span></div>
        <div class="d-flex justify-content-between"><span class="text-doux">Ventes</span><span>{{ $c->nb_ventes }} · {{ gnf($c->total_ventes) }}</span></div>
        <hr>
        <table class="table table-sm mb-2">
            <thead><tr><th>Mode</th><th class="text-end">Net encaissé</th></tr></thead>
            <tbody>
            @forelse ($c->encaissements as $mode => $montant)
                <tr><td>{{ libelle_mode($mode) }}@if (in_array($mode, \App\Models\ClotureCaisse::HORS_ARGENT, true)) <span class="small text-doux">(hors total)</span>@endif</td><td class="text-end montant">{{ gnf($montant) }}</td></tr>
            @empty
                <tr><td colspan="2" class="text-doux">Aucun encaissement.</td></tr>
            @endforelse
            </tbody>
            <tfoot><tr class="fw-bold"><td>Total</td><td class="text-end montant">{{ gnf($c->totalEncaisse()) }}</td></tr></tfoot>
        </table>
        <hr>
        @if ($c->sorties_especes)<div class="d-flex justify-content-between"><span>{{ $c->sorties_especes > 0 ? 'Sorties' : 'Entrées' }} d'espèces hors ventes (dépenses, fournisseurs, transferts)</span><span class="montant">{{ $c->sorties_especes > 0 ? '−' : '+' }} {{ gnf(abs($c->sorties_especes)) }}</span></div>@endif
        <div class="d-flex justify-content-between"><span>Espèces attendues</span><span class="montant">{{ gnf($c->especes_theoriques) }}</span></div>
        <div class="d-flex justify-content-between"><span>Espèces comptées</span><span class="montant">{{ gnf($c->especes_comptees) }}</span></div>
        @if ($c->billetage)
            {{-- Détail du comptage billet par billet --}}
            <table class="table table-sm small mb-1 mt-1" id="detailBillets">
                <tbody>
                @foreach ($c->billetage as $coupure => $nombre)
                    <tr><td class="text-doux">{{ $nombre }} × {{ number_format((int) $coupure, 0, ',', ' ') }} GNF</td><td class="text-end montant">{{ gnf($nombre * (int) $coupure) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
        <div class="d-flex justify-content-between fw-bold fs-5 mt-1 {{ $c->ecart < 0 ? 'text-danger' : '' }}"><span>Écart</span><span>{{ $c->libelleEcart() }}</span></div>
        @if ($c->motif_ecart)<div class="small mt-1"><span class="text-doux">Motif :</span> {{ $c->motif_ecart }}</div>@endif
        @if ($c->note)<div class="small mt-1"><span class="text-doux">Note :</span> {{ $c->note }}</div>@endif
        @if ($c->empreinte)<div class="small text-doux mt-3">Empreinte du rapport (registre inaltérable) : <code>{{ strtoupper(substr($c->empreinte, 0, 24)) }}</code></div>@endif
        <div class="d-flex justify-content-between mt-5 small text-doux"><span>Signature du caissier</span><span>Signature du responsable</span></div>
    </div>
@endsection

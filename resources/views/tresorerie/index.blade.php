@extends('layouts.app')
@section('titre', 'Trésorerie')
@section('contenu')
    <div class="entete-page">
        <div><h1>Trésorerie</h1>
            <div class="text-doux">Argent disponible au total : <strong class="montant">{{ gnf($total) }}</strong></div></div>
    </div>

    <div class="row g-3 mb-3">
        @foreach ($soldes as $cle => $s)
            <div class="col-sm-6 col-xl-4">
                <a href="{{ route('tresorerie.journal', $cle) }}" class="bloc kpi d-block text-reset text-decoration-none h-100">
                    <div class="etiquette"><i class="bi bi-{{ $s['icone'] }} me-1"></i>{{ $s['libelle'] }}</div>
                    <div class="valeur montant {{ $s['solde'] < 0 ? 'text-danger' : '' }}">{{ gnf($s['solde']) }}</div>
                    <div class="small text-doux">
                        @if ($s['constat'])Compté le {{ $s['constat']->date_operation->format('d/m/Y à H:i') }}@else Jamais vérifié : faites un constat de solde @endif
                        @if ($s['solde'] < 0)<div class="text-danger">Solde négatif : un encaissement n'a pas été enregistré, ou un constat est nécessaire.</div>@endif
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <form method="post" action="{{ route('tresorerie.store') }}" class="bloc bloc-corps" id="formTresorerie"
                  data-confirmer="Enregistrer cette opération de trésorerie ?" data-confirmer-titre="Confirmer" data-confirmer-bouton="Oui, enregistrer">
                @csrf
                <h2 class="h6">Nouvelle opération</h2>
                <div class="btn-group w-100 mb-3 flex-wrap" role="group" aria-label="Type d'opération">
                    @foreach (\App\Models\OperationTresorerie::TYPES as $k => $lib)
                        <input type="radio" class="btn-check" name="type" id="type_{{ $k }}" value="{{ $k }}" @checked(old('type', 'transfert') === $k)>
                        <label class="btn btn-sm btn-outline-primary" for="type_{{ $k }}">{{ $lib }}</label>
                    @endforeach
                </div>
                <div class="row g-2">
                    <div class="col-6" data-pour="transfert retrait"><label class="form-label small" for="compte_source">Depuis</label>
                        <select name="compte_source" id="compte_source" class="form-select">
                            @foreach ($soldes as $cle => $s)<option value="{{ $cle }}" @selected(old('compte_source') === $cle)>{{ $s['libelle'] }}</option>@endforeach</select></div>
                    <div class="col-6" data-pour="transfert apport constat"><label class="form-label small" for="compte_destination" id="libelleDestination">Vers</label>
                        <select name="compte_destination" id="compte_destination" class="form-select">
                            @foreach ($soldes as $cle => $s)<option value="{{ $cle }}" @selected(old('compte_destination', 'banque') === $cle)>{{ $s['libelle'] }}</option>@endforeach</select></div>
                    <div class="col-6"><label class="form-label small" for="montant" id="libelleMontant">Montant</label>
                        <input name="montant" id="montant" data-montant inputmode="numeric" value="{{ old('montant') }}" class="form-control text-end" required></div>
                    <div class="col-6" data-pour="transfert"><label class="form-label small" for="frais">Frais (retrait, transfert)</label>
                        <input name="frais" id="frais" data-montant inputmode="numeric" value="{{ old('frais') }}" class="form-control text-end" placeholder="0"></div>
                    <div class="col-12"><label class="form-label small" for="motif">Motif</label>
                        <input name="motif" id="motif" value="{{ old('motif') }}" class="form-control" maxlength="200" placeholder="Ex. : versement de la recette à la banque"></div>
                    <div class="col-12" data-pour="transfert apport retrait"><label class="form-label small" for="reference">Référence (n° de transaction, bordereau)</label>
                        <input name="reference" id="reference" value="{{ old('reference') }}" class="form-control" maxlength="100"></div>
                </div>
                <div class="form-text" id="aide"></div>
                <button class="btn btn-primary mt-3 w-100">Enregistrer</button>
            </form>
        </div>
        <div class="col-lg-7">
            <div class="bloc">
                <div class="bloc-entete"><h2 class="mb-0">Opérations récentes</h2></div>
                <div class="table-responsive"><table class="table mb-0 align-middle">
                    <tbody>
                    @forelse ($operations as $o)
                        <tr><td class="small text-nowrap">{{ $o->date_operation->format('d/m/Y H:i') }}</td>
                            <td>{{ $o->libelle() }}@if ($o->motif)<div class="small text-doux">{{ $o->motif }}</div>@endif
                                <div class="small text-doux">{{ $o->auteur?->nomComplet() }}{{ $o->reference ? ' · réf. '.$o->reference : '' }}</div></td>
                            <td class="text-end montant text-nowrap">{{ gnf($o->montant) }}
                                @if ($o->frais)<div class="small text-doux">frais {{ gnf($o->frais) }}</div>@endif
                                @if ($o->ecart)<div class="small {{ $o->ecart < 0 ? 'text-danger' : 'text-success' }}">écart {{ $o->ecart > 0 ? '+' : '−' }}{{ gnf(abs($o->ecart)) }}</div>@endif</td></tr>
                    @empty
                        <tr><td class="vide">Aucune opération. Commencez par un constat de solde pour chaque compte (l'argent réellement disponible aujourd'hui).</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </div>
            <div class="mt-2">{{ $operations->links() }}</div>
        </div>
    </div>
@endsection
@push('scripts')
<script>
(() => {
    const aides = {
        transfert: "Ex. : versement des espèces à la banque, dépôt ou retrait Orange Money. Les frais sont payés par le compte de départ.",
        apport: "Argent personnel mis dans l'activité (ce n'est pas une vente).",
        retrait: "Argent pris pour un usage personnel (ce n'est pas une dépense de la boutique).",
        constat: "Indiquez l'argent réellement disponible (espèces comptées, solde lu sur le téléphone ou le relevé). L'écart avec le solde calculé est enregistré.",
    };
    const maj = () => {
        const type = document.querySelector('input[name=type]:checked').value;
        document.querySelectorAll('[data-pour]').forEach(el => {
            const visible = el.dataset.pour.split(' ').includes(type);
            el.classList.toggle('d-none', !visible);
            el.querySelectorAll('select,input').forEach(i => i.disabled = !visible);
        });
        document.getElementById('libelleDestination').textContent = type === 'constat' ? 'Compte vérifié' : 'Vers';
        document.getElementById('libelleMontant').textContent = type === 'constat' ? 'Solde réel' : 'Montant';
        document.getElementById('aide').textContent = aides[type];
    };
    document.querySelectorAll('input[name=type]').forEach(r => r.addEventListener('change', maj));
    maj();
})();
</script>
@endpush

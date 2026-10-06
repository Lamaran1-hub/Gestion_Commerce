@extends('layouts.app')
@section('titre', 'Clôturer ma caisse')
@section('contenu')
    @php($modes = config('gestion.modes_paiement') + ['fidelite' => 'Points de fidélité'])
    <div class="entete-page"><div><h1>Clôturer ma caisse</h1>
        <div class="text-doux">{{ now()->translatedFormat('l d F Y') }} · {{ auth()->user()->nomComplet() }}</div></div></div>
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="bloc">
                <div class="bloc-entete"><h2 class="mb-0">Bilan de la journée</h2></div>
                <div class="bloc-corps">
                    <div class="d-flex justify-content-between"><span class="text-doux">Ventes</span><strong>{{ $bilan['nb_ventes'] }} · {{ gnf($bilan['total_ventes']) }}</strong></div>
                    <hr>
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Mode</th><th class="text-end">Encaissé</th><th class="text-end d-none d-sm-table-cell">Remboursé</th><th class="text-end">Net</th></tr></thead>
                        <tbody>
                        @forelse ($bilan['net'] as $mode => $net)
                            <tr class="{{ $mode === 'especes' ? 'fw-semibold' : '' }}"><td>{{ libelle_mode($mode) }}@if (in_array($mode, \App\Models\ClotureCaisse::HORS_ARGENT, true)) <span class="small text-doux">(pas de l'argent)</span>@endif</td>
                                <td class="text-end montant">{{ gnf($bilan['encaisse'][$mode] ?? 0) }}</td>
                                <td class="text-end montant text-doux d-none d-sm-table-cell">{{ ($bilan['rembourse'][$mode] ?? 0) ? '− '.gnf($bilan['rembourse'][$mode]) : '—' }}</td>
                                <td class="text-end montant">{{ gnf($net) }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="vide">Aucun encaissement aujourd'hui.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                    @if ($bilan['depenses_especes'] || $bilan['fournisseurs_payes_especes'] || $bilan['fournisseurs_rembourses_especes'] || $bilan['tresorerie_especes'])
                        <div class="mt-2 small">
                            @if ($bilan['depenses_especes'])<div class="d-flex justify-content-between"><span class="text-doux">Dépenses payées en espèces</span><span class="montant">− {{ gnf($bilan['depenses_especes']) }}</span></div>@endif
                            @if ($bilan['fournisseurs_payes_especes'])<div class="d-flex justify-content-between"><span class="text-doux">Fournisseurs payés en espèces</span><span class="montant">− {{ gnf($bilan['fournisseurs_payes_especes']) }}</span></div>@endif
                            @if ($bilan['fournisseurs_rembourses_especes'])<div class="d-flex justify-content-between"><span class="text-doux">Remboursés par les fournisseurs (retours)</span><span class="montant">+ {{ gnf($bilan['fournisseurs_rembourses_especes']) }}</span></div>@endif
                            @if ($bilan['tresorerie_especes'])<div class="d-flex justify-content-between"><span class="text-doux">Transferts de trésorerie (banque, mobile money, apports)</span><span class="montant">{{ $bilan['tresorerie_especes'] > 0 ? '+' : '−' }} {{ gnf(abs($bilan['tresorerie_especes'])) }}</span></div>@endif
                        </div>
                    @endif
                    <div class="small text-doux mt-2">Seules les espèces se comptent dans le tiroir ; les paiements mobiles et cartes sont vérifiés sur les relevés des opérateurs.</div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <form method="post" action="{{ route('clotures.store') }}" class="bloc" id="formCloture"
                  data-confirmer="Clôturer votre caisse ? Vous ne pourrez plus vendre ni encaisser aujourd'hui sans réouverture par l'administrateur."
                  data-confirmer-titre="Clôturer la caisse" data-confirmer-bouton="Oui, clôturer" data-confirmer-type="alerte">
                @csrf
                <div class="bloc-entete"><h2 class="mb-0">Comptage des espèces</h2></div>
                <div class="bloc-corps row g-3">
                    <div class="col-12 d-flex justify-content-between"><span class="text-doux">Espèces attendues dans le tiroir</span>
                        <strong class="montant fs-5" id="theorique" data-valeur="{{ $bilan['especes_theoriques'] }}">{{ gnf($bilan['especes_theoriques']) }}</strong></div>
                    {{-- Comptage billet par billet : le total se calcule et remplit « Espèces comptées » --}}
                    <div class="col-12">
                        <details id="billetage" @if (old('billetage')) open @endif>
                            <summary class="fw-semibold"><i class="bi bi-cash-stack me-1"></i>Compter billet par billet</summary>
                            <table class="table table-sm align-middle mt-2 mb-0">
                                <thead><tr><th class="ps-1">Billet (GNF)</th><th class="px-1" style="width:4.5rem">Nombre</th><th class="text-end pe-1">Montant</th></tr></thead>
                                <tbody>
                                @foreach (config('gestion.coupures') as $coupure)
                                    <tr>
                                        <td class="text-nowrap ps-1"><label for="billet{{ $coupure }}" class="mb-0">{{ number_format($coupure, 0, ',', ' ') }}</label></td>
                                        <td class="px-1"><input type="number" min="0" max="100000" step="1" inputmode="numeric" name="billetage[{{ $coupure }}]" id="billet{{ $coupure }}"
                                                   value="{{ old('billetage.'.$coupure) }}" class="form-control form-control-sm text-end billet px-1" style="min-width:3.6rem" data-coupure="{{ $coupure }}" placeholder="0"></td>
                                        <td class="text-end montant text-nowrap pe-1 sous-total" data-coupure="{{ $coupure }}">—</td>
                                    </tr>
                                @endforeach
                                </tbody>
                                <tfoot><tr class="fw-bold"><td colspan="2" class="ps-1">Total compté</td><td class="text-end montant text-nowrap pe-1" id="totalBillets">0 GNF</td></tr></tfoot>
                            </table>
                            <div class="form-text">Le total des billets devient le montant des espèces comptées et figure sur le rapport Z.</div>
                        </details>
                    </div>
                    <div class="col-12"><label class="form-label" for="especes_comptees">Espèces comptées (GNF)</label>
                        <input name="especes_comptees" id="especes_comptees" data-montant inputmode="numeric" value="{{ old('especes_comptees') }}" class="form-control form-control-lg text-end" required autofocus></div>
                    <div class="col-12"><div class="alert mb-0 py-2 d-none" id="ecart" role="status"></div></div>
                    <div class="col-12 d-none" id="blocMotif"><label class="form-label" for="motif">Motif de l'écart</label>
                        <select name="motif" id="motif" class="form-select" data-autre>
                            <option value="">Choisir…</option>
                            @foreach (config('gestion.motifs_ecart_caisse') as $m)<option @selected(old('motif') === $m)>{{ $m }}</option>@endforeach
                        </select></div>
                    <div class="col-12"><label class="form-label" for="note">Note (facultatif)</label>
                        <textarea name="note" id="note" rows="2" class="form-control">{{ old('note') }}</textarea></div>
                    <div class="col-12 d-none" id="alerteHorsLigne"><div class="alert alert-warning mb-0"><i class="bi bi-cloud-slash me-1"></i>
                        Des ventes faites hors connexion ne sont pas encore envoyées : attendez leur envoi (bandeau en bas de l'écran) avant de clôturer, sinon le rapport Z serait faux.</div></div>
                    <div class="col-12 d-grid"><button class="btn btn-primary btn-lg" id="boutonCloturer"><i class="bi bi-lock me-1"></i>Clôturer la caisse</button></div>
                </div>
            </form>
        </div>
    </div>
@endsection
@push('scripts')
<script>
    // Pas de clôture tant que des ventes hors connexion attendent sur cet appareil
    (() => {
        const verifier = () => {
            const enAttente = (window.HorsLigne?.file() || []).length > 0;
            document.getElementById('alerteHorsLigne').classList.toggle('d-none', !enAttente);
            document.getElementById('boutonCloturer').disabled = enAttente;
        };
        verifier();
        setInterval(verifier, 3000);
    })();
</script>
<script>
    // Écart calculé pendant la saisie ; le motif devient obligatoire dès qu'il y a un écart
    (() => {
        const saisie = document.getElementById('especes_comptees'), theorique = Number(document.getElementById('theorique').dataset.valeur),
            boite = document.getElementById('ecart'), blocMotif = document.getElementById('blocMotif'), motif = document.getElementById('motif');
        const maj = () => {
            if (saisie.value === '') { boite.classList.add('d-none'); blocMotif.classList.add('d-none'); motif.required = false; return; }
            const ecart = nombre(saisie.value) - theorique;
            boite.classList.remove('d-none', 'alert-success', 'alert-warning', 'alert-danger');
            boite.classList.add(ecart === 0 ? 'alert-success' : (ecart > 0 ? 'alert-warning' : 'alert-danger'));
            boite.textContent = ecart === 0 ? 'Caisse juste.' : (ecart > 0 ? 'Excédent de ' + gnf(ecart) : 'Manque de ' + gnf(-ecart));
            blocMotif.classList.toggle('d-none', ecart === 0);
            motif.required = ecart !== 0;
        };
        saisie.addEventListener('input', maj); maj();

        // Billetage : chaque nombre de billets met à jour son montant, le total et les espèces comptées
        const billets = [...document.querySelectorAll('.billet')];
        const majBillets = () => {
            let total = 0, utilise = false;
            billets.forEach((b) => {
                const n = Math.max(0, parseInt(b.value, 10) || 0), montant = n * Number(b.dataset.coupure);
                if (b.value !== '') utilise = true;
                total += montant;
                document.querySelector(`.sous-total[data-coupure="${b.dataset.coupure}"]`).textContent = n ? gnf(montant) : '—';
            });
            document.getElementById('totalBillets').textContent = gnf(total);
            if (utilise) {
                saisie.value = total.toLocaleString('fr-FR');
                saisie.readOnly = true;   // le comptage des billets fait foi
            } else {
                saisie.readOnly = false;
            }
            maj();
        };
        billets.forEach((b) => b.addEventListener('input', majBillets));
        if (billets.some((b) => b.value !== '')) majBillets();
    })();
</script>
@endpush

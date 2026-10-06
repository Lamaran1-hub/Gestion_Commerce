@extends('layouts.app')
@section('titre', 'Cartes cadeaux')
@section('contenu')
    <div class="entete-page">
        <div><h1>Cartes cadeaux</h1>
            <div class="text-doux">Le client paie aujourd'hui, la personne qui reçoit la carte la dépense plus tard à la caisse.</div>
            <div class="small mt-1">En circulation : <strong class="montant">{{ gnf($enCirculation) }}</strong> sur {{ $nbEnCirculation }} carte(s)
                · Vendues ce mois : <strong class="montant">{{ gnf($vendusMois) }}</strong></div></div>
        <a href="{{ route('aide.index') }}#guide-carte-cadeau" class="btn btn-light"><i class="bi bi-question-circle me-1"></i>Aide</a>
    </div>
    <div class="row g-3">
        @can('ventes.creer')
        <div class="col-lg-4">
            <form method="post" action="{{ route('cartes-cadeaux.store') }}" class="bloc bloc-corps" id="formCarte"
                  data-confirmer="Encaisser cette carte cadeau ?" data-confirmer-titre="Carte cadeau" data-confirmer-bouton="Oui, encaisser">
                @csrf
                <h2 class="h6"><i class="bi bi-gift me-1"></i>Vendre une carte cadeau</h2>
                <label class="form-label small" for="montant">Valeur de la carte</label>
                <input name="montant" id="montant" value="{{ old('montant') }}" data-montant inputmode="numeric" class="form-control text-end mb-1" placeholder="Ex. : 100 000" required>
                <div class="d-flex flex-wrap gap-1 mb-2">
                    @foreach ([50000, 100000, 200000, 500000] as $v)
                        <button type="button" class="btn btn-sm btn-light montant-rapide" data-valeur="{{ $v }}">{{ gnf($v, false) }}</button>
                    @endforeach
                </div>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label small mb-1" for="mode">Payée en</label>
                        <select name="mode" id="mode" class="form-select" data-autre="autre" data-autre-placeholder="Précisez le mode (facultatif)">
                            @foreach (config('gestion.modes_paiement') as $cle => $libelle)<option value="{{ $cle }}" @selected(old('mode', 'especes') === $cle)>{{ $libelle }}</option>@endforeach
                        </select></div>
                    <div class="col-6"><label class="form-label small mb-1" for="expire_le">Valable jusqu'au</label>
                        <input type="date" name="expire_le" id="expire_le" value="{{ old('expire_le', now()->addYear()->toDateString()) }}" min="{{ now()->toDateString() }}" class="form-control"></div>
                    <div class="col-12"><input name="reference" value="{{ old('reference') }}" class="form-control form-control-sm" maxlength="100" placeholder="N° de transaction (mobile money, carte…)" aria-label="Référence du paiement"></div>
                </div>
                <label class="form-label small mt-2" for="client_id">Acheteur</label>
                <select name="client_id" id="client_id" class="form-select mb-1">
                    <option value="">Client de passage</option>
                    @foreach ($clients as $cl)<option value="{{ $cl->id }}" @selected(old('client_id') == $cl->id)>{{ $cl->nomComplet() }}{{ $cl->telephone ? ' — '.$cl->telephone : '' }}</option>@endforeach
                </select>
                <input name="acheteur" value="{{ old('acheteur') }}" class="form-control form-control-sm mb-2" maxlength="120" placeholder="Ou son nom (facultatif)" aria-label="Nom de l'acheteur">
                <label class="form-label small" for="beneficiaire">Offerte à</label>
                <div class="row g-2">
                    <div class="col-7"><input name="beneficiaire" id="beneficiaire" value="{{ old('beneficiaire') }}" class="form-control" maxlength="120" placeholder="Nom (facultatif)"></div>
                    <div class="col-5"><input name="telephone" value="{{ old('telephone') }}" class="form-control" maxlength="30" inputmode="tel" placeholder="Téléphone" aria-label="Téléphone du bénéficiaire"></div>
                </div>
                <input name="message" value="{{ old('message') }}" class="form-control mt-2" maxlength="255" placeholder="Petit mot imprimé sur la carte (facultatif)" aria-label="Message">
                <div class="form-text">L'argent entre dans la caisse aujourd'hui. Le code imprimé sur la carte permet de la dépenser : la carte n'est pas nominative.</div>
                <button class="btn btn-primary w-100 mt-3"><i class="bi bi-gift me-1"></i>Encaisser la carte</button>
            </form>
        </div>
        @endcan
        <div class="{{ auth()->user()->aPermission('ventes.creer') ? 'col-lg-8' : 'col-12' }}">
            <form method="get" class="d-flex flex-wrap gap-2 mb-2" role="search">
                <input type="search" name="q" value="{{ $q }}" class="form-control flex-grow-1" style="min-width:12rem;max-width:26rem" placeholder="Code, nom ou téléphone" aria-label="Rechercher une carte">
                <select name="etat" class="form-select" style="max-width:13rem" aria-label="Filtrer" onchange="this.form.submit()">
                    <option value="">Toutes les cartes</option>
                    <option value="en_circulation" @selected($etat === 'en_circulation')>Utilisables</option>
                    <option value="annulees" @selected($etat === 'annulees')>Annulées</option>
                </select>
                <button class="btn btn-outline-primary"><i class="bi bi-search"></i><span class="visually-hidden">Rechercher</span></button>
            </form>
            <div class="bloc"><div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead><tr><th>Carte</th><th class="d-none d-md-table-cell">Offerte à</th><th class="text-end">Solde</th><th>État</th></tr></thead>
                    <tbody>
                    @forelse ($cartes as $c)
                        @php($e = $c->etat())
                        <tr>
                            <td><a href="{{ route('cartes-cadeaux.show', $c) }}" class="fw-semibold font-monospace text-nowrap">{{ $c->codeMasque() }}</a>
                                <div class="small text-doux">{{ $c->created_at->format('d/m/Y') }} · {{ gnf($c->montant) }}<span class="d-md-none">{{ $c->beneficiaire ? ' · '.$c->beneficiaire : '' }}</span></div></td>
                            <td class="d-none d-md-table-cell">{{ $c->beneficiaire ?? '—' }}@if ($c->acheteur)<div class="small text-doux">de {{ $c->acheteur }}</div>@endif</td>
                            <td class="text-end montant fw-semibold text-nowrap">{{ gnf($c->solde) }}</td>
                            <td>@include('cartes-cadeaux.etat', ['c' => $c])</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="vide"><i class="bi bi-gift"></i>{{ $q !== '' ? 'Aucune carte trouvée pour « '.$q.' ».' : 'Aucune carte cadeau vendue pour l\'instant.' }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div></div>
            <div class="mt-3">{{ $cartes->links() }}</div>
        </div>
    </div>
    <script>
        document.querySelectorAll('.montant-rapide').forEach(b => b.addEventListener('click', () => {
            const champ = document.getElementById('montant');
            champ.value = Number(b.dataset.valeur).toLocaleString('fr-FR');
            champ.dispatchEvent(new Event('input', { bubbles: true }));
        }));
    </script>
@endsection

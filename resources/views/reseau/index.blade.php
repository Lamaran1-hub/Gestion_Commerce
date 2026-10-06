@extends('layouts.app')
@section('titre', 'Mes boutiques')
@section('contenu')
    <div class="entete-page">
        <div><h1>Mes boutiques</h1>
            <div class="text-doux">{{ $chiffres->count() }}{{ $max ? ' / '.$max : '' }} point(s) de vente{{ $formule ? ' (formule '.$formule.($max ? '' : ', illimité').')' : '' }} · chiffres du jour et du mois, en temps réel.</div></div>
        @if ($chiffres->count() > 1)<a href="{{ route('transferts.index') }}" class="btn btn-outline-primary"><i class="bi bi-arrow-left-right me-1"></i>Transferts de stock</a>@endif
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><div class="bloc kpi"><div class="etiquette">Ventes du jour (réseau)</div><div class="valeur montant">{{ gnf($totaux['ca_jour']) }}</div><div class="small text-doux">{{ $totaux['nb_jour'] }} vente(s)</div></div></div>
        <div class="col-6 col-lg-3"><div class="bloc kpi"><div class="etiquette">Ventes du mois</div><div class="valeur montant">{{ gnf($totaux['ca_mois']) }}</div></div></div>
        <div class="col-6 col-lg-3"><div class="bloc kpi"><div class="etiquette">Crédits clients</div><div class="valeur montant {{ $totaux['credits'] ? 'text-danger' : '' }}">{{ gnf($totaux['credits']) }}</div></div></div>
        <div class="col-6 col-lg-3"><div class="bloc kpi"><div class="etiquette">Valeur du stock</div><div class="valeur montant">{{ gnf($totaux['stock']) }}</div></div></div>
    </div>

    <div class="bloc mb-4"><div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead><tr><th>Boutique</th><th class="text-end">Aujourd'hui</th><th class="text-end">Ce mois</th><th class="text-end">Crédits</th>
                <th class="text-end">Stock</th><th>Alertes</th><th>Licence</th><th></th></tr></thead>
            <tbody>
            @foreach ($chiffres as $c)
                @php($b = $c['boutique'])
                <tr class="{{ $b->id === boutique()->id ? 'table-active' : '' }}">
                    <td><div class="fw-semibold">{{ $b->nom }}</div><div class="small text-doux">{{ collect([$b->adresse, $b->ville])->filter()->implode(', ') ?: '—' }}</div></td>
                    <td class="text-end montant">{{ gnf($c['ca_jour']) }}<div class="small text-doux">{{ $c['nb_jour'] }} vente(s)</div></td>
                    <td class="text-end montant">{{ gnf($c['ca_mois']) }}</td>
                    <td class="text-end montant">{{ gnf($c['credits']) }}</td>
                    <td class="text-end montant">{{ gnf($c['stock']) }}</td>
                    <td class="small">
                        @if ($c['ruptures'])<div class="text-danger">{{ $c['ruptures'] }} en rupture</div>@endif
                        @if ($c['transferts_a_recevoir'])<div class="text-warning-emphasis">{{ $c['transferts_a_recevoir'] }} transfert(s) à recevoir</div>@endif
                    </td>
                    <td class="small">{{ $b->libelleStatut() }}@if ($b->abonnement_expire_le)<div class="text-doux">jusqu'au {{ $b->abonnement_expire_le->format('d/m/Y') }}</div>@endif</td>
                    <td class="text-end">
                        @if ($b->id === boutique()->id)
                            <span class="etat etat-ok">Ouverte</span>
                        @else
                            <form method="post" action="{{ route('reseau.activer', $b) }}" data-sans-confirmation>@csrf
                                <button class="btn btn-sm btn-primary">Ouvrir</button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div></div>

    <div class="row g-3">
        <div class="col-lg-6">
            <form method="post" action="{{ route('reseau.store') }}" class="bloc bloc-corps"
                  data-confirmer="Créer ce nouveau point de vente ? Il aura sa propre licence (période d'essai au départ)." data-confirmer-titre="Nouveau point de vente" data-confirmer-bouton="Oui, créer">
                @csrf
                <h2 class="h6"><i class="bi bi-shop me-1"></i>Ajouter un point de vente</h2>
                @if ($blocage)
                    <div class="alert alert-warning small">{{ $blocage }}
                        @can('parametres.gerer')<a href="{{ route('abonnement') }}" class="alert-link d-block mt-1">Voir les formules</a>@endcan</div>
                @endif
                <fieldset @disabled($blocage)>
                <div class="row g-2">
                    <div class="col-12"><label class="form-label small" for="nom">Nom</label>
                        <input name="nom" id="nom" value="{{ old('nom') }}" class="form-control" required maxlength="120" placeholder="Ex. : {{ auth()->user()->boutique->nom }} — Madina"></div>
                    <div class="col-6"><label class="form-label small" for="ville">Ville</label>
                        <input name="ville" id="ville" value="{{ old('ville', auth()->user()->boutique->ville) }}" class="form-control"></div>
                    <div class="col-6"><label class="form-label small" for="telephone">Téléphone</label>
                        <input name="telephone" id="telephone" value="{{ old('telephone') }}" class="form-control"></div>
                    <div class="col-12"><label class="form-label small" for="adresse">Adresse</label>
                        <input name="adresse" id="adresse" value="{{ old('adresse') }}" class="form-control" placeholder="Quartier, commune"></div>
                    <div class="col-12"><div class="form-check">
                        <input type="checkbox" name="copier_catalogue" value="1" id="copier_catalogue" class="form-check-input" checked>
                        <label for="copier_catalogue" class="form-check-label">Copier le catalogue (catégories, produits, prix, codes-barres) — sans le stock</label></div></div>
                </div>
                <div class="form-text">Logo, couleurs et règles de gestion sont repris de votre boutique. Vous en restez l'administrateur ; ajoutez ensuite ses employés depuis Utilisateurs, dans la nouvelle boutique.</div>
                <button class="btn btn-primary mt-3">Créer le point de vente</button>
                </fieldset>
            </form>
        </div>
        <div class="col-lg-6">
            <div class="bloc bloc-corps small">
                <h2 class="h6">Comment ça marche</h2>
                <ul class="mb-0 ps-3">
                    <li>Chaque boutique a son stock, sa caisse, ses clients, ses employés et sa licence.</li>
                    <li>Vous passez de l'une à l'autre avec le sélecteur en haut du menu ; vos employés ne voient que leur boutique.</li>
                    <li>La marchandise circule par <strong>transferts</strong> : la boutique qui envoie sort le stock, celle qui reçoit compte ce qui est arrivé. Un manque est enregistré en perte en transit.</li>
                </ul>
            </div>
        </div>
    </div>
@endsection

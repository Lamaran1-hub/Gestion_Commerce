@extends('layouts.app')
@section('titre', $client->exists ? 'Modifier le client' : 'Nouveau client')
@section('contenu')
    <div class="entete-page"><h1>{{ $client->exists ? 'Modifier '.$client->nomComplet() : 'Nouveau client' }}</h1></div>
    <form method="post" action="{{ $client->exists ? route('clients.update', $client) : route('clients.store') }}" class="bloc bloc-corps row g-3" style="max-width:760px">
        @csrf @if ($client->exists) @method('put') @endif
        <div class="col-md-6"><label class="form-label" for="prenom">Prénom</label><input name="prenom" id="prenom" value="{{ old('prenom', $client->prenom) }}" class="form-control"></div>
        <div class="col-md-6"><label class="form-label" for="nom">Nom (ou raison sociale)</label><input name="nom" id="nom" value="{{ old('nom', $client->nom) }}" class="form-control @error('nom') is-invalid @enderror" required>@error('nom')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-6"><label class="form-label" for="telephone">Téléphone</label><input name="telephone" id="telephone" value="{{ old('telephone', $client->telephone) }}" class="form-control @error('telephone') is-invalid @enderror">@error('telephone')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-6"><label class="form-label" for="email">E-mail</label><input type="email" name="email" id="email" value="{{ old('email', $client->email) }}" class="form-control @error('email') is-invalid @enderror">@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-6"><label class="form-label" for="date_naissance">Date de naissance <span class="text-doux small">(facultatif)</span></label>
            <input type="date" name="date_naissance" id="date_naissance" value="{{ old('date_naissance', $client->date_naissance?->toDateString()) }}" max="{{ now()->subDay()->toDateString() }}" class="form-control @error('date_naissance') is-invalid @enderror">@error('date_naissance')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="form-text">Pour lui souhaiter son anniversaire par WhatsApp.</div></div>
        <div class="col-12"><h2 class="h6 mb-0 mt-2"><i class="bi bi-house-door me-1"></i>Adresse de résidence</h2></div>
        <div class="col-md-4"><label class="form-label" for="quartier">Quartier</label>
            <input name="quartier" id="quartier" value="{{ old('quartier', $client->quartier) }}" class="form-control" placeholder="Ex. : Kaloum, Madina…"></div>
        <div class="col-md-4"><label class="form-label" for="commune">Commune</label>
            <input name="commune" id="commune" value="{{ old('commune', $client->commune) }}" class="form-control" list="communes">
            <datalist id="communes">@foreach (['Kaloum', 'Dixinn', 'Matam', 'Ratoma', 'Matoto', 'Kagbélen', 'Sanoyah', 'Gbessia'] as $c)<option value="{{ $c }}">@endforeach</datalist></div>
        <div class="col-md-4"><label class="form-label" for="ville">Ville</label>
            <input name="ville" id="ville" value="{{ old('ville', $client->ville) }}" class="form-control" placeholder="Conakry, Kindia…"></div>
        <div class="col-12"><label class="form-label" for="adresse">Précisions (rue, repère, n° de porte)</label>
            <input name="adresse" id="adresse" value="{{ old('adresse', $client->adresse) }}" class="form-control" placeholder="Ex. : derrière la mosquée, 2e maison à gauche"></div>
        <div class="col-md-6"><label class="form-label" for="plafond_credit">Plafond de crédit (GNF)</label>
            <input name="plafond_credit" id="plafond_credit" data-montant inputmode="numeric" value="{{ old('plafond_credit', $client->plafond_credit) }}" class="form-control text-end"
                   placeholder="{{ boutique()->plafond_credit_defaut ? 'Par défaut : '.gnf(boutique()->plafond_credit_defaut) : 'Sans limite' }}">
            <div class="form-text">Dette maximale autorisée pour ce client. Vide = règle générale de la boutique.</div></div>
        <div class="col-md-6 d-flex align-items-end"><div class="form-check form-switch mb-2">
            <input type="hidden" name="grossiste" value="0">
            <input type="checkbox" name="grossiste" value="1" id="grossiste" class="form-check-input" @checked(old('grossiste', $client->grossiste))>
            <label for="grossiste" class="form-check-label">Client grossiste (revendeur) : prix de gros sur tous les articles</label></div></div>
        <div class="col-12"><label class="form-label" for="notes">Notes</label><textarea name="notes" id="notes" rows="2" class="form-control">{{ old('notes', $client->notes) }}</textarea></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Enregistrer</button>
            <a href="{{ $retour ?? ($client->exists ? route('clients.show', $client) : route('clients.index')) }}" class="btn btn-light">Annuler</a></div>
    </form>
@endsection

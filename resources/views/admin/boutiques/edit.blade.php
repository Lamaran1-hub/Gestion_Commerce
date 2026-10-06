@extends('layouts.app')
@section('titre', 'Modifier '.$boutique->nom)
@section('contenu')
    <div class="entete-page"><h1>Fiche client : {{ $boutique->nom }}</h1></div>
    <form method="post" action="{{ route('admin.boutiques.update', $boutique) }}" class="row g-4">
        @csrf @method('put')
        <div class="col-xl-7"><div class="bloc bloc-corps row g-3">@include('admin.boutiques._fiche')</div></div>
        <div class="col-xl-5">
            <div class="bloc bloc-corps row g-3">
                <div class="col-12"><h2 class="h6 mb-0"><i class="bi bi-patch-check me-1"></i>Licence (correction manuelle)</h2>
                    <div class="form-text">Pour un paiement, utilisez plutôt « Enregistrer un paiement » sur la fiche : il est tracé et donne un reçu.</div></div>
                <div class="col-12"><label class="form-label" for="plan_id">Formule</label><select name="plan_id" id="plan_id" class="form-select">
                    @foreach ($plans as $p)<option value="{{ $p->id }}" @selected(old('plan_id', $boutique->plan_id) == $p->id)>{{ $p->nom }}</option>@endforeach</select></div>
                <div class="col-6"><label class="form-label" for="statut">État</label><select name="statut" id="statut" class="form-select">
                    @foreach (['essai' => "Période d'essai", 'actif' => 'Licence payée', 'suspendu' => 'Suspendue'] as $k => $l)<option value="{{ $k }}" @selected(old('statut', $boutique->statut) === $k)>{{ $l }}</option>@endforeach</select></div>
                <div class="col-6"><label class="form-label" for="abonnement_expire_le">Accès jusqu'au</label>
                    <input type="date" name="abonnement_expire_le" id="abonnement_expire_le" value="{{ old('abonnement_expire_le', $boutique->abonnement_expire_le?->toDateString()) }}" class="form-control">
                    <div class="form-text">Vide = sans échéance.</div></div>
            </div>
            <div class="bloc bloc-corps mt-3">
                <label class="form-label" for="notes_internes"><i class="bi bi-journal-text me-1"></i>Notes internes</label>
                <textarea name="notes_internes" id="notes_internes" rows="5" class="form-control" placeholder="Visible par vous seul : historique des échanges, accords particuliers…">{{ old('notes_internes', $boutique->notes_internes) }}</textarea>
            </div>
        </div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Enregistrer</button><a href="{{ route('admin.boutiques.show', $boutique) }}" class="btn btn-light">Annuler</a></div>
    </form>
@endsection

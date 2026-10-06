@extends('layouts.app')
@section('titre', 'Utilisateurs')
@section('contenu')
    <div class="entete-page"><div><h1>Utilisateurs de toutes les boutiques</h1>
        <div class="text-doux">Suspendez un compte ou créez un mot de passe provisoire quand un utilisateur l'a oublié.</div></div></div>
    <form class="bloc bloc-corps row g-2 align-items-end mb-3">
        <div class="col-md"><label class="form-label small text-doux mb-1" for="q">Recherche</label>
            <input name="q" id="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Nom, e-mail ou téléphone"></div>
        <div class="col-md-3"><label class="form-label small text-doux mb-1" for="boutique_id">Boutique</label>
            <select name="boutique_id" id="boutique_id" class="form-select form-select-sm"><option value="">Toutes</option>
                @foreach ($boutiques as $b)<option value="{{ $b->id }}" @selected(request('boutique_id') == $b->id)>{{ $b->nom }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label small text-doux mb-1" for="etat">État</label>
            <select name="etat" id="etat" class="form-select form-select-sm"><option value="">Tous</option>
                <option value="actif" @selected(request('etat') === 'actif')>Actifs</option><option value="suspendu" @selected(request('etat') === 'suspendu')>Suspendus</option></select></div>
        <div class="col-md-auto"><button class="btn btn-sm btn-primary">Filtrer</button></div>
    </form>
    <div class="bloc"><div class="table-responsive"><table class="table">
        <thead><tr><th>Nom</th><th>Boutique</th><th>E-mail</th><th>Rôle</th><th>Dernière connexion</th><th></th></tr></thead>
        <tbody>
        @forelse ($utilisateurs as $u)
            @include('admin.utilisateurs._ligne', ['u' => $u, 'avecBoutique' => true])
        @empty
            <tr><td colspan="6" class="vide">Aucun utilisateur.</td></tr>
        @endforelse
        </tbody>
    </table></div></div>
    <div class="mt-3">{{ $utilisateurs->links() }}</div>
@endsection

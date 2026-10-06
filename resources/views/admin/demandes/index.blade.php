@extends('layouts.app')
@section('titre', "Demandes d'assistance")
@section('contenu')
    <div class="entete-page"><div><h1>Demandes d'assistance</h1><div class="text-doux">Questions, problèmes et demandes de vos clients.</div></div></div>
    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach (['' => 'À traiter', 'ouverte' => 'En attente de réponse', 'repondue' => 'Répondues', 'fermee' => 'Clôturées'] as $k => $l)
            <a href="{{ route('admin.demandes.index', array_filter(['statut' => $k])) }}" class="btn btn-sm {{ request('statut', '') === $k ? 'btn-primary' : 'btn-outline-primary' }}">{{ $l }}</a>
        @endforeach
    </div>
    <div class="bloc"><div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Sujet</th><th>Client</th><th>Catégorie</th><th>État</th><th>Dernier message</th></tr></thead>
        <tbody>
        @forelse ($demandes as $d)
            <tr class="{{ $d->lue_proprietaire ? '' : 'fw-semibold' }}">
                <td><a href="{{ route('admin.demandes.show', $d) }}">{{ $d->sujet }}</a>@unless ($d->lue_proprietaire)<span class="badge bg-danger ms-1">Nouveau</span>@endunless</td>
                <td class="small">{{ $d->boutique?->nom }}<div class="text-doux">{{ $d->auteur?->nomComplet() }}</div></td>
                <td class="small">{{ $d->libelleCategorie() }}</td>
                <td><span class="etat {{ $d->classeStatut() }}">{{ $d->libelleStatut() }}</span></td>
                <td class="small text-doux">{{ $d->dernier_message_le?->format('d/m/Y H:i') }}</td></tr>
        @empty
            <tr><td colspan="5" class="vide"><i class="bi bi-chat-dots"></i>Aucune demande.</td></tr>
        @endforelse
        </tbody>
    </table></div></div>
    <div class="mt-3">{{ $demandes->links() }}</div>
@endsection

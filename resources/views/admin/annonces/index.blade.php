@extends('layouts.app')
@section('titre', 'Annonces')
@section('contenu')
    <div class="entete-page"><div><h1>Annonces et nouveautés</h1>
        <div class="text-doux">Informez vos utilisateurs : nouvelles fonctions, maintenance prévue, message important.</div></div>
        <a href="{{ route('admin.annonces.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nouvelle annonce</a></div>
    <div class="bloc"><div class="table-responsive"><table class="table">
        <thead><tr><th>Annonce</th><th>Type</th><th>Destinataires</th><th>État</th><th class="text-end">Lue par</th><th></th></tr></thead>
        <tbody>
        @forelse ($annonces as $a)
            <tr><td class="fw-semibold">{{ $a->titre }}<div class="small text-doux">{{ \Illuminate\Support\Str::limit($a->contenu, 90) }}</div></td>
                <td><span class="etat {{ $a->classeEtat() }}"><i class="bi bi-{{ $a->icone() }}"></i>{{ $a->libelleType() }}</span></td>
                <td class="small">{{ $a->boutique?->nom ?? 'Toutes les boutiques' }}</td>
                <td class="small">
                    @if (! $a->publiee_le)<span class="etat etat-neutre">Brouillon</span>
                    @elseif ($a->expire_le && $a->expire_le->isPast())<span class="etat etat-neutre">Expirée</span>
                    @else<span class="etat etat-ok">Publiée le {{ $a->publiee_le->format('d/m/Y') }}</span>@endif
                    @if ($a->expire_le)<div class="text-doux">jusqu'au {{ $a->expire_le->format('d/m/Y') }}</div>@endif</td>
                <td class="text-end small">{{ $a->lecteurs_count }} utilisateur(s)</td>
                <td class="text-end text-nowrap"><a href="{{ route('admin.annonces.edit', $a) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                    <form method="post" action="{{ route('admin.annonces.destroy', $a) }}" class="d-inline" data-confirmer="Supprimer l'annonce « {{ $a->titre }} » ?">@csrf @method('delete')
                        <button class="btn btn-sm btn-light text-danger" aria-label="Supprimer"><i class="bi bi-trash"></i></button></form></td></tr>
        @empty
            <tr><td colspan="6" class="vide"><i class="bi bi-megaphone"></i>Aucune annonce pour l'instant.</td></tr>
        @endforelse
        </tbody>
    </table></div></div>
    <div class="mt-3">{{ $annonces->links() }}</div>
@endsection

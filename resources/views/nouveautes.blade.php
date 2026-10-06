@extends('layouts.app')
@section('titre', 'Nouveautés')
@section('contenu')
    <div class="entete-page"><div><h1>Nouveautés et annonces</h1><div class="text-doux">Les messages de l'éditeur de votre logiciel.</div></div>
        <a href="{{ route('assistance.create') }}" class="btn btn-outline-primary"><i class="bi bi-chat-dots me-1"></i>Poser une question</a></div>
    <div class="vstack gap-3" style="max-width:900px">
        @forelse ($annonces as $a)
            <article class="bloc">
                <div class="bloc-entete">
                    <div><h2 class="mb-0">{{ $a->titre }} @if ($nonLues->contains($a->id))<span class="badge bg-danger ms-1 align-middle">Nouveau</span>@endif</h2>
                        <div class="small text-doux">{{ $a->publiee_le->translatedFormat('d F Y') }}</div></div>
                    <span class="etat {{ $a->classeEtat() }}"><i class="bi bi-{{ $a->icone() }}"></i>{{ $a->libelleType() }}</span>
                </div>
                <div class="bloc-corps" style="white-space:pre-line">{{ $a->contenu }}</div>
            </article>
        @empty
            <div class="bloc vide"><i class="bi bi-megaphone"></i>Aucune annonce pour l'instant.</div>
        @endforelse
    </div>
    <div class="mt-3">{{ $annonces->links() }}</div>
@endsection

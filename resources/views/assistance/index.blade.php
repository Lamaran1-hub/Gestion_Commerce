@extends('layouts.app')
@section('titre', 'Assistance')
@section('contenu')
    @php($ed = \App\Support\Plateforme::tout())
    <div class="entete-page"><div><h1>Assistance</h1><div class="text-doux">Une question, un problème, une idée ? Écrivez-nous, nous vous répondons ici.</div></div>
        <a href="{{ route('assistance.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nouvelle demande</a></div>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="bloc"><div class="table-responsive"><table class="table table-hover">
                <thead><tr><th>Sujet</th><th>Catégorie</th><th>État</th><th>Dernier message</th></tr></thead>
                <tbody>
                @forelse ($demandes as $d)
                    <tr class="{{ $d->lue_boutique ? '' : 'fw-semibold' }}">
                        <td><a href="{{ route('assistance.show', $d) }}">{{ $d->sujet }}</a>@unless ($d->lue_boutique)<span class="badge bg-success ms-1">Réponse</span>@endunless
                            <div class="small text-doux fw-normal">par {{ $d->auteur?->nomComplet() }}</div></td>
                        <td class="small">{{ $d->libelleCategorie() }}</td>
                        <td><span class="etat {{ $d->classeStatut() }}">{{ $d->libelleStatut() }}</span></td>
                        <td class="small text-doux">{{ $d->dernier_message_le?->format('d/m/Y H:i') }}</td></tr>
                @empty
                    <tr><td colspan="4" class="vide"><i class="bi bi-life-preserver"></i>Aucune demande pour l'instant.</td></tr>
                @endforelse
                </tbody>
            </table></div></div>
            <div class="mt-3">{{ $demandes->links() }}</div>
        </div>
        <div class="col-lg-4">
            <div class="bloc bloc-corps small vstack gap-1">
                <h2 class="h6">Nous joindre directement</h2>
                <div class="fw-semibold">{{ $ed['societe'] ?? config('app.name') }}</div>
                @if (! empty($ed['telephone']))<div><i class="bi bi-telephone me-1"></i><a href="tel:{{ $ed['telephone'] }}">{{ $ed['telephone'] }}</a></div>@endif
                @if (! empty($ed['whatsapp']))<div><i class="bi bi-whatsapp me-1"></i>{{ $ed['whatsapp'] }}</div>@endif
                @if (! empty($ed['email']))<div><i class="bi bi-envelope me-1"></i>{{ $ed['email'] }}</div>@endif
            </div>
        </div>
    </div>
@endsection

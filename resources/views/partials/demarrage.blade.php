{{-- Guide de démarrage : étapes cochées automatiquement, disparaît quand tout est fait (ou masqué) --}}
@php
    $bq = boutique();
    $etapes = [
        ['Renseigner vos coordonnées et votre logo', $bq->telephone && ($bq->logo || $bq->adresse), route('parametres.edit'), 'parametres.gerer'],
        ['Ajouter vos produits (un par un ou import Excel)', \App\Models\Produit::exists(), route('produits.create'), 'produits.gerer'],
        ['Créer le compte de vos vendeurs', \App\Models\User::where('boutique_id', $bq->id)->count() > 1, route('utilisateurs.create'), 'utilisateurs.gerer'],
        ['Faire votre première vente', \App\Models\Vente::exists(), route('ventes.create'), 'ventes.creer'],
        ['Clôturer la caisse en fin de journée', \App\Models\ClotureCaisse::exists(), route('clotures.create'), 'ventes.creer'],
    ];
    $faites = collect($etapes)->filter(fn ($e) => $e[1])->count();
@endphp
@if ($faites < count($etapes) && ! session('demarrage_masque'))
    <div class="bloc mb-3">
        <div class="bloc-entete">
            <h2 class="mb-0"><i class="bi bi-rocket-takeoff me-1"></i>Bien démarrer ({{ $faites }}/{{ count($etapes) }})</h2>
            <form method="post" action="{{ route('demarrage.masquer') }}" data-sans-confirmation>@csrf
                <button class="btn btn-sm btn-link text-doux" aria-label="Masquer le guide">Masquer</button></form>
        </div>
        <div class="progress mx-3" style="height:6px" role="progressbar" aria-valuenow="{{ $faites }}" aria-valuemin="0" aria-valuemax="{{ count($etapes) }}">
            <div class="progress-bar bg-success" style="width: {{ round($faites * 100 / count($etapes)) }}%"></div></div>
        <ol class="list-unstyled bloc-corps mb-0">
            @foreach ($etapes as [$texte, $fait, $lien, $permission])
                <li class="d-flex align-items-center gap-2 py-1">
                    <i class="bi bi-{{ $fait ? 'check-circle-fill text-success' : 'circle text-doux' }}"></i>
                    <span class="{{ $fait ? 'text-decoration-line-through text-doux' : '' }}">{{ $texte }}</span>
                    @if (! $fait && auth()->user()->aPermission($permission))<a href="{{ $lien }}" class="btn btn-sm btn-outline-primary ms-auto">Faire</a>@endif
                </li>
            @endforeach
        </ol>
    </div>
@endif

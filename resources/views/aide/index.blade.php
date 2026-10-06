@extends('layouts.app')
@section('titre', 'Centre d\'aide')
@section('contenu')
    <div class="entete-page">
        <div><h1>Centre d'aide</h1>
            <div class="text-doux">{{ $nbGuides }} guides pas à pas. Vous ne trouvez pas ? L'équipe d'assistance vous répond.</div></div>
        <a href="{{ route('assistance.create') }}" class="btn btn-outline-primary"><i class="bi bi-life-preserver me-1"></i>Poser une question</a>
    </div>

    <div class="input-group input-group-lg mb-3">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="search" id="rechercheAide" class="form-control" placeholder="Ex. : crédit, proforma, TVA, inventaire…" aria-label="Rechercher dans l'aide" autofocus>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-4" id="rubriquesAide">
        @foreach ($rubriques as $cle => [$nom, $icone])
            @if ($guides->has($cle))<a href="#rubrique-{{ $cle }}" class="btn btn-sm btn-light border"><i class="bi bi-{{ $icone }} me-1"></i>{{ $nom }}</a>@endif
        @endforeach
    </div>

    <p class="vide d-none" id="aideVide">Aucun guide ne correspond. <a href="{{ route('assistance.create') }}">Posez votre question à l'assistance</a>.</p>

    @foreach ($rubriques as $cle => [$nom, $icone])
        @continue(! $guides->has($cle))
        <section class="mb-4" id="rubrique-{{ $cle }}" data-rubrique>
            <h2 class="h5 mb-2"><i class="bi bi-{{ $icone }} me-1"></i>{{ $nom }}</h2>
            <div class="accordion" id="acc-{{ $cle }}">
                @foreach ($guides[$cle] as $g)
                    <div class="accordion-item" id="guide-{{ $g['cle'] }}" data-guide
                         data-texte="{{ \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($g['titre'].' '.$g['mots'].' '.$g['resume'].' '.implode(' ', $g['etapes']))) }}">
                        <h3 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#corps-{{ $g['cle'] }}" aria-expanded="false" aria-controls="corps-{{ $g['cle'] }}">
                                <span><span class="fw-semibold">{{ $g['titre'] }}</span>
                                    @unless ($g['inclus'])<span class="badge text-bg-warning ms-1">Formule supérieure</span>@endunless
                                    <span class="d-block small text-doux fw-normal">{{ $g['resume'] }}</span></span>
                            </button>
                        </h3>
                        <div id="corps-{{ $g['cle'] }}" class="accordion-collapse collapse">
                            <div class="accordion-body">
                                <ol class="mb-3 ps-3">
                                    @foreach ($g['etapes'] as $etape)<li class="mb-1">{{ $etape }}</li>@endforeach
                                </ol>
                                @if (! $g['inclus'])
                                    <div class="alert alert-warning py-2 mb-0 small">Cette fonction n'est pas incluse dans votre formule.
                                        <a href="{{ route('abonnement') }}" class="alert-link">Voir les formules</a></div>
                                @elseif ($g['url'])
                                    <a href="{{ $g['url'] }}" class="btn btn-sm btn-primary">Ouvrir l'écran <i class="bi bi-arrow-right ms-1"></i></a>
                                @else
                                    <div class="small text-doux">Cet écran est réservé à un responsable : demandez-lui l'accès si besoin.</div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach
@endsection
@push('scripts')
<script>
    // Recherche instantanée, insensible aux accents et à la casse ; chaque mot doit apparaître
    (() => {
        const champ = document.getElementById('rechercheAide');
        const normaliser = (t) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
        const filtrer = () => {
            const mots = normaliser(champ.value).split(/\s+/).filter(Boolean);
            let visibles = 0;
            document.querySelectorAll('[data-rubrique]').forEach((s) => {
                let dansRubrique = 0;
                s.querySelectorAll('[data-guide]').forEach((g) => {
                    const ok = mots.every((m) => g.dataset.texte.includes(m));
                    g.classList.toggle('d-none', !ok);
                    dansRubrique += ok;
                });
                s.classList.toggle('d-none', dansRubrique === 0);
                visibles += dansRubrique;
            });
            document.getElementById('aideVide').classList.toggle('d-none', visibles > 0);
            document.getElementById('rubriquesAide').classList.toggle('d-none', mots.length > 0);
        };
        champ.addEventListener('input', filtrer);
        // Lien direct vers un guide (#guide-xxx) : il s'ouvre
        const cible = location.hash.startsWith('#guide-') && document.querySelector(location.hash + ' .accordion-collapse');
        if (cible) { bootstrap.Collapse.getOrCreateInstance(cible).show(); cible.closest('[data-guide]').scrollIntoView(); }
        // Recherche passée dans l'adresse (?q=…), par exemple depuis un autre écran
        const q = new URLSearchParams(location.search).get('q');
        if (q) { champ.value = q; filtrer(); }
    })();
</script>
@endpush

@extends('layouts.app')
@section('titre', 'Clients')
@section('contenu')
    <div class="entete-page">
        <h1>Clients</h1>
        <div class="d-flex gap-2">
            <a href="{{ route('clients.export', request()->query() + ['format' => 'excel']) }}" class="btn btn-outline-primary"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
            @can('parametres.gerer')<a href="{{ route('clients.doublons') }}" class="btn btn-outline-secondary" title="Fiches clients en double"><i class="bi bi-intersect me-1"></i>Doublons</a>@endcan
            @can('clients.gerer')<a href="{{ route('clients.create') }}" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Nouveau client</a>@endcan
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-2" role="group" aria-label="Segments de clientèle">
        <a href="{{ route('clients.index', array_filter(['q' => request('q')])) }}" class="btn btn-sm {{ $segment ? 'btn-outline-primary' : 'btn-primary' }}">Tous</a>
        @foreach (\App\Http\Controllers\ClientController::SEGMENTS as $cle => $libelle)
            <a href="{{ route('clients.index', array_filter(['segment' => $cle, 'jours' => $cle === 'a_relancer' ? $jours : null, 'q' => request('q')])) }}"
               class="btn btn-sm {{ $segment === $cle ? 'btn-primary' : 'btn-outline-primary' }}">{{ $libelle }} <span class="badge {{ $segment === $cle ? 'bg-light text-primary' : 'bg-primary' }}">{{ $comptes[$cle] }}</span></a>
        @endforeach
    </div>
    <form class="row g-2 mb-3 align-items-center">
        @if ($segment)<input type="hidden" name="segment" value="{{ $segment }}">@endif
        <div class="col-sm-5 col-lg-4"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Nom, téléphone ou code client" aria-label="Rechercher un client"></div>
        @if ($segment === 'a_relancer')
            <div class="col-auto"><select name="jours" class="form-select" aria-label="Sans achat depuis" onchange="this.form.submit()">
                @foreach ([30, 60, 90, 180] as $j)<option value="{{ $j }}" @selected($jours === $j)>Sans achat depuis {{ $j }} jours</option>@endforeach
            </select></div>
        @endif
        <div class="col-auto"><select name="tri" class="form-select" aria-label="Trier" onchange="this.form.submit()">
            <option value="nom" @selected($tri === 'nom')>Trier par nom</option>
            <option value="dernier_achat" @selected($tri === 'dernier_achat')>Trier par dernier achat</option>
            <option value="total" @selected($tri === 'total')>Meilleurs clients d'abord</option>
        </select></div>
    </form>
    @if ($segment === 'a_relancer')
        <div class="alert alert-info py-2 small"><i class="bi bi-lightbulb me-1"></i>Ces clients ont déjà acheté chez vous mais ne sont pas revenus depuis {{ $jours }} jours.
            Une invitation WhatsApp avec vos promotions du moment les fait souvent revenir. Un client invité ne réapparaît ici qu'après
            {{ \App\Http\Controllers\ClientController::DELAI_ENTRE_INVITATIONS }} jours.
            @unless (fonction('relances'))<br><strong>L'envoi des invitations est inclus dans une formule supérieure.</strong>@endunless</div>
    @endif
    <div class="bloc">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Code</th><th>Nom</th><th>Téléphone</th><th class="text-end">Achats</th><th class="text-end">Total acheté</th><th>Dernier achat</th><th class="text-end">Reste à payer</th>@if (in_array($segment, ['a_relancer', 'anniversaires'], true))<th></th>@endif</tr></thead>
                <tbody>
                @forelse ($clients as $c)
                    <tr><td class="text-doux">{{ $c->code }}</td>
                        <td><a href="{{ route('clients.show', $c) }}" class="fw-semibold">{{ $c->nomComplet() }}</a></td>
                        <td class="text-nowrap">{{ numero_affiche($c->telephone) }}</td><td class="text-end">{{ $c->ventes_count }}</td>
                        <td class="text-end montant">{{ gnf($c->total_achats ?? 0) }}</td>
                        <td class="text-nowrap">@if ($c->dernier_achat)<span title="{{ \Carbon\Carbon::parse($c->dernier_achat)->format('d/m/Y') }}">{{ \Carbon\Carbon::parse($c->dernier_achat)->diffForHumans() }}</span>@else<span class="text-doux">—</span>@endif</td>
                        <td class="text-end montant {{ $c->total_du > 0 ? 'text-danger fw-semibold' : 'text-doux' }}">{{ $c->total_du > 0 ? gnf($c->total_du) : '—' }}</td>
                        @if ($segment === 'anniversaires')
                            @php($jours = $c->joursAvantAnniversaire())
                            <td class="text-end text-nowrap">
                                <span class="small {{ $jours === 0 ? 'text-success fw-semibold' : 'text-doux' }}">🎂 {{ $jours === 0 ? "Aujourd'hui" : ($jours === 1 ? 'Demain' : 'Le '.$c->prochainAnniversaire()->translatedFormat('j F')) }}</span>
                                @if (fonction('relances') && $c->telephone)
                                    @if ($c->voeuEnvoyeCetteAnnee())<span class="small text-doux ms-1">· vœux envoyés</span>
                                    @else
                                        <form method="post" action="{{ route('clients.souhaiter', $c) }}" target="_blank" class="d-inline ms-1" data-sans-confirmation>@csrf
                                            <button class="btn btn-sm btn-success" data-invitation><i class="bi bi-whatsapp me-1"></i>Souhaiter</button></form>
                                    @endif
                                @endif</td>
                        @endif
                        @if ($segment === 'a_relancer')
                            <td class="text-end">@if (fonction('relances'))
                                <form method="post" action="{{ route('clients.inviter', $c) }}" target="_blank" data-sans-confirmation>@csrf
                                    <button class="btn btn-sm btn-success text-nowrap" data-invitation><i class="bi bi-whatsapp me-1"></i>Inviter</button></form>
                            @endif</td>
                        @endif</tr>
                @empty
                    <tr><td colspan="8" class="vide"><i class="bi bi-people"></i>{{ $segment === 'a_relancer' ? 'Aucun client à relancer : tous vos clients sont revenus récemment ou ont déjà été invités.' : 'Aucun client.' }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $clients->links() }}</div>
@endsection
@push('scripts')
<script>
    // Après l'envoi (WhatsApp s'ouvre dans un nouvel onglet), la ligne est marquée pour éviter un double envoi
    document.querySelectorAll('[data-invitation]').forEach(b => b.form.addEventListener('submit', () => setTimeout(() => {
        b.disabled = true; b.classList.replace('btn-success', 'btn-outline-secondary'); b.innerHTML = '<i class="bi bi-check2 me-1"></i>Invité';
    }, 50)));
</script>
@endpush

<tr class="{{ $u->actif ? '' : 'opacity-50' }}">
    <td class="fw-semibold">{{ $u->nomComplet() }}
        @unless ($u->actif)<span class="etat etat-rupture ms-1">Suspendu</span>@endunless
        @if ($u->telephone)<div class="small text-doux">{{ numero_affiche($u->telephone) }}</div>@endif</td>
    @if ($avecBoutique)
        <td>@if ($u->boutique)<a href="{{ route('admin.boutiques.show', $u->boutique) }}">{{ $u->boutique->nom }}</a>@endif</td>
    @endif
    <td class="small">{{ $u->email }}</td>
    <td class="small">{{ $u->role?->nom }}</td>
    <td class="small text-doux">{{ $u->derniere_connexion?->format('d/m/Y H:i') ?? 'Jamais' }}</td>
    <td class="text-end text-nowrap">
        <form method="post" action="{{ route('admin.utilisateurs.reinitialiser', $u) }}" class="d-inline"
              data-confirmer="Créer un nouveau mot de passe provisoire pour {{ $u->nomComplet() }} ? L'ancien ne fonctionnera plus."
              data-confirmer-titre="Réinitialiser le mot de passe" data-confirmer-bouton="Oui, réinitialiser" data-confirmer-type="alerte">@csrf
            <button class="btn btn-sm btn-light" title="Mot de passe oublié : en créer un provisoire"><i class="bi bi-key"></i></button></form>
        <form method="post" action="{{ route('admin.utilisateurs.statut', $u) }}" class="d-inline"
              data-confirmer="{{ $u->actif ? 'Suspendre '.$u->nomComplet().' ? Il sera déconnecté et ne pourra plus se connecter.' : 'Réactiver '.$u->nomComplet().' ?' }}"
              data-confirmer-titre="{{ $u->actif ? "Suspendre l'utilisateur" : "Réactiver l'utilisateur" }}"
              data-confirmer-bouton="{{ $u->actif ? 'Oui, suspendre' : 'Oui, réactiver' }}" data-confirmer-type="{{ $u->actif ? 'danger' : 'primaire' }}">@csrf
            <button class="btn btn-sm {{ $u->actif ? 'btn-light text-danger' : 'btn-outline-success' }}" title="{{ $u->actif ? 'Suspendre' : 'Réactiver' }}">
                <i class="bi bi-{{ $u->actif ? 'person-slash' : 'person-check' }}"></i></button></form>
    </td>
</tr>

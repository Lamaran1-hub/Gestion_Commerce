@extends('layouts.app')
@section('titre', $boutique->nom)
@section('contenu')
    @php
        $etatClasse = $boutique->licencePayee() ? 'etat-ok' : ($boutique->estActive() ? 'etat-alerte' : 'etat-rupture');
        $j = $boutique->joursRestants();
    @endphp
    <div class="entete-page">
        <div class="d-flex align-items-center gap-3">
            @if ($boutique->logoTeleverseUrl())<img src="{{ $boutique->logoTeleverseUrl() }}" alt="" class="barre-logo border">
            @else<div class="barre-initiales" style="background:{{ $boutique->couleur }}">{{ $boutique->initiales() }}</div>@endif
            <div><h1>{{ $boutique->nom }} <span class="etat {{ $etatClasse }} align-middle">{{ $boutique->libelleStatut() }}</span></h1>
                <div class="text-doux">{{ collect([$boutique->secteur, $boutique->ville, 'client depuis le '.$boutique->created_at->format('d/m/Y')])->filter()->implode(' · ') }}</div>
                @if ($boutique->entreprise_id)
                    @php $reseau = $boutique->reseau(); @endphp
                    <div class="small mt-1"><i class="bi bi-diagram-3 me-1"></i>Réseau de {{ $reseau->count() }} boutiques :
                        {!! $reseau->map(fn ($r) => '<a href="'.e(route('admin.boutiques.show', $r)).'" class="'.($r->id === $boutique->id ? 'fw-bold' : '').'">'.e($r->nom).'</a>')->implode(', ') !!}
                        — une licence par boutique.</div>
                @endif</div>
        </div>
        @php
            $telClient = $boutique->responsable_telephone ?: $boutique->telephone;
            $waRelance = lien_whatsapp($telClient, \App\Support\MessagesClient::relance($boutique));
        @endphp
        <div class="d-flex flex-wrap gap-2">
            @if ($waRelance)
                <a href="{{ $waRelance }}" target="_blank" rel="noopener" class="btn btn-success"><i class="bi bi-whatsapp me-1"></i>Relancer (licence)</a>
                <a href="tel:{{ $telClient }}" class="btn btn-outline-primary" title="Appeler {{ $telClient }}"><i class="bi bi-telephone"></i></a>
            @endif
            <a href="{{ route('admin.annonces.create', ['boutique_id' => $boutique->id]) }}" class="btn btn-outline-primary"><i class="bi bi-megaphone me-1"></i>Envoyer un message</a>
            <a href="{{ route('admin.boutiques.edit', $boutique) }}" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>Modifier la fiche</a>
            <form method="post" action="{{ route('admin.boutiques.statut', $boutique) }}"
                  data-confirmer="{{ $boutique->statut === 'suspendu' ? 'Réactiver cette boutique ? Ses utilisateurs pourront de nouveau travailler.' : 'Suspendre cette boutique ? Ses utilisateurs ne pourront plus travailler (les données sont conservées).' }}"
                  data-confirmer-titre="{{ $boutique->statut === 'suspendu' ? 'Réactiver la boutique' : 'Suspendre la boutique' }}"
                  data-confirmer-bouton="{{ $boutique->statut === 'suspendu' ? 'Oui, réactiver' : 'Oui, suspendre' }}">@csrf
                <button class="btn {{ $boutique->statut === 'suspendu' ? 'btn-success' : 'btn-outline-danger' }}">
                    <i class="bi bi-{{ $boutique->statut === 'suspendu' ? 'play-circle' : 'pause-circle' }} me-1"></i>{{ $boutique->statut === 'suspendu' ? 'Réactiver' : 'Suspendre' }}</button></form>
        </div>
    </div>

    @if (session('recu_licence') && ($dernier = \App\Models\PaiementLicence::with('boutique')->find(session('recu_licence'))))
        <div class="alert alert-success d-flex flex-wrap align-items-center gap-2"><i class="bi bi-receipt"></i> Reçu prêt à remettre au client :
            <a href="{{ route('admin.paiements.recu', $dernier) }}" target="_blank" class="alert-link">ouvrir le reçu PDF</a>
            @if ($wa = lien_whatsapp($telClient, \App\Support\MessagesClient::paiement($dernier)))
                <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn btn-sm btn-success ms-auto"><i class="bi bi-whatsapp me-1"></i>Confirmer le paiement par WhatsApp</a>
            @endif</div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="bloc kpi h-100"><div class="etiquette">Échéance de la licence</div>
            <div class="valeur {{ $j !== null && $j < 0 ? 'text-danger' : '' }}">{{ $boutique->abonnement_expire_le?->format('d/m/Y') ?? 'Sans échéance' }}</div>
            @if ($j !== null)<div class="small text-doux">{{ $j < 0 ? 'expirée depuis '.abs($j).' j' : ($j === 0 ? "expire aujourd'hui" : 'dans '.$j.' jour(s)') }}</div>@endif</div></div>
        <div class="col-6 col-md-3"><div class="bloc kpi h-100"><div class="etiquette">Total payé</div><div class="valeur montant">{{ gnf($totalPaye) }}</div>
            <div class="small text-doux">Formule {{ $boutique->plan?->nom ?? '—' }} · {{ gnf($boutique->plan?->prix_mensuel ?? 0) }}/mois</div></div></div>
        <div class="col-6 col-md-3"><div class="bloc kpi h-100"><div class="etiquette">Ventes ce mois</div><div class="valeur montant">{{ gnf($stats['ca_mois']) }}</div>
            <div class="small text-doux">{{ $stats['ventes'] }} vente(s) au total · {{ $stats['produits'] }} produit(s)</div></div></div>
        <div class="col-6 col-md-3"><div class="bloc kpi h-100"><div class="etiquette">Dernière activité</div>
            <div class="valeur">{{ $stats['derniere_connexion'] ? \Carbon\Carbon::parse($stats['derniere_connexion'])->format('d/m/Y') : '—' }}</div>
            <div class="small text-doux">Dernière vente : {{ $stats['derniere_vente'] ? \Carbon\Carbon::parse($stats['derniere_vente'])->format('d/m/Y') : 'aucune' }}</div></div></div>
    </div>

    @include('admin.boutiques._formule')

    <div class="row g-3">
        <div class="col-xl-4">
            {{-- Paiement de licence : prolonge automatiquement l'accès --}}
            <form method="post" action="{{ route('admin.paiements.store', $boutique) }}" class="bloc" id="formPaiement"
                  data-confirmer="Enregistrer ce paiement ? La licence sera prolongée en conséquence." data-confirmer-titre="Confirmer le paiement" data-confirmer-bouton="Oui, enregistrer">
                @csrf
                <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-cash-coin me-1"></i>Enregistrer un paiement</h2></div>
                <div class="bloc-corps row g-2">
                    <div class="col-12"><label class="form-label small mb-1" for="p_plan">Formule</label>
                        <select name="plan_id" id="p_plan" class="form-select">
                            @foreach ($plans as $p)<option value="{{ $p->id }}" data-prix="{{ $p->prix_mensuel }}" @selected($boutique->plan_id == $p->id)>{{ $p->nom }} — {{ gnf($p->prix_mensuel) }}/mois</option>@endforeach
                        </select></div>
                    <div class="col-6"><label class="form-label small mb-1" for="p_duree">Durée payée</label>
                        <select name="duree" id="p_duree" class="form-select">
                            @foreach (['1' => '1 mois', '3' => '3 mois', '6' => '6 mois', '12' => '12 mois', '24' => '24 mois', '36' => '36 mois', 'illimitee' => 'Licence à vie'] as $k => $l)
                                <option value="{{ $k }}" @selected((string) $k === '12')>{{ $l }}</option>@endforeach
                        </select></div>
                    <div class="col-6"><label class="form-label small mb-1" for="p_montant">Montant reçu (GNF)</label>
                        <input name="montant" id="p_montant" data-montant inputmode="numeric" class="form-control text-end" required></div>
                    <div class="col-6"><label class="form-label small mb-1" for="p_mode">Mode</label>
                        <select name="mode" id="p_mode" class="form-select" data-autre="autre" data-autre-placeholder="Précisez le mode">
                            @foreach (config('gestion.modes_paiement') as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div class="col-6"><label class="form-label small mb-1" for="p_date">Payé le</label>
                        <input type="date" name="paye_le" id="p_date" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="form-control" required></div>
                    <div class="col-12"><input name="reference" class="form-control form-control-sm" placeholder="N° de transaction / reçu (facultatif)" aria-label="Référence"></div>
                    <div class="col-12"><input name="note" class="form-control form-control-sm" placeholder="Note (facultatif)" aria-label="Note"></div>
                    <div class="col-12 small text-doux" id="p_apercu"></div>
                    <div class="col-12 d-grid"><button class="btn btn-primary">Enregistrer et prolonger</button></div>
                </div>
            </form>

            <div class="bloc mt-3">
                <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-person-vcard me-1"></i>Coordonnées</h2></div>
                <div class="bloc-corps small vstack gap-1">
                    <div><span class="text-doux">Responsable :</span> <strong>{{ $boutique->responsable_nom ?: '—' }}</strong>
                        @if ($boutique->responsable_telephone) · <a href="tel:{{ $boutique->responsable_telephone }}">{{ $boutique->responsable_telephone }}</a>@endif</div>
                    <div><span class="text-doux">Boutique :</span> {{ $boutique->telephone ?: '—' }}</div>
                    <div><span class="text-doux">E-mail :</span> {{ $boutique->email ?: '—' }}</div>
                    <div><span class="text-doux">Adresse :</span> {{ collect([$boutique->adresse, $boutique->ville])->filter()->implode(', ') ?: '—' }}</div>
                    <div><span class="text-doux">RCCM / NIF :</span> {{ $boutique->rccm ?: '—' }} / {{ $boutique->nif ?: '—' }}</div>
                    @if ($boutique->notes_internes)
                        <div class="mt-2 p-2 rounded" style="background:var(--ambre-pale);white-space:pre-line"><i class="bi bi-journal-text me-1"></i>{{ $boutique->notes_internes }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="bloc">
                <div class="bloc-entete"><h2 class="mb-0">Historique des paiements</h2>
                    <a href="{{ route('admin.paiements.index', ['boutique_id' => $boutique->id, 'du' => $boutique->created_at->toDateString()]) }}" class="small">Tout voir</a></div>
                <div class="table-responsive"><table class="table">
                    <thead><tr><th>N°</th><th>Payé le</th><th>Période couverte</th><th>Mode</th><th class="text-end">Montant</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($paiements as $p)
                        <tr><td class="fw-semibold">{{ $p->numero }}</td><td>{{ $p->paye_le->format('d/m/Y') }}</td>
                            <td class="small">{{ $p->libellePeriode() }}</td>
                            <td class="small">{{ $p->libelleMode() }}@if ($p->reference)<div class="text-doux">{{ $p->reference }}</div>@endif</td>
                            <td class="text-end montant fw-semibold">{{ gnf($p->montant) }}</td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('admin.paiements.recu', $p) }}" target="_blank" class="btn btn-sm btn-light" title="Reçu PDF"><i class="bi bi-receipt"></i></a>
                                <form method="post" action="{{ route('admin.paiements.destroy', $p) }}" class="d-inline"
                                      data-confirmer="Supprimer le paiement {{ $p->numero }} saisi par erreur ? S'il s'agit du dernier, l'échéance de la licence sera rétablie.">@csrf @method('delete')
                                    <button class="btn btn-sm btn-light text-danger" aria-label="Annuler ce paiement"><i class="bi bi-trash"></i></button></form>
                            </td></tr>
                    @empty
                        <tr><td colspan="6" class="vide"><i class="bi bi-cash-coin"></i>Aucun paiement enregistré.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </div>

            <div class="bloc mt-3">
                <div class="bloc-entete"><h2 class="mb-0">Utilisateurs de la boutique</h2><span class="small text-doux">{{ $utilisateurs->where('actif', true)->count() }} actif(s)</span></div>
                <div class="table-responsive"><table class="table">
                    <thead><tr><th>Nom</th><th>E-mail</th><th>Rôle</th><th>Dernière connexion</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($utilisateurs as $u)
                        @include('admin.utilisateurs._ligne', ['u' => $u, 'avecBoutique' => false])
                    @endforeach
                    </tbody>
                </table></div>
            </div>

            <div class="bloc mt-3">
                <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-clock-history me-1"></i>Journal (15 dernières actions)</h2></div>
                <table class="table"><tbody>
                    @forelse ($journal as $j)
                        <tr><td class="small text-doux text-nowrap" style="width:1%">{{ \Carbon\Carbon::parse($j->created_at)->format('d/m/Y H:i') }}</td>
                            <td class="small">{{ $j->description }}</td>
                            <td class="small text-doux text-end">{{ $j->user?->est_super_admin ? 'Vous' : $j->user?->nomComplet() }}</td></tr>
                    @empty
                        <tr><td class="vide">Aucune action enregistrée.</td></tr>
                    @endforelse
                </tbody></table>
            </div>

            <div class="bloc mt-3">
                <div class="bloc-entete"><h2 class="mb-0">Demandes d'assistance</h2></div>
                <table class="table"><tbody>
                    @forelse ($demandes as $d)
                        <tr><td><a href="{{ route('admin.demandes.show', $d) }}" class="fw-semibold">{{ $d->sujet }}</a>
                                @unless ($d->lue_proprietaire)<span class="badge bg-danger ms-1">Nouveau</span>@endunless
                                <div class="small text-doux">{{ $d->libelleCategorie() }}</div></td>
                            <td><span class="etat {{ $d->classeStatut() }}">{{ $d->libelleStatut() }}</span></td>
                            <td class="text-end small text-doux">{{ $d->dernier_message_le?->format('d/m/Y H:i') }}</td></tr>
                    @empty
                        <tr><td class="vide">Aucune demande.</td></tr>
                    @endforelse
                </tbody></table>
            </div>
        </div>
    </div>
@endsection
@push('scripts')
<script>
    // Montant proposé = prix mensuel × durée ; aperçu de la nouvelle échéance
    (() => {
        const plan = document.getElementById('p_plan'), duree = document.getElementById('p_duree'), montant = document.getElementById('p_montant'),
            apercu = document.getElementById('p_apercu');
        const echeance = @json($boutique->abonnement_expire_le && $boutique->abonnement_expire_le->isFuture() ? $boutique->abonnement_expire_le->toDateString() : null);
        const maj = () => {
            const prix = Number(plan.selectedOptions[0]?.dataset.prix || 0);
            const mois = duree.value === 'illimitee' ? 0 : Number(duree.value);
            if (mois) { montant.value = (prix * mois).toLocaleString('fr-FR').replace(/ | /g, ' '); }
            const depart = echeance ? new Date(echeance + 'T00:00:00') : new Date();
            if (echeance) depart.setDate(depart.getDate() + 1);
            let texte = 'Licence à vie : aucune échéance.';
            if (mois) { const fin = new Date(depart); fin.setMonth(fin.getMonth() + mois); fin.setDate(fin.getDate() - 1);
                texte = 'Nouvelle échéance : ' + fin.toLocaleDateString('fr-FR') + (echeance ? ' (à la suite de la licence en cours)' : ''); }
            apercu.textContent = texte;
        };
        plan.addEventListener('change', maj); duree.addEventListener('change', maj); maj();
    })();
</script>
@endpush

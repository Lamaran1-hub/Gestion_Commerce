@extends('layouts.app')
@section('titre', 'Réception '.$appro->numero)
@section('contenu')
    <div class="entete-page">
        <div><h1>Réception {{ $appro->numero }}</h1>
            <div class="text-doux">{{ $appro->date_appro->format('d/m/Y') }} · {{ $appro->fournisseur?->nom ?? 'Fournisseur non précisé' }} · saisie par {{ $appro->auteur?->nomComplet() }}</div></div>
        <div class="d-flex gap-2"><button onclick="window.print()" class="btn btn-outline-primary"><i class="bi bi-printer me-1"></i>Imprimer</button>
            <a href="{{ route('approvisionnements.create') }}" class="btn btn-primary">Nouvelle réception</a></div>
    </div>
    @if (session('bon_retour'))
        <div class="alert alert-success d-flex flex-wrap align-items-center gap-2"><i class="bi bi-file-earmark-text"></i>
            <span class="me-auto">Faites signer le <strong>bon de retour</strong> par le livreur ou le fournisseur, et gardez-en une copie.</span>
            <a href="{{ session('bon_retour') }}" target="_blank" class="btn btn-sm btn-success"><i class="bi bi-printer me-1"></i>Imprimer le bon de retour</a></div>
    @endif
    <div class="bloc">
        <table class="table">
            <thead><tr><th>Produit</th><th class="text-end">Quantité</th><th class="text-end">Prix d'achat unitaire</th><th class="text-end">Total</th></tr></thead>
            <tbody>
            @foreach ($appro->lignes as $l)
                <tr><td>{{ $l->designation }}@if ($l->date_peremption)<div class="small {{ $l->date_peremption->isPast() ? 'text-danger' : 'text-doux' }}"><i class="bi bi-calendar-x"></i> Périme le {{ $l->date_peremption->format('d/m/Y') }}</div>@endif</td><td class="text-end">{{ qte($l->quantite) }}@if ($l->quantite_retournee > 0)<div class="small text-warning-emphasis text-nowrap"><i class="bi bi-arrow-return-left"></i> {{ qte($l->quantite_retournee) }} renvoyé(s)</div>@endif</td><td class="text-end montant">{{ gnf($l->prix_achat_unitaire) }}</td><td class="text-end montant">{{ gnf($l->total) }}</td></tr>
            @endforeach
            </tbody>
            <tfoot><tr><td colspan="3" class="text-end fw-bold">Total</td><td class="text-end fw-bold montant">{{ gnf($appro->total) }}</td></tr>
                @if ($appro->montant_retourne)
                    <tr><td colspan="3" class="text-end text-doux">Renvoyé au fournisseur</td><td class="text-end montant text-doux">− {{ gnf($appro->montant_retourne) }}</td></tr>
                    <tr><td colspan="3" class="text-end fw-bold">Net</td><td class="text-end fw-bold montant">{{ gnf($appro->netAPayer()) }}</td></tr>
                @endif</tfoot>
        </table>
    </div>
    @if ($appro->note)<div class="bloc bloc-corps mt-3"><strong>Note :</strong> {{ $appro->note }}</div>@endif

    <div class="bloc mt-3">
        <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-cash-coin me-1"></i>Règlement du fournisseur</h2>
            @if ($appro->resteAPayer() === 0)<span class="etat etat-ok">Réglée</span>
            @elseif ($appro->enRetard())<span class="etat etat-rupture">En retard : échéance {{ $appro->echeance->format('d/m/Y') }}</span>
            @else<span class="etat etat-alerte">Reste {{ gnf($appro->resteAPayer()) }}{{ $appro->echeance ? ' · échéance '.$appro->echeance->format('d/m/Y') : '' }}</span>@endif</div>
        <table class="table mb-0"><tbody>
            @forelse ($appro->paiements as $p)
                <tr><td>{{ $p->date_paiement->format('d/m/Y H:i') }}</td><td>{{ $p->libelleMode() }}{{ $p->reference ? ' · '.$p->reference : '' }}</td>
                    <td class="text-doux small">{{ $p->auteur?->nomComplet() }}</td>
                    <td class="text-end montant">@if ($p->montant < 0)<span class="text-success">+ {{ gnf(-$p->montant) }}</span><div class="small text-doux">{{ $p->mode === \App\Models\PaiementFournisseur::MODE_AVOIR ? 'mis en avoir' : 'remboursé' }}</div>@else{{ gnf($p->montant) }}@endif</td></tr>
            @empty
                <tr><td class="text-doux">Aucun paiement : réception à crédit.</td></tr>
            @endforelse
        </tbody></table>
        @if ($appro->resteAPayer() > 0)
            <div class="bloc-corps border-top"><a href="{{ route('fournisseurs.dettes') }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-journal-minus me-1"></i>Régler ce fournisseur</a></div>
        @endif
    </div>

    @if ($appro->retours->isNotEmpty())
        <div class="bloc mt-3">
            <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-arrow-return-left me-1"></i>Retours au fournisseur</h2></div>
            <table class="table mb-0"><tbody>
                @foreach ($appro->retours as $r)
                    <tr><td class="fw-semibold text-nowrap">{{ $r->numero }}<div class="small text-doux">{{ $r->created_at->format('d/m/Y H:i') }} · {{ $r->auteur?->nomComplet() }}</div></td>
                        <td class="small">{{ $r->lignes->map(fn ($l) => qte($l->quantite).' × '.$l->designation)->implode(', ') }}<div class="text-doux">{{ $r->motif }}</div></td>
                        <td class="text-end"><span class="montant">− {{ gnf($r->montant) }}</span><div class="small text-doux">{{ $r->libelleReglement() }}</div>
                            <a href="{{ route('retours-fournisseur.bon', $r) }}" target="_blank" class="small"><i class="bi bi-printer"></i> Bon de retour</a></td></tr>
                @endforeach
            </tbody></table>
        </div>
    @endif

    @php($retournables = $appro->lignes->filter(fn ($l) => $l->quantiteRetournable() > 0))
    @if ($appro->fournisseur_id && $retournables->isNotEmpty())
        <details class="bloc mt-3" id="retourFournisseur" @if ($errors->has('quantites') || old('quantites')) open @endif>
            <summary class="bloc-entete" style="cursor:pointer"><h2 class="mb-0"><i class="bi bi-box-arrow-left me-1"></i>Renvoyer de la marchandise au fournisseur</h2></summary>
            <form method="post" action="{{ route('retours-fournisseur.store', $appro) }}" class="bloc-corps"
                  data-confirmer="Enregistrer ce retour à {{ $appro->fournisseur?->nom }} ? Les produits sortiront du stock."
                  data-confirmer-titre="Retour fournisseur" data-confirmer-bouton="Oui, enregistrer le retour" data-confirmer-type="alerte">
                @csrf
                <table class="table table-sm">
                    <thead><tr><th>Produit</th><th class="text-end">Reçu</th><th class="text-end">En stock</th><th style="width:110px">À renvoyer</th></tr></thead>
                    <tbody>
                    @foreach ($retournables as $l)
                        @php($enStock = $l->produit ? max(0, floor($l->produit->stock / ($l->facteur ?: 1) * 100) / 100) : 0)
                        @php($max = min($l->quantiteRetournable(), $enStock))
                        <tr><td>{{ $l->designation }}<div class="small text-doux">{{ gnf($l->prix_achat_unitaire) }} / {{ $l->unite ?: 'unité' }}</div></td>
                            <td class="text-end">{{ qte($l->quantiteRetournable()) }}</td>
                            <td class="text-end {{ $enStock < $l->quantiteRetournable() ? 'text-warning-emphasis' : '' }}">{{ qte($enStock) }}</td>
                            <td><input type="number" step="0.01" min="0" max="{{ $max }}" name="quantites[{{ $l->id }}]" value="{{ old('quantites.'.$l->id) }}"
                                       data-prix="{{ $l->prix_achat_unitaire }}" class="form-control form-control-sm text-end qte-retour"
                                       aria-label="Quantité à renvoyer de {{ $l->designation }}" @disabled($max <= 0)></td></tr>
                    @endforeach
                    </tbody>
                </table>
                <div class="row g-2">
                    <div class="col-sm-6"><label class="form-label small mb-1" for="motif_retour_f">Motif</label>
                        <select name="motif" id="motif_retour_f" class="form-select form-select-sm" required data-autre>
                            <option value="">Choisir…</option>
                            @foreach (config('gestion.motifs_retour_fournisseur') as $m)<option @selected(old('motif') === $m)>{{ $m }}</option>@endforeach
                        </select></div>
                    <div class="col-sm-6"><label class="form-label small mb-1" for="mode_remboursement_f">Si c'était déjà payé, le fournisseur…</label>
                        <select name="mode_remboursement" id="mode_remboursement_f" class="form-select form-select-sm">
                            <option value="{{ \App\Models\PaiementFournisseur::MODE_AVOIR }}">accorde un avoir (pour une prochaine livraison)</option>
                            @foreach (config('gestion.modes_paiement') as $k => $lib)<option value="{{ $k }}" @selected(old('mode_remboursement') === $k)>rembourse : {{ $lib }}</option>@endforeach
                        </select></div>
                    <div class="col-12"><input name="note" value="{{ old('note') }}" maxlength="500" class="form-control form-control-sm" placeholder="Note (facultatif) : n° de lot, nom du livreur…" aria-label="Note"></div>
                </div>
                <div class="form-text" id="resumeRetourF" data-reste="{{ $appro->resteAPayer() }}">
                    La valeur renvoyée (au prix d'achat) se déduit d'abord de ce que vous devez encore sur cette réception ({{ gnf($appro->resteAPayer()) }}) ;
                    au-delà, le fournisseur vous rembourse ou vous accorde un avoir. On ne renvoie pas plus que ce qui est encore en stock.</div>
                <button class="btn btn-outline-warning mt-2"><i class="bi bi-box-arrow-left me-1"></i>Enregistrer le retour</button>
            </form>
        </details>
        <script>
            (() => {
                const champs = document.querySelectorAll('.qte-retour'), resume = document.getElementById('resumeRetourF');
                const texteInitial = resume.innerHTML, reste = +resume.dataset.reste;
                const gnf = (n) => new Intl.NumberFormat('fr-FR').format(Math.round(n)).replace(/[  ]/g, ' ') + ' GNF';
                const maj = () => {
                    const valeur = [...champs].reduce((s, c) => s + (parseFloat(c.value.replace(',', '.')) || 0) * +c.dataset.prix, 0);
                    if (!valeur) { resume.innerHTML = texteInitial; return; }
                    const deduit = Math.min(valeur, reste), rendu = valeur - deduit;
                    resume.innerHTML = '<strong>Valeur renvoyée : ' + gnf(valeur) + '.</strong> ' + (deduit ? 'Déduit de votre dette : ' + gnf(deduit) + '. ' : '')
                        + (rendu ? 'À récupérer auprès du fournisseur : <strong>' + gnf(rendu) + '</strong> (remboursement ou avoir).' : '');
                };
                champs.forEach(c => c.addEventListener('input', maj)); maj();
            })();
        </script>
    @endif
@endsection

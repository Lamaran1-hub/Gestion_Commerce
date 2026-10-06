@extends('layouts.app')
@section('titre', 'Commande '.$c->numero)
@section('contenu')
    @php
        $f = $c->fournisseur;
        $message = 'Bonjour'.($f?->contact ? ' '.$f->contact : '').", commande {$c->numero} de ".boutique()->nom." :\n"
            .$c->lignes->map(function ($l) {
                $p = $l->produit;
                $q = $p && $p->aConditionnement() && fmod($l->quantite, (float) $p->qte_conditionnement) == 0
                    ? qte($l->quantite / $p->qte_conditionnement).' '.$p->conditionnement.'(s)' : qte($l->quantite).' '.($p?->unite ?? '');
                return '- '.$l->designation.' : '.$q;
            })->implode("\n")
            .($c->livraison_prevue_le ? "\nLivraison souhaitée le ".$c->livraison_prevue_le->format('d/m/Y').'.' : '')."\nMerci de confirmer prix et délai.";
    @endphp
    <div class="entete-page">
        <div><h1>Commande {{ $c->numero }} <span class="etat {{ $c->classeEtat() }} align-middle">{{ $c->libelleEtat() }}{{ $c->enRetard() ? ' · en retard' : '' }}</span></h1>
            <div class="text-doux">{{ $f?->nom ?? 'Fournisseur non précisé' }} · du {{ $c->date_commande->format('d/m/Y') }}
                @if ($c->livraison_prevue_le) · livraison prévue le {{ $c->livraison_prevue_le->format('d/m/Y') }}@endif · par {{ $c->auteur?->nomComplet() }}</div></div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('commandes-fournisseur.pdf', $c) }}" target="_blank" class="btn btn-outline-primary"><i class="bi bi-file-earmark-pdf me-1"></i>Bon de commande</a>
            @if ($c->estEnAttente() && $f?->telephone && ($wa = lien_whatsapp($f->telephone, $message)))
                <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn btn-success"><i class="bi bi-whatsapp me-1"></i>Envoyer au fournisseur</a>
            @endif
            @if ($c->estEnAttente())
                <a href="{{ route('approvisionnements.create', ['commande' => $c->id]) }}" class="btn btn-primary" id="btnReceptionner"><i class="bi bi-truck me-1"></i>Réceptionner</a>
            @endif
        </div>
    </div>

    <div class="bloc">
        <table class="table mb-0">
            <thead><tr><th>Produit</th><th class="text-end">Commandé</th><th class="text-end">Reçu</th><th class="text-end">Reste à recevoir</th><th class="text-end">Prix estimé</th></tr></thead>
            <tbody>
            @foreach ($c->lignes as $l)
                <tr><td>{{ $l->designation }}@if ($l->produit?->aConditionnement())<div class="small text-doux">{{ $l->produit->libelleConditionnement() }}</div>@endif</td>
                    <td class="text-end">{{ qte($l->quantite) }} {{ $l->produit?->unite }}</td>
                    <td class="text-end">{{ qte($l->quantite_recue) }}</td>
                    <td class="text-end fw-semibold {{ $l->reste() > 0 && $c->estEnAttente() ? 'text-warning-emphasis' : 'text-doux' }}">{{ $l->reste() > 0 ? qte($l->reste()) : '—' }}</td>
                    <td class="text-end montant">{{ gnf($l->prix_achat_estime) }}</td></tr>
            @endforeach
            </tbody>
            <tfoot><tr><td colspan="4" class="text-end fw-bold">Total estimé</td><td class="text-end fw-bold montant">{{ gnf($c->total_estime) }}</td></tr></tfoot>
        </table>
    </div>
    @if ($c->note)<div class="bloc bloc-corps mt-3"><strong>Note :</strong> {{ $c->note }}</div>@endif

    <div class="bloc mt-3">
        <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-truck me-1"></i>Réceptions</h2></div>
        <table class="table mb-0"><tbody>
            @forelse ($c->receptions as $a)
                <tr><td><a href="{{ route('approvisionnements.show', $a) }}" class="fw-semibold">{{ $a->numero }}</a></td><td>{{ $a->date_appro->format('d/m/Y') }}</td>
                    <td class="text-end montant">{{ gnf($a->total) }}</td></tr>
            @empty
                <tr><td class="text-doux">Rien de reçu pour l'instant.</td></tr>
            @endforelse
        </tbody></table>
        @if ($c->estEnAttente())
            <form method="post" action="{{ route('commandes-fournisseur.solder', $c) }}" class="bloc-corps border-top"
                  data-confirmer="{{ $c->receptions->isEmpty() ? 'Annuler la commande '.$c->numero.' ?' : 'Le reste de la commande '.$c->numero.' n\'arrivera pas : clore la commande ?' }}">@csrf
                <button class="btn btn-sm btn-link text-danger p-0">{{ $c->receptions->isEmpty() ? 'Annuler la commande' : 'Le reste n\'arrivera pas : clore la commande' }}</button></form>
        @endif
    </div>
@endsection

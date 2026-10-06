<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 18mm 15mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1C2622; }
    .titre { font-size: 20px; font-weight: bold; color: {{ $boutique->couleur }}; }
    table { width: 100%; border-collapse: collapse; }
    .lignes th { background: {{ $boutique->couleur }}; color: {{ \App\Models\Boutique::texteSur($boutique->couleur) }}; padding: 6px; text-align: left; font-size: 10px; }
    .lignes td { padding: 6px; border-bottom: 1px solid #DCE3DD; }
    .r, .lignes th.r { text-align: right; } .b { font-weight: bold; } .doux { color: #5E6B65; }
    .cadre { border: 1px solid #DCE3DD; border-left: 3px solid {{ $boutique->couleurAccent() }}; padding: 8px 10px; }
</style>
</head>
<body>
    @component('pdf.partials.entete', ['boutique' => $boutique])
        <div class="titre">BON DE RETOUR</div>
        <div class="b">N° {{ $retour->numero }}</div>
        <div>Date : {{ $retour->created_at->format('d/m/Y') }}</div>
        <div>Réception d'origine : {{ $retour->approvisionnement?->numero }} du {{ $retour->approvisionnement?->date_appro->format('d/m/Y') }}</div>
    @endcomponent
    <table style="margin:14px 0"><tr><td style="width:50%;vertical-align:top"><div class="doux">Motif du retour</div><div class="b">{{ $retour->motif }}</div>
            @if ($retour->note)<div>{{ $retour->note }}</div>@endif</td>
        <td class="cadre"><div class="doux">Fournisseur</div><div class="b">{{ $retour->fournisseur?->nom ?? '—' }}</div>
            @if ($retour->fournisseur?->contact)<div>{{ $retour->fournisseur->contact }}</div>@endif
            @if ($retour->fournisseur?->telephone)<div>{{ $retour->fournisseur->telephone }}</div>@endif</td></tr></table>
    <table class="lignes">
        <thead><tr><th>Désignation</th><th class="r">Quantité renvoyée</th><th class="r">Prix d'achat</th><th class="r">Montant</th></tr></thead>
        <tbody>
        @foreach ($retour->lignes as $l)
            <tr><td>{{ $l->designation }}</td><td class="r">{{ qte($l->quantite) }} {{ $l->unite }}</td>
                <td class="r">{{ gnf($l->prix_achat_unitaire) }}</td><td class="r">{{ gnf($l->total) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><td colspan="3" class="r b">Valeur de la marchandise renvoyée</td><td class="r b">{{ gnf($retour->montant) }}</td></tr></tfoot>
    </table>
    <div class="cadre" style="margin-top:14px">
        @if ($retour->deduit)<div>Déduit de la somme due au fournisseur : <span class="b">{{ gnf($retour->deduit) }}</span></div>@endif
        @if ($retour->rembourse)
            <div>{{ $retour->mode_remboursement === \App\Models\PaiementFournisseur::MODE_AVOIR ? 'Avoir à valoir sur une prochaine livraison' : 'Remboursé par le fournisseur ('.libelle_mode($retour->mode_remboursement).')' }} :
                <span class="b">{{ gnf($retour->rembourse) }}</span></div>
        @endif
    </div>
    <table style="margin-top:34px"><tr>
        <td class="doux">Pour {{ $boutique->nom }}@if ($retour->auteur)<br>{{ $retour->auteur->nomComplet() }}@endif<br><br><br>____________________</td>
        <td class="r doux">Reçu par le fournisseur (nom, date, signature)<br><br><br><br>____________________</td></tr></table>
</body>
</html>

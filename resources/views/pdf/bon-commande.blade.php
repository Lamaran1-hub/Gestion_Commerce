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
        <div class="titre">BON DE COMMANDE</div>
        <div class="b">N° {{ $numero }}</div>
        <div>Date : {{ ($date ?? now())->format('d/m/Y') }}</div>
        @if (! empty($livraisonPrevue))<div>Livraison souhaitée : {{ $livraisonPrevue->format('d/m/Y') }}</div>@endif
    @endcomponent
    <table style="margin:14px 0"><tr><td style="width:55%"></td>
        <td class="cadre"><div class="doux">Fournisseur</div><div class="b">{{ $fournisseur?->nom ?? '—' }}</div>
            @if ($fournisseur?->contact)<div>{{ $fournisseur->contact }}</div>@endif
            @if ($fournisseur?->telephone)<div>{{ $fournisseur->telephone }}</div>@endif</td></tr></table>
    <table class="lignes">
        <thead><tr><th>Désignation</th><th class="r">Quantité</th><th class="r">Prix d'achat indicatif</th><th class="r">Montant estimé</th></tr></thead>
        <tbody>
        @foreach ($lignes as $l)
            @php($p = $l['produit'])
            <tr><td>{{ $p->designation }}{{ $p->code_barre ? ' — '.$p->code_barre : '' }}</td>
                <td class="r">{{ qte($l['quantite']) }} {{ $p->unite }}@if ($p->aConditionnement())<br><span class="doux">soit {{ qte($l['quantite'] / $p->qte_conditionnement) }} {{ $p->conditionnement }}(s)</span>@endif</td>
                <td class="r">{{ gnf($p->prix_achat) }}</td><td class="r">{{ gnf($l['cout']) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><td colspan="3" class="r b">Total estimé</td><td class="r b">{{ gnf($lignes->sum('cout')) }}</td></tr></tfoot>
    </table>
    <p class="doux" style="margin-top:14px">Merci de confirmer les prix, la disponibilité et le délai de livraison avant expédition.</p>
    <table style="margin-top:30px"><tr><td class="doux">Pour {{ $boutique->nom }}</td><td class="r doux">Signature et cachet<br><br><br>____________________</td></tr></table>
</body>
</html>

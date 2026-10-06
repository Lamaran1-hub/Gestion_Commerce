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
    .lignes td { padding: 7px 6px; border-bottom: 1px solid #DCE3DD; }
    .r, .lignes th.r { text-align: right; } .c { text-align: center; } .b { font-weight: bold; } .doux { color: #5E6B65; }
    .cadre { border: 1px solid #DCE3DD; border-left: 3px solid {{ $boutique->couleurAccent() }}; padding: 8px 10px; }
    .encaisser { border: 2px solid #B23A2E; color: #B23A2E; padding: 8px 10px; font-size: 13px; font-weight: bold; margin-top: 12px; }
    .case { display: inline-block; width: 10px; height: 10px; border: 1px solid #1C2622; }
</style>
</head>
<body>
    @component('pdf.partials.entete', ['boutique' => $boutique])
        <div class="titre">BON DE LIVRAISON</div>
        <div class="b">Vente N° {{ $vente->numero }}</div>
        <div>Date de la vente : {{ $vente->date_vente->format('d/m/Y') }}</div>
        @if ($vente->livraison_prevue_le)<div>Livraison prévue : {{ $vente->livraison_prevue_le->format('d/m/Y') }}</div>@endif
    @endcomponent

    <table style="margin:14px 0"><tr>
        <td style="width:50%;vertical-align:top;padding-right:10px">
            <div class="doux">Livreur</div><div class="b">{{ $vente->livreur ?: '____________________' }}</div>
        </td>
        <td class="cadre"><div class="doux">Livrer à</div>
            <div class="b">{{ $vente->client?->nomComplet() ?? 'Client comptoir' }}</div>
            <div>{{ $vente->livraison_adresse }}</div>
            @if ($vente->livraison_contact)<div>Contact : {{ $vente->livraison_contact }}</div>
            @elseif ($vente->client?->telephone)<div>Tél. : {{ $vente->client->telephone }}</div>@endif
        </td></tr></table>

    <table class="lignes">
        <thead><tr><th>Désignation</th><th class="r">Quantité</th><th class="c" style="width:70px">Reçu</th></tr></thead>
        <tbody>
        @foreach ($vente->lignes->where('quantite', '>', 0) as $l)
            <tr><td>{{ $l->designation }}</td><td class="r b">{{ qte($l->quantite) }} {{ $l->unite }}</td><td class="c"><span class="case"></span></td></tr>
        @endforeach
        </tbody>
    </table>

    @if ($vente->resteAPayer() > 0)
        <div class="encaisser">Montant à encaisser à la livraison : {{ gnf($vente->resteAPayer()) }}</div>
    @else
        <p class="doux" style="margin-top:12px">Marchandise entièrement payée : rien à encaisser à la livraison.</p>
    @endif
    <p class="doux" style="margin-top:12px">Vérifiez la marchandise à la réception. Toute réserve (article manquant ou abîmé) doit être notée ci-dessous avant de signer.</p>
    <div style="border:1px solid #DCE3DD;height:50px;margin-top:4px"><span class="doux" style="font-size:9px;padding:3px">Réserves :</span></div>

    <table style="margin-top:28px"><tr>
        <td class="doux">Le livreur<br><br><br>____________________</td>
        <td class="r doux">Reçu par (nom, date, signature)<br><br><br>____________________</td></tr></table>
</body>
</html>

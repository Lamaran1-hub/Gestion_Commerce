<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="darkreader-lock">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bon d'avoir {{ $reference }} · {{ $boutique->nom }}</title>
<link rel="icon" href="{{ $boutique->logoUrl() ?? logo_plateforme() }}">
<style>
    /* Ticket pour imprimante thermique 80 mm (même format que le reçu de caisse) */
    @page { size: 80mm auto; margin: 3mm; }
    body { font-family: "DejaVu Sans Mono", "Courier New", monospace; font-size: 12px; width: 72mm; max-width: 100%; margin: 0 auto; color: #000; }
    .c { text-align: center; } .r { text-align: right; } .b { font-weight: bold; }
    .sep { border-top: 1px dashed #000; margin: 6px 0; }
    .cadre { border: 2px solid #000; padding: 6px; margin: 6px 0; text-align: center; }
    .montant { font-size: 20px; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; } td { vertical-align: top; padding: 1px 0; }
    img { max-width: 40mm; max-height: 22mm; }
    .actions { margin: 12px 0; text-align: center; font-family: sans-serif; display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; }
    .actions a, .actions button { font: 14px sans-serif; padding: 6px 10px; border: 1px solid #999; border-radius: 6px; background: #fff; color: #000; text-decoration: none; cursor: pointer; }
    .actions .wa { background: #1f8f4e; border-color: #1f8f4e; color: #fff; }
    @media print { .actions { display: none; } }
</style>
</head>
<body>
    <div class="actions">
        <button type="button" onclick="window.print()">Imprimer</button>
        @if ($whatsapp)<a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="wa" id="envoiWhatsapp">Envoyer par WhatsApp</a>@endif
        <button type="button" onclick="window.close()">Fermer</button>
    </div>
    <div class="c">
        @if ($boutique->logoUrl())<img src="{{ $boutique->logoUrl() }}" alt=""><br>@endif
        <div class="b" style="font-size:14px">{{ $boutique->nom }}</div>
        @if ($boutique->adresse || $boutique->ville)<div>{{ collect([$boutique->adresse, $boutique->ville])->filter()->implode(', ') }}</div>@endif
        @if ($boutique->telephone)<div>Tél : {{ $boutique->telephone }}</div>@endif
    </div>
    <div class="sep"></div>
    <div class="c b" style="font-size:15px">BON D'AVOIR</div>
    <div class="sep"></div>
    <div>Client : <span class="b">{{ $client->nomComplet() }}</span></div>
    @if ($client->code)<div>N° client : {{ $client->code }}</div>@endif
    <div>Réf. : {{ $reference }}</div>
    <div>Date : {{ $date->format('d/m/Y H:i') }}</div>

    @if ($retour)
        <div class="sep"></div>
        <div>Retour sur la vente {{ $retour->vente?->numero }} :</div>
        <table>
            @foreach ($retour->lignes as $l)
                <tr><td>{{ qte($l->quantite) }} x {{ $l->designation }}</td></tr>
            @endforeach
        </table>
        <div class="cadre"><div>Avoir accordé</div><div class="montant">{{ gnf($credite) }}</div></div>
        <table><tr class="b"><td>Solde d'avoir disponible</td><td class="r">{{ gnf($solde) }}</td></tr></table>
    @else
        <div class="cadre"><div>Solde d'avoir disponible</div><div class="montant">{{ gnf($solde) }}</div></div>
    @endif

    <div class="sep"></div>
    <div style="font-size:11px">
        • Avoir nominatif, valable uniquement chez {{ $boutique->nom }}.<br>
        • Déductible de vos prochains achats : donnez votre nom à la caisse.<br>
        • Non remboursable en espèces. Le solde exact est toujours celui de la caisse.
    </div>
    <div class="sep"></div>
    <div class="c">Code de contrôle : <span class="b">{{ $code }}</span></div>
    {{-- Pas le pied de facture : il annonce souvent « ni repris ni échangé », contradictoire sur un bon d'avoir --}}
    <div class="c" style="font-size:11px;margin-top:4px">Merci de votre fidélité et à bientôt !</div>
    <script>if (location.search.includes('imprimer')) window.addEventListener('load', () => window.print());</script>
</body>
</html>

<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="darkreader-lock">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reçu d'acompte {{ $d->numero }} · {{ $boutique->nom }}</title>
<link rel="icon" href="{{ $boutique->logoUrl() ?? logo_plateforme() }}">
<style>
    /* Ticket pour imprimante thermique 80 mm (même format que le reçu de caisse) */
    @page { size: 80mm auto; margin: 3mm; }
    body { font-family: "DejaVu Sans Mono", "Courier New", monospace; font-size: 12px; width: 72mm; max-width: 100%; margin: 0 auto; color: #000; background: #fff; }
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
        @if ($whatsapp)<a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="wa">Envoyer par WhatsApp</a>@endif
        <button type="button" onclick="window.close()">Fermer</button>
    </div>
    <div class="c">
        @if ($boutique->logoUrl())<img src="{{ $boutique->logoUrl() }}" alt=""><br>@endif
        <div class="b" style="font-size:14px">{{ $boutique->nom }}</div>
        @if ($boutique->adresse || $boutique->ville)<div>{{ collect([$boutique->adresse, $boutique->ville])->filter()->implode(', ') }}</div>@endif
        @if ($boutique->telephone)<div>Tél : {{ $boutique->telephone }}</div>@endif
    </div>
    <div class="sep"></div>
    <div class="c b" style="font-size:15px">{{ $a->montant < 0 ? "REMBOURSEMENT D'ACOMPTE" : "REÇU D'ACOMPTE" }}</div>
    <div class="sep"></div>
    <div>Client : <span class="b">{{ $d->nomClient() }}</span></div>
    @if ($d->client?->telephone ?? $d->client_telephone)<div>Tél. : {{ $d->client?->telephone ?? $d->client_telephone }}</div>@endif
    <div>Commande : {{ $d->numero }}</div>
    <div>Date : {{ $a->date_versement->format('d/m/Y H:i') }}</div>
    <div>Reçu par : {{ $a->auteur?->nomComplet() }}</div>
    <div class="sep"></div>
    <table>
        @foreach ($d->lignes as $l)
            <tr><td>{{ qte($l->quantite) }} x {{ $l->designation }}</td><td class="r">{{ gnf($l->total, false) }}</td></tr>
        @endforeach
    </table>
    <div class="sep"></div>
    <table><tr class="b"><td>Total de la commande</td><td class="r">{{ gnf($d->total_ttc) }}</td></tr></table>
    <div class="cadre"><div>{{ $a->montant < 0 ? 'Acompte rendu' : 'Acompte versé' }} ({{ libelle_mode($a->mode) }})</div><div class="montant">{{ gnf(abs($a->montant)) }}</div></div>
    @if ($a->montant > 0)
        <table>
            <tr><td>Total déjà versé</td><td class="r">{{ gnf($d->acompte) }}</td></tr>
            <tr class="b"><td>Reste à payer</td><td class="r">{{ gnf($d->resteAPayer()) }}</td></tr>
        </table>
        <div class="sep"></div>
        <div style="font-size:11px">
            • Présentez ce reçu à la livraison ou au retrait.<br>
            • Prix garantis jusqu'au {{ $d->valable_jusqu_au->format('d/m/Y') }}.
        </div>
    @endif
    <div class="sep"></div>
    <div class="c" style="font-size:11px">Merci de votre confiance !</div>
    <script>if (location.search.includes('imprimer')) window.addEventListener('load', () => window.print());</script>
</body>
</html>

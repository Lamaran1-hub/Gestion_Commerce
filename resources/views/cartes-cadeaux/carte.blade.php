<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="darkreader-lock">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Carte cadeau · {{ $boutique->nom }}</title>
<link rel="icon" href="{{ $boutique->logoUrl() ?? logo_plateforme() }}">
<style>
    /* Ticket pour imprimante thermique 80 mm (même format que le reçu de caisse) */
    @page { size: 80mm auto; margin: 3mm; }
    body { font-family: "DejaVu Sans Mono", "Courier New", monospace; font-size: 12px; width: 72mm; max-width: 100%; margin: 0 auto; color: #000; background: #fff; }
    .c { text-align: center; } .b { font-weight: bold; }
    .sep { border-top: 1px dashed #000; margin: 6px 0; }
    .cadre { border: 2px solid #000; padding: 8px 6px; margin: 8px 0; text-align: center; }
    .valeur { font-size: 24px; font-weight: bold; }
    .code { font-size: 20px; font-weight: bold; letter-spacing: 2px; margin-top: 4px; word-break: break-all; }
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
    <div class="c b" style="font-size:16px">CARTE CADEAU</div>
    @if ($c->beneficiaire)<div class="c">Pour : <span class="b">{{ $c->beneficiaire }}</span></div>@endif
    @if ($c->acheteur)<div class="c">De la part de : {{ $c->acheteur }}</div>@endif
    @if ($c->message)<div class="c" style="margin-top:4px;font-style:italic">« {{ $c->message }} »</div>@endif
    <div class="cadre">
        <div class="valeur">{{ gnf($c->montant) }}</div>
        @if ($c->solde !== $c->montant)<div>Solde restant : <span class="b">{{ gnf($c->solde) }}</span></div>@endif
        <div style="margin-top:6px">Code</div>
        <div class="code">{{ $c->codeLisible() }}</div>
    </div>
    <div style="font-size:11px">
        • À dépenser en une ou plusieurs fois.<br>
        @if ($c->expire_le)• Valable jusqu'au {{ $c->expire_le->format('d/m/Y') }}.<br>@endif
        • Présentez ce code à la caisse.<br>
        • Gardez ce code secret : il suffit pour utiliser la carte.
    </div>
    <div class="sep"></div>
    <div class="c" style="font-size:11px">Émise le {{ $c->created_at->format('d/m/Y') }} — Merci de votre confiance !</div>
    <script>if (location.search.includes('imprimer')) window.addEventListener('load', () => window.print());</script>
</body>
</html>

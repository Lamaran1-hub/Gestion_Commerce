<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="darkreader-lock">
<title>Étiquettes — {{ $boutique->nom }}</title>
<style>
    @page { size: A4; margin: 8mm 6mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: Arial, sans-serif; color: #000; background: #fff; }
    .barre { padding: 10px 16px; background: #f3f5f4; display: flex; gap: 12px; align-items: center; font-size: 14px; }
    .barre button { padding: 8px 16px; font-size: 14px; cursor: pointer; }
    .page { display: grid; grid-template-columns: repeat({{ $colonnes }}, 1fr); grid-auto-rows: calc((297mm - 16mm) / {{ $lignes }});
        width: calc(210mm - 12mm); page-break-after: always; }
    .page:last-child { page-break-after: auto; }
    .etiquette { border: 1px dashed #ccc; padding: 1.5mm 2mm; display: flex; flex-direction: column; justify-content: space-between; overflow: hidden; }
    .nom { font-size: {{ $colonnes >= 5 ? '7' : '9' }}pt; font-weight: bold; line-height: 1.1; max-height: 2.3em; overflow: hidden; }
    .prix { font-size: {{ $colonnes >= 5 ? '9' : '13' }}pt; font-weight: bold; }
    .code svg { width: 100%; height: {{ $colonnes >= 5 ? '6' : '10' }}mm; display: block; }
    .chiffres { font-size: 6.5pt; text-align: center; letter-spacing: .5px; }
    .boutique { font-size: 6pt; color: #444; }
    @media print { .barre { display: none; } .etiquette { border-color: transparent; } }
</style>
</head>
<body>
    <div class="barre"><strong>{{ $etiquettes->count() }} étiquette(s)</strong>
        <button onclick="window.print()">Imprimer</button>
        <span>Réglez l'impression sur « Taille réelle / 100 % », sans en-têtes ni pieds de page.</span></div>
    @foreach ($etiquettes->chunk($colonnes * $lignes) as $page)
        <div class="page">
            @foreach ($page as $p)
                <div class="etiquette">
                    <div class="nom">{{ $p->designation }}</div>
                    @if ($avecPrix)<div class="prix">{{ gnf(\App\Support\Tva::prixClient($p->prix_vente, $p, $boutique)) }}@if ($p->unite && $p->unite !== 'pièce')<span style="font-weight:normal;font-size:.7em"> / {{ $p->unite }}</span>@endif</div>@endif
                    <div class="code">{!! $svg[$p->id] !!}<div class="chiffres">{{ $p->code_barre }}</div></div>
                    @if ($colonnes < 5)<div class="boutique">{{ $boutique->nom }}</div>@endif
                </div>
            @endforeach
        </div>
    @endforeach
</body>
</html>

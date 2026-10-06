@php($ed = \App\Support\Plateforme::tout())
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 12mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1C2622; }
    table { width: 100%; border-collapse: collapse; }
    .titre { font-size: 18px; font-weight: bold; color: #1F6F54; }
    .doux { color: #5E6B65; } .r { text-align: right; } .b { font-weight: bold; }
    .cadre { border: 1px solid #DCE3DD; padding: 8px 10px; margin-top: 10px; }
    .lignes td { padding: 5px 0; border-bottom: 1px solid #E3E8E4; }
    .total { background: #14302A; color: #fff; padding: 8px 10px; font-size: 13px; font-weight: bold; margin-top: 10px; }
    .pied { position: fixed; bottom: -4mm; left: 0; right: 0; text-align: center; font-size: 8px; color: #5E6B65; }
</style>
</head>
<body>
    <table><tr>
        <td style="vertical-align:top">
            <div class="b" style="font-size:14px">{{ $ed['societe'] ?? config('app.name') }}</div>
            <div class="doux">{{ collect([$ed['adresse'] ?? null, isset($ed['telephone']) ? 'Tél : '.$ed['telephone'] : null, $ed['email'] ?? null])->filter()->implode(' · ') }}</div>
        </td>
        <td class="r" style="vertical-align:top">
            <div class="titre">REÇU DE LICENCE</div>
            <div class="b">N° {{ $p->numero }}</div>
            <div>Payé le {{ $p->paye_le->format('d/m/Y') }}</div>
        </td>
    </tr></table>

    <div class="cadre">
        <div class="doux">Client</div>
        <div class="b" style="font-size:12px">{{ $p->boutique->nom }}</div>
        <div>{{ collect([$p->boutique->responsable_nom, $p->boutique->telephone, collect([$p->boutique->adresse, $p->boutique->ville])->filter()->implode(', ')])->filter()->implode(' · ') }}</div>
    </div>

    <table class="lignes" style="margin-top:12px">
        <tr><td class="doux">Objet</td><td class="r">Licence du logiciel {{ config('app.name') }}</td></tr>
        <tr><td class="doux">Formule</td><td class="r">{{ $p->plan?->nom ?? '—' }}</td></tr>
        <tr><td class="doux">Durée</td><td class="r">{{ $p->mois ? $p->mois.' mois' : 'Licence à vie' }}</td></tr>
        <tr><td class="doux">Période couverte</td><td class="r b">{{ ucfirst($p->libellePeriode()) }}</td></tr>
        <tr><td class="doux">Mode de paiement</td><td class="r">{{ $p->libelleMode() }}{{ $p->reference ? ' — '.$p->reference : '' }}</td></tr>
        @if ($p->note)<tr><td class="doux">Note</td><td class="r">{{ $p->note }}</td></tr>@endif
    </table>

    <table class="total"><tr><td>MONTANT REÇU</td><td class="r">{{ gnf($p->montant) }}</td></tr></table>
    <div style="margin-top:6px" class="doux">Arrêté à la somme de : <span class="b" style="color:#1C2622">{{ ucfirst(montant_en_lettres($p->montant)) }}</span>.</div>

    <table style="margin-top:28px"><tr>
        <td class="doux">Reçu enregistré par {{ $p->auteur?->nomComplet() ?? '—' }}</td>
        <td class="r">Signature et cachet<br><br><br>____________________</td>
    </tr></table>
    <div class="pied">{{ $ed['societe'] ?? config('app.name') }} — merci de votre confiance.</div>
</body>
</html>

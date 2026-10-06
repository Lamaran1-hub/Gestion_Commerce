<!doctype html>
<html lang="fr">
<head>
    @include('layouts.tete', ['marque' => $b])
    <title>Commande envoyée · {{ $b->nom }}</title>
</head>
<body style="background:var(--papier)">
    <main class="container py-5" style="max-width:560px">
        <div class="bloc bloc-corps text-center">
            <i class="bi bi-bag-check fs-1 text-success"></i>
            <h1 class="h4 mt-2">Merci, votre commande est enregistrée</h1>
            <p class="text-doux mb-1">Référence <strong>{{ $commande['numero'] }}</strong> · total estimé <strong>{{ gnf($commande['total']) }}</strong></p>
            <p class="text-doux">« {{ $b->nom }} » va vous recontacter pour confirmer la disponibilité, la livraison et le paiement.</p>
            @if ($commande['whatsapp'])
                <a href="{{ $commande['whatsapp'] }}" class="btn btn-success btn-lg w-100" id="lienWhatsapp"><i class="bi bi-whatsapp me-1"></i>Envoyer aussi sur WhatsApp</a>
                <p class="small text-doux mt-2 mb-0">Pour une réponse plus rapide, envoyez le récapitulatif à la boutique sur WhatsApp.</p>
            @endif
            <a href="{{ route('vitrine.index', $b->slug) }}" class="btn btn-link mt-3">Revenir au catalogue</a>
        </div>
    </main>
    {{-- Commande enregistrée : le panier du téléphone est vidé (il reste en cas d'erreur de saisie) --}}
    <script>try { localStorage.removeItem('gn-panier-{{ $b->slug }}'); } catch {}</script>
</body>
</html>

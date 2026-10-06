<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/** Notifications de l'utilisateur (licence, paiements, résumé quotidien…). */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = $request->user()->notifications()->latest()->paginate(20);
        $nonLues = $notifications->getCollection()->whereNull('read_at')->pluck('id')->all();

        // Ouvrir la page vaut lecture des notifications affichées
        $request->user()->unreadNotifications()->whereIn('id', $nonLues)->update(['read_at' => now()]);

        return view('notifications', ['notifications' => $notifications, 'nonLues' => $nonLues]);
    }

    /** Ouvre la cible d'une notification et la marque comme lue. */
    public function ouvrir(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();
        $lien = $notification->data['lien'] ?? null;
        // Notification d'une autre boutique du réseau (commande en ligne, résumé, licence) : on y bascule d'abord,
        // sinon la fiche visée serait introuvable depuis la boutique où l'on travaille
        $boutiqueId = $notification->data['boutique_id'] ?? null;
        if ($boutiqueId && $boutiqueId !== boutique()?->id && $request->user()->boutiquesAccessibles()->contains('id', $boutiqueId)) {
            $request->session()->put('boutique_active', $boutiqueId);
        }

        // Seuls les liens internes sont suivis
        return $lien && str_starts_with($lien, url('/')) ? redirect()->to($lien) : redirect()->route('notifications.index');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailEnvoye;
use App\Notifications\EmailTest;
use App\Support\Courrier;
use App\Support\Plateforme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

/** E-mails du logiciel : serveur d'envoi, e-mail de test, journal des envois (espace du propriétaire). */
class EmailController extends Controller
{
    public function index(Request $request)
    {
        $journal = EmailEnvoye::with('boutique:id,nom')
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->statut, fn ($q, $s) => $q->where('statut', $s))
            ->when($request->q, fn ($q, $t) => $q->where(fn ($s) => $s->where('destinataire', 'like', "%{$t}%")->orWhere('sujet', 'like', "%{$t}%")))
            ->latest('id')->paginate(30)->withQueryString();

        return view('admin.emails', [
            'p' => Plateforme::tout(),
            'reel' => Courrier::reel(),
            'journal' => $journal,
            'stats' => EmailEnvoye::where('created_at', '>=', now()->subDays(30))->selectRaw('statut, COUNT(*) as nb')
                ->groupBy('statut')->pluck('nb', 'statut'),
        ]);
    }

    public function configurer(Request $request)
    {
        $d = $request->validate([
            'smtp_hote' => ['nullable', 'required_if:emails_actifs,1', 'string', 'max:150'],
            'smtp_port' => ['nullable', 'required_if:emails_actifs,1', 'integer', 'between:1,65535'],
            'smtp_chiffrement' => ['required', Rule::in(['tls', 'ssl', 'aucun'])],
            'smtp_utilisateur' => ['nullable', 'string', 'max:150'],
            'smtp_mot_de_passe' => ['nullable', 'string', 'max:300'],
            'expediteur_email' => ['nullable', 'required_if:emails_actifs,1', 'email', 'max:150'],
            'expediteur_nom' => ['nullable', 'string', 'max:100'],
        ], ['smtp_hote.required_if' => 'Indiquez le serveur SMTP.', 'expediteur_email.required_if' => "Indiquez l'adresse d'expédition."]);
        $d['emails_actifs'] = $request->boolean('emails_actifs') ? '1' : '0';
        // Mot de passe chiffré ; laissé vide = inchangé
        if (! empty($d['smtp_mot_de_passe'])) {
            $d['smtp_mot_de_passe'] = Crypt::encryptString($d['smtp_mot_de_passe']);
        } else {
            unset($d['smtp_mot_de_passe']);
        }
        Plateforme::enregistrer(array_map(fn ($v) => $v === null ? '' : (string) $v, $d));
        Courrier::recharger();

        return back()->with('succes', $d['emails_actifs'] === '1'
            ? "Envoi des e-mails activé. Envoyez-vous un e-mail de test pour vérifier."
            : "Réglages enregistrés. L'envoi réel est désactivé : les e-mails sont seulement simulés.");
    }

    public function tester(Request $request)
    {
        $d = $request->validate(['email_test' => ['required', 'email']]);
        Courrier::recharger();
        if (Courrier::envoyerA($d['email_test'], new EmailTest, 'test')) {
            return back()->with('succes', Courrier::reel()
                ? "E-mail de test envoyé à {$d['email_test']}. Vérifiez la boîte de réception (et les indésirables)."
                : "E-mail simulé : l'envoi réel n'est pas activé. Activez-le et renseignez le serveur SMTP.");
        }
        $erreur = EmailEnvoye::where('type', 'test')->latest('id')->value('erreur');

        return back()->with('erreur', "L'e-mail n'est pas parti : ".\Illuminate\Support\Str::limit((string) $erreur, 250)
            .' Vérifiez le serveur, le port, le chiffrement et les identifiants.');
    }
}

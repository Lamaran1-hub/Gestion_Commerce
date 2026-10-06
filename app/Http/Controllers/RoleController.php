<?php

namespace App\Http\Controllers;

use App\Exceptions\OperationRefusee;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index()
    {
        return view('roles.index', ['roles' => Role::withCount('utilisateurs')->orderByDesc('systeme')->orderBy('nom')->get()]);
    }

    public function create()
    {
        return view('roles.form', ['role' => new Role(['permissions' => []])]);
    }

    public function store(Request $request)
    {
        Role::create($this->valider($request));

        return redirect()->route('roles.index')->with('succes', 'Rôle créé.');
    }

    public function edit(Role $role)
    {
        return view('roles.form', compact('role'));
    }

    public function update(Request $request, Role $role)
    {
        if ($role->systeme) {
            throw new OperationRefusee('Le rôle Administrateur a toutes les permissions et ne peut pas être modifié.');
        }
        $role->update($this->valider($request, $role));

        return redirect()->route('roles.index')->with('succes', 'Rôle mis à jour.');
    }

    public function destroy(Role $role)
    {
        if ($role->systeme || $role->utilisateurs()->exists()) {
            throw new OperationRefusee('Ce rôle est utilisé ou protégé : réaffectez d\'abord ses utilisateurs.');
        }
        $role->delete();

        return back()->with('succes', 'Rôle supprimé.');
    }

    private function valider(Request $request, ?Role $role = null): array
    {
        $d = $request->validate([
            'nom' => ['required', 'string', 'max:60', Rule::unique('roles')->where('boutique_id', boutique()->id)->ignore($role?->id)],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(Role::toutesLesPermissions())],
        ]);
        $d['permissions'] = array_values($d['permissions'] ?? []);

        return $d;
    }
}

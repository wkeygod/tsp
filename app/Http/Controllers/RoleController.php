<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::with('roles.permissions')->paginate(10);

        return view('roles.index', [
            'users' => $users,
        ]);
    }

    /**
     * Show the form for creating a new user resource.
     */
    public function create()
    {
        return view('roles.create', [
            'permissions' => Permission::all(),
        ]);
    }

    /**
     * Store a newly created user resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'name' => $validated['first_name'] . ' ' . $validated['last_name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
            'is_admin' => false,
        ]);

        // Get role from permissions
        if (!empty($validated['permissions'])) {
            // Create or get role for this user with their specific permissions
            $role = Role::create([
                'name' => 'custom_role_' . $user->id,
                'description' => 'Custom role for ' . $user->name,
            ]);

            $role->permissions()->attach($validated['permissions']);
            $user->roles()->attach($role);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Utilisateur créé avec succès.',
                'user' => $user,
            ]);
        }

        return redirect()->route('roles.index')->with('success', 'Utilisateur créé avec succès.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(User $user)
    {
        if ($user->is_admin) {
            return redirect()->route('roles.index')->with('error', 'Impossible de modifier un utilisateur administrateur.');
        }

        return view('roles.edit', [
            'user' => $user->load('roles.permissions'),
            'permissions' => Permission::all(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        // Prevent editing admin users or self
        if ($user->is_admin) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de modifier un utilisateur administrateur.',
                ], 403);
            }

            return redirect()->route('roles.index')->with('error', 'Impossible de modifier un utilisateur administrateur.');
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $user->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'name' => $validated['first_name'] . ' ' . $validated['last_name'],
            'email' => $validated['email'],
        ]);

        if ($validated['password'] ?? false) {
            $user->update(['password' => bcrypt($validated['password'])]);
        }

        // Update permissions
        $userRole = $user->roles()->first();
        if ($userRole) {
            $userRole->permissions()->sync($validated['permissions'] ?? []);
        } else {
            if (!empty($validated['permissions'])) {
                $role = Role::create([
                    'name' => 'custom_role_' . $user->id,
                    'description' => 'Custom role for ' . $user->name,
                ]);
                $role->permissions()->attach($validated['permissions']);
                $user->roles()->attach($role);
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Utilisateur mis à jour avec succès.',
                'user' => $user,
            ]);
        }

        return redirect()->route('roles.index')->with('success', 'Utilisateur mis à jour avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        // Prevent deleting admin users
        if ($user->is_admin) {
            if (request()->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer un utilisateur administrateur.',
                ], 403);
            }

            return redirect()->route('roles.index')->with('error', 'Impossible de supprimer un utilisateur administrateur.');
        }

        // Delete associated role if exists
        $user->roles()->each(function ($role) use ($user) {
            if ($role->name === 'custom_role_' . $user->id) {
                $role->permissions()->detach();
                $role->delete();
            }
        });

        $user->delete();

        if (request()->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Utilisateur supprimé avec succès.',
            ]);
        }

        return redirect()->route('roles.index')->with('success', 'Utilisateur supprimé avec succès.');
    }

    /**
     * Get user data for editing
     */
    public function show(User $user)
    {
        return response()->json([
            'user' => $user->load('roles.permissions'),
            'permissions' => Permission::all(),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('users.manage');

        return view('admin.users.index', [
            'users' => User::query()
                ->with('roles')
                ->when($request->filled('q'), function ($q) use ($request) {
                    $term = '%'.trim($request->string('q')->toString()).'%';

                    $q->where(fn ($inner) => $inner
                        ->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term));
                })
                ->when($request->filled('role'), fn ($q) => $q->role($request->string('role')))
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'permissionGroups' => Permissions::groups(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('users.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::exists('roles', 'name')],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        $user->assignRole($validated['role']);

        return back()->with('status', "{$user->name} created as {$validated['role']}.");
    }

    public function edit(User $user): View
    {
        $this->authorize('users.manage');

        return view('admin.users.edit', [
            'user' => $user->load('roles'),
            'roles' => Role::query()->orderBy('name')->pluck('name'),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('users.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::exists('roles', 'name')],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Never let an admin lock themselves out.
        if ($user->id === $request->user()->id) {
            $validated['is_active'] = true;
        }

        $user->update($validated + ['is_active' => $request->boolean('is_active')]);
        $user->syncRoles([$validated['role']]);

        return back()->with('status', 'Staff account updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('users.manage');

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        if ($user->hasRole('Super Admin') && User::role('Super Admin')->count() <= 1) {
            return back()->with('error', 'This is the only Super Admin account. Create another before deleting this one.');
        }

        $user->delete();

        return back()->with('status', 'Staff account removed.');
    }
}

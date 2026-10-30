<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AccessManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        return view('roles.index', ['roles' => Role::withCount('users')->with('permissions')->orderBy('name')->paginate(15)]);
    }

    public function create(): View
    {
        return view('roles.form', ['role' => new Role, 'permissions' => Permission::orderBy('code')->get()]);
    }

    public function store(RoleRequest $request, AccessManager $access): RedirectResponse
    {
        $access->saveRole($request->user(), $request->validated());

        return redirect()->route(Gate::allows('roles.view') ? 'roles.index' : $request->user()->landingRouteName())->with('status', __('app.saved'));
    }

    public function edit(Role $role): View
    {
        abort_if($role->is_system, 403);

        return view('roles.form', ['role' => $role, 'permissions' => Permission::orderBy('code')->get()]);
    }

    public function update(RoleRequest $request, Role $role, AccessManager $access): RedirectResponse
    {
        $access->saveRole($request->user(), $request->validated(), $role);

        return redirect()->route(Gate::allows('roles.view') ? 'roles.index' : $request->user()->landingRouteName())->with('status', __('app.saved'));
    }

    public function destroy(Request $request, Role $role, AccessManager $access): RedirectResponse
    {
        $access->deleteRole($request->user(), $role);

        return redirect()->route(Gate::allows('roles.view') ? 'roles.index' : $request->user()->landingRouteName())->with('status', __('app.deleted'));
    }
}

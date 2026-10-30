<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = $data['q'] ?? '';
        $users = User::with('role')->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))->orderBy('name')->paginate(15)->withQueryString();

        return view('users.index', compact('users', 'search'));
    }

    public function create(): View
    {
        return view('users.form', ['user' => new User, 'roles' => Role::orderBy('name')->get()]);
    }

    public function store(UserRequest $request, AccessManager $access): RedirectResponse
    {
        $access->saveUser($request->user(), $request->validated());

        return redirect()->route(Gate::allows('users.view') ? 'users.index' : $request->user()->landingRouteName())->with('status', __('app.saved'));
    }

    public function edit(User $user): View
    {
        return view('users.form', ['user' => $user, 'roles' => Role::orderBy('name')->get()]);
    }

    public function update(UserRequest $request, User $user, AccessManager $access): RedirectResponse
    {
        $saved = $access->saveUser($request->user(), $request->validated(), $user);
        if ($saved->id === $request->user()->id) {
            $request->session()->put('auth_version', $saved->auth_version);
        }

        $viewer = $saved->id === $request->user()->id ? $saved : $request->user();

        return redirect()->route(Gate::forUser($viewer)->allows('users.view') ? 'users.index' : $viewer->landingRouteName())->with('status', __('app.saved'));
    }
}

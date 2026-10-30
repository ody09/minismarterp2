<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use App\Services\MasterDataDeletion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

abstract class ContactController extends Controller
{
    protected string $model;

    protected string $resource;

    public function index(Request $request): View
    {
        $search = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '';
        $contacts = $this->model::query()->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('contacts.index', ['contacts' => $contacts, 'search' => $search, 'resource' => $this->resource]);
    }

    public function create(): View
    {
        return view('contacts.form', ['contact' => new $this->model, 'resource' => $this->resource]);
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        $this->model::create($request->validated());

        return redirect()->route($this->destination($request))->with('status', __('app.saved'));
    }

    public function edit(string $contact): View
    {
        return view('contacts.form', ['contact' => $this->model::findOrFail($contact), 'resource' => $this->resource]);
    }

    public function update(ContactRequest $request, string $contact): RedirectResponse
    {
        $this->model::findOrFail($contact)->update($request->validated());

        return redirect()->route($this->destination($request))->with('status', __('app.saved'));
    }

    public function destroy(Request $request, string $contact, MasterDataDeletion $deletion): RedirectResponse
    {
        $deletion->delete($this->model::findOrFail($contact));

        return redirect()->route($this->destination($request))->with('status', __('catalog.deleted'));
    }

    private function destination(Request $request): string
    {
        return $this->resource.($request->user()->can($this->resource.'.view') ? '.index' : '.create');
    }
}

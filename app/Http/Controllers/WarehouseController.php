<?php

namespace App\Http\Controllers;

use App\Http\Requests\WarehouseRequest;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    public function index(): View
    {
        return view('warehouses.form', ['warehouse' => Warehouse::where('code', 'main')->firstOrFail(), 'editable' => false]);
    }

    public function edit(): View
    {
        return view('warehouses.form', ['warehouse' => Warehouse::where('code', 'main')->firstOrFail(), 'editable' => true]);
    }

    public function update(WarehouseRequest $request): RedirectResponse
    {
        Warehouse::where('code', 'main')->firstOrFail()->update($request->validated());

        return redirect()->route($request->user()->can('warehouses.view') ? 'warehouses.index' : 'warehouses.edit')->with('status', __('app.saved'));
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\BusinessReports;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->canAny(['reports.sales', 'reports.purchases', 'reports.inventory']), 403);

        return view('reports.index');
    }

    public function sales(Request $request, BusinessReports $reports): View
    {
        $data = $reports->financial($request->user(), 'sales', $request->all());

        return view('reports.financial', [...$data, 'parties' => Customer::orderBy('name')->get()]);
    }

    public function purchases(Request $request, BusinessReports $reports): View
    {
        $data = $reports->financial($request->user(), 'purchases', $request->all());

        return view('reports.financial', [...$data, 'parties' => Supplier::orderBy('name')->get()]);
    }

    public function inventory(Request $request, BusinessReports $reports): View
    {
        $data = $reports->inventory($request->user(), $request->all());

        return view('reports.inventory', [...$data, 'choices' => Product::orderBy('name')->get()]);
    }
}

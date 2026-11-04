<?php

namespace App\Http\Controllers;

use App\Services\BusinessReports;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, BusinessReports $reports): View
    {
        return view('dashboard', ['data' => $reports->dashboard($request->user(), $request->all())]);
    }
}

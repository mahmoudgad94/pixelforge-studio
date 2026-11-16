<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reports\SpendReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __invoke(Request $request, SpendReport $report): Response
    {
        return Inertia::render('Admin/Report', $report->build($request->query('start'), $request->query('end')));
    }
}

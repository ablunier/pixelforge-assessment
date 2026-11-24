<?php

namespace App\Http\Controllers;

use App\Actions\CompileAdminReport;
use App\Http\Requests\AdminReportRequest;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    /**
     * The admin report: daily and per-user Generations, Charges, Provider
     * Cost and Profit over a date range, each Provider Cost beside what
     * Pixelforge billed. The route lets only admins in.
     */
    public function __invoke(AdminReportRequest $request, CompileAdminReport $compile): Response
    {
        $report = $compile->handle($request->range());

        return Inertia::render('AdminReport', [
            'range' => [
                'from' => $report->range->from->toDateString(),
                'to' => $report->range->to->toDateString(),
                'maxDays' => AdminReportRequest::MAX_DAYS,
            ],
            'days' => $report->days,
            'users' => $report->users,
            'totals' => $report->totals(),
            'pixelforgeReachable' => $report->billed !== null,
        ]);
    }
}

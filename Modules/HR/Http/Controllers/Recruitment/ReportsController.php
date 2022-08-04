<?php

namespace Modules\HR\Http\Controllers\Recruitment;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Modules\HR\Entities\Applicant;
use Carbon\Carbon;
use Modules\HR\Entities\Application;
use Modules\HR\Entities\Job;

class ReportsController extends Controller
{
    /**
     * Display the employee reports.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('hr.recruitment.reportcard');
    }

    public function searchBydate(Request $req)
    {
        $req->report_start_date = $req->report_start_date ?? carbon::now()->startOfMonth() == $req->report_end_date = $req->report_end_date ?? Carbon::today();

        $todayCount = Applicant::whereDate('created_at', '=', Carbon::today())
        ->count();

        $record = Applicant::select(
            \DB::raw('COUNT(*) as count'),
            \DB::raw('MONTHNAME(created_at) as month_created_at'),
            \DB::raw('DATE(created_at) as date_created_at'),
        )
            ->wheredate('created_at', '>=', $req->report_start_date)
            ->wheredate('created_at', '<=', $req->report_end_date)
            ->groupBy('date_created_at', 'month_created_at')
            ->orderBy('date_created_at', 'ASC')
            ->get();

        $data = [];

        $verifiedApplicationCount = $this->getVerifiedApplicationsCount();

        foreach ($record as $row) {
            $data['label'][] = (new Carbon($row->date_created_at))->format('M d');
            $data['data'][] = (int) $row->count;
        }

        $data['chartData'] = json_encode($data);

        return view('hr.recruitment.reports', $data, with($todayCount, $verifiedApplicationCount));
    }

    private function getVerifiedApplicationsCount()
    {
        $from = config('hr.verified_application_date.start_date');
        $currentDate = Carbon::today(config('constants.timezone.indian'));

        return Application::whereBetween('created_at', [$from, $currentDate])
            ->where('is_verified', 1)->count();
    }

    public function showReportCard()
    {
        $todayCount = Applicant::whereDate('created_at', now())
        ->count();
        $record = Applicant::select(
            \DB::raw('COUNT(*) as count'),
            \DB::raw('MONTHNAME(created_at) as month_created_at'),
            \DB::raw('DATE(created_at) as date_created_at')
        )
        ->where('created_at', '>', Carbon::now()->subDays(23))
        ->groupBy('date_created_at', 'month_created_at')
        ->orderBy('date_created_at', 'ASC')
        ->get();

        $data = [];

        foreach ($record as $row) {
            $data['data'][] = (int) $row->count;
            $data['label'][] = (new Carbon($row->date_created_at))->format('M d');
        }

        $data['chartData'] = json_encode($data);

        return view('hr.recruitment.reports')->with([
            'chartData' => $data['chartData'],
            'todayCount' => $todayCount,
        ]);
    }

    public function bargraph()
    {
        $jobs = Job::all();
        $jobsTitle = $jobs->pluck('title')->toArray();
        $applicationCount = [];
        $totalApplicationCount = 0;
         foreach($jobs as $job) {
            $count = Application::where('hr_job_id', $job->id)->count();
            $applicationCount[] = $count;
            $totalApplicationCount += $count;
        }
        $chartData = [
            'jobsTitle' => $jobsTitle,
            'application' => $applicationCount,
        ];
        return view('hr.recruitment.BarGraph')->with([
            'TotalCount' => $totalApplicationCount,
            'jobs' => $jobs,
            'application'=>$applicationCount,
            'chartData' => json_encode($chartData, true)
            
        ]);
    }
}

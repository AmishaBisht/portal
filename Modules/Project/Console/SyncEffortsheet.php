<?php

namespace Modules\Project\Console;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Modules\Project\Entities\Project;
use Modules\User\Entities\User;
use Modules\Project\Entities\ProjectTeamMemberEffort;
use Revolution\Google\Sheets\Sheets;

class SyncEffortsheet extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'sync:effortsheet';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This commands syncs the effortsheets with the projects';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $projects = Project::where('status', 'active')->get();
        $users = User::with('projectTeamMembers');
        $sheetColumnsName = config('efforttracking.columns_name');

        foreach ($projects as $project) {
            try {
                $effortSheetUrl = $project->effort_sheet_url;

                if (! $effortSheetUrl) {
                    continue;
                }

                $correctedEffortsheetUrl = [];

                $isSyntaxMatching = preg_match('/.*[^-\w]([-\w]{25,})[^-\w]?.*/', $effortSheetUrl, $correctedEffortsheetUrl);

                if (! $isSyntaxMatching) {
                    continue;
                }

                $sheetId = $correctedEffortsheetUrl[1];
                $sheet = new Sheets();
                $projectMembersCount = $project->teamMembers()->count();
                $endColumn = config('efforttracking.default_end_column_in_effort_sheet');
                $columnIndex = 5;
                $projectsInSheet = array();

                try {
                    while(true) {
                        $range = 'C1:' . ++$endColumn . '1';
                        $sheets = $sheet->spreadsheet($sheetId)
                            ->range($range)
                            ->get();
                
                        if (isset($sheets[0]) && sizeof($sheets[0]) == ++$columnIndex) {
                            $subProjectName = $sheets[0][sizeof($sheets[0]) - 1];
                            $subProject = Project::where(['name' => $subProjectName, 'status' => 'active'])->first();
                            if($subProject) {
                                $projectsInSheet[] = [
                                    'id' => $subProject->id, 
                                    'name' => $subProjectName,
                                    'sheetIndex' => $columnIndex - 1
                                ];
                            }
                            continue;
                        }
                        
                        $endColumn = chr(ord($endColumn) - 1);
                        $columnIndex--;
                        break;
                    }
                } catch (Exception $e) {
                    continue;
                }

                $range = config('efforttracking.default_start_column_in_effort_sheet') . ':2' . $endColumn . ($projectMembersCount + 1); // this will depend on the number of people on the project

                $sheetIndexForTeamMemberName = $this->getColumnIndex($sheetColumnsName['team_member_name'], $sheets[0]);
                $sheetIndexForTotalBillableEffort = $this->getColumnIndex($sheetColumnsName['billable_effort'], $sheets[0]);
                $sheetIndexForStartDate = $this->getColumnIndex($sheetColumnsName['start_date'], $sheets[0]);
                $sheetIndexForEndDate = $this->getColumnIndex($sheetColumnsName['end_date'], $sheets[0]);   
                
                if ($sheetIndexForTeamMemberName && $sheetIndexForTotalBillableEffort && $sheetIndexForStartDate && $sheetIndexForEndDate === false) {
                    continue;
                }

                if (sizeof($projectsInSheet) == 0) {
                    $projectsInSheet[] = [
                        'id' => $project->id, 
                        'name' => $project->name,
                        'sheetIndex' => $sheetIndexForTotalBillableEffort
                    ];
                }

                try {
                    $usersData = $sheet->spreadsheet($sheetId)
                        ->range($range)
                        ->get();
                } catch (Exception $e) {
                    continue;
                }

                foreach ($usersData as $sheetUser) {
                    $userNickname = $sheetUser[$sheetIndexForTeamMemberName];
                    $portalUsers = clone $users;
                    $portalUser = $portalUsers->where('nickname', $userNickname)->first();

                    if (! $portalUser) {
                        continue;
                    }

                    $billingStartDate = Carbon::create($sheetUser[$sheetIndexForStartDate]);
                    $billingEndDate = Carbon::create($sheetUser[$sheetIndexForEndDate]);
                    $currentDate = now(config('constants.timezone.indian'))->today();

                    if ($currentDate < $billingStartDate || $currentDate > $billingEndDate) {
                        continue;
                    }

                    $effortData = array(
                        'portal_user' => $portalUser,
                        'sheet_user' => $sheetUser,
                        'project' => $project,
                        'billing_start_date' => $billingStartDate,
                        'billing_end_date' => $billingEndDate,
                        'sheet_index_for_billable_effort' => $sheetIndexForTotalBillableEffort,
                    );

                    foreach ($projectsInSheet as $sheetProject) {
                        try {
                            $effortData['sheet_project'] = $sheetProject;
                            $this->updateEffort($effortData);
                        } catch (Exception $e) {
                            continue;
                        }
                    }
                }
            } catch (Exception $e) {
                continue;
            }
        }
    }

    public function getColumnIndex($columnName, $sheetColumns)
    {
        foreach ($sheetColumns as $columnIndex => $sheetColumn) {
            if (Str::lower($sheetColumn) == $columnName) {
                return $columnIndex;
            }
        }

        return false;
    }

    public function updateEffort(array $effortData) 
    {
        $currentDate = now(config('constants.timezone.indian'))->today();
        $projectTeamMember = $effortData['portal_user']->projectTeamMembers()->active()->where('project_id', $effortData['sheet_project']['id'])->first();

        if (! $projectTeamMember) {
            return;
        }
        $latestProjectTeamMemberEffort = $projectTeamMember->projectTeamMemberEffort()
            ->where('added_on', '<', $currentDate)
            ->orderBy('added_on', 'DESC')->first();

        $billableEffort = $effortData['sheet_user'][$effortData['sheet_project']['sheetIndex']];

        if ($latestProjectTeamMemberEffort) {
            $previousEffortDate = Carbon::parse($latestProjectTeamMemberEffort->added_on);
            if ($previousEffortDate >= $effortData['billing_start_date'] && $previousEffortDate <= $effortData['billing_end_date']) {
                $billableEffort -= $latestProjectTeamMemberEffort->total_effort_in_effortsheet;
            }
        }

        ProjectTeamMemberEffort::updateOrCreate(
            [
                'project_team_member_id' => $projectTeamMember->id,
                'added_on' => $currentDate,
            ],
            [
                'actual_effort' => $billableEffort,
                'total_effort_in_effortsheet' => $effortData['sheet_user'][$effortData['sheet_project']['sheetIndex']],
            ]
        );
    }
}

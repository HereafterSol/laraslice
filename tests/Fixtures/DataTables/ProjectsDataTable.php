<?php

namespace App\DataTables;

use Carbon\Carbon;
use App\Models\Project;
use App\Models\CustomField;
use App\Models\CustomFieldGroup;
use App\DataTables\BaseDataTable;
use App\Models\ProjectStatusSetting;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Illuminate\Support\Facades\DB;

class ProjectsDataTable extends BaseDataTable
{

    private $addProjectPermission;
    private $editProjectsPermission;
    private $deleteProjectPermission;
    private $viewProjectPermission;
    private $viewGanttPermission;
    private $addProjectMemberPermission;

    public function __construct()
    {
        parent::__construct();
        $this->addProjectPermission = user()->permission('add_projects');
        $this->editProjectsPermission = user()->permission('edit_projects');
        $this->deleteProjectPermission = user()->permission('delete_projects');
        $this->viewProjectPermission = user()->permission('view_projects');
        $this->viewGanttPermission = user()->permission('view_project_gantt_chart');
        $this->addProjectMemberPermission = user()->permission('add_project_members');

    }

    /**
     * Build DataTable class.
     *
     * @param mixed $query Results from query() method.
     * @return \Yajra\DataTables\DataTableAbstract
     */
    public function dataTable($query)
    {
        $projectStatus = ProjectStatusSetting::get();


        $datatables = datatables()->eloquent($query);
        $datatables->addIndexColumn();

        $datatables->addColumn('check', function ($row) {
            return '<input type="checkbox" class="select-table-row" id="datatable-row-' . $row->id . '"  name="datatable_ids[]" value="' . $row->id . '" onclick="dataTableRowCheck(' . $row->id . ')">';
        });
        $datatables->addColumn('action', function ($row) {
            $memberIds = $row->members->pluck('user_id')->toArray();

            $action = '<div class="task_view">

                <div class="dropdown">
                    <a class="task_view_more d-flex align-items-center justify-content-center dropdown-toggle" type="link"
                        id="dropdownMenuLink-' . $row->id . '" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <i class="icon-options-vertical icons"></i>
                    </a>
                    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="dropdownMenuLink-' . $row->id . '" tabindex="0">';

            $action .= '<a href="' . route('projects.show', [$row->id]) . '" class="dropdown-item"><i class="fa fa-eye mr-2"></i>' . __('app.view') . '</a>';

            if ($this->editProjectsPermission == 'all'
                || ($this->editProjectsPermission == 'added' && user()->id == $row->added_by)
                || ($this->editProjectsPermission == 'owned' && user()->id == $row->client_id && in_array('client', user_roles()))
                || ($this->editProjectsPermission == 'owned' && in_array(user()->id, $memberIds) && in_array('employee', user_roles()))
                || ($this->editProjectsPermission == 'both' && (user()->id == $row->client_id || user()->id == $row->added_by))
                || ($this->editProjectsPermission == 'both' && in_array(user()->id, $memberIds) && in_array('employee', user_roles()))
            ) {
                $action .= '<a class="dropdown-item openRightModal" href="' . route('projects.edit', [$row->id]) . '">
                            <i class="fa fa-edit mr-2"></i>
                            ' . trans('app.edit') . '
                        </a>';
            }

            if ($this->addProjectPermission == 'all' || $this->addProjectPermission == 'added' || $this->addProjectPermission == 'both') {
                $action .= '<a class="dropdown-item duplicateProject" href="javascript:;" data-project-id="' . $row->id . '">
                            <i class="fa fa-clone"></i>
                            ' . trans('app.duplicate') . '
                        </a>';
            }

            if ($this->viewGanttPermission == 'all' || ($this->viewGanttPermission == 'added' && user()->id == $row->added_by) || ($this->viewGanttPermission == 'owned' && user()->id == $row->client_id)) {
                $action .= '<a class="dropdown-item" href="' . route('projects.show', $row->id) . '?tab=gantt' . '">
                            <i class="fa fa-project-diagram mr-2"></i>
                            ' . trans('modules.projects.viewGanttChart') . '
                        </a>';
            }

            $action .= '<a class="dropdown-item" target="_blank" href="' . route('front.gantt', $row->hash) . '">
                    <i class="fa fa-share-square mr-2"></i>
                    ' . trans('modules.projects.viewPublicGanttChart') . '
                </a>';

            $action .= '<a class="dropdown-item" target="_blank" href="' . route('front.taskboard', $row->hash) . '">
                    <i class="fa fa-share-square mr-2"></i>
                    ' . trans('app.public') . ' ' . __('modules.tasks.taskBoard') . '
                </a>';

            if ($this->deleteProjectPermission == 'all'
                || ($this->deleteProjectPermission == 'added' && user()->id == $row->added_by)
                || ($this->deleteProjectPermission == 'owned' && user()->id == $row->client_id && in_array('client', user_roles()))
                || ($this->deleteProjectPermission == 'owned' && in_array(user()->id, $memberIds) && in_array('employee', user_roles()))
                || ($this->deleteProjectPermission == 'both' && (user()->id == $row->client_id || user()->id == $row->added_by))
                || ($this->deleteProjectPermission == 'both' && in_array(user()->id, $memberIds) && in_array('employee', user_roles()))
            ) {
                $action .= '<a class="dropdown-item archive" href="javascript:;" data-user-id="' . $row->id . '">
                            <i class="fa fa-archive mr-2"></i>
                            ' . trans('app.archive') . '
                        </a>';
                $action .= '<a class="dropdown-item delete-table-row" href="javascript:;" data-user-id="' . $row->id . '">
                            <i class="fa fa-trash mr-2"></i>
                            ' . trans('app.delete') . '
                        </a>';
            }

            $action .= '</div>
                </div>
            </div>';

            return $action;
        }
        );
        $datatables->addColumn('members', function ($row) {
            if ($row->public) {
                return '--';
            }

            $members = '<div class="position-relative">';

            if (count($row->members) > 0) {
                foreach ($row->members as $key => $member) {
                    if ($key < 4) {
                        $img = '<img data-toggle="tooltip" height="25" width="25" data-original-title="' . $member->user->name . '" src="' . $member->user->image_url . '">';

                        $position = $key > 0 ? 'position-absolute' : '';
                        $members .= '<div class="taskEmployeeImg rounded-circle ' . $position . '" style="left:  ' . ($key * 13) . 'px"><a href="' . route('employees.show', $member->user->id) . '">' . $img . '</a></div> ';
                    }

                }
            }
            else if ($this->addProjectMemberPermission == 'all') {
                $members .= '<a href="' . route('projects.show', $row->id) . '?tab=members" class="f-12 text-dark-grey"><i class="fa fa-plus" ></i> ' . __('modules.projects.addMemberTitle') . '</a>';

            }
            else {

                $members .= '--';
            }

            if (count($row->members) > 4) {
                $members .= '<div class="taskEmployeeImg more-user-count text-center rounded-circle bg-amt-grey position-absolute" style="left:  52px"><a href="' . route('projects.show', $row->id) . '?tab=members" class="text-dark f-10">+' . (count($row->members) - 4) . '</a></div> ';
            }

            $members .= '</div>';

            return $members;
        }
        );
        $datatables->addColumn('name', function ($row) {
            $members = [];

            if (count($row->members) > 0) {

                foreach ($row->members as $member) {
                    $members[] = $member->user->name;
                }

                return implode(',', $members);
            }
        });
        $datatables->addColumn('project', function ($row) {
            return $row->project_name;
        });

        $datatables->editColumn('project_name', function ($row) {
            $pin = '';

            if (($row->pinned_project)) {
                $pin .= '<span class="badge badge-secondary"><i class="fa fa-thumbtack"></i> ' . __('app.pinned') . '</span>';
            }

            if (($row->public)) {
                $pin = '<span class="badge badge-primary"><i class="fa fa-globe"></i> ' . __('app.public') . '</span>';
            }

            return '<div class="media align-items-center">
                        <div class="media-body">
                    <h5 class="mb-0 f-13 text-darkest-grey"><a href="' . route('projects.show', [$row->id]) . '">' . $row->project_name . '</a></h5>
                    <p class="mb-0">' . $pin . '</p>
                    </div>
                </div>';
        }
        );
        $datatables->editColumn('start_date', function ($row) {
            return $row->start_date->translatedFormat($this->company->date_format);
        });
        $datatables->editColumn('deadline', function ($row) {
            if (is_null($row->deadline)) {
                return '--';
            }

            $formattedDeadline = $row->deadline->translatedFormat($this->company->date_format);

            if ($row->deadline->endOfDay()->isPast()) {
                return '<span class="text-danger">' . $formattedDeadline . '</span>';
            }

            return $row->deadline->isToday() ? '<span class="text-success">' . __('app.today') . '</span>' : '<span>' . $formattedDeadline . '</span>';
        });


        $datatables->addColumn('client_name', function ($row) {
            if ($row->client) {
                return ($row->client->salutation ? $row->client->salutation->label() . ' ' : '') . $row->client->name;
            }

            return '--';
        });
        $datatables->addColumn('client_email', function ($row) {
            if ($row->client) {
                return $row->client->email;
            }

            return '--';
        });
        $datatables->addColumn('project_status', function ($row) {
            return __('app.' . $row->status);
        });
        $datatables->editColumn('client_id', function ($row) {
            if (is_null($row->client_id)) {
                return '';
            }

            return view('components.client', ['user' => $row->client]);

        });
        $datatables->addColumn('status', function ($row) use ($projectStatus) {
            $projectUsers = $row->members->pluck('user_id')->toArray();

            if ($row->completion_percent < 50) {
                $statusColor = 'danger';
            }
            elseif ($row->completion_percent < 75) {
                $statusColor = 'warning';
            }
            else {
                $statusColor = 'success';
            }

            $status = '<p><div class="progress" style="height: 15px;">
                <div class="progress-bar f-12 bg-' . $statusColor . '" role="progressbar" style="width: ' . $row->completion_percent . '%;" aria-valuenow="' . $row->completion_percent . '" aria-valuemin="0" aria-valuemax="100">' . $row->completion_percent . '%</div>
              </div></p>';

            if ($this->editProjectsPermission == 'all'
                || ($this->editProjectsPermission == 'added' && user()->id == $row->added_by)
                || ($this->editProjectsPermission == 'owned' && user()->id == $row->client_id && in_array('client', user_roles()))
                || ($this->editProjectsPermission == 'owned' && in_array(user()->id, $projectUsers) && in_array('employee', user_roles()))
                || ($this->editProjectsPermission == 'both' && (user()->id == $row->client_id || user()->id == $row->added_by))
                || ($this->editProjectsPermission == 'both' && in_array(user()->id, $projectUsers) && in_array('employee', user_roles()))
            ) {
                $status .= '<select class="form-control select-picker change-status" data-project-id="' . $row->id . '">';

                foreach ($projectStatus as $item) {
                    $status .= '<option ';

                    if ($item->status_name == $row->status) {
                        $status .= 'selected';
                    }

                    $status .= '  data-content="<i class=\'fa fa-circle mr-2\' style=\'color: ' . $item->color . '\'></i> ' . $item->status_name . '" value="' . $item->status_name . '" >' . $item->status_name . '</option>';
                }

                $status .= '</select>';

                return $status;
            }
            else {
                foreach ($projectStatus as $item) {
                    if ($row->status == $item->status_name) {
                        return '<i class="fa fa-circle mr-1 text-yellow"
                            style="color: ' . $item->color . '"></i>' . $item->status_name;
                    }
                }
            }

        });
        $datatables->editColumn('completion_percent', function ($row) {
            $completionPercent = $row->completion_percent;
            $statusColor = $completionPercent < 50 ? 'danger' : ($completionPercent < 75 ? 'warning' : 'success');

            return '<div class="progress" style="height: 15px;">
                        <div class="progress-bar f-12 bg-' . $statusColor . '" role="progressbar" style="width: ' . $completionPercent . '%;" aria-valuenow="' . $completionPercent . '" aria-valuemin="0" aria-valuemax="100">' . $completionPercent . '%</div>
                     </div>';
        });
        $datatables->addColumn('completion_export', function ($row) {
            return $row->completion_percent . '% ' . __('app.complete');
        });

        $datatables->setRowId(
            function ($row) {
                return 'row-' . $row->id;
            }
        );
        $datatables->editColumn('project_short_code', function ($row) {
            return '<a href="' . route('projects.show', [$row->id]) . '" class="text-darkest-grey">' . $row->project_short_code . '</a>';
        });
        $datatables->orderColumn('status', 'status $1');
        $datatables->removeColumn('project_summary');
        $datatables->removeColumn('notes');
        $datatables->removeColumn('category_id');
        $datatables->removeColumn('feedback');
        $datatables->setRowClass(
            function ($row) {
                return $row->pinned_project ? 'alert-primary' : '';
            }
        );

        // Custom Fields For export
        $customFieldColumns = CustomField::customFieldData($datatables, Project::CUSTOM_FIELD_MODEL);

        $datatables->rawColumns(array_merge(['project_name', 'action', 'completion_percent', 'members', 'status', 'client_id', 'check', 'project_short_code', 'deadline'], $customFieldColumns));

        return $datatables;
    }

    /**
     * @param Project $model
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function query(Project $model)
    {
        $request = $this->request();
        $startFilterDate = null;
        $endFilterDate = null;

        if ($request->startFilterDate !== null && $request->startFilterDate != 'null' && $request->startFilterDate != '') {
            $startFilterDate = Carbon::createFromFormat($this->company->date_format, $request->startFilterDate)->toDateString();
        }

        if ($request->endFilterDate !== null && $request->endFilterDate != 'null' && $request->endFilterDate != '') {
            $endFilterDate = Carbon::createFromFormat($this->company->date_format, $request->endFilterDate)->toDateString();
        }

        $model = $model
            ->with('members', 'members.user', 'client', 'client.clientDetails', 'currency', 'client.session', 'mentionUser')
            ->leftJoin('project_members', 'project_members.project_id', 'projects.id')
            ->leftJoin('users', 'project_members.user_id', 'users.id')
            ->leftJoin('users as client', 'projects.client_id', 'users.id')
            ->leftJoin('mention_users', 'mention_users.project_id', 'projects.id')
            ->selectRaw(
                'projects.id, projects.project_short_code, projects.hash, projects.added_by, projects.project_name, projects.start_date, projects.deadline, projects.client_id,
              projects.completion_percent, projects.project_budget, projects.currency_id,
            projects.status, users.salutation, users.name, client.name as client_name, client.email as client_email, projects.public, mention_users.user_id as mention_user,
           ( select count("id") from pinned where pinned.project_id = projects.id and pinned.user_id = ' . user()->id . ') as pinned_project'
            );

        if ($request->pinned == 'pinned') {
            $model->join('pinned', 'pinned.project_id', 'projects.id');
            $model->where('pinned.user_id', user()->id);
        }

        if (!is_null($request->status) && $request->status != 'all') {

            if ($request->status == 'overdue') {
                $model->where('projects.completion_percent', '!=', 100);

                if ($request->deadLineStartDate == '' && $request->deadLineEndDate == '') {
                    $model->whereDate('projects.deadline', '<', now(company()->timezone)->toDateString());
                }
            }
            else {
                $model->where('projects.status', $request->status);
            }
        }

        if ($request->progress) {
            $model->where(
                function ($q) use ($request) {
                    foreach ($request->progress as $progress) {
                        $completionPercent = explode('-', $progress);
                        $q->orWhereBetween('projects.completion_percent', [$completionPercent[0], $completionPercent[1]]);
                    }
                }
            );
        }

        if ($request->deadLineStartDate != '' && $request->deadLineEndDate != '') {
            $startDate = Carbon::createFromFormat($this->company->date_format, $request->deadLineStartDate)->toDateString();
            $endDate = Carbon::createFromFormat($this->company->date_format, $request->deadLineEndDate)->toDateString();
            $model->whereRaw('Date(projects.deadline) >= ?', [$startDate])->whereRaw('Date(projects.deadline) <= ?', [$endDate]);
        }

        if (!is_null($request->client_id) && $request->client_id != 'all') {
            $model->where('projects.client_id', $request->client_id);
        }

        if (!is_null($request->team_id) && $request->team_id != 'all') {
            $model->where('team_id', $request->team_id);
        }

        if (!is_null($request->category_id) && $request->category_id != 'all') {
            $model->where('category_id', $request->category_id);
        }

        if (!is_null($request->public) && $request->public != 'all') {
            $model->where('public', $request->public);
        }

        if (!is_null($request->employee_id) && $request->employee_id != 'all') {
            $model->where(
                function ($query) {
                    return $query->where('project_members.user_id', request()->employee_id)
                        ->orWhere('mention_users.user_id', user()->id)
                        ->orWhere('projects.public', 1);
                }
            );
        }

        if ($this->viewProjectPermission == 'added') {
            $model->where(
                function ($query) {

                    return $query->where('projects.added_by', user()->id)
                        ->orWhere('mention_users.user_id', user()->id)
                        ->orWhere('projects.public', 1);
                }
            );
        }

        if ($this->viewProjectPermission == 'owned' && in_array('employee', user_roles())) {
            $model->where(
                function ($query) {
                    return $query->where('project_members.user_id', user()->id)
                        ->orWhere('mention_users.user_id', user()->id)
                        ->orWhere('projects.public', 1);
                }
            );
        }

        if ($this->viewProjectPermission == 'both' && in_array('employee', user_roles())) {
            $model->where(
                function ($query) {
                    return $query->where('projects.added_by', user()->id)
                        ->orWhere('project_members.user_id', user()->id)
                        ->orWhere('mention_users.user_id', user()->id)
                        ->orWhere('projects.public', 1);
                }
            );
        }

        if ($startFilterDate !== null && $endFilterDate !== null) {
            $model->where(function ($query) use ($startFilterDate, $endFilterDate) {
                if (request()->date_filter_on == 'start_date') {
                    $query->whereBetween(DB::raw('DATE(projects.`start_date`)'), [$startFilterDate, $endFilterDate]);

                }
                elseif (request()->date_filter_on == 'deadline') {
                    $query->whereBetween(DB::raw('DATE(projects.`deadline`)'), [$startFilterDate, $endFilterDate]);

                }

            });
        }

        if ($request->searchText != '') {
            $model->where(
                function ($query) {
                    $query->where('projects.project_name', 'like', '%' . request('searchText') . '%')
                        ->orWhere('users.name', 'like', '%' . request('searchText') . '%')
                        ->orWhere('projects.project_short_code', 'like', '%' . request('searchText') . '%'); // project short code
                }
            );
        }

        if ($request->startDate && $request->endDate) {
            $startDate = Carbon::createFromFormat($this->company->date_format, $request->startDate)->toDateString();
            $endDate = Carbon::createFromFormat($this->company->date_format, $request->endDate)->toDateString();
            $model->whereBetween(DB::raw('DATE(projects.`created_at`)'), [$startDate, $endDate]);
        }

        $model->groupBy('projects.id');
        $model->orderbyRaw('pinned_project desc');

        return $model;
    }

    /**
     * Optional method if you want to use html builder.
     *
     * @return \Yajra\DataTables\Html\Builder
     */
    public function html()
    {
        $dataTable = $this->setBuilder('projects-table', 2)
            ->parameters(
                [
                    'initComplete' => 'function () {
                    window.LaravelDataTables["projects-table"].buttons().container()
                     .appendTo( "#table-actions")
                 }',
                    'fnDrawCallback' => 'function( oSettings ) {
                    $("#projects-table .select-picker").selectpicker();

                    $("body").tooltip({
                        selector: \'[data-toggle="tooltip"]\'
                    })
                }',
                ]
            );

        if (canDataTableExport()) {
            $dataTable->buttons(Button::make(['extend' => 'excel', 'text' => '<i class="fa fa-file-export"></i> ' . trans('app.exportExcel')]));
        }

        return $dataTable;
    }

    /**
     * Get columns.
     *
     * @return array
     */
    protected function getColumns()
    {


        $data = [
            'check' => [
                'title' => '<input type="checkbox" name="select_all_table" id="select-all-table" onclick="selectAllTable(this)">',
                'exportable' => false,
                'orderable' => false,
                'searchable' => false,
                'visible' => !in_array('client', user_roles())
            ],
            '#' => ['data' => 'DT_RowIndex', 'orderable' => false, 'searchable' => false, 'visible' => false, 'title' => '#'],
            __('modules.taskCode') => ['data' => 'project_short_code', 'name' => 'project_short_code', 'title' => __('modules.taskCode')],
            __('modules.projects.projectName') => ['data' => 'project_name', 'name' => 'project_name', 'exportable' => false, 'width' => '20%', 'title' => __('modules.projects.projectName')],
            __('app.project') => ['data' => 'project', 'name' => 'project_name', 'visible' => false, 'title' => __('app.project')],
            __('modules.projects.members') => ['data' => 'members', 'name' => 'members', 'exportable' => false, 'width' => '15%', 'title' => __('modules.projects.members')],
            __('modules.projects.projectMembers') => ['data' => 'name', 'name' => 'name', 'visible' => false, 'title' => __('modules.projects.projectMembers')],
            __('modules.projects.startDate') => ['data' => 'start_date', 'name' => 'start_date', 'title' => __('modules.projects.startDate'), 'width' => '12%'],
            __('app.deadline') => ['data' => 'deadline', 'name' => 'deadline', 'title' => __('app.deadline'), 'width' => '12%'],
            __('app.client') => ['data' => 'client_id', 'name' => 'client_id', 'width' => '15%', 'exportable' => false, 'title' => __('app.client'), 'visible' => !in_array('client', user_roles())],
            __('app.customers') => ['data' => 'client_name', 'name' => 'client_id', 'visible' => false, 'title' => __('app.customers')],

            __('app.client') . ' ' . __('app.email') => ['data' => 'client_email', 'name' => 'client_id', 'visible' => false, 'title' => __('app.client') . ' ' . __('app.email')],
            // Hide __('app.progress') => ['data' => 'completion_percent', 'name' => 'completion_percent', 'exportable' => false, 'title' => __('app.progress')],
            __('app.completion') => ['data' => 'completion_export', 'name' => 'completion_export', 'visible' => false, 'title' => __('app.completion')],
            __('app.status') => ['data' => 'status', 'name' => 'status', 'width' => '16%', 'exportable' => false, 'title' => __('app.status')],
            __('app.project') . ' ' . __('app.status') => ['data' => 'project_status', 'name' => 'status', 'visible' => false, 'title' => __('app.project') . ' ' . __('app.status')]
        ];

        $action = [
            Column::computed('action', __('app.action'))
                ->exportable(false)
                ->printable(false)
                ->orderable(false)
                ->searchable(false)
                ->addClass('text-right pr-20')
        ];

        return array_merge($data, CustomFieldGroup::customFieldsDataMerge(new Project()), $action);
    }

}

<?php

namespace App\DataTables;

use App\Models\BaseModel;
use Carbon\Carbon;
use App\Models\Task;
use App\Models\CustomField;
use App\Models\TaskboardColumn;
use App\Models\CustomFieldGroup;
use App\Models\TaskSetting;
use Carbon\CarbonInterval;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Illuminate\Support\Facades\DB;

class TasksDataTable extends BaseDataTable
{

    private $editTaskPermission;
    private $deleteTaskPermission;
    private $viewTaskPermission;
    private $changeStatusPermission;
    private $viewUnassignedTasksPermission;
    private $hasTimelogModule;

    public function __construct()
    {
        parent::__construct();

        $this->editTaskPermission = user()->permission('edit_tasks');
        $this->deleteTaskPermission = user()->permission('delete_tasks');
        $this->viewTaskPermission = user()->permission('view_tasks');
        $this->changeStatusPermission = user()->permission('change_status');
        $this->viewUnassignedTasksPermission = user()->permission('view_unassigned_tasks');
        $this->hasTimelogModule = (in_array('timelogs', user_modules()));
    }

    /**
     * Build DataTable class.
     *
     * @param  mixed $query Results from query() method.
     * @return \Yajra\DataTables\DataTableAbstract
     */
    public function dataTable($query)
    {
        $taskBoardColumns = TaskboardColumn::orderBy('priority')->get();

        $datatables = datatables()->eloquent($query);
        $datatables->addColumn(
            'check', function ($row) {
                return '<input type="checkbox" class="select-table-row" id="datatable-row-' . $row->id . '"  name="datatable_ids[]" value="' . $row->id . '" onclick="dataTableRowCheck(' . $row->id . ')">';
            }
        );
        $datatables->addColumn(
            'action', function ($row) {
                $taskUsers = $row->users->pluck('id')->toArray();

                $action = '<div class="task_view">

                    <div class="dropdown">
                        <a class="task_view_more d-flex align-items-center justify-content-center dropdown-toggle" type="link"
                            id="dropdownMenuLink-' . $row->id . '" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <i class="icon-options-vertical icons"></i>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right" aria-labelledby="dropdownMenuLink-' . $row->id . '" tabindex="0">';

                $action .= '<a href="' . route('tasks.show', [$row->id]) . '" class="dropdown-item openRightModal"><i class="fa fa-eye mr-2"></i>' . __('app.view') . '</a>';

                if ($this->editTaskPermission == 'all'
                    || ($this->editTaskPermission == 'owned' && in_array(user()->id, $taskUsers))
                    || ($this->editTaskPermission == 'added' && $row->added_by == user()->id)
                    || ($row->project_admin == user()->id)
                    || ($this->editTaskPermission == 'both' && (in_array(user()->id, $taskUsers) || $row->added_by == user()->id))
                    || ($this->editTaskPermission == 'both' && (in_array(user()->id, $taskUsers) || $row->added_by == user()->id || in_array('client', user_roles())))
                    || ($this->editTaskPermission == 'owned' && in_array('client', user_roles()))
                ) {
                    $action .= '<a class="dropdown-item openRightModal" href="' . route('tasks.edit', [$row->id]) . '">
                                <i class="fa fa-edit mr-2"></i>
                                ' . trans('app.edit') . '
                            </a>';
                }

                if ($this->deleteTaskPermission == 'all'
                    || ($this->deleteTaskPermission == 'owned' && in_array(user()->id, $taskUsers))
                    || ($this->deleteTaskPermission == 'added' && $row->added_by == user()->id)
                    || ($row->project_admin == user()->id)
                    || ($this->deleteTaskPermission == 'both' && (in_array(user()->id, $taskUsers) || $row->added_by == user()->id))
                    || ($this->deleteTaskPermission == 'both' && (in_array(user()->id, $taskUsers) || $row->added_by == user()->id || in_array('client', user_roles())))
                    || ($this->deleteTaskPermission == 'owned' && in_array('client', user_roles()))
                ) {
                    $action .= '<a class="dropdown-item delete-table-row" href="javascript:;" data-user-id="' . $row->id . '">
                                <i class="fa fa-trash mr-2"></i>
                                ' . trans('app.delete') . '
                            </a>';
                }

                if ($this->editTaskPermission == 'all'
                    || ($this->editTaskPermission == 'owned' && in_array(user()->id, $taskUsers))
                    || ($this->editTaskPermission == 'added' && $row->added_by == user()->id)
                    || ($this->editTaskPermission == 'both' && (in_array(user()->id, $taskUsers) || $row->added_by == user()->id))
                    || ($row->project_admin == user()->id)
                ) {
                    $action .= '<a class="dropdown-item openRightModal" href="' . route('tasks.create') . '?duplicate_task=' . $row->id . '">
                                <i class="fa fa-clone"></i>
                                ' . trans('app.duplicate') . '
                            </a>';
                }

                $action .= '</div>
                    </div>
                </div>';

                return $action;
            }
        );


        $datatables->editColumn(
            'start_date', function ($row) {
                if (is_null($row->start_date)) {
                    return '--';
                }

                if ($row->start_date->endOfDay()->isPast()) {
                    return '<span class="text-black">' . $row->start_date->translatedFormat($this->company->date_format) . '</span>';
                }
                elseif ($row->start_date->isToday()) {

                    return '<span class="text-success">' . __('app.today') . '</span>';
                }

                return '<span >' . $row->start_date->translatedFormat($this->company->date_format) . '</span>';
            }
        );

        $datatables->editColumn(
            'due_date', function ($row) {
                if (is_null($row->due_date)) {
                    return '--';
                }

                if ($row->due_date->endOfDay()->isPast()) {
                    if ($row->boardColumn->column_name == 'Completed') {
                        return '<span class="text-black">'. $row->due_date->translatedFormat($this->company->date_format) . '</span>';
                    }
                    else{
                        return '<span class="text-danger">'. $row->due_date->translatedFormat($this->company->date_format) . '</span>';
                    }
                }
                elseif ($row->due_date->isToday()) {
                    return '<span class="text-success">' . __('app.today') . '</span>';
                }

                return '<span>' . $row->due_date->translatedFormat($this->company->date_format) . '</span>';
            }
        );
        $datatables->editColumn(
            'completed_on', function ($row) {
                if (is_null($row->completed_on)) {
                    return '--';
                }

                if ($row->completed_on->endOfDay()->isPast()) {
                    return '<span class="text-black">' . $row->completed_on->translatedFormat($this->company->date_format) . '</span>';
                }
                elseif ($row->completed_on->isToday()) {
                    return '<span class="text-success">' . __('app.today') . '</span>';

                }
                return '<span>' . $row->completed_on->translatedFormat($this->company->date_format) . '</span>';
            }
        );
        $datatables->editColumn(
            'users', function ($row) {
                if (count($row->users) == 0) {
                    return '--';
                }

                $key = '';
                $members = '<div class="position-relative">';

                foreach ($row->users as $key => $member) {
                    if ($key < 4) {
                        $img = '<img data-toggle="tooltip" data-original-title="' . $member->name . '" src="' . $member->image_url . '">';
                        $position = $key > 0 ? 'position-absolute' : '';

                        $members .= '<div class="taskEmployeeImg rounded-circle ' . $position . '" style="left:  ' . ($key * 13) . 'px"><a href="' . route('employees.show', $member->id) . '">' . $img . '</a></div> ';
                    }
                }

                if (count($row->users) > 4 && $key) {
                    $members .= '<div class="taskEmployeeImg more-user-count text-center rounded-circle border bg-amt-grey position-absolute" style="left:  ' . (($key - 1) * 13) . 'px"><a href="' . route('tasks.show', [$row->id]) . '" class="text-dark f-10">+' . (count($row->users) - 4) . '</a></div> ';
                }

                $members .= '</div>';

                return $members;
            }
        );

        $datatables->editColumn(
            'short_code', function ($row) {

                if (is_null($row->task_short_code)) {
                    return ' -- ';
                }

                return '<a href="' . route('tasks.show', [$row->id]) . '" class="text-darkest-grey openRightModal">' . $row->task_short_code . '</a>';
            }
        );

        $datatables->addColumn(
            'name', function ($row) {
                $members = [];

                foreach ($row->users as $member) {
                    $members[] = $member->name;
                }

                return implode(',', $members);
            }
        );

        if (in_array('timelogs', user_modules()) ) {

            $datatables->addColumn(
                'timer', function ($row) {
                    if ($row->boardColumn->slug == 'completed' || is_null($row->is_task_user)) {
                        return null;
                    }

                    if (is_null($row->userActiveTimer)) {
                        return '<a href="javascript:;" class="text-primary btn border f-15 start-timer" data-task-id="' . $row->id . '" data-toggle="tooltip" data-original-title="' . __('modules.timeLogs.startTimer') . '"><i class="bi bi-play-circle-fill"></i></a>';
                    }

                    if (is_null($row->userActiveTimer->activeBreak)) {
                        $timerButtons = '<div class="btn-group" role="group">';
                        $timerButtons .= '<a href="javascript:;" class="text-secondary btn border f-15 pause-timer" data-time-id="' . $row->userActiveTimer->id . '" data-toggle="tooltip" data-original-title="' . __('modules.timeLogs.pauseTimer') . '"><i class="bi bi-pause-circle-fill"></i></a>';
                        $timerButtons .= '<a href="javascript:;" class="text-secondary btn border f-15 stop-timer" data-time-id="' . $row->userActiveTimer->id . '" data-toggle="tooltip" data-original-title="' . __('modules.timeLogs.stopTimer') . '"><i class="bi bi-stop-circle-fill"></i></a>';
                        $timerButtons .= '</div>';
                        return $timerButtons;
                    }

                    $timerButtons = '<div class="btn-group" role="group">';
                    $timerButtons .= '<a href="javascript:;" class="text-secondary btn border f-15 resume-timer" data-time-id="' . $row->userActiveTimer->activeBreak->id . '" data-toggle="tooltip" data-original-title="' . __('modules.timeLogs.resumeTimer') . '"><i class="bi bi-play-circle-fill"></i></a>';
                    $timerButtons .= '<a href="javascript:;" class="text-secondary btn border f-15 stop-timer" data-time-id="' . $row->userActiveTimer->id . '" data-toggle="tooltip" data-original-title="' . __('modules.timeLogs.stopTimer') . '"><i class="bi bi-stop-circle-fill"></i></a>';
                    $timerButtons .= '</div>';
                    return $timerButtons;
                }
            );
        }

        $datatables->editColumn(
            'clientName', function ($row) {
                return ($row->clientName) ? $row->clientName : '--';
            }
        );

        $datatables->addColumn(
            'task', function ($row) {
                return $row->heading;
            }
        );
        $datatables->addColumn(
            'timeLogged', function ($row) {

                $timeLog = '--';

                if (count($row->timeLogged) > 0) {
                    $totalMinutes = $row->timeLogged->sum('total_minutes');

                    $breakMinutes = $row->breakMinutes();

                    $timeLog = CarbonInterval::formatHuman($totalMinutes - $breakMinutes); /** @phpstan-ignore-line */

                }
            }
        );

        $datatables->addColumn('task_project_name', function ($row) {
            return !is_null($row->project_id) ? $row->project_name : '--';
        });
        $datatables->addColumn(
            'timeLogged', function ($row) {

                $timeLog = '--';

                if ($row->timeLogged) {
                    $totalMinutes = $row->timeLogged->sum('total_minutes');

                    $breakMinutes = $row->breakMinutes();

                    $timeLog = CarbonInterval::formatHuman($totalMinutes - $breakMinutes); /** @phpstan-ignore-line */
                }

                return $timeLog;
            }
        );
        $datatables->editColumn(
            'heading', function ($row) {
                $subTask = $labels = $private = $pin = $timer = '';

                if ($row->is_private) {
                    $private = '<span class="badge badge-secondary mr-1"><i class="fa fa-lock"></i> ' . __('app.private') . '</span>';
                }

                if (($row->pinned_task)) {
                    $pin = '<span class="badge badge-secondary mr-1"><i class="fa fa-thumbtack"></i> ' . __('app.pinned') . '</span>';
                }

                if ($row->active_timer_all_count > 1) {
                    $timer .= '<span class="badge badge-primary mr-1" ><i class="fa fa-clock"></i> ' . $row->active_timer_all_count . ' ' . __('modules.projects.activeTimers') . '</span>';
                }

                if ($row->activeTimer && $row->active_timer_all_count == 1) {
                    $timer .= '<span class="badge badge-primary mr-1" data-toggle="tooltip" data-original-title="' . __('modules.projects.activeTimers') . '" ><i class="fa fa-clock"></i> ' . $row->activeTimer->timer . '</span>';
                }

                if ($row->subtasks_count > 0) {
                    $subTask .= '<a href="' . route('tasks.show', [$row->id]) . '?view=sub_task" class="openRightModal"><span class="border rounded p-1 f-11 mr-1 text-dark-grey" data-toggle="tooltip" data-original-title="' . __('modules.tasks.subTask') . '"><i class="bi bi-diagram-2"></i> ' . $row->completed_subtasks_count .'/' . $row->subtasks_count . '</span></a>';
                }

                foreach ($row->labels as $label) {
                    $labels .= '<span class="badge badge-secondary mr-1" style="background-color: ' . $label->label_color . '">' . $label->label_name . '</span>';
                }

                $name = '';

                if (!is_null($row->project_id) && !is_null($row->id)) {
                    $name .= '<h5 class="f-13 text-darkest-grey mb-0">' . $row->heading . '</h5><div class="text-muted f-11">' . $row->project_name . '</div>';
                }
                else if (!is_null($row->id)) {
                    $name .= '<h5 class="f-13 text-darkest-grey mb-0 mr-1">' . $row->heading . '</h5>';
                }

                if ($row->repeat) {
                    $name .= '<span class="badge badge-primary">' . __('modules.events.repeat') . '</span>';
                }

                return BaseModel::clickAbleLink(route('tasks.show', [$row->id]), $name, $subTask . ' ' . $private . ' ' . $pin . ' ' . $timer . ' ' . $labels);

            }
        );
        $datatables->editColumn(
            'board_column', function ($row) use ($taskBoardColumns) {
                $taskUsers = $row->users->pluck('id')->toArray();

                if ($this->changeStatusPermission == 'all'
                    || ($this->changeStatusPermission == 'added' && $row->added_by == user()->id)
                    || ($this->changeStatusPermission == 'owned' && in_array(user()->id, $taskUsers))
                    || ($this->changeStatusPermission == 'both' && (in_array(user()->id, $taskUsers) || $row->added_by == user()->id))
                    || ($row->project_admin == user()->id)
                ) {
                    $status = '<select class="form-control select-picker change-status" data-task-id="' . $row->id . '">';

                    foreach ($taskBoardColumns as $item) {
                        $status .= '<option ';

                        if ($item->id == $row->board_column_id) {
                            $status .= 'selected';
                        }

                        $status .= '  data-content="<i class=\'fa fa-circle mr-2\' style=\'color: ' . $item->label_color . '\'></i> ' . $item->column_name . '" value="' . $item->slug . '">' . $item->column_name . '</option>';
                    }

                    $status .= '</select>';

                    return $status;

                }
                else {
                    return '<span class="p-2"><i class="fa fa-circle mr-1 text-yellow"
                    style="color: ' . $row->boardColumn->label_color . '"></i>' . $row->boardColumn->column_name.'</span>';
                }
            }
        );
        $datatables->addColumn(
            'status', function ($row) {
                return $row->boardColumn->column_name;
            }
        );

        $datatables->setRowId(
            function ($row) {
                return 'row-' . $row->id;
            }
        );
        $datatables->setRowClass(
            function ($row) {
                return $row->pinned_task ? 'alert-primary' : '';
            }
        );
        $datatables->removeColumn('project_id');
        $datatables->removeColumn('image');
        $datatables->removeColumn('created_image');
        $datatables->removeColumn('label_color');

        // CustomField For export
        $customFieldColumns = CustomField::customFieldData($datatables, Task::CUSTOM_FIELD_MODEL);

        $datatables->rawColumns(array_merge(['short_code', 'board_column','completed_on', 'action', 'clientName', 'due_date', 'users', 'heading', 'check', 'timeLogged', 'timer', 'start_date'], $customFieldColumns));

        return $datatables;
    }

    /**
     * @param  Task $model
     * @return mixed
     */
    public function query(Task $model)
    {
        $request = $this->request();
        $startDate = null;
        $endDate = null;

        if ($request->startDate !== null && $request->startDate != 'null' && $request->startDate != '') {
            $startDate = Carbon::createFromFormat($this->company->date_format, $request->startDate)->toDateString();
        }

        if ($request->endDate !== null && $request->endDate != 'null' && $request->endDate != '') {
            $endDate = Carbon::createFromFormat($this->company->date_format, $request->endDate)->toDateString();
        }

        $projectId = $request->projectId;
        $taskBoardColumn = TaskboardColumn::completeColumn();

        $model = $model->leftJoin('projects', 'projects.id', '=', 'tasks.project_id')
            ->leftJoin('users as client', 'client.id', '=', 'projects.client_id')
            ->join('taskboard_columns', 'taskboard_columns.id', '=', 'tasks.board_column_id')
            ->leftJoin('mention_users', 'mention_users.task_id', 'tasks.id');

        if (($this->viewUnassignedTasksPermission == 'all'
            && !in_array('client', user_roles())
            && ($request->assignedTo == 'unassigned' || $request->assignedTo == 'all'))
            || ($request->has('project_admin') && $request->project_admin == 1)
        ) {
            $model->leftJoin('task_users', 'task_users.task_id', '=', 'tasks.id')
                ->leftJoin('users as member', 'task_users.user_id', '=', 'member.id');
        }
        else {
            $model->leftJoin('task_users', 'task_users.task_id', '=', 'tasks.id')
                ->leftJoin('users as member', 'task_users.user_id', '=', 'member.id');

        }

        $model->leftJoin('users as creator_user', 'creator_user.id', '=', 'tasks.created_by')
            ->leftJoin('task_labels', 'task_labels.task_id', '=', 'tasks.id')
            ->selectRaw(
                'tasks.id, tasks.completed_on, tasks.task_short_code, tasks.start_date, tasks.added_by, projects.project_name, projects.project_admin, tasks.heading, tasks.repeat, client.name as clientName, creator_user.name as created_by, creator_user.image as created_image, tasks.board_column_id,
             tasks.due_date, taskboard_columns.column_name as board_column, taskboard_columns.label_color,
              tasks.project_id, tasks.is_private ,( select count("id") from pinned where pinned.task_id = tasks.id and pinned.user_id = ' . user()->id . ') as pinned_task'
            )
            ->addSelect('tasks.company_id') // Company_id is fetched so the we have fetch company relation with it)
            ->with('users', 'activeTimerAll', 'boardColumn', 'activeTimer', 'timeLogged', 'timeLogged.breaks', 'userActiveTimer', 'userActiveTimer.activeBreak', 'labels', 'taskUsers')
            ->withCount('activeTimerAll', 'completedSubtasks', 'subtasks')
            ->groupBy('tasks.id');

        if ($request->pinned == 'pinned') {
            $model->join('pinned', 'pinned.task_id', 'tasks.id');
            $model->where('pinned.user_id', user()->id);
        }

        if (!in_array('admin', user_roles())) {
            if ($request->pinned == 'private') {
                $model->where(
                    function ($q2) {
                        $q2->where('tasks.is_private', 1);
                        $q2->where(
                            function ($q4) {
                                $q4->where('task_users.user_id', user()->id);
                                $q4->orWhere('tasks.added_by', user()->id);
                            }
                        );
                    }
                );

            }
            else {
                $model->where(
                    function ($q) {
                        $q->where('tasks.is_private', 0);
                        $q->orWhere(
                            function ($q2) {
                                $q2->where('tasks.is_private', 1);
                                $q2->where(
                                    function ($q5) {
                                        $q5->where('task_users.user_id', user()->id);
                                        $q5->orWhere('tasks.added_by', user()->id);
                                    }
                                );
                            }
                        );
                    }
                );
            }
        }

        if ($request->assignedTo == 'unassigned' && $this->viewUnassignedTasksPermission == 'all' && !in_array('client', user_roles())) {
            $model->whereDoesntHave('users');
        }

        if ($startDate !== null && $endDate !== null) {
            $model->where(
                function ($q) use ($startDate, $endDate) {
                    if (request()->date_filter_on == 'due_date') {
                        $q->whereBetween(DB::raw('DATE(tasks.`due_date`)'), [$startDate, $endDate]);

                    }
                    elseif (request()->date_filter_on == 'start_date') {
                        $q->whereBetween(DB::raw('DATE(tasks.`start_date`)'), [$startDate, $endDate]);

                    }
                    elseif (request()->date_filter_on == 'completed_on') {
                        $q->whereBetween(DB::raw('DATE(tasks.`completed_on`)'), [$startDate, $endDate]);
                    }

                }
            );
        }

        if ($request->overdue == 'yes' && $request->status != 'all') {
            $model->where(DB::raw('DATE(tasks.`due_date`)'), '<', now(company()->timezone)->toDateString());
        }

        if ($projectId != 0 && $projectId != null && $projectId != 'all') {
            $model->where('tasks.project_id', '=', $projectId);
        }

        if ($request->clientID != '' && $request->clientID != null && $request->clientID != 'all') {
            $model->where('projects.client_id', '=', $request->clientID);
        }

        if ($request->assignedTo != '' && $request->assignedTo != null && $request->assignedTo != 'all' && $request->assignedTo != 'unassigned') {
            $model->where('task_users.user_id', '=', $request->assignedTo);
        }

        if (($request->has('project_admin') && $request->project_admin != 1) || !$request->has('project_admin')) {
            if ($this->viewTaskPermission == 'owned') {
                $model->where(
                    function ($q) use ($request) {
                        $q->where('task_users.user_id', '=', user()->id);
                          $q->orWhere('mention_users.user_id', user()->id);

                        if ($this->viewUnassignedTasksPermission == 'all' && !in_array('client', user_roles()) && $request->assignedTo == 'all') {
                            $q->orWhereDoesntHave('users');
                        }

                        if (in_array('client', user_roles())) {
                            $q->orWhere('projects.client_id', '=', user()->id);
                        }
                    }
                );

                if ($projectId != 0 && $projectId != null && $projectId != 'all' && !in_array('client', user_roles())) {
                    $model->where(
                        function ($q) {
                            $q->where('projects.project_admin', '<>', user()->id)
                                ->orWhere('mention_users.user_id', user()->id);

                        }
                    );

                }

            }

            if ($this->viewTaskPermission == 'added') {
                $model->where(
                    function ($q) {
                        $q->where('tasks.added_by', '=', user()->id)
                            ->orWhere('mention_users.user_id', user()->id);

                    }
                );
            }

            if ($this->viewTaskPermission == 'both') {

                $model->where(
                    function ($q) use ($request) {
                        $q->where('task_users.user_id', '=', user()->id);
                        $q->orWhere('tasks.added_by', '=', user()->id)
                            ->orWhere('mention_users.user_id', user()->id);

                        if (in_array('client', user_roles())) {
                            $q->orWhere('projects.client_id', '=', user()->id);
                        }

                        if ($this->viewUnassignedTasksPermission == 'all' && !in_array('client', user_roles()) && ($request->assignedTo == 'unassigned' || $request->assignedTo == 'all')) {
                            $q->orWhereDoesntHave('users');
                        }

                    }
                );

            }
        }

        if ($request->assignedBY != '' && $request->assignedBY != null && $request->assignedBY != 'all') {
            $model->where('creator_user.id', '=', $request->assignedBY);
        }

        if ($request->status != '' && $request->status != null && $request->status != 'all') {
            if ($request->status == 'not finished' || $request->status == 'pending_task') {
                $model->where('tasks.board_column_id', '<>', $taskBoardColumn->id);
            }
            else {
                $model->where('tasks.board_column_id', '=', $request->status);
            }
        }

        if ($request->label != '' && $request->label != null && $request->label != 'all') {
            $model->where('task_labels.label_id', '=', $request->label);
        }

        if ($request->category_id != '' && $request->category_id != null && $request->category_id != 'all') {
            $model->where('tasks.task_category_id', '=', $request->category_id);
        }

        if ($request->billable != '' && $request->billable != null && $request->billable != 'all') {
            $model->where('tasks.billable', '=', $request->billable);
        }

        if ($request->milestone_id != '' && $request->milestone_id != null && $request->milestone_id != 'all') {
            $model->where('tasks.milestone_id', $request->milestone_id);
        }

        if ($request->searchText != '') {
            $model->where(
                function ($query) {
                    $query->where('tasks.heading', 'like', '%' . request('searchText') . '%')
                        ->orWhere('member.name', 'like', '%' . request('searchText') . '%')
                        ->orWhere('projects.project_name', 'like', '%' . request('searchText') . '%')
                        ->orWhere('projects.project_short_code', 'like', '%' . request('searchText') . '%')
                        ->orWhere('tasks.task_short_code', 'like', '%' . request('searchText') . '%');
                }
            );
        }

        if ($request->trashedData == 'true') {
            $model->whereNotNull('projects.deleted_at');
        }
        else {
            $model->whereNull('projects.deleted_at');
        }

        if ($request->type == 'public') {
            $model->where('tasks.is_private', 0);
        }

        $model->orderbyRaw('pinned_task desc');
        return $model;
    }

    /**
     * Optional method if you want to use html builder.
     *
     * @return \Yajra\DataTables\Html\Builder
     */
    public function html()
    {
        $dataTable = $this->setBuilder('allTasks-table')
            ->parameters(
                [
                'initComplete' => 'function () {
                   window.LaravelDataTables["allTasks-table"].buttons().container()
                    .appendTo("#table-actions")
                }',
                'fnDrawCallback' => 'function( oSettings ) {
                    $("#allTasks-table .select-picker").selectpicker();
                    $(".bs-tooltip-top").removeClass("show");
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
        $taskSettings = TaskSetting::first();

        $data = [
            'check' => [
                'title' => '<input type="checkbox" name="select_all_table" id="select-all-table" onclick="selectAllTable(this)">',
                'exportable' => false,
                'orderable' => false,
                'searchable' => false,
            ],
            __('modules.taskCode') => ['data' => 'short_code', 'name' => 'task_short_code', 'title' => __('modules.taskCode')]
        ];

        if (in_array('timelogs', user_modules())) {
            $data[__('app.timer') . ' ' ] = ['data' => 'timer', 'name' => 'timer', 'exportable' => false, 'searchable' => false, 'sortable' => false, 'title' => __('app.timer'), 'class' => 'text-right'];
        }

        $data2 = [
            __('app.task') => ['data' => 'heading', 'name' => 'heading', 'exportable' => false, 'title' => __('app.task')],
            __('app.menu.tasks') . ' ' => ['data' => 'task', 'name' => 'heading', 'visible' => false, 'title' => __('app.menu.tasks')],
            __('app.project') => ['data' => 'task_project_name', 'visible' => false, 'name' => 'task_project_name', 'title' => __('app.project')],
            __('modules.tasks.assigned') => ['data' => 'name', 'name' => 'name', 'visible' => false, 'title' => __('modules.tasks.assigned')],
            __('app.completedOn') => ['data' => 'completed_on', 'name' => 'completed_on', 'title' => __('app.completedOn')]
        ];

        $data = array_merge($data, $data2);

        if (in_array('client', user_roles())) {

            if (in_array('client', user_roles()) && $taskSettings->start_date == 'yes') {
                $data[__('app.startDate')] = ['data' => 'start_date', 'name' => 'start_date', 'title' => __('app.startDate')];
            }

            if ($taskSettings->due_date == 'yes') {
                $data[__('app.dueDate')] = ['data' => 'due_date', 'name' => 'due_date', 'title' => __('app.dueDate')];
            }

            if ($taskSettings->hours_logged == 'yes' && in_array('timelogs', user_modules())) {
                $data[__('modules.employees.hoursLogged')] = ['data' => 'timeLogged', 'name' => 'timeLogged', 'title' => __('modules.employees.hoursLogged')];
            }

            if ($taskSettings->assigned_to == 'yes') {
                $data[ __('modules.tasks.assignTo')] = ['data' => 'users', 'name' => 'member.name', 'exportable' => false, 'title' => __('modules.tasks.assignTo')];
            }

            if ($taskSettings->status == 'yes') {
                $data[__('app.columnStatus')] = ['data' => 'board_column', 'name' => 'board_column', 'exportable' => false, 'searchable' => false, 'title' => __('app.columnStatus')];
            }
        }
        else {
            $data[__('app.startDate')] = ['data' => 'start_date', 'name' => 'start_date', 'title' => __('app.startDate')];
            $data[__('app.dueDate')] = ['data' => 'due_date', 'name' => 'due_date', 'title' => __('app.dueDate')];

            if (in_array('timelogs', user_modules())) {
                $data[__('modules.employees.hoursLogged')] = ['data' => 'timeLogged', 'name' => 'timeLogged', 'title' => __('modules.employees.hoursLogged')];
            }

            $data[ __('modules.tasks.assignTo')] = ['data' => 'users', 'name' => 'member.name', 'exportable' => false, 'title' => __('modules.tasks.assignTo')];
            $data[__('app.columnStatus')] = ['data' => 'board_column', 'name' => 'board_column', 'exportable' => false, 'searchable' => false, 'title' => __('app.columnStatus')];

        }

        $data[__('app.task') . ' ' . __('app.status')] = ['data' => 'status', 'name' => 'board_column_id', 'visible' => false, 'title' => __('app.task')];

        $action = [
            Column::computed('action', __('app.action'))
                ->exportable(false)
                ->printable(false)
                ->orderable(false)
                ->searchable(false)
                ->addClass('text-right pr-20')
        ];

        return array_merge($data, CustomFieldGroup::customFieldsDataMerge(new Task()), $action);

    }

}

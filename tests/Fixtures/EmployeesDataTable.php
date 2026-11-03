<?php

namespace App\DataTables;

use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class EmployeesDataTable extends DataTable
{
    public function dataTable($query)
    {
        return (new EloquentDataTable($query))
            ->editColumn('employee_id', function ($row) {
                return '<a href="'.route('employees.show', [$row->id]).'" class="text-darkest-grey">'.$row->employee_detail->employee_id.'</a>';
            })
            ->editColumn('name', function ($row) {
                return view('components.employee', [
                    'user' => $row,
                ]);
            })
            ->editColumn('status', function ($row) {
                if ($row->status == 'active') {
                    return '<i class="fa fa-circle mr-1 text-light-green f-10"></i>'.__('app.active');
                }

                return '<i class="fa fa-circle mr-1 text-red f-10"></i>'.__('app.inactive');
            })
            ->addColumn('role', function ($row) {
                return '<select class="form-control select-picker assign_role" data-user-id="'.$row->id.'"><option value="1">Admin</option></select>';
            })
            ->rawColumns(['name', 'action', 'role', 'status', 'check', 'employee_id']);
    }

    protected function getColumns()
    {
        return [
            '#' => ['data' => 'DT_RowIndex', 'orderable' => false, 'searchable' => false, 'visible' => false, 'title' => '#'],
            __('app.id') => ['data' => 'id', 'name' => 'id', 'title' => __('app.id'), 'visible' => false],
            __('modules.employees.employeeId') => ['data' => 'employee_id', 'name' => 'employee_id', 'title' => __('modules.employees.employeeId')],
            __('app.name') => ['data' => 'name', 'name' => 'name', 'exportable' => false, 'title' => __('app.name')],
            __('app.email') => ['data' => 'email', 'name' => 'email', 'title' => __('app.email')],
            __('app.role') => ['data' => 'role', 'name' => 'role', 'title' => __('app.role')],
            __('app.status') => ['data' => 'status', 'name' => 'status', 'title' => __('app.status')],
            Column::computed('action', __('app.action'))->exportable(false)->printable(false),
        ];
    }
}

<?php

namespace App\DataTables;

use App\Models\Task;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Services\DataTable;

class TasksDataTable extends DataTable
{
    public function dataTable($query)
    {
        return (new EloquentDataTable($query))
            ->editColumn('due_date', function ($row) {
                if ($row->due_date && $row->due_date->isPast()) {
                    return '<span class="text-danger">'.$row->due_date->format('d M, Y').'</span>';
                }

                return $row->due_date ? $row->due_date->format('d M, Y') : '--';
            })
            ->editColumn('board_column', function ($row) {
                $column = $row->board_column;

                return '<span class="badge" style="background-color: '.$column->label_color.'">'.$column->column_name.'</span>';
            })
            ->addColumn('users', function ($row) {
                $html = '';
                foreach ($row->users as $user) {
                    $html .= '<img src="'.$user->image_url.'" class="user-image" alt="'.$user->name.'" />';
                }

                return $html;
            })
            ->rawColumns(['due_date', 'board_column', 'users']);
    }
}

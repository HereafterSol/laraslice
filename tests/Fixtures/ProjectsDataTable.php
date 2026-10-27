<?php

namespace App\DataTables;

use App\Models\Project;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Services\DataTable;

class ProjectsDataTable extends DataTable
{
    public function dataTable($query)
    {
        return (new EloquentDataTable($query))
            ->editColumn('deadline', function ($row) {
                if ($row->deadline && $row->deadline->isPast()) {
                    return '<span class="text-danger">'.$row->deadline->format('d M, Y').'</span>';
                }

                return $row->deadline ? $row->deadline->format('d M, Y') : '--';
            })
            ->editColumn('completion_percent', function ($row) {
                return '<div class="progress"><div class="progress-bar" role="progressbar" style="width: '.$row->completion_percent.'%"></div></div>';
            })
            ->addColumn('members', function ($row) {
                $html = '';
                foreach ($row->members as $member) {
                    $html .= '<img src="'.$member->image_url.'" class="user-image" alt="'.$member->name.'" />';
                }

                return $html;
            })
            ->rawColumns(['deadline', 'completion_percent', 'members']);
    }
}

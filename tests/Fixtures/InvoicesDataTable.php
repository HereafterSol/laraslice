<?php

namespace App\DataTables;

use App\Models\Invoice;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Services\DataTable;

class InvoicesDataTable extends DataTable
{
    public function dataTable($query)
    {
        return (new EloquentDataTable($query))
            ->editColumn('total', function ($row) {
                $paid = currency_format($row->amount_paid);
                $due = currency_format($row->amount_due);

                return currency_format($row->total).'<br><span class="text-success">Paid: '.$paid.'</span><br><span class="text-danger">Due: '.$due.'</span>';
            })
            ->editColumn('status', function ($row) {
                return '<span class="badge badge-primary">'.$row->status_name.'</span>';
            })
            ->editColumn('invoice_number', function ($row) {
                return $row->invoice_number;
            })
            ->rawColumns(['total', 'status']);
    }
}

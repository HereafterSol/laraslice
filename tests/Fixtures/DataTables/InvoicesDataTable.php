<?php

namespace App\DataTables;

use Carbon\Carbon;
use App\Models\Invoice;
use App\Models\CustomField;
use App\Models\CustomFieldGroup;
use App\DataTables\BaseDataTable;
use Illuminate\Database\Eloquent\Model;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Illuminate\Support\Facades\DB;

class InvoicesDataTable extends BaseDataTable
{

    protected $firstInvoice;
    private $viewInvoicePermission;
    private $deleteInvoicePermission;
    private $editInvoicePermission;
    private $addPaymentPermission;
    private $addInvoicesPermission;
    private $viewProjectInvoicePermission;

    public function __construct()
    {
        parent::__construct();
        $this->viewInvoicePermission = user()->permission('view_invoices');
        $this->deleteInvoicePermission = user()->permission('delete_invoices');
        $this->editInvoicePermission = user()->permission('edit_invoices');
        $this->addPaymentPermission = user()->permission('add_payments');
        $this->viewProjectInvoicePermission = user()->permission('view_project_invoices');
        $this->addInvoicesPermission = user()->permission('add_invoices');

    }

    /**
     * Build DataTable class.
     *
     * @param mixed $query Results from query() method.
     * @return \Yajra\DataTables\DataTableAbstract
     */
    public function dataTable($query)
    {

        $firstInvoice = $this->firstInvoice;
        $datatables = datatables()->eloquent($query);
        $datatables->addIndexColumn();
        $datatables->addColumn('action', function ($row) use ($firstInvoice) {
            $action = '<div class="task_view">

                <div class="dropdown dropup">
                    <a class="task_view_more d-flex align-items-center justify-content-center dropdown-toggle" type="link"
                        id="dropdownMenuLink-' . $row->id . '" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <i class="icon-options-vertical icons"></i>
                    </a>
                    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="dropdownMenuLink-' . $row->id . '" tabindex="0">';

            $action .= '<a href="' . route('invoices.show', [$row->id]) . '" class="dropdown-item"><i class="fa fa-eye mr-2"></i>' . __('app.view') . '</a>';

            if (
                $this->viewInvoicePermission == 'all'
                || ($this->viewInvoicePermission == 'added' && user()->id == $row->added_by)
                || ($this->viewInvoicePermission == 'owned' && user()->id == $row->client_id)
                || $this->viewProjectInvoicePermission == 'owned' && !is_null($row->project_id) && user()->id == $row->project->client_id
            ) {
                $action .= '<a class="dropdown-item" href="' . route('invoices.download', [$row->id]) . '">
                                <i class="fa fa-download mr-2"></i>
                                ' . trans('app.download') . '
                            </a>';
                $action .= '<a class="dropdown-item" target="_blank" href="' . route('invoices.download', [$row->id, 'view' => true]) . '">
                                <i class="fa fa-eye mr-2"></i>
                                ' . trans('app.viewPdf') . '
                            </a>';
            }

            if ($row->status != 'canceled' && !in_array('client', user_roles()) && $row->credit_note == 0) {
                $action .= '<a class="dropdown-item sendButton" href="javascript:;" data-toggle="tooltip"  data-invoice-id="' . $row->id . '">
                                <i class="fa fa-paper-plane mr-2"></i>
                                ' . trans('app.send') . '
                            </a>';
            }

            if ($row->status != 'canceled' && !in_array('client', user_roles()) && $row->credit_note == 0 && $row->send_status == 0) {
                $action .= '<a class="dropdown-item sendButton d-flex justify-content-between align-items-center" data-type="mark_as_send" href="javascript:;"  data-invoice-id="' . $row->id . '">
                                <div><i class="fa fa-check-double mr-2"></i>
                                ' . trans('app.markSent') . '
                                </div>
                                <i class="fa fa-question-circle" data-toggle="tooltip" data-original-title="' . __('messages.markSentInfo') . '"></i>
                            </a>';
            }

            $edit = '<a class="dropdown-item" href="' . route('invoices.edit', $row->id) . '" >
                        <i class="fa fa-edit mr-2"></i>
                        ' . trans('app.edit') . '
                    </a>';

            if ($row->status == 'paid' && !in_array('client', user_roles()) && $row->credit_note == 0) {
                $action .= '<a class="dropdown-item invoice-upload" href="javascript:;" data-toggle="tooltip"  data-invoice-id="' . $row->id . '">
                                <i class="fa fa-upload mr-2"></i>
                                ' . trans('app.upload') . '
                            </a>';

                if ($row->amountPaid() == 0 && $row->amountDue() > 0) {
                    $action .= $edit;
                }
            }

            if ($row->status != 'paid' && $row->status != 'canceled') {
                if (is_null($row->invoice_recurring_id)) {
                    if (
                        $this->editInvoicePermission == 'all'
                        || ($this->editInvoicePermission == 'added' && $row->added_by == user()->id)
                        || ($this->editInvoicePermission == 'owned' && $row->client_id == user()->id)
                        || ($this->editInvoicePermission == 'both' && ($row->client_id == user()->id || $row->added_by == user()->id))
                    ) {
                        $action .= $edit;
                    }
                }

                if (!in_array('client', user_roles()) && in_array('payments', $this->user->modules) && $row->credit_note == 0 && $row->status != 'draft' && $row->send_status) {
                    if (
                        $this->addPaymentPermission == 'all'
                        || ($this->addPaymentPermission == 'added' && $row->added_by == user()->id)
                    ) {
                        $action .= '<a class="dropdown-item openRightModal"
                        data-redirect-url="' . route('invoices.index') . '" href="' . route('payments.create') . '?invoice_id=' . $row->id . '&default_client=' . $row->client_id . '" >
                                    <i class="fa fa-plus mr-2"></i>
                                    ' . trans('modules.payments.addPayment') . '
                                </a>';
                    }
                }
            }

            if ($row->status != 'canceled' && $row->credit_note == 0) {
                if ($row->clientdetails) {
                    if (!is_null($row->clientdetails->shipping_address)) {

                        $action .= ($row->show_shipping_address == 'yes') ? '<a class="dropdown-item toggle-shipping-address" href="javascript:;" data-toggle="tooltip"  data-invoice-id="' . $row->id . '">
                                <i class="fa fa-eye-slash mr-2"></i>
                                ' . __('app.hideShippingAddress') . '
                            </a>' : '<a class="dropdown-item toggle-shipping-address" href="javascript:;" data-toggle="tooltip"  data-invoice-id="' . $row->id . '">
                                <i class="fa fa-eye mr-2"></i>
                                ' . __('app.showShippingAddress') . '
                            </a>';

                    }
                    else {
                        $action .= '<a class="dropdown-item add-shipping-address" href="javascript:;" data-toggle="tooltip"  data-invoice-id="' . $row->id . '">
                            <i class="fa fa-plus mr-2"></i>
                            ' . __('app.addShippingAddress') . '
                        </a>';
                    }
                }
                else {
                    if ($row->project && $row->project->clientdetails) {
                        if (!is_null($row->project->clientdetails->shipping_address)) {
                            $action .= ($row->show_shipping_address == 'yes') ? '<a class="dropdown-item toggle-shipping-address" href="javascript:;" data-toggle="tooltip"  data-invoice-id="' . $row->id . '">
                                    <i class="fa fa-eye-slash mr-2"></i>
                                    ' . __('app.hideShippingAddress') . '
                                </a>' : '<a class="dropdown-item toggle-shipping-address" href="javascript:;" data-toggle="tooltip" data-invoice-id="' . $row->id . '">
                                    <i class="fa fa-eye mr-2"></i>
                                    ' . __('app.showShippingAddress') . '
                                </a>';
                        }
                        else {
                            $action .= '<a class="dropdown-item add-shipping-address" href="javascript:;" data-invoice-id="' . $row->id . '">
                                <i class="fa fa-plus mr-2"></i>
                                ' . __('app.addShippingAddress') . '
                            </a>';
                        }
                    }
                }
            }

            if (($row->status == 'unpaid' || $row->status == 'draft') && !in_array('client', user_roles())) {
                $action .= '<a class="dropdown-item cancel-invoice" href="javascript:;"  data-invoice-id="' . $row->id . '">
                    <i class="fa fa-times mr-2"></i>
                    ' . trans('app.cancel') . '
                </a>';
            }

            if ($row->credit_note == 0 && $row->status != 'draft' && $row->status != 'canceled' && $row->send_status) {
                $action .= '<a class="dropdown-item btn-copy" href="javascript:;" data-clipboard-text="' . url()->temporarySignedRoute('front.invoice', now()->addDays(2), $row->hash) . '"><i class="fa fa-copy mr-2"></i>' . trans('modules.invoices.copyPaymentLink') . '</a>';

                $action .= '<a class="dropdown-item" href="' . url()->temporarySignedRoute('front.invoice', now()->addDays(2), $row->hash) . '" target="_blank"><i class="fa fa-external-link-alt mr-2"></i>' . trans('modules.payments.paymentLink') . '</a>';
            }

            if ($row->credit_note == 0 && $row->status != 'draft' && $row->status != 'canceled' && $row->status != 'unpaid' && !in_array('client', user_roles())) {
                if ($row->amountPaid() > 0) {
                    if ($row->status == 'paid') {
                        $action .= '<a class="dropdown-item" href="' . route('creditnotes.create') . '?invoice=' . $row->id . '"><i class="fa fa-plus mr-2"></i>' . trans('modules.credit-notes.addCreditNote') . '</a>';
                    }
                    else {
                        $action .= '<a class="dropdown-item unpaidAndPartialPaidCreditNote" data-toggle="tooltip"  data-invoice-id="' . $row->id . '" href="javascript:;"><i class="fa fa-plus mr-2"></i>' . trans('modules.credit-notes.addCreditNote') . '</a>';
                    }
                }
            }

            if ($row->status != 'paid' && $row->status != 'draft' && $row->status != 'canceled' && $row->credit_note == 0 && !in_array('client', user_roles()) && $row->send_status) {
                $action .= '<a class="dropdown-item reminderButton" data-toggle="tooltip"  data-invoice-id="' . $row->id . '" href="javascript:;"><i class="fa fa-bell mr-2"></i>' . trans('app.paymentReminder') . '</a>';
            }

            if (
                $this->deleteInvoicePermission == 'all'
                || ($this->deleteInvoicePermission == 'added' && $row->added_by == user()->id)
                || ($this->deleteInvoicePermission == 'owned' && $row->client_id == user()->id)
                || ($this->deleteInvoicePermission == 'both' && ($row->client_id == user()->id || $row->added_by == user()->id))
            ) {
                if ($firstInvoice->id == $row->id && ($row->status != 'paid' && $row->status != 'partial')) {
                    $action .= '<a class="dropdown-item delete-table-row" href="javascript:;" data-toggle="tooltip"  data-invoice-id="' . $row->id . '">
                        <i class="fa fa-trash mr-2"></i>
                        ' . trans('app.delete') . '
                    </a>';
                }
            }

            if ($this->addInvoicesPermission == 'all' || $this->addInvoicesPermission == 'added') {

                $action .= '<a href="' . route('invoices.create') . '?invoice=' . $row->id . '" class="dropdown-item"><i class="fa fa-copy mr-2"></i> ' . __('app.create') . ' ' . __('app.duplicate') . '</a>';
            }

            $action .= '</div>
            </div>
        </div>';

            return $action;
        });
        $datatables->editColumn('project_name', function ($row) {
            if ($row->project_id != null) {
                return '<a href="' . route('projects.show', $row->project_id) . '" class="text-darkest-grey">' . $row->project->project_name . '</a>';
            }

            return '--';
        });
        $datatables->addColumn('short_code', function ($row) {
            if (!is_null($row->project)) {
                return $row->project->project_short_code;
            }
            else {
                return '--';
            }
        });
        $datatables->addColumn('client_name', function ($row) {
            if ($row->client) {
                return $row->client->name;
            }
            else if ($row->project && $row->project->client) {
                return $row->project->client->name;
            }
            else if ($row->estimate && $row->estimate->client) {
                return $row->estimate->client->name;
            }
            else {
                return '--';
            }
        });
        $datatables->addColumn('client_email', function ($row) {
            if ($row->project && $row->project->client) {
                return $row->project->client->email;
            }
            else if ($row->client) {
                return $row->client->email;
            }
            else if ($row->estimate && $row->estimate->client) {
                return $row->estimate->client->email;
            }
            else {
                return '--';
            }
        });

        $datatables->editColumn('name', function ($row) {
            if ($row->client) {
                $client = $row->client;

            }
            else if ($row->project && $row->project->client) {
                $client = $row->project->client;
            }
            else if ($row->estimate && $row->estimate->client) {
                $client = $row->estimate->client;
            }
            else {
                return '--';
            }

            return view('components.client', [
                'user' => $client
            ]);
        });
        $datatables->addColumn('invoice', function ($row) {
            return $row->invoice_number;
        });

        $datatables->editColumn('invoice_number', function ($row) {
            $recurring = '';

            if (!is_null($row->invoice_recurring_id)) {
                $recurring = '<span class="badge badge-primary"> ' . __('app.recurring') . ' </span>';
            }

            return '<div class="media align-items-center">
                        <div class="media-body">
                    <h5 class="mb-0 f-13 text-darkest-grey"><a href="' . route('invoices.show', [$row->id]) . '">' . $row->invoice_number . '</a></h5>
                    <p class="mb-0">' . $recurring . '</p>
                    </div>
                  </div>';
        });
        $datatables->editColumn('status', function ($row) {
            $status = '';

            if ($row->credit_note) {
                $status .= ' <i class="fa fa-circle mr-1 text-yellow f-10"></i>' . __('app.credit-note');
            }
            else {
                if ($row->status == 'unpaid') {
                    $status .= ' <i class="fa fa-circle mr-1 text-red f-10"></i>' . __('app.' . $row->status);
                }
                elseif ($row->status == 'paid') {
                    $status .= ' <i class="fa fa-circle mr-1 text-dark-green f-10"></i>' . __('app.' . $row->status);
                }
                elseif ($row->status == 'draft') {
                    $status .= ' <i class="fa fa-circle mr-1 text-blue f-10"></i>' . __('app.' . $row->status);
                }
                elseif ($row->status == 'canceled') {
                    $status .= ' <i class="fa fa-circle mr-1 text-red f-10"></i>' . __('app.' . $row->status);
                }
                else {
                    $status .= ' <i class="fa fa-circle mr-1 text-blue f-10"></i>' . __('modules.invoices.partial');
                }
            }

            if (!$row->send_status && $row->status != 'draft') {
                $status .= '<br><br><span class="badge badge-secondary">' . __('modules.invoices.notSent') . '</span>';
            }

            return $status;
        });
        $datatables->editColumn('total', function ($row) {
            $currencyId = $row->currency->id;

            return '<div class="text-right">' . __('app.total') . ': ' . currency_format($row->total, $currencyId) . '<p class="my-0"><span class="text-success mt-1">' . __('app.paid') . ':</span> ' . currency_format($row->amountPaid(), $currencyId) . '</p><span class="text-danger">' . __('app.unpaid') . ':</span> ' . currency_format($row->amountDue(), $currencyId) . '</div>';
        });
        $datatables->editColumn(
            'issue_date',
            function ($row) {
                return $row->issue_date->timezone($this->company->timezone)->translatedFormat($this->company->date_format);
            }
        );
        $datatables->orderColumn('short_code', 'invoice_number $1');
        $datatables->removeColumn('currency_symbol');
        $datatables->removeColumn('currency_code');
        $datatables->removeColumn('project_id');

        // Custom Fields For export
        $customFieldColumns = CustomField::customFieldData($datatables, Invoice::CUSTOM_FIELD_MODEL);

        $datatables->rawColumns(array_merge(['project_name', 'action', 'status', 'invoice_number', 'total', 'name'], $customFieldColumns));

        return $datatables;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function query()
    {
        $request = $this->request();
        $this->firstInvoice = Invoice::orderBy('id', 'desc')->first();

        $model = Invoice::with(
            [
                'project' => function ($q) {
                    $q->withTrashed();
                    $q->select('id', 'project_name', 'project_short_code', 'client_id');
                },
                'currency:id,currency_symbol,currency_code', 'project.client', 'client', 'payment', 'estimate', 'project.clientdetails'
            ]
        )
            ->with('client', 'client.session', 'client.clientdetails', 'payment', 'clientdetails')
            ->select([
                'invoices.id', 'invoices.due_amount', 'invoices.project_id', 'invoices.client_id', 'invoices.invoice_number',
                'invoices.currency_id', 'invoices.total', 'invoices.status', 'invoices.issue_date', 'invoices.credit_note',
                'invoices.show_shipping_address', 'invoices.send_status', 'invoices.invoice_recurring_id',
                'invoices.added_by', 'invoices.hash', 'invoices.custom_invoice_number'
            ])
            ->addSelect('invoices.company_id'); // Company_id is fetched so the we have fetch company relation with it)

        if ($request->startDate !== null && $request->startDate != 'null' && $request->startDate != '') {
            $startDate = Carbon::createFromFormat($this->company->date_format, $request->startDate)->toDateString();
            $model = $model->where(DB::raw('DATE(invoices.`issue_date`)'), '>=', $startDate);
        }

        if ($request->endDate !== null && $request->endDate != 'null' && $request->endDate != '') {
            $endDate = Carbon::createFromFormat($this->company->date_format, $request->endDate)->toDateString();
            $model = $model->where(DB::raw('DATE(invoices.`issue_date`)'), '<=', $endDate);
        }

        if ($request->status != 'all' && !is_null($request->status)) {
            if ($request->status == 'pending') {
                $model = $model->where(function ($q) {
                    $q->where('invoices.status', '=', 'unpaid');

                    $q->orWhere('invoices.status', '=', 'partial');
                });
            }
            else {
                $model = $model->where('invoices.status', '=', $request->status);
            }

            $model = $model->where('invoices.credit_note', 0);
        }

        if (request('amount') == 'pending') {
            $model = $model->where(function ($query) {
                $query->where('invoices.status', 'unpaid')
                    ->orWhere('invoices.status', 'partial');
            });
        }

        if ($request->projectID != 'all' && !is_null($request->projectID)) {
            $model = $model->where('invoices.project_id', '=', $request->projectID);
        }

        if ($request->clientID != 'all' && !is_null($request->clientID)) {
            $model = $model->where('invoices.client_id', '=', $request->clientID);
        }

        if ($request->searchText != '') {
            $model->where(function ($query) {
                $query->where('invoices.invoice_number', 'like', '%' . request('searchText') . '%')
                    ->orWhere('invoices.custom_invoice_number', 'like', '%' . request('searchText') . '%')
                    ->orWhere('invoices.id', 'like', '%' . request('searchText') . '%')
                    ->orWhere('invoices.total', 'like', '%' . request('searchText') . '%')
                    ->orWhere(function ($query) {
                        $query->whereHas('client', function ($q) {
                            $q->where('name', 'like', '%' . request('searchText') . '%');
                        });
                    })
                    ->orWhere(function ($query) {
                        $query->whereHas('project', function ($q) {
                            $q->where('project_name', 'like', '%' . request('searchText') . '%')
                                ->orWhere('project_short_code', 'like', '%' . request('searchText') . '%'); // project short code
                        });
                    })
                    ->orWhere(function ($query) {
                        $query->where('invoices.status', 'like', '%' . request('searchText') . '%');
                    });
            });
        }

        if (in_array('client', user_roles())) {
            $model = $model->where('invoices.send_status', 1);
            $model = $model->where('invoices.client_id', user()->id);
        }

        if ($this->viewInvoicePermission == 'added') {
            $model = $model->where('invoices.added_by', user()->id);
        }

        if ($this->viewInvoicePermission == 'owned') {
            $model = $model->where('invoices.client_id', user()->id);
        }

        if ($this->viewInvoicePermission == 'both') {
            $model = $model->where('invoices.client_id', user()->id)->orWhere('invoices.added_by', user()->id);
        }

        $model = $model->whereHas('project', function ($q) {
            $q->whereNull('deleted_at');
        }, '>=', 0);

        return $model;
    }

    /**
     * Optional method if you want to use html builder.
     *
     * @return \Yajra\DataTables\Html\Builder
     */
    public function html()
    {
        $dataTable = $this->setBuilder('invoices-table', 0)
            ->parameters([
                'initComplete' => 'function () {
                    window.LaravelDataTables["invoices-table"].buttons().container()
                    .appendTo( "#table-actions")
                }',
                'fnDrawCallback' => 'function( oSettings ) {
                    $("body").tooltip({
                        selector: \'[data-toggle="tooltip"]\'
                    })
                }',
            ]);

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
            __('app.id') => ['data' => 'id', 'name' => 'id', 'visible' => false, 'title' => __('app.id')],
            __('modules.taskCode') => ['data' => 'short_code', 'name' => 'short_code', 'title' => __('modules.taskCode')],
            __('app.invoice') . '#' => ['data' => 'invoice_number', 'name' => 'invoice_number', 'exportable' => false, 'title' => __('app.invoice')],
            __('app.invoiceNumber') . '#' => ['data' => 'invoice', 'name' => 'invoice_number', 'visible' => false, 'title' => __('app.invoiceNumber')],
            __('app.project') => ['data' => 'project_name', 'name' => 'project.project_name', 'title' => __('app.project'), 'visible' => in_array('projects', user_modules()) , 'exportable' => in_array('projects', user_modules())],
            __('app.client') => ['data' => 'name', 'name' => 'project.client.name', 'exportable' => false, 'title' => __('app.client'), 'visible' => !in_array('client', user_roles())],
            __('app.customers') => ['data' => 'client_name', 'name' => 'project.client.name', 'visible' => false, 'title' => __('app.customers')],
            __('app.email') => ['data' => 'client_email', 'name' => 'project.client.email', 'visible' => false, 'title' => __('app.email')],
            __('modules.invoices.total') => ['data' => 'total', 'name' => 'total', 'class' => 'text-right', 'title' => __('modules.invoices.total')],
            __('modules.invoices.invoiceDate') => ['data' => 'issue_date', 'name' => 'issue_date', 'title' => __('modules.invoices.invoiceDate')],
            __('app.status') => ['data' => 'status', 'name' => 'status', 'width' => '10%', 'title' => __('app.status')]
        ];

        $action = [
            Column::computed('action', __('app.action'))
                ->exportable(false)
                ->printable(false)
                ->orderable(false)
                ->searchable(false)
                ->addClass('text-right pr-20')
        ];

        return array_merge($data, CustomFieldGroup::customFieldsDataMerge(new Invoice()), $action);

    }

}

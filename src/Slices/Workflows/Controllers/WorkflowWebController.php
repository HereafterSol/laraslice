<?php

namespace LaraSlice\Slices\Workflows\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaraSlice\Slices\Workflows\Examples\CorporateInvoiceWorkflow;
use LaraSlice\Slices\Workflows\Examples\LegalComplaintWorkflow;
use LaraSlice\Slices\Workflows\Examples\SupportTicketWorkflow;
use LaraSlice\Slices\Workflows\Examples\WelfareBenefitClaimWorkflow;
use LaraSlice\Slices\Workflows\Models\Workflow;
use LaraSlice\Slices\Workflows\Models\WorkflowLog;

class WorkflowWebController extends Controller
{
    public function index()
    {
        $workflows = Workflow::withCount(['states', 'transitions'])
            ->latest()
            ->get();

        return view('workflows::index', compact('workflows'));
    }

    public function show(int $id)
    {
        $workflow = Workflow::with(['states', 'transitions.routingRules'])->findOrFail($id);

        return view('workflows::show', compact('workflow'));
    }

    public function inbox(Request $request)
    {
        $user = $request->user();

        $query = WorkflowLog::query();
        if ($user) {
            $query->where(function ($q) use ($user) {
                $q->where('assigned_to_user_id', $user->id);
                if (! empty($user->role)) {
                    $q->orWhere('assigned_to_role', $user->role);
                }
                if (! empty($user->department)) {
                    $q->orWhere('assigned_to_department', $user->department);
                }
            });
        }

        $items = $query->latest('id')->take(50)->get();

        return view('workflows::inbox', compact('items'));
    }

    public function logs()
    {
        $logs = WorkflowLog::with(['performer', 'assignee'])
            ->latest('id')
            ->paginate(25);

        return view('workflows::logs', compact('logs'));
    }

    public function seedExamples()
    {
        SupportTicketWorkflow::seed();
        LegalComplaintWorkflow::seed();
        WelfareBenefitClaimWorkflow::seed();
        CorporateInvoiceWorkflow::seed();

        return redirect()->route('workflows.index')->with('success', 'Successfully seeded 4 Real-World Enterprise Workflows (Support, Legal Scrutiny, WWF Grants, Invoices)!');
    }
}
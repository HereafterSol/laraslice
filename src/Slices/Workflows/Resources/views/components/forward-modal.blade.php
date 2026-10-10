<div id="workflow-action-modal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" onclick="window.closeWorkflowModal()"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

        <!-- Dialog Box -->
        <div class="inline-block align-bottom bg-white dark:bg-slate-900 rounded-2xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-slate-200 dark:border-slate-800">
            <form id="workflow-modal-form" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="transition" id="wf-modal-transition-input">

                <div class="px-6 pt-6 pb-4">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-3">
                            <span class="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-slate-900 dark:text-white" id="wf-modal-title">Transition Document</h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Review remarks & target routing</p>
                            </div>
                        </div>
                        <button type="button" onclick="window.closeWorkflowModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="mt-4 space-y-4">
                        <!-- Remarks / Notes -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Official Remarks & Scrutiny Notes <span id="wf-remarks-required" class="text-rose-500">*</span>
                            </label>
                            <textarea
                                name="remarks"
                                id="wf-modal-remarks"
                                rows="3"
                                placeholder="Enter reason, findings, or directives for this transition..."
                                class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                            ></textarea>
                        </div>

                        <!-- Forward To Role / Department Override (Optional) -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Target Department (Optional)</label>
                                <input
                                    type="text"
                                    name="assigned_to_department"
                                    placeholder="e.g. Legal, Finance"
                                    class="w-full px-3 py-2 text-xs rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                >
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Target Role (Optional)</label>
                                <input
                                    type="text"
                                    name="assigned_to_role"
                                    placeholder="e.g. director, cfo"
                                    class="w-full px-3 py-2 text-xs rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                >
                            </div>
                        </div>

                        <!-- File Attachment -->
                        <div id="wf-attachment-group">
                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Supporting Document / Findings Report (Optional)
                            </label>
                            <input
                                type="file"
                                name="attachment"
                                class="w-full text-xs text-slate-500 dark:text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 dark:file:bg-indigo-950/60 dark:file:text-indigo-400 hover:file:bg-indigo-100"
                            >
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 bg-slate-50 dark:bg-slate-800/50 flex items-center justify-end gap-2.5 border-t border-slate-100 dark:border-slate-800">
                    <button
                        type="button"
                        onclick="window.closeWorkflowModal()"
                        class="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg transition"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        id="wf-modal-submit-btn"
                        class="px-5 py-2 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg shadow-sm transition"
                    >
                        Confirm & Forward
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
window.openWorkflowModal = function(slug, label, actionUrl, requiresRemarks, requiresAttachment) {
    const modal = document.getElementById('workflow-action-modal');
    const form = document.getElementById('workflow-modal-form');
    const input = document.getElementById('wf-modal-transition-input');
    const title = document.getElementById('wf-modal-title');
    const remarks = document.getElementById('wf-modal-remarks');
    const remarksReq = document.getElementById('wf-remarks-required');

    if (!modal || !form) return;

    form.action = actionUrl;
    input.value = slug;
    title.innerText = label;
    remarks.value = '';

    if (requiresRemarks) {
        remarks.setAttribute('required', 'required');
        if (remarksReq) remarksReq.style.display = 'inline';
    } else {
        remarks.removeAttribute('required');
        if (remarksReq) remarksReq.style.display = 'none';
    }

    modal.classList.remove('hidden');
};

window.closeWorkflowModal = function() {
    const modal = document.getElementById('workflow-action-modal');
    if (modal) modal.classList.add('hidden');
};
</script>
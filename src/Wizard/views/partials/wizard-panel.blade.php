<style>[x-cloak] { display: none !important; } .laraslice-studio-root { max-width: 100vw; overflow-x: hidden; } .laraslice-studio-root table { display: block; max-width: 100%; overflow-x: auto; }</style>
<script>
function larasliceWizard() {
    return {
        mainTab: @js(old('tab', $initialTab ?? (request()->query('tab') ?: (request()->routeIs('*studio*') ? 'studio' : 'wizard')))),
        showAdvancedUi: false,
        step: 1,
        projectName: '',
        domain: '',
        namespace: @js(config('laraslice.slices_namespace', 'App\\Slices')),
        description: '',
        author: '',
        uiFramework: 'blatui',
        database: 'mysql',
        includeAuth: true,
        includeDatabase: true,
        includeApi: true,
        includeWorkflow: true,
        includeFlutter: false,
        autoGenerateRbac: true,
        permissions: [],
        newPermInput: '',
        customFields: [],
        childTables: [],
        wizardTargetTable: 'root',
        activeSliceIdx: 0,
        multiSliceData: {},
        showAddChildModal: false,
        newChildName: '',
        newChildRelation: 'hasMany',
        auditLogs: [],
        auditSearch: '',
        auditActionFilter: 'all',
        auditCounts: {},
        isLoadingAuditLogs: false,
                expandedAuditLogId: null,
        presetCategory: 'enterprise',
        workflowConfig: null,
        workflowRoles: [],
        showAddStateModal: false,
        showAddRoleModal: false,
        newState: { label: '', slug: '', color: 'slate', icon: 'clock', sla_hours: '', terminal: false },
        newRole: { name: '', slug: '', description: '', permissions: [] },
        showPortModal: false,
        portPreset: 'erp',
        portSourcePath: 'e:/laragon/www/erp',
        portDomain: 'ERP',
        portMode: 'system-base',
        portImportData: true,
        portWithFiles: false,
        portInspecting: false,
        portExecuting: false,
        portError: '',
        portInspectionData: null,
        portPlanData: null,
        portPlanJsonText: '',
        portActiveTab: 'sidebar',
        portExecutionLogs: [],
        pageArchTab: 'index',
        selectedUiSlice: 'Projects',
        selectedUiPage: 'index',
        selectedUiSubTab: 'overview',
        uiSliceSearch: '',

        sliceSearchQuery: '',
        collapsedDomains: {},
        deleteModal: {
            open: false,
            targetType: 'slice',
            targetName: '',
            mode: 'complete',
            isDeleting: false,
            errorMessage: ''
        },
        toastMessage: '',
        toastType: 'success',
        isActionRunning: false,
        actionStatusText: '',

        slicesList() {
            if (!this.projectName) return [];
            return this.projectName.split(',').map(s => s.trim()).filter(Boolean);
        },

        isMultiSlice() {
            return this.slicesList().length > 1;
        },

        activeSliceName() {
            const list = this.slicesList();
            if (list.length === 0) return this.projectName || 'item';
            if (this.activeSliceIdx >= list.length) this.activeSliceIdx = 0;
            return list[this.activeSliceIdx] || list[0];
        },

        saveActiveSliceData() {
            const curName = this.activeSliceName();
            if (!this.multiSliceData[curName]) {
                this.multiSliceData[curName] = {};
            }
            this.multiSliceData[curName].fields = JSON.parse(JSON.stringify(this.customFields));
            this.multiSliceData[curName].childTables = JSON.parse(JSON.stringify(this.childTables));
        },

        switchSliceTab(idx) {
            this.saveActiveSliceData();
            this.activeSliceIdx = idx;
            const nextName = this.activeSliceName();
            if (this.multiSliceData[nextName]) {
                this.customFields = JSON.parse(JSON.stringify(this.multiSliceData[nextName].fields || []));
                this.childTables = JSON.parse(JSON.stringify(this.multiSliceData[nextName].childTables || []));
            } else {
                this.customFields = [
                    { name: 'name', label: 'Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '255', default: '' }
                ];
                this.childTables = [];
                this.multiSliceData[nextName] = {
                    fields: JSON.parse(JSON.stringify(this.customFields)),
                    childTables: []
                };
            }
            this.wizardTargetTable = 'root';
        },

        getSliceFieldsCount(sName) {
            if (sName === this.activeSliceName()) {
                return this.customFields.length;
            }
            return this.multiSliceData[sName]?.fields?.length ?? 1;
        },

        addMoreSlice() {
            const name = prompt('Enter new Slice Name (e.g. Customers, Invoices):');
            if (name) this.addNamedSlice(name);
        },

        addNamedSlice(name) {
            if (!name || !name.trim()) return;
            const clean = name.trim().replace(/,/g, '');
            if (!clean) return;
            this.saveActiveSliceData();
            if (this.projectName && this.projectName.trim()) {
                const currentList = this.slicesList();
                if (!currentList.includes(clean)) {
                    this.projectName = this.projectName.trim() + ', ' + clean;
                }
            } else {
                this.projectName = clean;
            }
            this.refreshRbacList();
            const list = this.slicesList();
            const newIdx = list.indexOf(clean);
            this.switchSliceTab(newIdx >= 0 ? newIdx : list.length - 1);
        },

        removeSlice(idx) {
            const list = this.slicesList();
            if (list.length <= 1) return;
            const toRemove = list[idx];
            if (!confirm(`Are you sure you want to remove slice "${toRemove}"?`)) return;
            list.splice(idx, 1);
            this.projectName = list.join(', ');
            delete this.multiSliceData[toRemove];
            this.refreshRbacList();
            this.activeSliceIdx = Math.max(0, idx - 1);
            this.switchSliceTab(this.activeSliceIdx);
        },

        pruneAuditLogsNow() {
            if (!confirm(`Are you sure you want to permanently prune audit logs older than ${this.pruneDaysOption} days?`)) return;
            this.isPruningAuditLogs = true;
            fetch('/laraslice/wizard/audit-logs/prune', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                },
                body: JSON.stringify({
                    days: this.pruneDaysOption,
                    slice: this.selectedSlice?.name || 'all'
                })
            })
            .then(r => r.json())
            .then(d => {
                this.isPruningAuditLogs = false;
                this.showAuditPruneModal = false;
                alert(d.message || 'Audit logs pruned successfully.');
                this.loadAuditLogs(this.selectedSlice?.name);
            })
            .catch(e => {
                this.isPruningAuditLogs = false;
                alert('Pruning failed: ' + e.message);
            });
        },

        loadAuditLogs(sliceName) {
            if (!sliceName) return;
            this.isLoadingAuditLogs = true;
            const params = new URLSearchParams({ slice: sliceName, action: this.auditActionFilter, search: this.auditSearch || '' });
            fetch('/laraslice/wizard/audit-logs?' + params.toString(), {
                headers: { 'Accept': 'application/json' }
            })
            .then(r => r.json())
            .then(d => {
                this.isLoadingAuditLogs = false;
                if (d.success) {
                    this.auditLogs = d.logs || [];
                    this.auditCounts = d.counts || {};
                }
            })
            .catch(e => {
                this.isLoadingAuditLogs = false;
                console.error('Audit logs error:', e);
            });
        },

                        applyPreset(preset) {
            if (preset === 'legal_dispute_adjudication') {
                this.presetCategory = 'enterprise';
                this.includeWorkflow = true;
                this.projectName = 'Complaints, Hearings, Notices, Orders, FieldInquiries, MasterData';
                this.domain = 'Adjudication';
                this.description = 'Federal Ombudsman statutory dispute adjudication suite (Workplace Harassment & Women Property Rights) encompassing petition intake, multi-party respondents, summons dispatch, court cause lists, field inquiries, and dual-track speaking orders.';
                
                this.multiSliceData = {
                    'Complaints': {
                        fields: [
                            { name: 'complaint_no', label: 'Diary / Complaint #', type: 'string', width: '50%', required: true, nullable: false, optionsText: '100', default: '' },
                            { name: 'title', label: 'Complaint Title', type: 'string', width: '50%', required: true, nullable: false, optionsText: '255', default: '' },
                            { name: 'complaint_type', label: 'Statutory Act / Type', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'harassment_workplace,property_rights_women', default: 'harassment_workplace' },
                            { name: 'complaint_subtype', label: 'Specific Sub-Type', type: 'select', width: '50%', required: false, nullable: true, optionsText: 'workplace_harassment,govt_office,private_sector,property_ownership,possession_deprivation', default: 'workplace_harassment' },
                            { name: 'region', label: 'Regional Ombudsman Office', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'head_office_islamabad,lahore,karachi,peshawar,quetta', default: 'head_office_islamabad' },
                            { name: 'filing_date', label: 'Date of Lodging', type: 'date', width: '50%', required: true, nullable: false, optionsText: '', default: '' },
                            { name: 'is_short_order', label: 'Order Track', type: 'select', width: '50%', required: false, nullable: true, optionsText: 'short_order,detailed_order', default: 'detailed_order' },
                            { name: 'status', label: 'Judicial Stage', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'lodged,deficient,maintainability_verified,preliminary_hearing,notice_issued,regular_hearing,evidence_recording,report_sought,orders_reserved,final_judgment,disposed_off', default: 'lodged' }
                        ],
                        childTables: [
                            {
                                name: 'accused',
                                label: 'Accused / Respondent Parties',
                                relation: 'hasMany',
                                fields: [
                                    { name: 'name', label: 'Respondent Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: '' },
                                    { name: 'cnic', label: 'CNIC / National ID', type: 'string', width: '50%', required: false, nullable: true, optionsText: '20', default: '' },
                                    { name: 'designation', label: 'Designation / Post', type: 'string', width: '50%', required: false, nullable: true, optionsText: '100', default: '' },
                                    { name: 'address', label: 'Service Address for Summons', type: 'text', width: '100%', required: false, nullable: true, optionsText: '', default: '' }
                                ]
                            },
                            {
                                name: 'complaint_properties',
                                label: 'Disputed Properties & Assets',
                                relation: 'hasMany',
                                fields: [
                                    { name: 'property_nature', label: 'Property Nature', type: 'string', width: '50%', required: true, nullable: false, optionsText: 'residential,commercial,agricultural', default: 'residential' },
                                    { name: 'acquisition_mode', label: 'Mode of Acquisition', type: 'string', width: '50%', required: false, nullable: true, optionsText: 'inheritance,gift,purchase', default: 'inheritance' },
                                    { name: 'khasra_survey_no', label: 'Khasra / Survey #', type: 'string', width: '50%', required: false, nullable: true, optionsText: '100', default: '' },
                                    { name: 'location_address', label: 'Complete Property Location', type: 'text', width: '100%', required: false, nullable: true, optionsText: '', default: '' }
                                ]
                            },
                            {
                                name: 'harassment_attributes',
                                label: 'Harassment EAV Attributes',
                                relation: 'hasOne',
                                fields: [
                                    { name: 'harassment_nature', label: 'Harassment Nature', type: 'string', width: '50%', required: true, nullable: false, optionsText: 'physical,verbal,psychological,quid_pro_quo', default: 'verbal' },
                                    { name: 'is_cross_organization', label: 'Cross-Organization Matter', type: 'boolean', width: '50%', required: false, nullable: true, optionsText: '', default: '0' },
                                    { name: 'incident_date', label: 'Date of Incident', type: 'date', width: '50%', required: false, nullable: true, optionsText: '', default: '' }
                                ]
                            }
                        ]
                    },
                    'Hearings': {
                        fields: [
                            { name: 'complaint_id', label: 'Complaint / Case #', type: 'integer', width: '50%', required: true, nullable: false, optionsText: '', default: '' },
                            { name: 'hearing_date', label: 'Hearing Date', type: 'date', width: '50%', required: true, nullable: false, optionsText: '', default: '' },
                            { name: 'hearing_time', label: 'Session Time', type: 'string', width: '50%', required: false, nullable: true, optionsText: '50', default: '10:00 AM' },
                            { name: 'hearing_type', label: 'Hearing Type', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'preliminary,regular,evidence_of_parties,cross_examination,final_arguments', default: 'preliminary' },
                            { name: 'presiding_bench', label: 'Presiding Officer / Bench', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: 'Federal Ombudsperson Bench I' },
                            { name: 'attendance_record', label: 'Parties Attendance Summary', type: 'string', width: '50%', required: false, nullable: true, optionsText: '255', default: 'Complainant Present, Respondent Absent' },
                            { name: 'daily_order_sheet', label: 'Daily Interim Order Sheet', type: 'text', width: '100%', required: false, nullable: true, optionsText: '', default: '' }
                        ],
                        childTables: [
                            {
                                name: 'hearing_attendees',
                                label: 'Hearing Appearance Roster',
                                relation: 'hasMany',
                                fields: [
                                    { name: 'party_type', label: 'Party Type', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'complainant,respondent,counsel,witness', default: 'complainant' },
                                    { name: 'name', label: 'Attendee Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: '' },
                                    { name: 'is_present', label: 'Present in Court', type: 'boolean', width: '50%', required: true, nullable: false, optionsText: '', default: '1' }
                                ]
                            }
                        ]
                    },
                    'Notices': {
                        fields: [
                            { name: 'complaint_id', label: 'Complaint Ref #', type: 'integer', width: '50%', required: true, nullable: false, optionsText: '', default: '' },
                            { name: 'diary_no', label: 'Summons / Notice Diary #', type: 'string', width: '50%', required: true, nullable: false, optionsText: '100', default: '' },
                            { name: 'notice_title', label: 'Summons Title / Purpose', type: 'string', width: '50%', required: true, nullable: false, optionsText: '200', default: 'Summons for Appearance & Written Reply' },
                            { name: 'recipient_party', label: 'Recipient (Accused / Org)', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: '' },
                            { name: 'hearing_date', label: 'Appearance Hearing Date', type: 'date', width: '50%', required: true, nullable: false, optionsText: '', default: '' },
                            { name: 'dispatch_date', label: 'Dispatch Date', type: 'date', width: '50%', required: true, nullable: false, optionsText: '', default: '' },
                            { name: 'delivery_status', label: 'Service / Delivery Status', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'pending_dispatch,dispatched_tcs,served_verified,unserved_returned', default: 'dispatched_tcs' },
                            { name: 'tcs_consignment_no', label: 'Courier Tracking #', type: 'string', width: '50%', required: false, nullable: true, optionsText: '50', default: '' },
                            { name: 'notice_content', label: 'Formal Notice Legal Body', type: 'text', width: '100%', required: true, nullable: false, optionsText: '', default: '' }
                        ],
                        childTables: [
                            {
                                name: 'notice_templates',
                                label: 'Statutory Notice Letter Templates',
                                relation: 'hasMany',
                                fields: [
                                    { name: 'title', label: 'Template Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: '' },
                                    { name: 'matter_type', label: 'Applicable Matter', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'harassment,property', default: 'harassment' },
                                    { name: 'template_body_html', label: 'HTML Letter Template', type: 'text', width: '100%', required: true, nullable: false, optionsText: '', default: '' }
                                ]
                            }
                        ]
                    },
                    'Orders': {
                        fields: [
                            { name: 'complaint_id', label: 'Complaint Ref #', type: 'integer', width: '50%', required: true, nullable: false, optionsText: '', default: '' },
                            { name: 'order_type', label: 'Order Track', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'short_order,detailed_order', default: 'detailed_order' },
                            { name: 'decision_type', label: 'Judicial Decision Verdict', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'ex_parte,allowed,dismissed_maintainability,penalty_imposed,restoration_decreed', default: 'penalty_imposed' },
                            { name: 'summary_findings', label: 'Adjudication Findings Summary', type: 'text', width: '100%', required: true, nullable: false, optionsText: '', default: '' },
                            { name: 'law_officer_vetted', label: 'Vetted by Law Officer', type: 'boolean', width: '50%', required: false, nullable: true, optionsText: '', default: '1' },
                            { name: 'ombudsperson_signed', label: 'Signed by Federal Ombudsperson', type: 'boolean', width: '50%', required: true, nullable: false, optionsText: '', default: '0' },
                            { name: 'signed_at', label: 'Date of Execution', type: 'datetime', width: '50%', required: false, nullable: true, optionsText: '', default: '' }
                        ],
                        childTables: [
                            {
                                name: 'harassment_penalties',
                                label: 'Statutory Penalties Imposed',
                                relation: 'hasMany',
                                fields: [
                                    { name: 'penalty_type', label: 'Penalty Category', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'minor_censure,withholding_promotion,fine_compensation,dismissal_from_service', default: 'fine_compensation' },
                                    { name: 'compensation_amount', label: 'Fine / Quantum Compensation', type: 'decimal', width: '50%', required: false, nullable: true, optionsText: '12,2', default: '50000.00' },
                                    { name: 'enforcement_authority', label: 'Executing Organization / Officer', type: 'string', width: '50%', required: false, nullable: true, optionsText: '150', default: '' }
                                ]
                            }
                        ]
                    },
                    'FieldInquiries': {
                        fields: [
                            { name: 'complaint_id', label: 'Complaint Ref #', type: 'integer', width: '50%', required: true, nullable: false, optionsText: '', default: '' },
                            { name: 'authority_type', label: 'Inquiry Agency / Authority', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'deputy_commissioner,district_police_officer,workplace_inquiry_committee,revenue_tehsildar', default: 'deputy_commissioner' },
                            { name: 'authority_name', label: 'Officer / Agency Designation', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: 'Deputy Commissioner Islamabad' },
                            { name: 'request_date', label: 'Requisition Date', type: 'date', width: '50%', required: true, nullable: false, optionsText: '', default: '' },
                            { name: 'due_date', label: 'Compliance Due Date', type: 'date', width: '50%', required: true, nullable: false, optionsText: '', default: '' },
                            { name: 'report_status', label: 'Report Status', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'awaiting_submission,submitted_received,reminder_issued', default: 'awaiting_submission' },
                            { name: 'findings_summary', label: 'Executive Findings of Field Report', type: 'text', width: '100%', required: false, nullable: true, optionsText: '', default: '' }
                        ],
                        childTables: []
                    },
                    'MasterData': {
                        fields: [
                            { name: 'catalog_type', label: 'Taxonomy Catalog', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'complaint_types,subtypes,statuses,employment_natures,organization_types,regions', default: 'statuses' },
                            { name: 'code', label: 'Code / Slug', type: 'string', width: '50%', required: true, nullable: false, optionsText: '50', default: '' },
                            { name: 'name', label: 'Display Name / Title', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: '' },
                            { name: 'is_active', label: 'Active', type: 'boolean', width: '50%', required: true, nullable: false, optionsText: '', default: '1' }
                        ],
                        childTables: [
                            {
                                name: 'statutory_subtypes',
                                label: 'Matter Sub-Types & Categories',
                                relation: 'hasMany',
                                fields: [
                                    { name: 'parent_type', label: 'Parent Matter Type', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'harassment,property', default: 'harassment' },
                                    { name: 'subtype_name', label: 'Subtype Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: '' },
                                    { name: 'statutory_act', label: 'Governing Statutory Act', type: 'string', width: '50%', required: true, nullable: false, optionsText: '200', default: 'Act IV of 2010' }
                                ]
                            }
                        ]
                    }
                };

                this.customFields = JSON.parse(JSON.stringify(this.multiSliceData['Complaints'].fields));
                this.childTables = JSON.parse(JSON.stringify(this.multiSliceData['Complaints'].childTables));
                this.activeSliceIdx = 0;

                this.workflowConfig = {
                    enabled: true,
                    slug: 'adjudication_pipeline',
                    name: 'Ombudsman Judicial Adjudication Pipeline',
                    states: [
                        { slug: 'lodged', label: 'Fresh Complaint / Lodged', color: 'slate', icon: 'inbox', initial: true, sla_hours: 0 },
                        { slug: 'deficient', label: 'Deficient Notice Issued', color: 'rose', icon: 'alert-triangle', sla_hours: 0 },
                        { slug: 'maintainability_verified', label: 'Maintainability Scrutinized', color: 'indigo', icon: 'check-square', sla_hours: 0 },
                        { slug: 'preliminary_hearing', label: 'Preliminary Hearing Fixed', color: 'amber', icon: 'calendar', sla_hours: 0 },
                        { slug: 'notice_issued', label: 'Summons / Notice Issued', color: 'sky', icon: 'send', sla_hours: 0 },
                        { slug: 'regular_hearing', label: 'Regular Hearing in Progress', color: 'purple', icon: 'scale', sla_hours: 0 },
                        { slug: 'evidence_recording', label: 'Evidence of Parties / Cross-Exam', color: 'blue', icon: 'file-text', sla_hours: 0 },
                        { slug: 'report_sought', label: 'Field Inquiry Report Sought', color: 'cyan', icon: 'search', sla_hours: 0 },
                        { slug: 'orders_reserved', label: 'Arguments Concluded / Orders Reserved', color: 'orange', icon: 'clock', sla_hours: 0 },
                        { slug: 'final_judgment', label: 'Final Speaking Order Pronounced', color: 'emerald', icon: 'award', terminal: true },
                        { slug: 'disposed_off', label: 'Disposed Off / Inadmissible', color: 'zinc', icon: 'x-circle', terminal: true }
                    ],
                    transitions: [
                        { slug: 'scrutinize_deficiency', name: 'Mark Deficient & Notify Complainant', from: ['lodged'], to: 'deficient', permission: 'complaint.scrutiny', color: 'danger', requires_remarks: true, target_role: 'assistant_registrar' },
                        { slug: 'rectify_deficiency', name: 'Rectified - Re-submit for Scrutiny', from: ['deficient'], to: 'lodged', permission: 'complaint.lodge', color: 'secondary', requires_remarks: true, target_role: 'assistant_registrar' },
                        { slug: 'admit_preliminary', name: 'Formulate Maintainability Opinion -> Registrar', from: ['lodged'], to: 'maintainability_verified', permission: 'complaint.scrutiny', color: 'primary', requires_remarks: true, target_role: 'registrar' },
                        { slug: 'refer_to_ombudsperson', name: 'Forward Admissibility -> Ombudsperson', from: ['maintainability_verified'], to: 'maintainability_verified', permission: 'complaint.admissibility', color: 'indigo', requires_remarks: true, target_role: 'ombudsperson' },
                        { slug: 'dismiss_maintainability', name: 'Dismiss as Non-Maintainable', from: ['maintainability_verified'], to: 'disposed_off', permission: 'complaint.judicial_rule', color: 'danger', requires_remarks: true, target_role: 'ombudsperson' },
                        { slug: 'fix_hearing_cause_list', name: 'Fix for Hearing & Issue Summons', from: ['maintainability_verified'], to: 'notice_issued', permission: 'complaint.fix_hearing', color: 'sky', requires_remarks: true, target_role: 'registrar' },
                        { slug: 'commence_regular_hearing', name: 'Notices Served -> Forward File to Legal Advisor', from: ['notice_issued'], to: 'regular_hearing', permission: 'complaint.hear', color: 'purple', requires_remarks: true, target_role: 'legal_advisor' },
                        { slug: 'seek_inquiry_report', name: 'Seek Field Report (DC/Police/Org)', from: ['regular_hearing'], to: 'report_sought', permission: 'complaint.hear', color: 'cyan', requires_remarks: true, target_role: 'legal_advisor' },
                        { slug: 'record_evidence', name: 'Record Evidence & Cross Examination', from: ['regular_hearing', 'report_sought'], to: 'evidence_recording', permission: 'complaint.hear', color: 'blue', requires_remarks: true, target_role: 'legal_advisor' },
                        { slug: 'reserve_orders', name: 'Conclude Arguments & Draft Order', from: ['evidence_recording', 'regular_hearing'], to: 'orders_reserved', permission: 'complaint.draft_order', color: 'amber', requires_remarks: true, target_role: 'legal_advisor' },
                        { slug: 'law_officer_scrutiny', name: 'Forward Detailed Order -> Law Officer Scrutiny', from: ['orders_reserved'], to: 'orders_reserved', permission: 'complaint.draft_order', color: 'indigo', requires_remarks: true, target_role: 'law_officer' },
                        { slug: 'submit_to_ombudsperson', name: 'Submit Vetted Order -> Ombudsperson for Signature', from: ['orders_reserved'], to: 'orders_reserved', permission: 'complaint.vet_order', color: 'purple', requires_remarks: true, target_role: 'ombudsperson' },
                        { slug: 'pronounce_speaking_order', name: 'Sign & Pronounce Final Order', from: ['orders_reserved'], to: 'final_judgment', permission: 'complaint.judicial_rule', color: 'success', requires_remarks: true, requires_attachment: true, target_role: 'registrar' }
                    ]
                };
                this.workflowRoles = [
                    { slug: 'assistant_registrar', name: 'Assistant Registrar', description: 'Scrutinizes petitions for legal maintainability and prepares case index/brief', seed: true, seedUser: true, permissions: ['complaint.view', 'complaint.scrutiny'] },
                    { slug: 'registrar', name: 'Registrar', description: 'Verifies admissibility, manages cause lists, issues summons, and maintains judicial records', seed: true, seedUser: true, permissions: ['complaint.view', 'complaint.admissibility', 'complaint.fix_hearing'] },
                    { slug: 'legal_advisor', name: 'Legal Advisor', description: 'Conducts formal hearings, examines evidence/witnesses, and drafts short & detailed orders', seed: true, seedUser: true, permissions: ['complaint.view', 'complaint.hear', 'complaint.draft_order'] },
                    { slug: 'law_officer', name: 'Law Officer', description: 'Vets statutory drafting, legal grounds, and legal precedents of detailed orders', seed: true, seedUser: true, permissions: ['complaint.view', 'complaint.vet_order'] },
                    { slug: 'ombudsperson', name: 'Ombudsperson', description: 'Federal Ombudsperson: judicial rulings on maintainability and signing speaking judgments', seed: true, seedUser: true, permissions: ['complaint.view', 'complaint.judicial_rule'] }
                ];
                this.refreshRbacList();
                this.wizardTargetTable = 'root';
            } else if (preset === 'grant_subsidy_pipeline') {
                this.presetCategory = 'enterprise';
                this.includeWorkflow = true;
                this.projectName = 'GrantApplications';
                this.domain = 'Grants & Subsidies';
                this.description = 'Social welfare grant disbursement pipeline with eligibility scrutiny, committee sanction, pre-audit, and bank disbursement.';
                this.customFields = [
                    { name: 'application_number', label: 'Application #', type: 'string', width: '50%', required: true, nullable: false, optionsText: '100', default: '' },
                    { name: 'applicant_name', label: 'Applicant Full Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: '' },
                    { name: 'identification_number', label: 'National ID / Passport', type: 'string', width: '50%', required: true, nullable: false, optionsText: '50', default: '' },
                    { name: 'grant_type', label: 'Grant Category', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'education_stipend,marriage_grant,medical_aid,welfare_assistance', default: 'education_stipend' },
                    { name: 'requested_amount', label: 'Requested Grant ($)', type: 'decimal', width: '50%', required: true, nullable: false, optionsText: '14,2', default: '0.00' },
                    { name: 'approved_amount', label: 'Sanctioned Amount ($)', type: 'decimal', width: '50%', required: false, nullable: true, optionsText: '14,2', default: '0.00' },
                    { name: 'disbursement_bank', label: 'Banking Channel', type: 'string', width: '50%', required: false, nullable: true, optionsText: '100', default: '' },
                    { name: 'status', label: 'Pipeline State', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'submitted,eligibility_verified,committee_sanctioned,pre_audit_cleared,fund_disbursed,rejected', default: 'submitted' }
                ];
                this.childTables = [
                    {
                        name: 'disbursement_ledgers',
                        label: 'Disbursement Ledger',
                        relation: 'hasMany',
                        fields: [
                            { name: 'transaction_ref', label: 'Bank Ref #', type: 'string', width: '50%', required: true, nullable: false, optionsText: '100', default: '' },
                            { name: 'amount_released', label: 'Amount Released ($)', type: 'decimal', width: '50%', required: true, nullable: false, optionsText: '14,2', default: '0.00' },
                            { name: 'cleared_date', label: 'Settlement Date', type: 'date', width: '50%', required: false, nullable: true, optionsText: '', default: '' }
                        ]
                    }
                ];
                this.workflowConfig = {
                    enabled: true,
                    slug: 'grant_applications',
                    name: 'Social Grant & Capital Disbursement Pipeline',
                    states: [
                        { slug: 'submitted', label: 'Submitted / Desk Intake', color: 'slate', icon: 'file-text', initial: true, sla_hours: 24 },
                        { slug: 'eligibility_verified', label: 'Eligibility Verified', color: 'sky', icon: 'check-square', sla_hours: 48 },
                        { slug: 'committee_sanctioned', label: 'Committee Sanction Approved', color: 'purple', icon: 'users', sla_hours: 72 },
                        { slug: 'pre_audit_cleared', label: 'Pre-Audit Passed', color: 'amber', icon: 'shield-check', sla_hours: 48 },
                        { slug: 'fund_disbursed', label: 'Bank Fund Disbursed', color: 'emerald', icon: 'credit-card', terminal: true },
                        { slug: 'rejected', label: 'Application Rejected', color: 'rose', icon: 'x-circle', terminal: true }
                    ],
                    transitions: [
                        { slug: 'verify_eligibility', name: 'Verify Applicant Eligibility', from: ['submitted'], to: 'eligibility_verified', permission: 'grant.scrutiny', color: 'primary', requires_remarks: true, target_role: 'eligibility_scrutineer' },
                        { slug: 'reject_intake', name: 'Reject for Non-Compliance', from: ['submitted'], to: 'rejected', permission: 'grant.scrutiny', color: 'danger', requires_remarks: true },
                        { slug: 'submit_committee_batch', name: 'Submit to Grants Committee', from: ['eligibility_verified'], to: 'committee_sanctioned', permission: 'grant.committee', color: 'purple', requires_remarks: true, target_role: 'committee_secretary' },
                        { slug: 'clear_pre_audit', name: 'Pre-Audit Regulatory Clearance', from: ['committee_sanctioned'], to: 'pre_audit_cleared', permission: 'grant.audit', color: 'amber', requires_remarks: true, target_role: 'audit_controller' },
                        { slug: 'release_disbursement', name: 'Authorize Bank Release & Disburse', from: ['pre_audit_cleared'], to: 'fund_disbursed', permission: 'grant.disburse', color: 'success', requires_remarks: true, requires_attachment: true, target_role: 'disbursement_treasurer' }
                    ]
                };
                this.workflowRoles = [
                    { slug: 'intake_officer', name: 'Intake Verification Officer', description: 'Verifies applicant identity and completeness of documentation', seed: true, seedUser: true, permissions: ['grant.view', 'grant.intake'] },
                    { slug: 'eligibility_scrutineer', name: 'Eligibility Scrutiny Officer', description: 'Calculates quota eligibility and applies statutory formula', seed: true, seedUser: true, permissions: ['grant.view', 'grant.scrutiny'] },
                    { slug: 'committee_secretary', name: 'Grants Committee Secretary', description: 'Records committee consensus and sanctions grant batches', seed: true, seedUser: true, permissions: ['grant.view', 'grant.committee'] },
                    { slug: 'audit_controller', name: 'Pre-Audit Controller', description: 'Ensures statutory compliance and voucher authorization', seed: true, seedUser: true, permissions: ['grant.view', 'grant.audit'] },
                    { slug: 'disbursement_treasurer', name: 'Disbursement Treasurer', description: 'Transmits payment instructions and reconciles banking records', seed: true, seedUser: true, permissions: ['grant.view', 'grant.disburse'] }
                ];
                this.wizardTargetTable = 'root';
            } else if (preset === 'executive_procurement_approval') {
                this.presetCategory = 'enterprise';
                this.includeWorkflow = true;
                this.projectName = 'Requisitions';
                this.domain = 'Procurement & Operations';
                this.description = 'Executive capital expenditure requisition pipeline with budget verification, committee endorsement, and PO generation.';
                this.customFields = [
                    { name: 'requisition_number', label: 'Requisition Ref #', type: 'string', width: '50%', required: true, nullable: false, optionsText: '100', default: '' },
                    { name: 'title', label: 'Requisition Title', type: 'string', width: '50%', required: true, nullable: false, optionsText: '255', default: '' },
                    { name: 'department_name', label: 'Requesting Department', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: '' },
                    { name: 'estimated_cost', label: 'Estimated Expenditure ($)', type: 'decimal', width: '50%', required: true, nullable: false, optionsText: '14,2', default: '0.00' },
                    { name: 'budget_code', label: 'GL / Budget Code', type: 'string', width: '50%', required: false, nullable: true, optionsText: '50', default: '' },
                    { name: 'justification', label: 'Business Justification', type: 'text', width: '100%', required: false, nullable: true, optionsText: '', default: '' },
                    { name: 'status', label: 'Authorization Stage', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'draft,department_endorsed,budget_scrutinized,board_sanctioned,po_generated,returned_observation', default: 'draft' }
                ];
                this.childTables = [
                    {
                        name: 'requisition_items',
                        label: 'Requisition Line Items',
                        relation: 'hasMany',
                        fields: [
                            { name: 'item_name', label: 'Item Description', type: 'string', width: '50%', required: true, nullable: false, optionsText: '255', default: '' },
                            { name: 'quantity', label: 'Quantity', type: 'integer', width: '25%', required: true, nullable: false, optionsText: '', default: '1' },
                            { name: 'unit_price', label: 'Unit Price ($)', type: 'decimal', width: '25%', required: true, nullable: false, optionsText: '10,2', default: '0.00' }
                        ]
                    }
                ];
                this.workflowConfig = {
                    enabled: true,
                    slug: 'requisitions',
                    name: 'Executive Requisition & Capital Expenditure Approval',
                    states: [
                        { slug: 'draft', label: 'Draft / Requisitioned', color: 'slate', icon: 'file-text', initial: true, sla_hours: 24 },
                        { slug: 'department_endorsed', label: 'Department Endorsed', color: 'sky', icon: 'user-check', sla_hours: 48 },
                        { slug: 'budget_scrutinized', label: 'Budget Scrutinized', color: 'amber', icon: 'calculator', sla_hours: 48 },
                        { slug: 'board_sanctioned', label: 'Executive Board Sanctioned', color: 'purple', icon: 'shield-check', sla_hours: 72 },
                        { slug: 'po_generated', label: 'Purchase Order Issued', color: 'emerald', icon: 'shopping-cart', terminal: true },
                        { slug: 'returned_observation', label: 'Returned with Observation', color: 'rose', icon: 'alert-triangle', terminal: false }
                    ],
                    transitions: [
                        { slug: 'endorse_department', name: 'Endorse Departmental Requisition', from: ['draft', 'returned_observation'], to: 'department_endorsed', permission: 'procurement.endorse', color: 'primary', requires_remarks: true, target_role: 'department_director' },
                        { slug: 'scrutinize_budget', name: 'Verify Budget & Allocation Codes', from: ['department_endorsed'], to: 'budget_scrutinized', permission: 'procurement.scrutiny', color: 'amber', requires_remarks: true, target_role: 'budget_scrutineer' },
                        { slug: 'return_with_observation', name: 'Mark Back with Budget Observation', from: ['department_endorsed', 'budget_scrutinized'], to: 'returned_observation', permission: 'procurement.scrutiny', color: 'danger', requires_remarks: true, target_role: 'requisition_author' },
                        { slug: 'board_sanction', name: 'Grant Executive Board Approval', from: ['budget_scrutinized'], to: 'board_sanctioned', permission: 'procurement.sanction', color: 'purple', requires_remarks: true, requires_attachment: true, target_role: 'executive_board' },
                        { slug: 'issue_purchase_order', name: 'Generate & Dispatch Purchase Order', from: ['board_sanctioned'], to: 'po_generated', permission: 'procurement.issue', color: 'success', requires_remarks: true }
                    ]
                };
                this.workflowRoles = [
                    { slug: 'requisition_author', name: 'Requisition Author', description: 'Drafts procurement requests and responds to observations', seed: true, seedUser: true, permissions: ['procurement.view', 'procurement.create'] },
                    { slug: 'department_director', name: 'Department Director', description: 'Endorses departmental requisitions before financial submission', seed: true, seedUser: true, permissions: ['procurement.view', 'procurement.endorse'] },
                    { slug: 'budget_scrutineer', name: 'Budget Scrutineer', description: 'Verifies ledger codes, availability of funds, and compliance', seed: true, seedUser: true, permissions: ['procurement.view', 'procurement.scrutiny'] },
                    { slug: 'executive_board', name: 'Executive Sanctioning Authority', description: 'Grants final corporate financial approval for capital purchases', seed: true, seedUser: true, permissions: ['procurement.view', 'procurement.sanction', 'procurement.issue'] }
                ];
                this.wizardTargetTable = 'root';
            } else if (preset === 'support_service_ticket') {
                this.presetCategory = 'enterprise';
                this.includeWorkflow = true;
                this.projectName = 'Tickets';
                this.domain = 'Customer Service';
                this.description = 'Multi-tier customer support ticket lifecycle with triage, engineering escalation, SLA timers, and resolution.';
                this.customFields = [
                    { name: 'ticket_number', label: 'Ticket #', type: 'string', width: '50%', required: true, nullable: false, optionsText: '100', default: '' },
                    { name: 'subject', label: 'Ticket Subject', type: 'string', width: '50%', required: true, nullable: false, optionsText: '255', default: '' },
                    { name: 'priority', label: 'Priority Tier', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'low,medium,high,urgent', default: 'medium' },
                    { name: 'customer_email', label: 'Requester Email', type: 'email', width: '50%', required: true, nullable: false, optionsText: '255', default: '' },
                    { name: 'category', label: 'Category', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'technical,billing,feature_request,incident', default: 'technical' },
                    { name: 'status', label: 'Lifecycle Stage', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'new,triaged,in_progress,escalated_engineering,resolved,closed', default: 'new' }
                ];
                this.childTables = [
                    {
                        name: 'ticket_replies',
                        label: 'Ticket Responses',
                        relation: 'hasMany',
                        fields: [
                            { name: 'reply_body', label: 'Reply Message', type: 'text', width: '100%', required: true, nullable: false, optionsText: '', default: '' },
                            { name: 'author_name', label: 'Author Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: '' },
                            { name: 'is_internal_note', label: 'Internal Staff Note', type: 'boolean', width: '50%', required: false, nullable: true, optionsText: '', default: '0' }
                        ]
                    }
                ];
                this.workflowConfig = {
                    enabled: true,
                    slug: 'tickets',
                    name: 'Enterprise Support Ticket & SLA Escalation',
                    states: [
                        { slug: 'new', label: 'New / Unassigned', color: 'slate', icon: 'inbox', initial: true, sla_hours: 2 },
                        { slug: 'triaged', label: 'Triaged & Assigned', color: 'sky', icon: 'user-check', sla_hours: 8 },
                        { slug: 'in_progress', label: 'Under Investigation', color: 'amber', icon: 'clock', sla_hours: 24 },
                        { slug: 'escalated_engineering', label: 'Escalated to Engineering', color: 'purple', icon: 'code', sla_hours: 48 },
                        { slug: 'resolved', label: 'Resolved / Solved', color: 'emerald', icon: 'check-circle', sla_hours: null },
                        { slug: 'closed', label: 'Closed & Archived', color: 'slate', icon: 'check-check', terminal: true, sla_hours: null }
                    ],
                    transitions: [
                        { slug: 'triage', name: 'Assign to Support Agent', from: ['new'], to: 'triaged', permission: 'tickets.triage', color: 'primary', requires_remarks: false, target_role: 'support_agent' },
                        { slug: 'escalate_engineering', name: 'Escalate to Engineering Team', from: ['triaged', 'in_progress'], to: 'escalated_engineering', permission: 'tickets.escalate', color: 'purple', requires_remarks: true, target_role: 'software_engineer' },
                        { slug: 'resolve', name: 'Mark Ticket Resolved', from: ['triaged', 'in_progress', 'escalated_engineering'], to: 'resolved', permission: 'tickets.resolve', color: 'success', requires_remarks: true, target_role: 'support_lead' },
                        { slug: 'close', name: 'Close & Archive Ticket', from: ['resolved'], to: 'closed', permission: 'tickets.close', color: 'secondary' },
                        { slug: 'reopen', name: 'Reopen Ticket', from: ['resolved', 'closed'], to: 'in_progress', permission: 'tickets.reopen', color: 'warning', requires_remarks: true, target_role: 'support_agent' }
                    ]
                };
                this.workflowRoles = [
                    { slug: 'support_agent', name: 'Customer Support Agent', description: 'Handles first-line support ticket response and triage', seed: true, seedUser: true, permissions: ['tickets.view', 'tickets.triage', 'tickets.edit'] },
                    { slug: 'support_lead', name: 'Support Team Lead', description: 'Supervises ticket queues and SLA escalations', seed: true, seedUser: true, permissions: ['tickets.view', 'tickets.resolve', 'tickets.edit'] },
                    { slug: 'software_engineer', name: 'Software Engineer', description: 'Resolves technical bugs and infrastructure issues', seed: true, seedUser: true, permissions: ['tickets.view', 'tickets.edit'] }
                ];
                this.wizardTargetTable = 'root';
            } else if (preset === 'asset_inventory') {
                this.presetCategory = 'crud';
                this.includeWorkflow = false;
                this.workflowConfig = null;
                this.workflowRoles = [];
                this.projectName = 'Assets';
                this.domain = 'Inventory';
                this.description = 'Corporate asset inventory, equipment allocation, and serial maintenance tracking';
                this.customFields = [
                    { name: 'asset_tag', label: 'Asset Barcode / Tag', type: 'string', width: '50%', required: true, nullable: false, optionsText: '100', default: '' },
                    { name: 'name', label: 'Equipment Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '255', default: '' },
                    { name: 'category', label: 'Hardware Category', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'laptop,workstation,server,mobile,peripheral', default: 'laptop' },
                    { name: 'purchase_cost', label: 'Purchase Cost ($)', type: 'decimal', width: '50%', required: false, nullable: true, optionsText: '10,2', default: '0.00' },
                    { name: 'purchase_date', label: 'Purchase Date', type: 'date', width: '50%', required: false, nullable: true, optionsText: '', default: '' },
                    { name: 'serial_number', label: 'Serial Number', type: 'string', width: '50%', required: false, nullable: true, optionsText: '100', default: '' },
                    { name: 'status', label: 'Deployment State', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'in_stock,assigned,under_repair,retired', default: 'in_stock' }
                ];
                this.childTables = [
                    {
                        name: 'maintenance_logs',
                        label: 'Service Records',
                        relation: 'hasMany',
                        fields: [
                            { name: 'service_date', label: 'Service Date', type: 'date', width: '50%', required: true, nullable: false, optionsText: '', default: '' },
                            { name: 'technician_name', label: 'Technician', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: '' },
                            { name: 'service_cost', label: 'Repair Cost ($)', type: 'decimal', width: '50%', required: false, nullable: true, optionsText: '10,2', default: '0.00' },
                            { name: 'notes', label: 'Diagnostic Notes', type: 'text', width: '100%', required: false, nullable: true, optionsText: '', default: '' }
                        ]
                    }
                ];
                this.wizardTargetTable = 'root';
            } else if (preset === 'crm_suite') {
                this.presetCategory = 'crud';
                this.includeWorkflow = false;
                this.workflowConfig = null;
                this.workflowRoles = [];
                this.projectName = 'Companies, Contacts, Deals';
                this.domain = 'CRM';
                this.description = 'Complete B2B CRM Suite with Account Companies, People Contacts, and Sales Pipeline Deals';
                this.multiSliceData = {
                    'Companies': {
                        fields: [
                            { name: 'name', label: 'Company Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '255', default: '' },
                            { name: 'industry', label: 'Industry', type: 'string', width: '50%', required: false, nullable: true, optionsText: '100', default: '' },
                            { name: 'website', label: 'Website URL', type: 'string', width: '50%', required: false, nullable: true, optionsText: '255', default: '' },
                            { name: 'phone', label: 'Phone', type: 'string', width: '50%', required: false, nullable: true, optionsText: '50', default: '' },
                            { name: 'annual_revenue', label: 'Annual Revenue', type: 'decimal', width: '50%', required: false, nullable: true, optionsText: '14,2', default: '0.00' },
                            { name: 'status', label: 'Account Tier', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'prospect,customer,churned', default: 'prospect' }
                        ],
                        childTables: []
                    },
                    'Contacts': {
                        fields: [
                            { name: 'first_name', label: 'First Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '100', default: '' },
                            { name: 'last_name', label: 'Last Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '100', default: '' },
                            { name: 'email', label: 'Email', type: 'email', width: '50%', required: true, nullable: false, optionsText: '255', default: '' },
                            { name: 'phone', label: 'Direct Phone', type: 'string', width: '50%', required: false, nullable: true, optionsText: '50', default: '' },
                            { name: 'job_title', label: 'Job Title', type: 'string', width: '50%', required: false, nullable: true, optionsText: '150', default: '' },
                            { name: 'company_id', label: 'Company ID', type: 'integer', width: '50%', required: false, nullable: true, optionsText: '', default: '' }
                        ],
                        childTables: []
                    },
                    'Deals': {
                        fields: [
                            { name: 'title', label: 'Deal Title', type: 'string', width: '50%', required: true, nullable: false, optionsText: '255', default: '' },
                            { name: 'value', label: 'Deal Value ($)', type: 'decimal', width: '50%', required: true, nullable: false, optionsText: '14,2', default: '0.00' },
                            { name: 'stage', label: 'Pipeline Stage', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'lead,qualified,proposal,negotiation,won,lost', default: 'lead' },
                            { name: 'close_date', label: 'Expected Close Date', type: 'date', width: '50%', required: false, nullable: true, optionsText: '', default: '' },
                            { name: 'company_id', label: 'Company ID', type: 'integer', width: '50%', required: false, nullable: true, optionsText: '', default: '' }
                        ],
                        childTables: []
                    }
                };
                this.activeSliceIdx = 0;
                this.customFields = JSON.parse(JSON.stringify(this.multiSliceData['Companies'].fields));
                this.childTables = [];
                this.wizardTargetTable = 'root';
            } else if (preset === 'ecommerce_suite') {
                this.presetCategory = 'crud';
                this.includeWorkflow = false;
                this.workflowConfig = null;
                this.workflowRoles = [];
                this.projectName = 'Products, Orders, Customers';
                this.domain = 'Commerce';
                this.description = 'Full E-Commerce suite with catalog items, buyer checkouts, and customer profiles';
                this.multiSliceData = {
                    'Products': {
                        fields: [
                            { name: 'sku', label: 'SKU Code', type: 'string', width: '50%', required: true, nullable: false, optionsText: '100', default: '' },
                            { name: 'name', label: 'Product Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '255', default: '' },
                            { name: 'price', label: 'Price ($)', type: 'decimal', width: '50%', required: true, nullable: false, optionsText: '10,2', default: '0.00' },
                            { name: 'stock_quantity', label: 'Stock Qty', type: 'integer', width: '50%', required: true, nullable: false, optionsText: '', default: '0' },
                            { name: 'status', label: 'Status', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'draft,active,archived', default: 'draft' }
                        ],
                        childTables: []
                    },
                    'Orders': {
                        fields: [
                            { name: 'order_number', label: 'Order #', type: 'string', width: '50%', required: true, nullable: false, optionsText: '50', default: '' },
                            { name: 'total_amount', label: 'Total ($)', type: 'decimal', width: '50%', required: true, nullable: false, optionsText: '14,2', default: '0.00' },
                            { name: 'status', label: 'Fulfillment Status', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'pending,processing,shipped,delivered,cancelled', default: 'pending' }
                        ],
                        childTables: []
                    },
                    'Customers': {
                        fields: [
                            { name: 'full_name', label: 'Customer Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: '' },
                            { name: 'email', label: 'Email', type: 'email', width: '50%', required: true, nullable: false, optionsText: '255', default: '' },
                            { name: 'phone', label: 'Phone', type: 'string', width: '50%', required: false, nullable: true, optionsText: '50', default: '' }
                        ],
                        childTables: []
                    }
                };
                this.activeSliceIdx = 0;
                this.customFields = JSON.parse(JSON.stringify(this.multiSliceData['Products'].fields));
                this.childTables = [];
                this.wizardTargetTable = 'root';
            }
            this.refreshRbacList();
        },
        initDefaultWorkflow() {
            const slug = (this.projectName || 'item').toLowerCase().replace(/[^a-z0-9_]/g, '_');
            const studly = (this.projectName || 'Item').replace(/[^a-zA-Z0-9]/g, '');
            this.workflowConfig = {
                enabled: true,
                slug: slug,
                name: (this.projectName || 'Item') + ' Lifecycle Workflow',
                states: [
                    { slug: 'draft', label: 'Draft', color: 'slate', icon: 'file-text', initial: true, sla_hours: null },
                    { slug: 'under_review', label: 'Under Review', color: 'amber', icon: 'clock', initial: false, sla_hours: 24 },
                    { slug: 'approved', label: 'Approved', color: 'emerald', icon: 'check-circle', initial: false, sla_hours: null },
                    { slug: 'rejected', label: 'Rejected', color: 'rose', icon: 'x-circle', initial: false, terminal: true, sla_hours: null }
                ],
                transitions: [
                    { slug: 'submit', name: 'Submit for Review', from: ['draft'], to: 'under_review', permission: null, color: 'primary', requires_remarks: false, target_role: 'reviewer' },
                    { slug: 'approve', name: 'Approve Record', from: ['under_review'], to: 'approved', permission: `${slug}.edit`, color: 'success', requires_remarks: true, target_role: 'approver' },
                    { slug: 'reject', name: 'Reject back to Draft', from: ['under_review'], to: 'draft', permission: `${slug}.edit`, color: 'danger', requires_remarks: true, target_role: 'reviewer' }
                ]
            };
            this.workflowRoles = [
                { slug: `${slug}_reviewer`, name: `${studly} Reviewer`, description: `Reviews and triages ${this.projectName || 'item'} records`, seed: true, seedUser: true, permissions: [`${slug}.view`, `${slug}.edit`] },
                { slug: `${slug}_approver`, name: `${studly} Approver`, description: `Authorizes and signs off on ${this.projectName || 'item'} records`, seed: true, seedUser: true, permissions: [`${slug}.view`, `${slug}.edit`] }
            ];
        },

        toggleWorkflow(val) {
            this.includeWorkflow = val;
            if (val && (!this.workflowConfig || !this.workflowConfig.states || this.workflowConfig.states.length === 0)) {
                this.initDefaultWorkflow();
            }
        },

        addWorkflowState() {
            if (!this.newState.label.trim()) return;
            const sSlug = this.newState.slug.trim() || this.newState.label.toLowerCase().replace(/[^a-z0-9_]/g, '_');
            if (!this.workflowConfig) this.initDefaultWorkflow();
            this.workflowConfig.states.push({
                slug: sSlug,
                label: this.newState.label.trim(),
                color: this.newState.color || 'slate',
                icon: this.newState.icon || 'clock',
                sla_hours: this.newState.sla_hours ? parseInt(this.newState.sla_hours) : null,
                initial: false,
                terminal: !!this.newState.terminal
            });
            this.newState = { label: '', slug: '', color: 'slate', icon: 'clock', sla_hours: '', terminal: false };
            this.showAddStateModal = false;
        },

        removeWorkflowState(idx) {
            if (this.workflowConfig && this.workflowConfig.states) {
                this.workflowConfig.states.splice(idx, 1);
            }
        },

        addCustomRole() {
            if (!this.newRole.name.trim()) return;
            const rSlug = this.newRole.slug.trim() || this.newRole.name.toLowerCase().replace(/[^a-z0-9_]/g, '_');
            const basePerm = (this.projectName || 'item').toLowerCase().replace(/[^a-z0-9_]/g, '_');
            this.workflowRoles.push({
                slug: rSlug,
                name: this.newRole.name.trim(),
                description: this.newRole.description.trim() || `Operational role for ${this.newRole.name}`,
                seed: true,
                seedUser: true,
                permissions: [`${basePerm}.view`, `${basePerm}.edit`]
            });
            this.newRole = { name: '', slug: '', description: '', permissions: [] };
            this.showAddRoleModal = false;
        },

        removeWorkflowRole(idx) {
            this.workflowRoles.splice(idx, 1);
        },

        selectedRolesForGeneration() {
            if (!this.includeWorkflow) return [];
            return (this.workflowRoles || []).filter(r => r.seed !== false).map(r => ({
                slug: r.slug,
                name: r.name,
                description: r.description,
                seedUser: r.seedUser !== false,
                permissions: r.permissions || []
            }));
        },
        refreshRbacList() {
            if (!this.autoGenerateRbac) return;
            let defaults = [];
            if (this.isMultiSlice()) {
                this.slicesList().forEach(sName => {
                    const base = sName.toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/s$/, '');
                    defaults.push(`${base}.view`, `${base}.create`, `${base}.edit`, `${base}.delete`);
                });
            } else {
                const base = (this.projectName || 'item').toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/s$/, '');
                defaults = [
                    `${base}.view`,
                    `${base}.create`,
                    `${base}.edit`,
                    `${base}.delete`
                ];
            }
            // Keep any existing custom permissions not in old CRUD
            const custom = this.permissions.filter(p => !p.endsWith('.view') && !p.endsWith('.create') && !p.endsWith('.edit') && !p.endsWith('.delete'));
            this.permissions = [...defaults, ...custom];
        },

        addCustomRbac() {
            let val = (this.newPermInput || '').trim();
            if (!val) return;
            const base = (this.projectName || 'item').toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/s$/, '');
            if (!val.includes('.')) {
                val = `${base}.${val}`;
            }
            if (!this.permissions.includes(val)) {
                this.permissions.push(val);
            }
            this.newPermInput = '';
        },

        removeRbac(idx) {
            this.permissions.splice(idx, 1);
        },

        currentFieldsList() {
            if (this.wizardTargetTable === 'root' || this.wizardTargetTable === null || typeof this.childTables[this.wizardTargetTable] === 'undefined') {
                return this.customFields;
            }
            return this.childTables[this.wizardTargetTable].fields;
        },

        addWizardField() {
            this.currentFieldsList().push({
                name: '',
                label: '',
                type: 'string',
                width: '50%',
                required: false,
                nullable: true,
                optionsText: '',
                default: ''
            });
        },

        removeWizardField(index) {
            this.currentFieldsList().splice(index, 1);
        },

        moveWizardField(idx, dir) {
            const list = this.currentFieldsList();
            const target = idx + dir;
            if (target < 0 || target >= list.length) return;
            const temp = list[idx];
            list[idx] = list[target];
            list[target] = temp;
        },

        addChildTable() {
            const raw = (this.newChildName || '').trim();
            if (!raw) return;
            const snake = raw.toLowerCase().replace(/[^a-z0-9_]/g, '_');
            this.childTables.push({
                name: snake,
                label: raw,
                relation: this.newChildRelation || 'hasMany',
                fields: [
                    { name: 'title', label: 'Title', type: 'string', width: '50%', required: true, nullable: false, optionsText: '255', default: '' }
                ]
            });
            this.wizardTargetTable = this.childTables.length - 1;
            this.newChildName = '';
            this.newChildRelation = 'hasMany';
            this.showAddChildModal = false;
        },

        removeChildTable(idx) {
            this.childTables.splice(idx, 1);
            this.wizardTargetTable = 'root';
        },

        fieldsForGeneration() {
            return this.customFields
                .filter(field => field.name && field.name.trim())
                .map(field => {
                    const result = {
                        name: field.name.trim(),
                        label: field.label ? field.label.trim() : undefined,
                        type: field.type || 'string',
                        width: field.width || '50%',
                        required: !!field.required,
                        nullable: field.nullable !== undefined ? !!field.nullable : !field.required,
                        default: field.default || null,
                    };
                    if (field.type === 'select' && field.optionsText) {
                        const options = field.optionsText.split(',').map(value => value.trim()).filter(Boolean);
                        result.options = Object.fromEntries(options.map(label => [label.toLowerCase().replace(/[^a-z0-9_.-]+/g, '_'), label]));
                    } else if (field.optionsText) {
                        result.length = field.optionsText.trim();
                    }
                    return result;
                });
        },
        isGenerating: false,
        resultMessage: '',
        resultData: null,
        autoMigrate: false,
        isMigrating: false,
        migrationSuccess: false,
        migrationOutput: '',
        installedSlices: [],
        selectedSlice: null,
        selectedTable: null,
        targetTable: '',
        selectTable(tbl) {
            this.selectedTable = tbl;
            this.targetTable = tbl ? tbl.name : '';
            this.syncStudioFields();
        },
        studioFields: [],
        // Editable UI config (Page & UI Architecture) - auto-detected from fields, fully editable
        uiQuickFilters: [],
        uiSummaryCards: [],
        uiSidebarFilters: [],
        uiBulkActions: ['delete', 'export'],
        uiViews: ['table'],
        uiDefaultView: 'table',
        uiInlineEdit: [],
        uiDashboardWidgets: [],
        navRoles: [],
        navGroup: 'Work',
        navVisible: true,
        studioConfigSaving: false,
        showDiffModal: false,
        pendingDiff: '',
        pendingSavePayload: null,
        studioConfigMessage: '',
        // Form sections (Step 3: Create & Edit Form)
        uiFormSections: [],
        uiFormMode: 'drawer',
        uiFormInitialized: false,
        uiRowActions: { display: 'dropdown', items: [
            { handle: 'view', label: 'View', icon: 'eye', placement: 'inline' },
            { handle: 'edit', label: 'Edit', icon: 'pencil', placement: 'dropdown' },
            { handle: 'delete', label: 'Delete', icon: 'trash-2', placement: 'dropdown', danger: true },
        ]},
        uiInitialized: false,
        deletedStudioFields: [],
        isSavingStudioFields: false,
        studioRelations: [],
        isSavingStudioRelations: false,
        isRollingBack: false,

        syncStudioFields() {
            this.deletedStudioFields = [];
            if (!this.selectedTable || !Array.isArray(this.selectedTable.columns)) {
                this.studioFields = [];
                return;
            }
            const tableName = this.selectedTable.name;
            const isPrimary = this.selectedTable.is_primary;
            const manifestFields = isPrimary 
                ? (this.selectedSlice?.fields || {}) 
                : ((this.selectedSlice?.child_fields && this.selectedSlice.child_fields[tableName]) || {});

            const fieldsList = [];
            this.selectedTable.columns.forEach(col => {
                if (['id', 'created_at', 'updated_at', 'deleted_at'].includes(col.name)) {
                    return;
                }
                const meta = manifestFields[col.name] || {};
                const cType = meta.type || (col.type === 'varchar' ? 'string' : (col.type === 'int' ? 'integer' : col.type)) || 'string';
                const cLabel = meta.label || (col.name.charAt(0).toUpperCase() + col.name.slice(1).replace(/_/g, ' '));
                const cNullable = meta.nullable !== undefined ? !!meta.nullable : (col.nullable !== undefined ? !!col.nullable : false);
                const isHidden = meta.hidden !== undefined ? !!meta.hidden : false;
                const showInForm = meta.show_in_form !== undefined ? !!meta.show_in_form : !isHidden;
                const showInList = meta.show_in_list !== undefined ? !!meta.show_in_list : !isHidden;

                fieldsList.push({
                    handle: col.name,
                    label: cLabel,
                    type: cType,
                    width: parseInt(meta.width) || (['text', 'json'].includes(cType) ? 100 : 50),
                    required: !cNullable,
                    nullable: cNullable,
                    hidden: isHidden,
                    show_in_form: showInForm,
                    show_in_list: showInList,
                    length: meta.length !== undefined && meta.length !== null ? String(meta.length) : (cType === 'decimal' ? '10,2' : (cType === 'string' ? '255' : '')),
                    default: meta.default !== undefined && meta.default !== null ? String(meta.default) : '',
                    optionsText: '',
                    is_existing: true
                });
            });

            if (fieldsList.length === 0) {
                fieldsList.push({
                    handle: 'name',
                    label: 'Name',
                    type: 'string',
                    width: 50,
                    required: true,
                    nullable: false,
                    length: '255',
                    default: '',
                    optionsText: '',
                    is_existing: false
                });
            }

            this.studioFields = fieldsList;
        },

        moveStudioFieldUp(fIdx) {
            if (fIdx <= 0) return;
            const temp = this.studioFields[fIdx];
            this.studioFields[fIdx] = this.studioFields[fIdx - 1];
            this.studioFields[fIdx - 1] = temp;
        },

        moveStudioFieldDown(fIdx) {
            if (fIdx >= this.studioFields.length - 1) return;
            const temp = this.studioFields[fIdx];
            this.studioFields[fIdx] = this.studioFields[fIdx + 1];
            this.studioFields[fIdx + 1] = temp;
        },

        onStudioRequiredToggle(field) {
            if (field.required) {
                field.nullable = false;
            } else {
                field.nullable = true;
            }
        },

        onStudioNullableToggle(field) {
            if (field.nullable) {
                field.required = false;
            } else {
                field.required = true;
            }
        },

        addStudioField() {
            const count = this.studioFields.length + 1;
            this.studioFields.push({
                handle: `field_${count}`,
                label: `Field ${count}`,
                type: 'string',
                width: 50,
                required: false,
                nullable: true,
                length: '255',
                default: '',
                optionsText: '',
                is_existing: false
            });
        },

        removeStudioField(fIdx) {
            const f = this.studioFields[fIdx];
            if (f.is_existing) {
                if (!confirm(`Are you sure you want to delete column '${f.handle}'? It will be dropped from the database upon running migration.`)) {
                    return;
                }
                this.deletedStudioFields.push(f.handle);
            }
            this.studioFields.splice(fIdx, 1);
        },

        applyStudioSchema() {
            const newCols = this.studioFields.filter(f => !f.is_existing && f.handle && f.handle.trim());
            const deletedCols = [...this.deletedStudioFields];

            this.isSavingStudioFields = true;
            this.fieldFeedback = '';

            fetch('/laraslice/wizard/sync-fields', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    slice: this.selectedSlice.name,
                    targetTable: this.targetTable,
                    new_fields: newCols.map(f => ({
                        name: f.handle.trim(),
                        type: f.type,
                        length: f.length || null,
                        nullable: !!f.nullable,
                        default: f.default || null
                    })),
                    deleted_fields: deletedCols,
                    all_fields: this.studioFields.map(f => ({
                        handle: (f.handle || f.name || '').trim(),
                        label: f.label,
                        type: f.type,
                        width: f.width,
                        required: !!f.required,
                        nullable: !!f.nullable,
                        hidden: !!f.hidden,
                        show_in_form: f.hidden ? false : (f.show_in_form !== undefined ? !!f.show_in_form : true),
                        show_in_list: f.hidden ? false : (f.show_in_list !== undefined ? !!f.show_in_list : true),
                        length: f.length || null,
                        default: f.default || null
                    })),
                    migrate: true
                })
            }).then(r => r.json()).then(d => {
                this.isSavingStudioFields = false;
                if (d.success) {
                    this.fieldFeedback = d.message;
                    this.deletedStudioFields = [];
                    this.loadSlices();
                } else {
                    alert(d.message || 'Schema synchronization failed');
                }
            }).catch(e => {
                this.isSavingStudioFields = false;
                alert('Error: ' + e.message);
            });
        },

        addStudioRelationship() {
            const src = this.selectedSlice?.models?.[0]?.handle || (this.selectedSlice?.tables_data?.[0]?.name ? this.selectedSlice.tables_data[0].name.replace(/s$/, '') : 'model');
            const other = this.selectedSlice?.models?.find(m => m.handle !== src);
            const tgt = other ? other.handle : 'user';
            this.studioRelations.push({
                source_model: src,
                type: 'belongsTo',
                model: tgt,
                foreign_key: (tgt ? (tgt + '_id') : ''),
                method: tgt || 'relation'
            });
        },

        removeStudioRelation(rIdx) {
            this.studioRelations.splice(rIdx, 1);
        },

        onStudioRelationTypeChange(rel) {
            if (rel.type === 'belongsTo') {
                rel.foreign_key = rel.model ? (rel.model + '_id') : '';
                rel.method = rel.model;
            } else if (rel.type === 'hasMany') {
                rel.foreign_key = rel.source_model ? (rel.source_model + '_id') : '';
                rel.method = rel.model ? (rel.model.endsWith('s') ? rel.model : rel.model + 's') : '';
            }
        },

        onStudioRelationTargetChange(rel) {
            this.onStudioRelationTypeChange(rel);
        },

        saveStudioRelationships() {
            this.isSavingStudioRelations = true;
            fetch('/laraslice/wizard/save-relationships', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    slice: this.selectedSlice.name,
                    relations: this.studioRelations
                })
            }).then(r => r.json()).then(d => {
                this.isSavingStudioRelations = false;
                if (d.success) {
                    alert(d.message || 'Relationships saved successfully!');
                    this.loadSlices();
                } else {
                    alert(d.message || 'Failed to save relationships');
                }
            }).catch(e => {
                this.isSavingStudioRelations = false;
                alert('Error: ' + e.message);
            });
        },

        rollbackVersion(hist, hIdx) {
            if (!confirm(`Are you sure you want to rollback / restore '${this.selectedSlice.name}' to version ${hist.version}?`)) {
                return;
            }
            this.isRollingBack = true;
            fetch('/laraslice/wizard/rollback-version', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    slice: this.selectedSlice.name,
                    target_version: hist.version,
                    target_index: (typeof hIdx === 'number') ? hIdx : null,
                    target_date: hist.date || null
                })
            }).then(r => r.json()).then(d => {
                this.isRollingBack = false;
                if (d.success) {
                    alert(d.message || 'Version rollback successful!');
                    this.loadSlices();
                } else {
                    alert(d.message || 'Rollback failed');
                }
            }).catch(e => {
                this.isRollingBack = false;
                alert('Error: ' + e.message);
            });
        },

        newField: { name: '', type: 'string', nullable: true },
        builderMode: 'batch',
        showAddChildTable: false,
        isCreatingChildTable: false,
        childTableFeedback: '',
        newChildTable: {
            tableName: '',
            relationType: 'hasMany',
            foreignKey: '',
            fields: [
                { name: 'title', type: 'string', nullable: false },
                { name: 'price', type: 'decimal', nullable: false },
                { name: 'sku', type: 'string', nullable: true }
            ]
        },
        addChildTableField() {
            this.newChildTable.fields.push({ name: '', type: 'string', nullable: true });
        },
        removeChildTableField(idx) {
            if (this.newChildTable.fields.length > 1) {
                this.newChildTable.fields.splice(idx, 1);
            }
        },
        submitChildTable() {
            if (!this.newChildTable.tableName || this.newChildTable.tableName.trim() === '') {
                return alert('Please enter a valid Child Table Name (e.g. product_variants or product_images)');
            }
            const valid = this.newChildTable.fields.filter(f => f.name && f.name.trim() !== '');
            this.isCreatingChildTable = true;
            this.childTableFeedback = '';
            
            fetch('/laraslice/wizard/add-child-table', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    slice: this.selectedSlice.name,
                    tableName: this.newChildTable.tableName.trim(),
                    relationType: this.newChildTable.relationType,
                    foreignKey: this.newChildTable.foreignKey || null,
                    fields: valid,
                    migrate: true
                })
            }).then(r => r.json()).then(d => {
                this.isCreatingChildTable = false;
                if (d.success) {
                    this.childTableFeedback = d.message;
                    this.showAddChildTable = false;
                    this.newChildTable.tableName = '';
                    this.loadSlices();
                } else {
                    this.childTableFeedback = 'Error: ' + (d.message || 'Failed to add child table');
                }
            }).catch(e => {
                this.isCreatingChildTable = false;
                this.childTableFeedback = 'Error: ' + e.message;
            });
        },
        batchFields: [
            { name: '', type: 'string', length: '', nullable: true, default: '', unsigned: false }
        ],
        addBatchRow() {
            this.batchFields.push({ name: '', type: 'string', length: '', nullable: true, default: '', unsigned: false });
        },
        removeBatchRow(idx) {
            if (this.batchFields.length > 1) {
                this.batchFields.splice(idx, 1);
            }
        },
        submitBatchFields() {
            const valid = this.batchFields.filter(f => f.name && f.name.trim() !== '');
            if (valid.length === 0) return alert('At least one field name is required');
            this.isAddingField = true;
            this.fieldFeedback = '';
            fetch('/laraslice/wizard/add-fields-batch', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    slice: this.selectedSlice.name,
                    targetTable: this.targetTable,
                    fields: valid,
                    migrate: true
                })
            }).then(r => r.json()).then(d => {
                this.isAddingField = false;
                if (d.success) {
                    this.fieldFeedback = d.message;
                    this.batchFields = [{ name: '', type: 'string', length: '', nullable: true, default: '', unsigned: false }];
                    this.loadSlices();
                } else {
                    alert(d.message || 'Batch migration failed');
                }
            }).catch(e => {
                this.isAddingField = false;
                alert('Error: ' + e.message);
            });
        },
        isAddingField: false,
        fieldFeedback: '',
        navConfig: { title: '', icon: 'cube', order: 10, permission: '', permissions: [], url: '' },
        newPermInput: '',
        addPermissionToList() {
            if (!this.newPermInput || !this.newPermInput.trim()) return;
            let val = this.newPermInput.trim().toLowerCase();
            const slugBase = (this.selectedSlice?.name || '').replace(/([A-Z])/g, '_$1').toLowerCase().replace(/^_/, '').replace(/s$/, '');
            if (!val.includes('.')) {
                val = slugBase + '.' + val;
            }
            if (!this.navConfig.permissions) {
                this.navConfig.permissions = [];
            }
            if (!this.navConfig.permissions.includes(val)) {
                this.navConfig.permissions.push(val);
                if (!this.navConfig.permission) {
                    this.navConfig.permission = val;
                }
            }
            this.newPermInput = '';
        },
        init() {
            if (!this.projectName) { this.applyPreset('legal_dispute_adjudication'); }

            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('port') === '1' || this.mainTab === 'porting') {
                this.showPortModal = true;
                // NOTE: Analyze is NOT auto-fired. The developer clicks it explicitly.
            }

            this.loadSlices();
        },
        loadSlices() {
            fetch('/laraslice/wizard/slices')
                .then(r => r.json())
                .then(d => {
                    this.installedSlices = d.data || [];
                    if (this.installedSlices.length > 0) {
                        const curName = this.selectedSlice ? this.selectedSlice.name : null;
                        const match = this.installedSlices.find(s => s.name === curName);
                        const curTableName = this.selectedTable ? this.selectedTable.name : (this.targetTable || null);
                        this.selectSlice(match || this.installedSlices[0]);
                        if (curTableName && this.selectedSlice?.tables_data) {
                            const matchTable = this.selectedSlice.tables_data.find(t => t.name === curTableName);
                            if (matchTable) {
                                this.selectedTable = matchTable;
                                this.targetTable = matchTable.name;
                                this.syncStudioFields();
                            }
                        }
                    }
                }).catch(e => console.error(e));
        },
        showToast(msg, type = 'success') {
            this.toastMessage = msg;
            this.toastType = type;
            setTimeout(() => { this.toastMessage = ''; }, 4500);
        },
        groupedSlices() {
            const groups = {};
            const query = (this.sliceSearchQuery || '').toLowerCase().trim();
            const list = (this.installedSlices || []).filter(s => {
                if (!query) return true;
                const nameMatch = (s.name || '').toLowerCase().includes(query);
                const titleMatch = (s.title || '').toLowerCase().includes(query);
                const domainMatch = ((s.domain || s.navigation?.group || '')).toLowerCase().includes(query);
                const appMatch = (s.app || s.super_domain || '').toLowerCase().includes(query);
                const modMatch = (s.module || '').toLowerCase().includes(query);
                return nameMatch || titleMatch || domainMatch || appMatch || modMatch;
            });

            list.forEach(s => {
                const appName = (s.app || s.super_domain || '').trim();
                const modName = (s.module || s.domain || s.navigation?.group || '').trim();
                let domain = '';
                if (appName && modName && appName.toLowerCase() !== modName.toLowerCase()) {
                    domain = `${appName} › ${modName}`;
                } else {
                    domain = appName || modName || 'General / Core';
                }

                if (!groups[domain]) {
                    groups[domain] = {
                        domain: domain,
                        slices: []
                    };
                }
                groups[domain].slices.push(s);
            });

            return Object.values(groups).map(g => {
                const totalModels = g.slices.reduce((sum, s) => {
                    const modelCount = s.models && s.models.length ? s.models.length : (s.tables_data && s.tables_data.length ? s.tables_data.length : 1);
                    return sum + modelCount;
                }, 0);
                const activeCount = g.slices.filter(s => s.active !== false).length;
                let status = 'active';
                if (activeCount === 0) {
                    status = 'disabled';
                } else if (activeCount < g.slices.length) {
                    status = 'partial';
                }
                return {
                    ...g,
                    totalModels,
                    activeCount,
                    status
                };
            });
        },
        toggleDomainCollapse(domainName) {
            this.collapsedDomains[domainName] = !this.collapsedDomains[domainName];
        },
        toggleSliceActive(slice, event) {
            if (event) event.stopPropagation();
            const newActive = slice.active === false;
            fetch('/laraslice/wizard/toggle-slice', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    slice: slice.name,
                    active: newActive
                })
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    slice.active = newActive;
                    this.showToast(d.message);
                } else {
                    alert(d.message || 'Could not update slice status');
                }
            }).catch(e => alert(e.message));
        },
        toggleDomainActive(domainName, event) {
            if (event) event.stopPropagation();
            const grp = this.groupedSlices().find(g => g.domain === domainName);
            const anyActive = grp ? grp.slices.some(s => s.active !== false) : true;
            const targetActive = !anyActive;

            fetch('/laraslice/wizard/toggle-domain', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    domain: domainName,
                    active: targetActive
                })
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    this.showToast(`Domain [${domainName}] ${targetActive ? 'Enabled' : 'Disabled'}`);
                    this.loadSlices();
                } else {
                    alert(d.message || 'Error updating domain');
                }
            }).catch(e => alert(e.message));
        },
        seedSliceData(sliceName, event) {
            if (event) event.stopPropagation();
            this.isActionRunning = true;
            this.actionStatusText = `Seeding ${sliceName}...`;
            fetch('/laraslice/wizard/seed-slice', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ slice: sliceName, count: 10 })
            }).then(r => r.json()).then(d => {
                this.isActionRunning = false;
                if (d.success) {
                    this.showToast(d.message);
                    this.loadSlices();
                } else {
                    alert(d.message || 'Seeding failed');
                }
            }).catch(e => {
                this.isActionRunning = false;
                alert(e.message);
            });
        },
        seedDomainData(domainName, event) {
            if (event) event.stopPropagation();
            this.isActionRunning = true;
            this.actionStatusText = `Seeding ${domainName} domain...`;
            fetch('/laraslice/wizard/seed-domain', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ domain: domainName, count: 10 })
            }).then(r => r.json()).then(d => {
                this.isActionRunning = false;
                if (d.success) {
                    this.showToast(d.message);
                    this.loadSlices();
                } else {
                    alert(d.message || 'Domain seeding failed');
                }
            }).catch(e => {
                this.isActionRunning = false;
                alert(e.message);
            });
        },
        wipeSliceData(sliceName, event) {
            if (event) event.stopPropagation();
            if (!confirm(`Are you sure you want to wipe (truncate) all database records for slice [${sliceName}]? Code will remain intact.`)) return;
            fetch('/laraslice/wizard/wipe-slice', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ slice: sliceName })
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    this.showToast(d.message);
                    this.loadSlices();
                } else {
                    alert(d.message || 'Wipe failed');
                }
            }).catch(e => alert(e.message));
        },
        wipeDomainData(domainName, event) {
            if (event) event.stopPropagation();
            if (!confirm(`Are you sure you want to wipe all database records for domain [${domainName}]?`)) return;
            fetch('/laraslice/wizard/wipe-domain', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ domain: domainName })
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    this.showToast(d.message);
                    this.loadSlices();
                } else {
                    alert(d.message || 'Domain wipe failed');
                }
            }).catch(e => alert(e.message));
        },
        openDeleteModal(targetType, targetName, event) {
            if (event) event.stopPropagation();
            this.deleteModal = {
                open: true,
                targetType: targetType,
                targetName: targetName,
                mode: 'complete',
                isDeleting: false,
                errorMessage: ''
            };
        },
        confirmDelete() {
            this.deleteModal.isDeleting = true;
            this.deleteModal.errorMessage = '';
            const endpoint = this.deleteModal.targetType === 'domain'
                ? '/laraslice/wizard/destroy-domain'
                : '/laraslice/wizard/destroy-slice';
            const payload = this.deleteModal.targetType === 'domain'
                ? { domain: this.deleteModal.targetName, mode: this.deleteModal.mode }
                : { slice: this.deleteModal.targetName, mode: this.deleteModal.mode };

            fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            }).then(r => r.json()).then(d => {
                this.deleteModal.isDeleting = false;
                if (d.success) {
                    this.deleteModal.open = false;
                    this.showToast(d.message);
                    this.selectedSlice = null;
                    this.loadSlices();
                } else {
                    this.deleteModal.errorMessage = d.message || 'Operation failed';
                }
            }).catch(e => {
                this.deleteModal.isDeleting = false;
                this.deleteModal.errorMessage = e.message;
            });
        },
        selectSlice(slice) {
            this.selectedSlice = slice;
            this.navRoles = Array.isArray(slice.navigation?.roles) ? [...slice.navigation.roles] : [];
            this.navGroup = slice.navigation?.group || slice.domain || 'General';
            this.navVisible = slice.navigation?.visible !== false;
            this.uiViews = Array.isArray(slice.ui?.directory?.views) ? [...slice.ui.directory.views] : ['table'];
            this.uiDefaultView = slice.ui?.directory?.default_view || 'table';
            this.uiInlineEdit = Array.isArray(slice.ui?.directory?.inline_edit) ? [...slice.ui.directory.inline_edit] : [];
            this.uiDashboardWidgets = Array.isArray(slice.ui?.dashboard?.widgets) ? [...slice.ui.dashboard.widgets] : [];
            // Hydrate workflow editor from manifest (Slice Studio GitOps)
            if (slice.workflow && typeof slice.workflow === 'object') {
                this.workflowConfig = JSON.parse(JSON.stringify(slice.workflow));
                if (!Array.isArray(this.workflowConfig.states)) this.workflowConfig.states = [];
                if (!Array.isArray(this.workflowConfig.transitions)) this.workflowConfig.transitions = [];
                this.includeWorkflow = this.workflowConfig.enabled !== false;
            } else if (slice.workflow === true) {
                this.initDefaultWorkflow();
                this.includeWorkflow = true;
            } else {
                this.workflowConfig = this.workflowConfig || null;
            }
            const bas = slice.ui?.directory?.bulk_actions;
            if (Array.isArray(bas) && bas.length) {
                this.uiBulkActions = bas.map(b => typeof b === 'string' ? b : (b.handle || b.slug || 'action'));
            }
            if (slice.ui?.directory?.row_actions) {
                this.uiRowActions = JSON.parse(JSON.stringify(slice.ui.directory.row_actions));
            }
            this.loadAuditLogs(slice.name);
            const primary = (slice.tables_data || []).find(t => t.is_primary) || (slice.tables_data || [])[0] || null;
            this.selectedTable = primary;
            this.targetTable = primary ? primary.name : '';
            this.studioRelations = Array.isArray(slice.relations) ? JSON.parse(JSON.stringify(slice.relations)) : [];
            this.syncStudioFields();

            const slugBase = (slice.name || '').replace(/([A-Z])/g, '_$1').toLowerCase().replace(/^_/, '').replace(/s$/, '');
            const defaultPerms = [
                slugBase + '.view',
                slugBase + '.create',
                slugBase + '.edit',
                slugBase + '.delete'
            ];
            const rawPerms = Array.isArray(slice.permissions) && slice.permissions.length > 0
                ? slice.permissions
                : defaultPerms;
            const perms = rawPerms.map(p => {
                if (typeof p === 'object' && p !== null) {
                    return p.slug || p.name || p.key || JSON.stringify(p);
                }
                return String(p);
            });

            this.navConfig = {
                title: slice.navigation?.title || slice.title || slice.name,
                icon: slice.navigation?.icon || slice.icon || 'cube',
                order: slice.navigation?.order || 10,
                permission: slice.navigation?.permission || (perms[0] || ''),
                permissions: perms,
                url: slice.navigation?.url || ('/' + (slice.tables_data?.[0]?.name || slice.name.replace(/([A-Z])/g, '_$1').toLowerCase().replace(/^_/, ''))),
                group: slice.navigation?.group || slice.domain || '',
                hidden: !!(slice.navigation?.hidden || (slice.navigation?.visible === false)),
                redirect_old: true,
                children: Array.isArray(slice.navigation?.children)
                    ? slice.navigation.children.map(c => ({
                        label: c.label || c.title || '',
                        url: c.url || c.route || '',
                        route: c.route || c.url || '',
                        icon: c.icon || ''
                    }))
                    : []
            };
            this.newPermInput = '';

            // Notify AI Copilot of selected slice context
            try {
                window.dispatchEvent(new CustomEvent('laraslice-slice-selected', {
                    detail: {
                        name: slice.name,
                        title: slice.title || slice.name,
                        domain: slice.domain || '',
                        version: slice.version || '1.0.0',
                        description: slice.description || '',
                        tables_data: slice.tables_data || [],
                        child_tables: slice.child_tables || [],
                        fields: slice.fields || {},
                        permissions: perms,
                        navigation: this.navConfig
                    }
                }));
            } catch (e) {}
        },
                renderNavIcon(icon) {
            const name = (icon || 'box').toLowerCase().trim();
            const svgs = {
                'box': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>',
                'shopping-bag': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
                'users': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
                'user': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
                'shield': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
                'shield-check': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>',
                'settings': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
                'file-text': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>',
                'pie-chart': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>',
                'layout-dashboard': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>',
                'wallet': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg>',
                'bell': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>',
                'message-square': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
                'boxes': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.97 12.92A2 2 0 0 0 2 14.63v3.24a2 2 0 0 0 .97 1.71l3 1.8a2 2 0 0 0 2.06 0L12 19v-5.5l-5-3-4.03 2.42Z"/><path d="m7 16.5-4.74-2.85"/><path d="m7 16.5 5-3"/><path d="M7 16.5v5.17"/><path d="M12 13.5V19l3.97 2.38a2 2 0 0 0 2.06 0l3-1.8a2 2 0 0 0 .97-1.71v-3.24a2 2 0 0 0-.97-1.71L17 10.5l-5 3Z"/><path d="m17 16.5-5-3"/><path d="m17 16.5 4.74-2.85"/><path d="M17 16.5v5.17"/><path d="M7.97 4.42A2 2 0 0 0 7 6.13v4.37l5 3 5-3V6.13a2 2 0 0 0-.97-1.71l-3-1.8a2 2 0 0 0-2.06 0l-3 1.8Z"/><path d="M12 8 7.26 5.15"/><path d="m12 8 4.74-2.85"/><path d="M12 13.5V8"/></svg>',
                'tag': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2H2v10l9.29 9.29c.94.94 2.48.94 3.42 0l6.58-6.58c.94-.94.94-2.48 0-3.42L12 2Z"/><path d="M7 7h.01"/></svg>',
                'activity': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
                'smartphone': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg>',
                'database': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/></svg>',
                'folder': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></svg>',
                'credit-card': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>',
                'layers': '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.9a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/></svg>'
            };
            return svgs[name] || svgs['box'];
        },
        moveSubmenu(index, direction) {
            if (!this.navConfig || !Array.isArray(this.navConfig.children)) return;
            const target = index + direction;
            if (target < 0 || target >= this.navConfig.children.length) return;
            const item = this.navConfig.children.splice(index, 1)[0];
            this.navConfig.children.splice(target, 0, item);
        },
        formatPerm(p) {
            if (typeof p === 'object' && p !== null) {
                return p.slug || p.name || p.key || '';
            }
            return String(p || '');
        },
        submitField() {
            if (!this.newField.name) return alert('Field name required');
            this.isAddingField = true;
            this.fieldFeedback = '';
            fetch('/laraslice/wizard/add-field', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    slice: this.selectedSlice.name,
                    targetTable: this.targetTable,
                    field: this.newField.name,
                    type: this.newField.type,
                    nullable: this.newField.nullable,
                    migrate: true
                })
            }).then(r => r.json()).then(d => {
                this.isAddingField = false;
                if (d.success) {
                    this.fieldFeedback = d.message;
                    this.newField.name = '';
                    this.loadSlices();
                } else {
                    alert(d.message || 'Failed');
                }
            }).catch(e => {
                this.isAddingField = false;
                alert('Error: ' + e.message);
            });
        },
        submitNavigation() {
            fetch('/laraslice/wizard/update-navigation', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    slice: this.selectedSlice.name,
                    ...this.navConfig
                })
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    alert('Navigation updated for ' + this.selectedSlice.name + '!');
                    this.loadSlices();
                }
            });
        },
        async saveStudioConfig() {
            if (!this.selectedSlice) return;
            this.studioConfigSaving = true;
            this.studioConfigMessage = '';
            try {
                const payload = this.buildStudioConfigPayload();
                // Preview diff before overwrite — no silent writes
                const previewRes = await fetch('{{ route('laraslice.wizard.preview_studio_config') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(payload),
                });
                const preview = await previewRes.json();
                if (!preview.success) {
                    this.studioConfigMessage = preview.message || 'Could not build diff';
                    return;
                }
                if (!preview.changed) {
                    this.studioConfigMessage = 'No changes to save.';
                    return;
                }
                this.pendingDiff = preview.diff;
                this.pendingSavePayload = payload;
                this.showDiffModal = true;
                return;
            } catch (e) {
                this.studioConfigMessage = e.message || String(e);
            } finally {
                this.studioConfigSaving = false;
            }
        },
        buildStudioConfigPayload() {
                const ui = this.buildUiConfig();
                ui.dashboard = { enabled: true, widgets: this.uiDashboardWidgets || [] };
                const navigation = {
                    roles: this.navRoles || [],
                    visible: this.navVisible !== false,
                    label: this.navConfig?.title || this.selectedSlice.navigation?.label || this.selectedSlice.title || this.selectedSlice.name,
                    icon: this.navConfig?.icon || this.selectedSlice.navigation?.icon || 'package',
                    group: this.navGroup || this.navConfig?.group || this.selectedSlice.navigation?.group || 'General',
                    order: this.navConfig?.order || 50,
                    hidden: !!this.navConfig?.hidden,
                };
                const workflowPayload = this.includeWorkflow && this.workflowConfig
                    ? {
                        enabled: true,
                        state_field: this.workflowConfig.state_field || this.workflowConfig.state_column || 'status',
                        initial_state: this.workflowConfig.initial_state || (this.workflowConfig.states?.[0]?.slug || 'draft'),
                        states: this.workflowConfig.states || [],
                        transitions: this.workflowConfig.transitions || [],
                    }
                    : (this.includeWorkflow === false ? { enabled: false, states: [], transitions: [] } : null);
                return {
                    slice: this.selectedSlice.name,
                    ui,
                    navigation,
                    ...(workflowPayload !== null ? { workflow: workflowPayload } : {}),
                };
        },
        async confirmStudioSave() {
            if (!this.pendingSavePayload) return;
            this.showDiffModal = false;
            this.studioConfigSaving = true;
            try {
                const res = await fetch('{{ route('laraslice.wizard.save_studio_config') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(this.pendingSavePayload),
                });
                const data = await res.json();
                this.studioConfigMessage = data.message || (data.success ? 'Saved' : 'Save failed');
                if (data.version_history) this.selectedSlice.version_history = data.version_history;
                if (data.ui) this.selectedSlice.ui = data.ui;
                if (data.navigation) this.selectedSlice.navigation = data.navigation;
                if (data.workflow !== undefined) this.selectedSlice.workflow = data.workflow;
            } catch (e) {
                this.studioConfigMessage = e.message || String(e);
            } finally {
                this.studioConfigSaving = false;
                this.pendingSavePayload = null;
                this.pendingDiff = '';
            }
        },
        async saveStudioConfigLegacy() {
            if (!this.selectedSlice) return;
            this.studioConfigSaving = true;
            this.studioConfigMessage = '';
            try {
                const ui = this.buildUiConfig();
                ui.dashboard = { enabled: true, widgets: this.uiDashboardWidgets || [] };
                const navigation = {
                    roles: this.navRoles || [],
                    visible: this.navVisible !== false,
                    label: this.navConfig?.title || this.selectedSlice.navigation?.label || this.selectedSlice.title || this.selectedSlice.name,
                    icon: this.navConfig?.icon || this.selectedSlice.navigation?.icon || 'package',
                    group: this.navGroup || this.navConfig?.group || this.selectedSlice.navigation?.group || 'General',
                    order: this.navConfig?.order || 50,
                    hidden: !!this.navConfig?.hidden,
                };
                const workflowPayload = this.includeWorkflow && this.workflowConfig
                    ? {
                        enabled: true,
                        state_field: this.workflowConfig.state_field || this.workflowConfig.state_column || 'status',
                        initial_state: this.workflowConfig.initial_state || (this.workflowConfig.states?.[0]?.slug || 'draft'),
                        states: this.workflowConfig.states || [],
                        transitions: this.workflowConfig.transitions || [],
                    }
                    : (this.includeWorkflow === false ? { enabled: false, states: [], transitions: [] } : null);
                const res = await fetch('{{ route('laraslice.wizard.save_studio_config') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        slice: this.selectedSlice.name,
                        ui,
                        navigation,
                        ...(workflowPayload !== null ? { workflow: workflowPayload } : {}),
                    }),
                });
                const data = await res.json();
                if (!res.ok || data.success === false) throw new Error(data.message || 'Save failed');
                this.studioConfigMessage = data.message || 'Saved';
                if (data.version) this.selectedSlice.version = data.version;
                if (data.version_history) this.selectedSlice.version_history = data.version_history;
                if (data.ui) this.selectedSlice.ui = data.ui;
                if (data.navigation) this.selectedSlice.navigation = data.navigation;
                if (data.workflow !== undefined) this.selectedSlice.workflow = data.workflow;
                if (this.navGroup) {
                    this.selectedSlice.navigation = this.selectedSlice.navigation || {};
                    this.selectedSlice.navigation.group = this.navGroup;
                }
            } catch (e) {
                this.studioConfigMessage = e.message || String(e);
            } finally {
                this.studioConfigSaving = false;
            }
        },
        async syncManifestPrefer(prefer = 'json') {
            if (!this.selectedSlice) return;
            this.studioConfigSaving = true;
            try {
                const res = await fetch('{{ route('laraslice.wizard.sync_manifest') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ slice: this.selectedSlice.name, prefer }),
                });
                const data = await res.json();
                this.studioConfigMessage = data.message || (data.success ? 'Synced' : 'Sync failed');
                if (data.ui) this.selectedSlice.ui = data.ui;
                if (data.navigation) this.selectedSlice.navigation = data.navigation;
            } catch (e) {
                this.studioConfigMessage = e.message || String(e);
            } finally {
                this.studioConfigSaving = false;
            }
        },
        generateDomainSuite(domain, slicesList) {
            const domainName = (domain || this.domain || 'General').trim();
            const rawSlices = slicesList || (this.projectName.includes(',') ? this.projectName.split(',').map(s => s.trim()).filter(Boolean) : [this.projectName.trim()]);
            const slices = rawSlices.filter(Boolean);

            if (!slices || slices.length === 0) {
                return alert('Please enter at least one Slice Name');
            }

            this.saveActiveSliceData();

            this.isGenerating = true;
            this.resultMessage = '';
            this.resultData = null;
            this.migrationSuccess = false;
            this.migrationOutput = '';

            const sliceSchemas = {};
            slices.forEach(sName => {
                const sData = this.multiSliceData[sName] || { fields: this.customFields, childTables: this.childTables };
                const rawFields = (sName === this.activeSliceName()) ? this.customFields : (sData.fields || []);
                const rawChild = (sName === this.activeSliceName()) ? this.childTables : (sData.childTables || []);

                const formattedFields = rawFields
                    .filter(field => field.name && field.name.trim())
                    .map(field => {
                        const result = {
                            name: field.name.trim(),
                            label: field.label ? field.label.trim() : undefined,
                            type: field.type || 'string',
                            width: field.width || '50%',
                            required: !!field.required,
                            nullable: field.nullable !== undefined ? !!field.nullable : !field.required,
                            default: field.default || null,
                        };
                        if (field.type === 'select' && field.optionsText) {
                            const options = field.optionsText.split(',').map(value => value.trim()).filter(Boolean);
                            result.options = Object.fromEntries(options.map(label => [label.toLowerCase().replace(/[^a-z0-9_.-]+/g, '_'), label]));
                        } else if (field.optionsText) {
                            result.length = field.optionsText.trim();
                        }
                        return result;
                    });

                sliceSchemas[sName] = {
                    fields: formattedFields,
                    childTables: rawChild,
                    ui: (this.multiSliceData[sName] && this.multiSliceData[sName].ui) ? this.multiSliceData[sName].ui : this.buildUiConfig()
                };
            });

            fetch('/laraslice/wizard/domain-suite', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    domain: domainName,
                    slices: slices,
                    sliceSchemas: sliceSchemas,
                    namespace: this.namespace,
                    author: this.author,
                    workflow: this.includeWorkflow,
                    workflowConfig: this.includeWorkflow ? this.workflowConfig : null,
                    roles: this.selectedRolesForGeneration(),
                    includeApi: this.includeApi,
                    flutter: this.includeFlutter,
                    runMigration: this.autoMigrate
                })
            }).then(r => r.json()).then(d => {
                this.isGenerating = false;
                if (d.success) {
                    this.resultMessage = d.message;
                    const domainSlug = domainName.toLowerCase().replace(/[^a-z0-9_]/g, '_');
                    const allCreated = (d.created && d.created.length > 0) ? d.created : slices.map(s => {
                        const slSlug = s.toLowerCase().replace(/[^a-z0-9_]/g, '_');
                        const plSlug = slSlug.endsWith('s') ? slSlug : slSlug + 's';
                        return {
                            name: s,
                            web_url: '/' + domainSlug + '/' + plSlug,
                            api_url: '/api/' + domainSlug + '/' + plSlug + '/list',
                            path: 'app/Slices/' + domainName + '/' + s
                        };
                    });
                    const firstItem = allCreated[0] || {};
                    this.resultData = {
                        path: 'app/Slices/' + domainName,
                        web_url: firstItem.web_url || ('/' + domainSlug),
                        api_url: firstItem.api_url || ('/api/' + domainSlug),
                        created: allCreated,
                        allSlices: allCreated
                    };
                    this.migrationSuccess = !!d.migrated;
                    this.migrationOutput = d.migrationOutput || '';
                    this.loadSlices();
                } else {
                    this.resultMessage = 'Generation failed: ' + (d.message || 'Unknown error');
                }
            }).catch(e => {
                this.isGenerating = false;
                this.resultMessage = 'Error: ' + e.message;
            });
        },
        refreshUiFromFields() {
            // Auto-detect UI config from studioFields - called on demand, preserves manual edits otherwise
            const fields = this.studioFields || [];
            const label = (h) => h.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            this.uiQuickFilters = fields
                .filter(f => f.type === 'foreign_id' || (f.handle && (f.handle.includes('status') || f.handle.includes('type') || f.handle.includes('category'))))
                .map(f => ({
                    field: f.handle, handle: f.handle,
                    label: f.label || label(f.handle),
                    type: 'select', searchable: true, is_searchable: true,
                    options_from: 'distinct:' + f.handle,
                }));
            this.uiSummaryCards = fields
                .filter(f => f.type === 'decimal' || (f.handle && (f.handle.includes('amount') || f.handle.includes('total') || f.handle.includes('price') || f.handle.includes('balance'))))
                .map(f => ({
                    handle: f.handle, label: f.label || label(f.handle),
                    aggregate: 'sum', formatter: 'money',
                }));
            this.uiSidebarFilters = fields
                .filter(f => f.type === 'date' || f.type === 'datetime' || (f.handle && (f.handle.includes('_at') || f.handle.includes('date'))))
                .slice(0, 2)
                .map(f => ({ handle: f.handle, label: f.label || label(f.handle), type: 'date_range' }));
            this.uiInitialized = true;
        },
        refreshFormSections() {
            // Auto-generate form sections from studioFields
            const fields = (this.studioFields || [])
                .filter(f => f.show_in_form !== false && f.handle && f.handle.trim() && !['id', 'created_at', 'updated_at', 'deleted_at'].includes(f.handle))
                .map(f => ({
                    handle: f.handle,
                    label: f.label || f.handle.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()),
                    span: (f.width === 100 ? 2 : 1),
                    width: f.width || 50,
                    searchable: f.type === 'foreign_id' || f.handle.endsWith('_id') || !!f.searchable,
                    type: f.type || 'text',
                }));
            // Group: put foreign keys and searchable in first section, rest in second if many fields
            if (fields.length <= 8) {
                this.uiFormSections = [{ title: 'General Information', description: '', columns: 2, fields: fields }];
            } else {
                const primary = fields.filter(f => f.searchable || f.type === 'foreign_id');
                const rest = fields.filter(f => !(f.searchable || f.type === 'foreign_id'));
                this.uiFormSections = [
                    { title: 'General Information', description: '', columns: 2, fields: primary.length ? primary : fields.slice(0, 6) },
                    { title: 'Additional Details', description: '', columns: 2, fields: primary.length ? rest : fields.slice(6) },
                ].filter(s => s.fields.length);
            }
            this.uiFormInitialized = true;
        },
        buildUiConfig() {
            const activeCols = (this.studioFields || [])
                .filter(f => f.show_in_list !== false && f.handle && f.handle.trim())
                .map(f => ({
                    handle: f.handle,
                    key: f.handle,
                    label: f.label || f.handle.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()),
                    type: f.formatter || 'text',
                    formatter: f.formatter || 'text',
                    sortable: f.sortable !== false,
                    searchable: true
                }));

            // Use editable UI state (auto-detect on first use, then preserve manual edits)
            if (!this.uiInitialized) this.refreshUiFromFields();
            const quickFilters = this.uiQuickFilters;

            const formFields = (this.studioFields || [])
                .filter(f => f.show_in_form !== false && f.handle && f.handle.trim() && !['id', 'created_at', 'updated_at', 'deleted_at'].includes(f.handle))
                .map(f => ({
                    handle: f.handle,
                    span: (f.width === 100 ? 2 : 1),
                    width: f.width || 50,
                    searchable: f.type === 'foreign_id' || f.handle.endsWith('_id') || !!f.searchable
                }));

            // Use editable form sections (auto-generate on first use, preserve manual edits)
            if (!this.uiFormInitialized) this.refreshFormSections();
            const sections = this.uiFormSections.length > 0 ? this.uiFormSections.map(s => ({
                name: s.title, title: s.title, description: s.description || '',
                grid: s.columns || 2, columns: s.columns || 2,
                fields: s.fields,
            })) : [
                { name: 'General Information', title: 'General Information', grid: 2, columns: 2, fields: [{ handle: 'name', span: 1, width: 50, searchable: false }] }
            ];

            return {
                presentation: { create: 'drawer', edit: 'drawer' },
                directory: {
                    presentation: 'table',
                    columns: activeCols.length > 0 ? activeCols : [
                        { handle: 'name', key: 'name', label: 'Name', type: 'text', sortable: true, searchable: true }
                    ],
                    quick_filters: quickFilters,
                    sidebar_filters: this.uiSidebarFilters,
                    summary_cards: this.uiSummaryCards,
                    bulk_actions: (this.uiBulkActions || []).map(ba => {
                        if (typeof ba === 'object') return ba;
                        return { handle: ba, label: ba.charAt(0).toUpperCase() + ba.slice(1), type: ba === 'delete' ? 'native' : (ba === 'export' ? 'native' : 'workflow') };
                    }),
                    row_actions: this.uiRowActions || { display: 'dropdown', items: [
                        { handle: 'view', label: 'View', icon: 'eye', placement: 'inline' },
                        { handle: 'edit', label: 'Edit', icon: 'pencil', placement: 'dropdown' },
                        { handle: 'delete', label: 'Delete', icon: 'trash-2', placement: 'dropdown', danger: true },
                    ]},
                    views: this.uiViews || ['table'],
                    default_view: this.uiDefaultView || 'table',
                    inline_edit: this.uiInlineEdit || [],
                },
                forms: {
                    mode: this.uiFormMode,
                    presentation: this.uiFormMode,
                    sections: sections
                }
            };
        },

        generateSlice() {
            if (!this.projectName || this.projectName.trim() === '') {
                return alert('Please enter a Slice Name');
            }
            if (this.projectName.includes(',') && this.domain) {
                return this.generateDomainSuite(this.domain, this.projectName.split(',').map(s => s.trim()).filter(Boolean));
            }
            this.isGenerating = true;
            this.resultMessage = '';
            this.resultData = null;
            this.migrationSuccess = false;
            this.migrationOutput = '';

            fetch('/laraslice/wizard/generate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    projectName: this.projectName,
                    namespace: this.namespace,
                    domain: this.domain,
                    description: this.description,
                    author: this.author,
                    uiFramework: this.uiFramework,
                    database: this.database,
                    includeAuth: this.includeAuth,
                    includeDatabase: this.includeDatabase,
                    includeApi: this.includeApi,
                    includeWorkflow: this.includeWorkflow,
                    workflow: this.includeWorkflow,
                    workflowConfig: this.includeWorkflow ? this.workflowConfig : null,
                    roles: this.selectedRolesForGeneration(),
                    fields: this.fieldsForGeneration(),
                    childTables: this.childTables.map(ct => ({
                        name: ct.name,
                        label: ct.label,
                        relation: ct.relation || 'hasMany',
                        fields: (ct.fields || []).filter(f => f.name && f.name.trim()).map(f => ({
                            name: f.name.trim(),
                            label: f.label ? f.label.trim() : undefined,
                            type: f.type || 'string',
                            required: !!f.required,
                            nullable: f.nullable !== undefined ? !!f.nullable : !f.required,
                            default: f.default || null
                        }))
                    })),
                    permissions: this.permissions,
                    ui: this.buildUiConfig(),
                    flutter: this.includeFlutter,
                    runMigration: this.autoMigrate
                })
            }).then(r => r.json()).then(d => {
                this.isGenerating = false;
                if (d.success) {
                    this.resultMessage = d.message;
                    this.resultData = d;
                    this.migrationSuccess = !!d.migrated;
                    this.migrationOutput = d.migrationOutput || '';
                    this.loadSlices();
                } else {
                    this.resultMessage = 'Generation failed: ' + (d.message || 'Unknown error');
                }
            }).catch(e => {
                this.isGenerating = false;
                this.resultMessage = 'Error: ' + e.message;
            });
        },
        runMigrationNow() {
            this.isMigrating = true;
            fetch('/laraslice/wizard/migrate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                }
            }).then(r => r.json()).then(d => {
                this.isMigrating = false;
                if (d.success) {
                    this.migrationSuccess = true;
                    this.migrationOutput = d.output || d.message;
                } else {
                    alert('Migration failed: ' + (d.message || 'Unknown error'));
                }
            }).catch(e => {
                this.isMigrating = false;
                alert('Migration request error: ' + e.message);
            });
        },
        copilotOpen: false,
        copilotMessages: [
            {
                role: 'assistant',
                text: "👋 Hi! I'm your LaraSlice AI Copilot. Ask me to build an entire solution like **Create HR complete solution** or **Build E-Commerce store**!",
                options: ['Create HR complete solution', 'Build E-Commerce store', 'Scaffold CRM pipeline']
            }
        ],
        copilotInput: '',
        copilotLoading: false,
        copilotStep: 1,
        copilotPlan: null,
        sendCopilotMessage(customText = null) {
            const text = customText || this.copilotInput;
            if (!text || text.trim() === '') return;
            this.copilotMessages.push({ role: 'user', text: text });
            if (!customText) this.copilotInput = '';
            this.copilotLoading = true;

            fetch('/laraslice/wizard/copilot/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    message: text,
                    slice: this.selectedSlice ? this.selectedSlice.name : '',
                    step: this.copilotStep
                })
            })
            .then(res => res.json())
            .then(data => {
                this.copilotLoading = false;
                if (data.success) {
                    this.copilotStep = data.step || 1;
                    if (data.plan) this.copilotPlan = data.plan;
                    this.copilotMessages.push({
                        role: 'assistant',
                        text: data.reply,
                        options: data.options || [],
                        can_execute: data.can_execute || false,
                        plan: data.plan || null
                    });
                } else {
                    this.copilotMessages.push({
                        role: 'assistant',
                        text: '⚠️ ' + (data.message || 'Something went wrong.')
                    });
                }
            })
            .catch(err => {
                this.copilotLoading = false;
                this.copilotMessages.push({
                    role: 'assistant',
                    text: '⚠️ Connection error: ' + err.message
                });
            });
        },
        applyCopilotPlan(plan) {
            if (!plan) return;
            this.copilotLoading = true;
            fetch('/laraslice/wizard/batch-generate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    slice: plan.slice,
                    tables: plan.tables,
                    migrate: true
                })
            })
            .then(res => res.json())
            .then(data => {
                this.copilotLoading = false;
                if (data.success) {
                    this.copilotPlan = null;
                    this.copilotMessages.push({
                        role: 'assistant',
                        text: '🎉 ' + data.message
                    });
                    this.loadSlices();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(err => {
                this.copilotLoading = false;
                alert('Execution error: ' + err.message);
            });
        },

        toSnakeCase(str) {
            if (!str) return '';
            return str.replace(/([a-z])([A-Z])/g, '$1_$2')
                      .replace(/[\s\-]+/g, '_')
                      .toLowerCase()
                      .replace(/^[^a-z]+/, '')
                      .replace(/_+/g, '_');
        },

        toStudlyCase(str) {
            if (!str) return '';
            return str.replace(/[\_\-\s]+(.)?/g, (_, c) => c ? c.toUpperCase() : '')
                      .replace(/^(.)/, c => c.toUpperCase());
        },

        getSelectedUiSliceObj() {
            if (!this.portPlanData || !this.portPlanData.modules) return null;
            for (let m of this.portPlanData.modules) {
                if (m.slices) {
                    for (let s of m.slices) {
                        if (s.name === this.selectedUiSlice) {
                            return Object.assign({}, s, { moduleName: m.name });
                        }
                    }
                }
            }
            const first = this.portPlanData.modules[0]?.slices?.[0];
            return first ? Object.assign({}, first, { moduleName: this.portPlanData.modules[0].name }) : null;
        },

        getAllUiSlices() {
            let res = [];
            if (!this.portPlanData || !this.portPlanData.modules) return res;
            const q = (this.uiSliceSearch || '').toLowerCase().trim();
            for (let m of this.portPlanData.modules) {
                if (m.slices) {
                    for (let s of m.slices) {
                        if (!q || s.name.toLowerCase().includes(q) || (m.name && m.name.toLowerCase().includes(q)) || (s.root_table && s.root_table.toLowerCase().includes(q))) {
                            res.push(Object.assign({}, s, { moduleName: m.name }));
                        }
                    }
                }
            }
            return res;
        },

        selectPortPreset(val) {
            this.portPreset = val;
            // Changing the preset only prefills the fields and clears stale plan data.
            // The developer clicks Analyze explicitly — no auto-fire.
            this.portPlanData = null;
            this.portPlanJsonText = '';
            this.portInspectionData = null;
            this.portError = '';
            if (val === 'erp') {
                this.portSourcePath = 'e:/laragon/www/erp';
                this.portDomain = 'ERP';
            } else if (val === 'adjudication') {
                this.portSourcePath = 'e:/laragon/www/wwf-old';
                this.portDomain = 'Legal';
            } else if (val === 'custom') {
                this.portSourcePath = '';
                this.portDomain = 'Core';
            }
        },

        moveSidebarGroup(gIdx, direction) {
            const groups = this.portPlanData?.sidebar_preview?.groups;
            if (!groups || gIdx < 0 || gIdx >= groups.length) return;
            const newIdx = gIdx + direction;
            if (newIdx < 0 || newIdx >= groups.length) return;
            const [moved] = groups.splice(gIdx, 1);
            groups.splice(newIdx, 0, moved);
        },

        fetchPortPlan() {
            this.portInspecting = true;
            fetch('/laraslice/wizard/port-plan', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    preset: this.portPreset,
                    source: this.portSourcePath,
                    domain: this.portDomain,
                    plan_json: this.portPreset === 'json' ? this.portPlanJsonText : ''
                })
            })
            .then(async (r) => {
                const text = await r.text();
                let res;
                try {
                    res = JSON.parse(text);
                } catch (parseErr) {
                    throw new Error(`Server returned non-JSON response (${r.status} ${r.statusText}): ${text.substring(0, 250)}`);
                }
                if (!r.ok && !res.message) {
                    throw new Error(`Server error ${r.status}: ${text.substring(0, 250)}`);
                }
                return res;
            })
            .then(res => {
                this.portInspecting = false;
                if (res.success && res.plan) {
                    this.portPlanData = res.plan;
                    this.portPlanJsonText = JSON.stringify(res.plan, null, 2);
                    if (res.inspection) this.portInspectionData = res.inspection;
                    if (!this.selectedUiSlice && res.plan.modules && res.plan.modules[0] && res.plan.modules[0].slices && res.plan.modules[0].slices[0]) {
                        this.selectedUiSlice = res.plan.modules[0].slices[0].name;
                    }
                } else {
                    alert('Plan generation failed: ' + (res.message || 'Unknown error'));
                }
            })
            .catch(e => {
                this.portInspecting = false;
                alert('Plan generation error: ' + e.message);
            });
        },

        downloadPlanJson() {
            if (!this.portPlanJsonText) return;
            const blob = new Blob([this.portPlanJsonText], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `porting-architecture-plan-${(this.portDomain || 'app').toLowerCase()}.json`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        },

        executePortPlan() {
            if (!this.portPlanData && !this.portPlanJsonText) {
                alert('Please inspect the project or load an architecture plan first.');
                return;
            }
            this.portExecuting = true;
            this.portActiveTab = 'log';
            this.portExecutionLogs = [
                `[${new Date().toLocaleTimeString()}] Initializing LaraSlice VSA Porting Engine...`,
                `[${new Date().toLocaleTimeString()}] Target Domain: ${this.portDomain}`,
                `[${new Date().toLocaleTimeString()}] Mode: Canonical Enterprise Business Aggregates`,
                `[${new Date().toLocaleTimeString()}] Multi-tenancy: ${this.portPlanData?.summary?.multi_tenancy?.enabled ? 'Enabled (' + this.portPlanData.summary.multi_tenancy.tenant_key + ')' : 'Disabled'}`,
                `[${new Date().toLocaleTimeString()}] Scaffolding ${this.portPlanData?.modules?.length || 0} Modules and ${this.portPlanData?.summary?.total_canonical_slices || 0} Canonical Slices...`
            ];

            let planPayload = this.portPlanData;
            try {
                if (this.portPlanJsonText) {
                    planPayload = JSON.parse(this.portPlanJsonText);
                }
            } catch (e) {}

            fetch('/laraslice/wizard/port-execute', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    plan: planPayload,
                    source: this.portSourcePath,
                    domain: this.portDomain,
                    import_data: this.portImportData,
                    with_files: this.portWithFiles
                })
            })
            .then(async (r) => {
                const text = await r.text();
                let res;
                try {
                    res = JSON.parse(text);
                } catch (parseErr) {
                    throw new Error(`Server returned non-JSON response (${r.status} ${r.statusText}): ${text.substring(0, 250)}`);
                }
                if (!r.ok && !res.message) {
                    throw new Error(`Server error ${r.status}: ${text.substring(0, 250)}`);
                }
                return res;
            })
            .then(res => {
                this.portExecuting = false;
                if (res.success) {
                    this.portExecutionLogs.push(`[${new Date().toLocaleTimeString()}] ✓ Slices and Child Tables successfully created.`);
                    this.portExecutionLogs.push(`[${new Date().toLocaleTimeString()}] ✓ Database migrations executed.`);
                    if (res.result?.data_import) {
                        const imp = res.result.data_import;
                        this.portExecutionLogs.push(`[${new Date().toLocaleTimeString()}] ✓ ETL Data Stream: ${imp.total_rows_imported || 0} records streamed across ${imp.tables_imported || 0} tables.`);
                    }
                    this.portExecutionLogs.push(`[${new Date().toLocaleTimeString()}] 🚀 100% Usable Porting Completed Successfully!`);
                    this.loadSlices();
                    setTimeout(() => {
                        this.showToast('Porting completed successfully! Slices loaded into Studio.');
                    }, 1000);
                } else {
                    this.portExecutionLogs.push(`[${new Date().toLocaleTimeString()}] ❌ Error: ${res.message || 'Execution failed'}`);
                    alert('Porting execution error: ' + (res.message || 'Unknown error'));
                }
            })
            .catch(e => {
                this.portExecuting = false;
                this.portExecutionLogs.push(`[${new Date().toLocaleTimeString()}] ❌ Network Error: ${e.message}`);
                alert('Port execution error: ' + e.message);
            });
        },

        convertModulesToStudioSlices(modules) {
            const out = [];
            if (!Array.isArray(modules)) return out;
            for (let m of modules) {
                const modName = m.name || 'Core';
                if (!Array.isArray(m.slices)) continue;
                for (let s of m.slices) {
                    const sName = s.name;
                    const sHandle = s.handle || this.toSnakeCase(sName);
                    const rootTable = s.root_table || this.toSnakeCase(sName);
                    const rootModelHandle = this.toSnakeCase(rootTable.replace(/s$/, ''));
                    
                    const models = [];
                    const relations = [];
                    
                    if (Array.isArray(s.child_tables)) {
                        for (let ct of s.child_tables) {
                            const cTable = typeof ct === 'string' ? ct : ct.table;
                            const cModel = (typeof ct === 'object' && ct.model) ? ct.model : this.toStudlyCase(cTable.replace(/s$/, ''));
                            const cHandle = this.toSnakeCase(cModel);
                            const fk = rootModelHandle + '_id';
                            models.push({
                                name: cModel,
                                handle: cHandle,
                                table: cTable,
                                root: false,
                                timestamps: true,
                                soft_deletes: false,
                                description: `Child entity of ${sName}`,
                                fields: [
                                    { handle: 'id', label: 'ID', type: 'integer', input_type: 'number', required: true, nullable: false, width: 25 },
                                    { handle: fk, label: this.toStudlyCase(fk), type: 'integer', input_type: 'select', required: true, nullable: false, width: 50 },
                                    { handle: 'name', label: 'Name', type: 'string', input_type: 'text', required: false, nullable: true, width: 50 }
                                ],
                                relations: [{ name: rootModelHandle, type: 'belongsTo', model: rootModelHandle, foreign_key: fk }]
                            });
                            relations.push({ type: 'hasMany', source: rootTable, target: cTable, source_model: rootModelHandle, target_model: cHandle, foreign_key: fk });
                        }
                    }
                    
                    models.unshift({
                        name: this.toStudlyCase(rootModelHandle),
                        handle: rootModelHandle,
                        table: rootTable,
                        root: true,
                        timestamps: true,
                        soft_deletes: true,
                        description: `Primary aggregate root for ${sName}`,
                        fields: [
                            { handle: 'id', label: 'ID', type: 'integer', input_type: 'number', required: true, nullable: false, width: 25 },
                            { handle: 'name', label: 'Name', type: 'string', input_type: 'text', required: true, nullable: false, width: 50 },
                            { handle: 'status', label: 'Status', type: 'string', input_type: 'select', required: false, nullable: true, width: 50 }
                        ],
                        relations: relations.map(r => ({ name: r.target, type: 'hasMany', model: r.target_model, foreign_key: r.foreign_key }))
                    });
                    
                    let uiConfig = null;
                    if (s.ui) {
                        uiConfig = JSON.parse(JSON.stringify(s.ui));
                    } else if (s.pages) {
                        let uiCols = [];
                        if (s.pages.index && Array.isArray(s.pages.index.columns)) {
                            uiCols = s.pages.index.columns.map(c => ({
                                handle: c.field || c.key,
                                label: c.label || this.toStudlyCase(c.field || c.key),
                                type: c.type || 'text',
                                formatter: c.type || 'text',
                                sortable: c.sortable !== false,
                                searchable: true
                            }));
                        }
                        let quickFilters = [];
                        if (s.pages.index?.filter_bar?.filters) {
                            quickFilters = s.pages.index.filter_bar.filters.map(f => ({
                                field: f.name || f.field || 'status',
                                handle: f.name || f.field || 'status',
                                label: f.label || 'Status',
                                type: 'select',
                                searchable: true,
                                is_searchable: true
                            }));
                        }
                        let sections = [];
                        if (s.pages.forms?.create?.sections) {
                            sections = s.pages.forms.create.sections.map(sec => ({
                                name: sec.name || 'General Information',
                                grid: sec.columns || 2,
                                columns: sec.columns || 2,
                                fields: (sec.fields || []).map(f => {
                                    const h = typeof f === 'string' ? f : (f.handle || f.name);
                                    return {
                                        handle: h,
                                        span: 1,
                                        searchable: (typeof f === 'object' && f.searchable !== undefined) ? f.searchable : (h.endsWith('_id') || h.includes('status'))
                                    };
                                })
                            }));
                        }
                        uiConfig = {
                            presentation: { create: 'drawer', edit: 'drawer' },
                            directory: {
                                presentation: 'table',
                                columns: uiCols.length ? uiCols : (models[0]?.fields?.map(f => ({ handle: f.handle, label: f.label, type: 'text', sortable: true })) || []),
                                quick_filters: quickFilters,
                                sidebar_filters: []
                            },
                            forms: {
                                mode: s.pages.forms?.presentation || 'drawer',
                                sections: sections
                            }
                        };
                    }

                    const bp = {
                        schema_version: 1,
                        name: sName,
                        handle: sHandle,
                        domain: this.portDomain || 'ERP',
                        module: modName,
                        description: `Canonical ${sName} slice in ${modName}`,
                        permissions: s.permissions || [`${sHandle}.view`, `${sHandle}.create`, `${sHandle}.edit`, `${sHandle}.delete`],
                        workflow: s.workflow || null,
                        roles: [],
                        navigation: {
                            label: sName,
                            icon: s.icon || 'briefcase',
                            group: modName,
                            route: `erp/${this.toSnakeCase(modName)}/${sHandle}`
                        },
                        ui: uiConfig,
                        pages: s.pages || null,
                        models: models
                    };
                    
                    out.push({
                        name: sName,
                        handle: sHandle,
                        domain: this.portDomain || 'ERP',
                        module: modName,
                        blueprint: bp,
                        allRelations: relations,
                        source: ''
                    });
                }
            }
            return out;
        },

        loadPortedToStudio() {
            let targetSlices = null;
            if (this.portPlanData && Array.isArray(this.portPlanData.studio_slices) && this.portPlanData.studio_slices.length > 0) {
                targetSlices = this.portPlanData.studio_slices;
            } else if (this.portInspectionData && Array.isArray(this.portInspectionData.studio_slices) && this.portInspectionData.studio_slices.length > 0) {
                targetSlices = this.portInspectionData.studio_slices;
            } else if (this.portPlanData && Array.isArray(this.portPlanData.modules)) {
                targetSlices = this.convertModulesToStudioSlices(this.portPlanData.modules);
            }

            if (!targetSlices || !targetSlices.length) {
                alert('Please inspect the project schema or load an architecture plan first.');
                return;
            }

            try {
                sessionStorage.setItem('laraslice_studio_slices', JSON.stringify(targetSlices));
                sessionStorage.setItem('laraslice_active_slice_idx', '0');
            } catch(e) {}

            window.location.href = "{{ route('laraslice.wizard.blueprint') }}?load_port=1";
        }
    };
}
</script>

<div id="laraslice-wizard-root" class="laraslice-studio-root space-y-6" x-data="larasliceWizard()">
        <!-- Wizard Mode Grid -->
        <div class="grid grid-cols-1 md:grid-cols-12 gap-6" x-show="mainTab === 'wizard'">
            <!-- Mobile progress (compact) -->
            <div class="col-span-12 md:hidden flex items-center gap-2 px-1">
                <template x-for="n in [1,2,3,4,5]" :key="n">
                    <button type="button" @click="(n === 1 || projectName) && (step = n)"
                            class="flex-1 h-1.5 rounded-full transition"
                            :class="step >= n ? 'bg-primary' : 'bg-muted'"
                            :aria-label="'Step ' + n"></button>
                </template>
                <span class="text-[10px] font-mono text-muted-foreground shrink-0" x-text="step + '/5'"></span>
            </div>
            <!-- Left Steps Sidebar (hidden on small screens — progress bar replaces it) -->
            <div class="hidden md:block col-span-12 md:col-span-5 lg:col-span-4 space-y-6">
                <div class="bg-card border border-border rounded-2xl p-6 space-y-4 shadow-xl">
                    <!-- Step 1 Indicator -->
                    <div class="flex items-center space-x-4 cursor-pointer" @click="step = 1">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold transition"
                             :class="step >= 1 ? 'bg-primary text-white shadow-lg shadow-amber-500/30' : 'bg-gray-800 text-muted-foreground'">
                            1
                        </div>
                        <div>
                            <p class="text-sm font-semibold" :class="step === 1 ? 'text-foreground' : 'text-muted-foreground'">Project Information</p>
                            <p class="text-xs text-muted-foreground">Basic project details</p>
                        </div>
                    </div>

                    <!-- Step 2 Indicator -->
                    <div class="flex items-center space-x-4" :class="projectName ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed'" @click="projectName && (step = 2)">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold transition"
                             :class="step >= 2 ? 'bg-primary text-white shadow-lg shadow-amber-500/30' : 'bg-gray-800 text-muted-foreground'">
                            2
                        </div>
                        <div>
                            <p class="text-sm font-semibold" :class="step === 2 ? 'text-foreground' : 'text-muted-foreground'">Platform & Architecture</p>
                            <p class="text-xs text-muted-foreground">UI, client & data fields</p>
                        </div>
                    </div>

                    <!-- Step 3 Indicator -->
                    <div class="flex items-center space-x-4" :class="projectName ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed'" @click="projectName && (step = 3)">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold transition"
                             :class="step >= 3 ? 'bg-primary text-white shadow-lg shadow-amber-500/30' : 'bg-gray-800 text-muted-foreground'">
                            3
                        </div>
                        <div>
                            <p class="text-sm font-semibold" :class="step === 3 ? 'text-foreground' : 'text-muted-foreground'">Workflow & Roles Studio</p>
                            <p class="text-xs text-muted-foreground">Stages, gates & scoped roles</p>
                        </div>
                    </div>

                    <!-- Step 4 Indicator -->
                    <div class="flex items-center space-x-4" :class="projectName ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed'" @click="projectName && (step = 4)">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold transition"
                             :class="step >= 4 ? 'bg-primary text-white shadow-lg shadow-amber-500/30' : 'bg-gray-800 text-muted-foreground'">
                            4
                        </div>
                        <div>
                            <p class="text-sm font-semibold" :class="step === 4 ? 'text-foreground' : 'text-muted-foreground'">Access Control & RBAC</p>
                            <p class="text-xs text-muted-foreground">Capabilities & security</p>
                        </div>
                    </div>

                    <!-- Step 5 Indicator -->
                    <div class="flex items-center space-x-4" :class="projectName ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed'" @click="projectName && (step = 5)">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold transition"
                             :class="step >= 5 ? 'bg-primary text-white shadow-lg shadow-amber-500/30' : 'bg-gray-800 text-muted-foreground'">
                            5
                        </div>
                        <div>
                            <p class="text-sm font-semibold" :class="step === 5 ? 'text-foreground' : 'text-muted-foreground'">Review & Generate</p>
                            <p class="text-xs text-muted-foreground">Scaffold solution</p>
                        </div>
                    </div>
                </div>

                <!-- Quick Summary Card (shown from step 3 — progressive disclosure) -->
                <div x-show="step >= 3" x-cloak class="bg-card border border-border rounded-2xl p-6 space-y-3 shadow-xl text-xs">
                    <p class="font-bold text-muted-foreground uppercase tracking-wider text-[10px]">Your Project Summary</p>
                    <div class="flex justify-between py-1 border-b border-border/60">
                        <span class="text-muted-foreground">Name:</span>
                        <span class="text-primary font-bold" x-text="projectName || 'Not set'"></span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-border/60">
                        <span class="text-muted-foreground">Backend:</span>
                        <span class="text-foreground">Laravel 13+</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-border/60">
                        <span class="text-muted-foreground">Web UI:</span>
                        <span class="text-foreground font-bold" x-text="uiFramework.toUpperCase()"></span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-border/60">
                        <span class="text-muted-foreground">Mobile Client:</span>
                        <span class="text-cyan-400 font-bold" x-text="includeFlutter ? 'Flutter (AOT)' : 'Disabled'"></span>
                    </div>
                    <div class="flex justify-between py-1">
                        <span class="text-muted-foreground">Workflow:</span>
                        <span class="text-green-400 font-bold" x-text="includeWorkflow ? ((workflowConfig?.states?.length || 0) + ' States, ' + selectedRolesForGeneration().length + ' Roles') : 'Standard CRUD'"></span>
                    </div>
                </div>
            </div>

            <!-- Right Wizard Forms -->
            <div class="col-span-12 md:col-span-7 lg:col-span-8 min-w-0">
                <div class="bg-card border border-border rounded-2xl p-8 shadow-xl">
                    <!-- Step 1: Project Information -->
                    <div x-show="step === 1" class="space-y-6">
                        <div class="border-b border-border pb-4">
                            <h2 class="text-xl font-bold text-foreground flex items-center gap-2">
                                <span class="text-primary">📦</span> Project & Entity Information
                            </h2>
                            <p class="text-xs text-muted-foreground mt-1">Configure your vertical slice core identity</p>
                        </div>

                                                <!-- Legacy Reverse-Engineering Callout Banner -->
                        <div class="rounded-2xl border border-indigo-500/30 bg-gradient-to-r from-indigo-500/10 via-purple-500/10 to-transparent p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-sm">
                            <div class="flex items-center gap-3">
                                <span class="text-3xl">🚀</span>
                                <div>
                                    <h4 class="text-xs font-bold text-foreground">Reverse-Engineer & Port Legacy Laravel Monolith</h4>
                                    <p class="text-[11px] text-muted-foreground mt-0.5">Auto-classify legacy database tables into Bounded Domains, generate zero-blank-dropdown seeders (system-base) or perform full historical migration.</p>
                                </div>
                            </div>
                            <a href="{{ route('laraslice.wizard.blueprint') }}?port=1" class="shrink-0 inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-3.5 py-2 text-xs font-bold text-white hover:bg-indigo-500 transition shadow-xs">
                                <span>Launch Porting Wizard</span>
                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </a>
                        </div>

                        <!-- Blueprint Starter Templates & Categories -->
                        <div class="space-y-3.5">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-border/80 pb-2.5">
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground">Select Blueprint Starter Template</label>
                                    <p class="text-[11px] text-muted-foreground">Choose between full role-governed enterprise pipelines or clean transactional CRUD entities.</p>
                                </div>
                                <!-- Segmented Category Tabs -->
                                <div class="inline-flex rounded-xl p-1 bg-muted/60 border border-border shrink-0">
                                    <button type="button" @click="presetCategory = 'enterprise'"
                                            :class="presetCategory === 'enterprise' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                                            class="px-3 py-1.5 rounded-lg text-xs transition cursor-pointer flex items-center gap-1.5">
                                        <span>⚡ Enterprise Workflows</span>
                                        <span class="text-[10px] px-1.5 py-0.2 rounded-full" :class="presetCategory === 'enterprise' ? 'bg-white/20' : 'bg-primary/10 text-primary'">4 Models</span>
                                    </button>
                                    <button type="button" @click="presetCategory = 'crud'"
                                            :class="presetCategory === 'crud' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                                            class="px-3 py-1.5 rounded-lg text-xs transition cursor-pointer flex items-center gap-1.5">
                                        <span>📦 Standard CRUD</span>
                                        <span class="text-[10px] px-1.5 py-0.2 rounded-full" :class="presetCategory === 'crud' ? 'bg-white/20' : 'bg-primary/10 text-primary'">3 Models</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Category B: Full Enterprise Workflows Grid -->
                            <div x-show="presetCategory === 'enterprise'" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2.5">
                                <button type="button" @click="applyPreset('legal_dispute_adjudication')" class="p-3.5 rounded-xl border border-indigo-500/40 bg-indigo-500/5 hover:bg-indigo-500/15 text-left transition cursor-pointer group flex flex-col justify-between">
                                    <div>
                                        <div class="flex items-center justify-between mb-1.5">
                                            <span class="text-xl">⚖️</span>
                                            <span class="text-[9px] font-bold uppercase px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-400">11 States · 5 Roles · 6 Slices</span>
                                        </div>
                                        <div class="text-xs font-bold text-foreground group-hover:text-indigo-400">Dispute Adjudication Suite</div>
                                        <div class="text-[10px] text-muted-foreground mt-1 line-clamp-2">Scrutiny → Hearings → Evidence → Speaking Order</div>
                                    </div>
                                    <div class="text-[10px] font-semibold text-indigo-400 pt-2 mt-2 border-t border-border/40">Includes Judicial Roles & Summons</div>
                                </button>

                                <button type="button" @click="applyPreset('grant_subsidy_pipeline')" class="p-3.5 rounded-xl border border-emerald-500/40 bg-emerald-500/5 hover:bg-emerald-500/15 text-left transition cursor-pointer group flex flex-col justify-between">
                                    <div>
                                        <div class="flex items-center justify-between mb-1.5">
                                            <span class="text-xl">💰</span>
                                            <span class="text-[9px] font-bold uppercase px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-400">6 States · 5 Roles</span>
                                        </div>
                                        <div class="text-xs font-bold text-foreground group-hover:text-emerald-400">Grant & Capital Disbursement</div>
                                        <div class="text-[10px] text-muted-foreground mt-1 line-clamp-2">Intake → Scrutiny → Committee Sanction → Pre-Audit → Bank Release</div>
                                    </div>
                                    <div class="text-[10px] font-semibold text-emerald-400 pt-2 mt-2 border-t border-border/40">Includes Committee & Audit Roles</div>
                                </button>

                                <button type="button" @click="applyPreset('executive_procurement_approval')" class="p-3.5 rounded-xl border border-amber-500/40 bg-amber-500/5 hover:bg-amber-500/15 text-left transition cursor-pointer group flex flex-col justify-between">
                                    <div>
                                        <div class="flex items-center justify-between mb-1.5">
                                            <span class="text-xl">📋</span>
                                            <span class="text-[9px] font-bold uppercase px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-400">6 States · 4 Roles</span>
                                        </div>
                                        <div class="text-xs font-bold text-foreground group-hover:text-amber-400">Executive Requisition Approval</div>
                                        <div class="text-[10px] text-muted-foreground mt-1 line-clamp-2">Draft → Scrutiny → Board Sanction → PO Generation</div>
                                    </div>
                                    <div class="text-[10px] font-semibold text-amber-400 pt-2 mt-2 border-t border-border/40">Includes Executive Signoff Gates</div>
                                </button>

                                <button type="button" @click="applyPreset('support_service_ticket')" class="p-3.5 rounded-xl border border-purple-500/40 bg-purple-500/5 hover:bg-purple-500/15 text-left transition cursor-pointer group flex flex-col justify-between">
                                    <div>
                                        <div class="flex items-center justify-between mb-1.5">
                                            <span class="text-xl">🎫</span>
                                            <span class="text-[9px] font-bold uppercase px-2 py-0.5 rounded-full bg-purple-500/20 text-purple-400">6 States · 3 Roles</span>
                                        </div>
                                        <div class="text-xs font-bold text-foreground group-hover:text-purple-400">Support Ticket & Escalation</div>
                                        <div class="text-[10px] text-muted-foreground mt-1 line-clamp-2">Triage → In-Progress → Engineering Bug Escalation → Resolved</div>
                                    </div>
                                    <div class="text-[10px] font-semibold text-purple-400 pt-2 mt-2 border-t border-border/40">Includes SLA Timers & Triage Roles</div>
                                </button>
                            </div>

                            <!-- Category A: Standard CRUD Blueprints Grid -->
                            <div x-show="presetCategory === 'crud'" class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                                <button type="button" @click="applyPreset('asset_inventory')" class="p-3.5 rounded-xl border border-cyan-500/40 bg-cyan-500/5 hover:bg-cyan-500/15 text-left transition cursor-pointer group flex flex-col justify-between">
                                    <div>
                                        <div class="flex items-center justify-between mb-1.5">
                                            <span class="text-xl">💻</span>
                                            <span class="text-[9px] font-bold uppercase px-2 py-0.5 rounded-full bg-cyan-500/20 text-cyan-400">Single Slice · 7 Fields</span>
                                        </div>
                                        <div class="text-xs font-bold text-foreground group-hover:text-cyan-400">Asset & Equipment Inventory</div>
                                        <div class="text-[10px] text-muted-foreground mt-1">Hardware asset tags, serial numbers, allocation state, and maintenance logs</div>
                                    </div>
                                    <div class="text-[10px] font-semibold text-cyan-400 pt-2 mt-2 border-t border-border/40">Pure CRUD · Workflow Optional</div>
                                </button>

                                <button type="button" @click="applyPreset('crm_suite')" class="p-3.5 rounded-xl border border-blue-500/40 bg-blue-500/5 hover:bg-blue-500/15 text-left transition cursor-pointer group flex flex-col justify-between">
                                    <div>
                                        <div class="flex items-center justify-between mb-1.5">
                                            <span class="text-xl">💼</span>
                                            <span class="text-[9px] font-bold uppercase px-2 py-0.5 rounded-full bg-blue-500/20 text-blue-400">3 Slices Suite</span>
                                        </div>
                                        <div class="text-xs font-bold text-foreground group-hover:text-blue-400">CRM Suite (Companies, Contacts, Deals)</div>
                                        <div class="text-[10px] text-muted-foreground mt-1">B2B accounts, people contacts, deal pipeline stages</div>
                                    </div>
                                    <div class="text-[10px] font-semibold text-blue-400 pt-2 mt-2 border-t border-border/40">Multi-Slice Domain Suite</div>
                                </button>

                                <button type="button" @click="applyPreset('ecommerce_suite')" class="p-3.5 rounded-xl border border-amber-500/40 bg-amber-500/5 hover:bg-amber-500/15 text-left transition cursor-pointer group flex flex-col justify-between">
                                    <div>
                                        <div class="flex items-center justify-between mb-1.5">
                                            <span class="text-xl">🛒</span>
                                            <span class="text-[9px] font-bold uppercase px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-400">4 Slices Suite</span>
                                        </div>
                                        <div class="text-xs font-bold text-foreground group-hover:text-amber-400">E-Commerce Suite (Catalog & Orders)</div>
                                        <div class="text-[10px] text-muted-foreground mt-1">Products, variants, customer orders, and store inventory</div>
                                    </div>
                                    <div class="text-[10px] font-semibold text-amber-400 pt-2 mt-2 border-t border-border/40">Multi-Slice Domain Suite</div>
                                </button>
                            </div>

                            <!-- Dedicated Toggleable Workflow Pipeline Checkbox Card -->
                            <div class="p-3.5 rounded-xl border border-border bg-card flex items-center justify-between gap-3">
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input type="checkbox" x-model="includeWorkflow" @change="toggleWorkflow(includeWorkflow)" class="size-4.5 rounded border-input text-primary focus:ring-primary">
                                    <div>
                                        <span class="text-xs font-bold text-foreground flex items-center gap-2">
                                            <span>Enable Workflow Pipeline & State Machine Engine</span>
                                            <template x-if="includeWorkflow">
                                                <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-500 border border-emerald-500/30">Active</span>
                                            </template>
                                        </span>
                                        <p class="text-[10px] text-muted-foreground mt-0.5">Scaffolds stage transitions, forwarding columns, and audit history. You can configure stages & roles in Step 3.</p>
                                    </div>
                                </label>
                                <template x-if="includeWorkflow && workflowConfig?.states">
                                    <div class="text-right shrink-0">
                                        <span class="text-xs font-bold font-mono text-primary" x-text="workflowConfig.states.length + ' States'"></span>
                                        <span class="text-[10px] text-muted-foreground block" x-text="(selectedRolesForGeneration()?.length ?? 0) + ' Roles pre-mapped'"></span>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">Feature / Slice Name(s) *</label>
                                <input type="text" x-model="projectName" @input="refreshRbacList()" placeholder="e.g., Invoice or Companies, Contacts, Deals"
                                       class="w-full px-4 py-3 bg-muted/30 border border-border rounded-xl text-foreground text-sm focus:border-primary focus:outline-none">
                                <p class="text-[10px] text-muted-foreground mt-1">Separate with commas to batch scaffold multi-slice domain suites in 1-click.</p>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">Domain / Group</label>
                                <input type="text" x-model="domain" placeholder="e.g., CRM, E-Commerce, Operations"
                                       class="w-full px-4 py-3 bg-muted/30 border border-border rounded-xl text-foreground text-sm focus:border-primary focus:outline-none">
                                <p class="text-[10px] text-muted-foreground mt-1">Groups slices in sidebar, routes & namespace.</p>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">Root Namespace *</label>
                                <input type="text" x-model="namespace" placeholder="App\Slices" readonly aria-describedby="slice-namespace-help"
                                       class="w-full px-4 py-3 bg-muted/30 border border-border rounded-xl text-foreground text-sm opacity-80">
                                <p class="text-[10px] text-muted-foreground mt-1" x-text="'App\\Slices\\' + (domain ? domain.replace(/[^A-Za-z0-9]/g, '') + '\\...' : '...')"></p>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">Description</label>
                            <textarea x-model="description" rows="3" placeholder="Brief description of this feature slice..."
                                      class="w-full px-4 py-3 bg-muted/30 border border-border rounded-xl text-foreground text-sm focus:border-primary focus:outline-none"></textarea>
                        </div>

                        <div class="flex justify-end pt-4">
                            <button @click="if(projectName) step = 2; else alert('Please enter a Project Name')"
                                    class="px-6 py-3 bg-primary hover:bg-primary/90 text-white font-bold rounded-xl text-sm transition flex items-center gap-2 cursor-pointer">
                                Next Step →
                            </button>
                        </div>
                    </div>

                    <!-- Step 2: Platform & Architecture -->
                    <div x-show="step === 2" class="space-y-6">
                        <div class="border-b border-border pb-4">
                            <h2 class="text-xl font-bold text-foreground flex items-center gap-2">
                                <span class="text-primary">🏗️</span> Platform & Architecture
                            </h2>
                            <p class="text-xs text-muted-foreground mt-1">Select frontend component library and cross-platform targets</p>
                        </div>

                        <!-- UI Framework Choice (Locked to BlatUI Standard) -->
                        <div class="space-y-2">
                            <label class="block text-xs font-semibold uppercase tracking-wider text-muted-foreground">UI Framework</label>
                            <div class="border border-primary/40 bg-primary/10 p-4 rounded-xl flex items-center justify-between">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <p class="font-bold text-sm text-foreground">BlatUI (Core Standard)</p>
                                        <span class="rounded-full bg-primary/20 text-primary px-2 py-0.5 text-[10px] font-semibold">100% Enforced</span>
                                    </div>
                                    <p class="text-xs text-muted-foreground">Modern shadcn/ui architecture for Blade, Tailwind CSS v4, Alpine.js & Lucide icons</p>
                                </div>
                                <div class="text-primary text-xl">✨</div>
                            </div>
                        </div>

                        <!-- Flutter Cross-Platform Target -->
                        <div class="border border-cyan-500/20 bg-cyan-500/5 p-5 rounded-xl space-y-2">
                            <label class="flex items-center space-x-3 cursor-pointer">
                                <input type="checkbox" x-model="includeFlutter" class="w-5 h-5 rounded border-border text-cyan-500 focus:ring-cyan-500">
                                <div>
                                    <span class="font-bold text-sm text-cyan-300">Generate Flutter Mobile Client Slice</span>
                                    <p class="text-xs text-muted-foreground">Scaffold Dart models, typed HTTP clients, and native Flutter screens</p>
                                </div>
                            </label>
                        </div>

                        <!-- Domain Suite Multi-Slice Tabs (When slices are configured) -->
                        <div x-show="slicesList().length > 0" class="rounded-xl border border-primary/30 bg-primary/5 p-4 space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold text-primary uppercase tracking-wider">📦 Domain Suite Slices</span>
                                    <span class="rounded-full bg-primary/20 px-2 py-0.5 text-[10px] font-bold text-primary" x-text="slicesList().length + (slicesList().length === 1 ? ' Slice' : ' Slices') + ' in [' + (domain || 'General') + ']'"></span>
                                </div>
                                <p class="text-[11px] text-muted-foreground">Select a slice below to configure its independent fields & tables, or add more slices:</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <template x-for="(sName, sIdx) in slicesList()" :key="sIdx">
                                    <div class="inline-flex items-center rounded-lg border transition shadow-xs"
                                         :class="activeSliceIdx === sIdx ? 'bg-primary border-primary text-primary-foreground font-bold ring-2 ring-primary/40' : 'bg-card border-border text-foreground hover:bg-muted'">
                                        <button type="button" @click="switchSliceTab(sIdx)"
                                                class="px-3.5 py-2 text-xs flex items-center gap-2 cursor-pointer">
                                            <span class="size-2 rounded-full" :class="activeSliceIdx === sIdx ? 'bg-white' : 'bg-primary'"></span>
                                            <span x-text="sName"></span>
                                            <span class="rounded-md bg-black/10 dark:bg-white/10 px-1.5 py-0.5 text-[10px]" x-text="getSliceFieldsCount(sName) + ' fields'"></span>
                                        </button>
                                        <template x-if="slicesList().length > 1">
                                            <button type="button" @click.stop="removeSlice(sIdx)" class="pr-2 pl-0.5 py-2 text-xs opacity-60 hover:opacity-100 hover:text-destructive cursor-pointer" title="Remove slice">
                                                ×
                                            </button>
                                        </template>
                                    </div>
                                </template>
                                <div x-data="{ adding: false, newSliceNameInput: '' }" class="inline-flex items-center">
                                    <template x-if="!adding">
                                        <button type="button" @click="adding = true; $nextTick(() => $refs.wizardNewSliceInput?.focus())" class="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-primary/50 bg-primary/10 hover:bg-primary/20 text-primary px-3 py-2 text-xs font-bold transition shadow-xs cursor-pointer" title="Add another slice to this domain suite">
                                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                            <span>+ Add More Slice</span>
                                        </button>
                                    </template>
                                    <template x-if="adding">
                                        <div class="inline-flex items-center gap-1.5 rounded-lg border border-primary/60 bg-card p-1 shadow-sm">
                                            <input x-ref="wizardNewSliceInput" type="text" x-model="newSliceNameInput" @keydown.enter.prevent="if (newSliceNameInput.trim()) { addNamedSlice(newSliceNameInput); newSliceNameInput = ''; adding = false; }" @keydown.escape="adding = false" placeholder="Slice name (e.g. Invoices)..." class="h-8 w-44 rounded-md border border-input bg-background px-2.5 text-xs text-foreground focus:ring-1 focus:ring-primary focus:outline-none">
                                            <button type="button" @click="if (newSliceNameInput.trim()) { addNamedSlice(newSliceNameInput); newSliceNameInput = ''; adding = false; }" class="rounded-md bg-primary px-3 py-1.5 text-xs font-bold text-primary-foreground hover:bg-primary/90 cursor-pointer">Add</button>
                                            <button type="button" @click="adding = false" class="rounded px-2 py-1.5 text-xs text-muted-foreground hover:text-foreground cursor-pointer">✕</button>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <section class="rounded-xl border border-border bg-card p-4 space-y-4" aria-labelledby="slice-fields-heading">
                            <!-- High-Density Header Bar (Blueprint Studio Style) -->
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border/70 pb-3">
                                <div class="flex items-center gap-2">
                                    <span class="rounded-full bg-primary/10 px-2.5 py-1 text-xs font-bold text-primary" x-text="'🧬 FIELDS ' + currentFieldsList().length"></span>
                                    <span class="rounded-md bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground" x-text="wizardTargetTable === 'root' ? 'Aggregate Root Table' : 'Child Entity (' + (childTables[wizardTargetTable]?.relation || 'hasMany') + ')'"></span>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <!-- Target Table Dropdown -->
                                    <div class="flex items-center gap-1.5 text-xs bg-muted/30 border border-border rounded-lg px-2.5 py-1">
                                        <span class="text-muted-foreground font-medium">🎯 Target Table:</span>
                                        <select x-model="wizardTargetTable" class="bg-transparent text-xs font-semibold text-foreground focus:outline-none cursor-pointer">
                                            <option value="root" x-text="activeSliceName().toLowerCase().replace(/[^a-z0-9_]/g, '_') + 's (Primary Root)'"></option>
                                            <template x-for="(ct, cIdx) in childTables" :key="cIdx">
                                                <option :value="cIdx" x-text="ct.name + ' (' + ct.relation + ')'"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <button type="button" @click="addWizardField()" class="inline-flex items-center gap-1 rounded-lg bg-primary/10 hover:bg-primary/20 text-primary border border-primary/30 px-3 py-1.5 text-xs font-bold transition shadow-xs cursor-pointer">
                                        <span>+</span> Add Field
                                    </button>
                                    <button type="button" @click="showAddChildModal = true" class="inline-flex items-center gap-1 rounded-lg bg-secondary/80 hover:bg-secondary text-secondary-foreground border border-border px-3 py-1.5 text-xs font-semibold transition shadow-xs cursor-pointer">
                                        <svg class="inline-block size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="16" y="16" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="9" y="2" width="6" height="6" rx="1"/><path d="M5 16v-3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3"/><path d="M12 12V8"/></svg>
                                        <span>+ Add Child Table</span>
                                    </button>
                                    <template x-if="wizardTargetTable !== 'root'">
                                        <button type="button" @click="removeChildTable(wizardTargetTable)" class="inline-flex items-center gap-1 rounded-lg bg-destructive/10 hover:bg-destructive/20 text-destructive border border-destructive/30 px-2.5 py-1.5 text-xs font-semibold transition cursor-pointer" title="Remove this child table">
                                            <svg class="inline-block size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                            <span>Delete Table</span>
                                        </button>
                                    </template>
                                </div>
                            </div>

                            <template x-if="currentFieldsList().length === 0">
                                <p class="rounded-lg border border-dashed border-border p-6 text-center text-xs text-muted-foreground">No fields configured yet. Click "+ Add Field" above to add columns to this table.</p>
                            </template>

                            <!-- High-Density Data Grid Table (Blueprint Studio Image 3 Style) -->
                            <div x-show="currentFieldsList().length > 0" class="overflow-x-auto rounded-xl border border-border">
                                <table class="w-full min-w-[1080px] text-left text-[13px]">
                                    <thead class="bg-muted/50 text-[10px] font-bold uppercase tracking-wider text-muted-foreground border-b border-border">
                                        <tr>
                                            <th class="w-10 px-2 py-2.5 text-center">#</th>
                                            <th class="w-44 px-2.5 py-2.5">Field Handle (Column)</th>
                                            <th class="w-44 px-2.5 py-2.5">Display Label</th>
                                            <th class="w-36 px-2.5 py-2.5">Data Type</th>
                                            <th class="w-32 px-2 py-2.5 text-center">Width</th>
                                            <th class="w-12 px-1.5 py-2.5 text-center">Req</th>
                                            <th class="w-12 px-1.5 py-2.5 text-center">Null</th>
                                            <th class="w-28 px-2 py-2.5">Length / Options</th>
                                            <th class="w-28 px-2 py-2.5">Default Value</th>
                                            <th class="w-10 px-2 py-2.5 text-right"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border/60 bg-card">
                                        <template x-for="(field, fIdx) in currentFieldsList()" :key="fIdx">
                                            <tr class="hover:bg-muted/20 transition-colors">
                                                <!-- Reorder Arrows -->
                                                <td class="px-1.5 py-2 text-center text-muted-foreground font-mono">
                                                    <div class="flex items-center justify-center gap-0.5">
                                                        <button type="button" @click="moveWizardField(fIdx, -1)" :disabled="fIdx === 0" title="Move Up" class="p-0.5 rounded text-muted-foreground hover:text-foreground disabled:opacity-20 transition cursor-pointer">↑</button>
                                                        <button type="button" @click="moveWizardField(fIdx, 1)" :disabled="fIdx === currentFieldsList().length - 1" title="Move Down" class="p-0.5 rounded text-muted-foreground hover:text-foreground disabled:opacity-20 transition cursor-pointer">↓</button>
                                                    </div>
                                                </td>
                                                <!-- Field Handle -->
                                                <td class="px-2.5 py-2">
                                                    <input type="text" x-model="field.name" placeholder="e.g. title, sku" class="h-8 w-full rounded border border-transparent hover:border-input focus:border-input bg-transparent px-1.5 font-mono text-[13px] font-semibold text-foreground focus:bg-background focus:outline-none">
                                                </td>
                                                <!-- Display Label -->
                                                <td class="px-2.5 py-2">
                                                    <input type="text" x-model="field.label" placeholder="Display Label" class="h-8 w-full rounded border border-transparent hover:border-input focus:border-input bg-transparent px-1.5 text-[13px] text-foreground focus:bg-background focus:outline-none">
                                                </td>
                                                <!-- Data Type -->
                                                <td class="px-2.5 py-2">
                                                    <select x-model="field.type" class="h-8 w-full rounded border border-input bg-card px-2 text-[13px] font-medium text-foreground focus:ring-1 focus:ring-primary">
                                                        <option value="string">string (varchar)</option>
                                                        <option value="text">text (longtext)</option>
                                                        <option value="integer">integer</option>
                                                        <option value="bigInteger">bigInteger</option>
                                                        <option value="decimal">decimal</option>
                                                        <option value="float">float</option>
                                                        <option value="boolean">boolean</option>
                                                        <option value="date">date</option>
                                                        <option value="datetime">datetime</option>
                                                        <option value="email">email</option>
                                                        <option value="foreign_id">foreign_id (FK)</option>
                                                        <option value="select">select (options)</option>
                                                    </select>
                                                </td>
                                                <!-- Statamic Width Pills -->
                                                <td class="px-2 py-2 text-center">
                                                    <div class="inline-flex rounded-md border border-input bg-card p-0.5 text-[10px] font-mono">
                                                        <button type="button" @click="field.width = '33%'" :class="field.width === '33%' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-1.5 py-0.5 rounded transition cursor-pointer">33%</button>
                                                        <button type="button" @click="field.width = '50%'" :class="field.width === '50%' || !field.width ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-1.5 py-0.5 rounded transition cursor-pointer">50%</button>
                                                        <button type="button" @click="field.width = '100%'" :class="field.width === '100%' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-1.5 py-0.5 rounded transition cursor-pointer">100%</button>
                                                    </div>
                                                </td>
                                                <!-- Req Checkbox -->
                                                <td class="px-1.5 py-2 text-center">
                                                    <input type="checkbox" x-model="field.required" @change="if(field.required) field.nullable = false" class="size-4 rounded border-input text-primary">
                                                </td>
                                                <!-- Null Checkbox -->
                                                <td class="px-1.5 py-2 text-center">
                                                    <input type="checkbox" x-model="field.nullable" @change="if(field.nullable) field.required = false" class="size-4 rounded border-input text-primary">
                                                </td>
                                                <!-- Length / Options -->
                                                <td class="px-2 py-2">
                                                    <input type="text" x-model="field.optionsText" placeholder="255 or opt1,opt2" class="h-7 w-full rounded border border-transparent hover:border-input focus:border-input bg-transparent px-1.5 font-mono text-[11px] text-muted-foreground focus:bg-background focus:text-foreground focus:outline-none">
                                                </td>
                                                <!-- Default Value -->
                                                <td class="px-2 py-2">
                                                    <input type="text" x-model="field.default" placeholder="NULL" class="h-7 w-full rounded border border-transparent hover:border-input focus:border-input bg-transparent px-1.5 font-mono text-[11px] text-muted-foreground focus:bg-background focus:text-foreground focus:outline-none">
                                                </td>
                                                <!-- Delete Action -->
                                                <td class="px-2 py-2 text-right">
                                                    <button type="button" @click="removeWizardField(fIdx)" class="p-1 rounded text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition cursor-pointer" title="Delete Field">
                                                        <svg class="inline-block size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                                    </button>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Add Child Table Modal -->
                            <div x-show="showAddChildModal" style="display: none;" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4">
                                <div class="bg-card border border-border rounded-2xl p-5 max-w-md w-full shadow-2xl space-y-4">
                                    <div class="flex items-center justify-between border-b border-border/70 pb-3">
                                        <div class="flex items-center gap-2">
                                            <svg class="inline-block size-4 text-primary" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="16" y="16" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="9" y="2" width="6" height="6" rx="1"/><path d="M5 16v-3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3"/><path d="M12 12V8"/></svg>
                                            <h3 class="font-bold text-sm text-foreground">Add Child Entity / Relationship</h3>
                                        </div>
                                        <button type="button" @click="showAddChildModal = false" class="text-muted-foreground hover:text-foreground">✕</button>
                                    </div>
                                    <div class="space-y-3">
                                        <div>
                                            <label class="block text-xs font-semibold text-muted-foreground mb-1">Child Table Name</label>
                                            <input type="text" x-model="newChildName" placeholder="e.g. product_variants, contacts, ticket_replies" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-xs font-mono text-foreground focus:ring-1 focus:ring-primary focus:outline-none">
                                            <p class="text-[10px] text-muted-foreground mt-1">Automatically linked to aggregate root via foreign key & Eloquent relation.</p>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-muted-foreground mb-1">Relationship Type</label>
                                            <select x-model="newChildRelation" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-xs text-foreground focus:ring-1 focus:ring-primary focus:outline-none">
                                                <option value="hasMany">hasMany (One to Many Aggregate - e.g. Variants, Items, Replies)</option>
                                                <option value="hasOne">hasOne (One to One Extension - e.g. Profile, Settings)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="flex justify-end gap-2 pt-2 border-t border-border/70">
                                        <button type="button" @click="showAddChildModal = false" class="px-3 py-1.5 text-xs text-muted-foreground hover:text-foreground">Cancel</button>
                                        <button type="button" @click="addChildTable()" class="px-4 py-1.5 rounded-lg bg-primary hover:bg-primary/90 text-primary-foreground text-xs font-bold transition">Add Child Table</button>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <div class="flex justify-between pt-4">
                            <button @click="step = 1" class="px-5 py-2.5 text-muted-foreground hover:text-foreground text-sm">← Previous</button>
                            <button @click="step = 3" class="px-6 py-3 bg-primary hover:bg-primary/90 text-white font-bold rounded-xl text-sm transition cursor-pointer">
                                Next Step →
                            </button>
                        </div>
                    </div>

                    <!-- Step 3: Workflow & Roles Studio -->
                    <div x-show="step === 3" class="space-y-6">
                        <div class="border-b border-border pb-4">
                            <h2 class="text-xl font-bold text-foreground flex items-center gap-2">
                                <span class="text-primary">⚙️</span> Workflow & Roles Studio
                            </h2>
                            <p class="text-xs text-muted-foreground mt-1">Configure lifecycle progression stages, turnaround SLA hours, and domain-scoped operational roles</p>
                        </div>

                        <!-- Workflow Master Toggle -->
                        <div class="space-y-3">
                            <label class="flex items-center space-x-3 cursor-pointer p-4 bg-card rounded-xl border border-border shadow-xs hover:border-primary/40 transition">
                                <input type="checkbox" x-model="includeWorkflow" class="w-4 h-4 rounded text-primary">
                                <div>
                                    <span class="font-bold text-sm text-foreground">Workflow & State Machine Engine</span>
                                    <p class="text-xs text-muted-foreground">Declarative state transitions, guards, desk forwarding, and SLA tracking</p>
                                </div>
                            </label>
                        </div>

                        <!-- Active Workflow Configuration Cards -->
                        <div x-show="includeWorkflow" x-transition class="space-y-5">
                            <!-- 1. Pipeline Stages & SLA Card -->
                            <div class="rounded-xl border border-border bg-card p-5 space-y-3 shadow-xs">
                                <div class="flex items-center justify-between border-b border-border/60 pb-3">
                                    <div class="flex items-center gap-2.5">
                                        <svg class="size-4 text-primary" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                        <h3 class="font-bold text-sm text-foreground">Workflow Pipeline Stages & SLA</h3>
                                        <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary" x-text="workflowConfig?.states?.length ?? 0"></span>
                                    </div>
                                    <button type="button" @click="showAddStateModal = true" class="h-8 px-3 rounded-lg bg-primary/10 text-primary hover:bg-primary hover:text-white transition text-xs font-semibold flex items-center gap-1.5 cursor-pointer">
                                        <span>+ Add Stage</span>
                                    </button>
                                </div>
                                <p class="text-xs text-muted-foreground">Lifecycle progression milestones. Each stage defines resolution SLA targets and whether it marks terminal completion.</p>

                                <!-- Stages Grid -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 pt-1">
                                    <template x-for="(st, sIdx) in (workflowConfig?.states ?? [])" :key="sIdx">
                                        <div class="flex items-center justify-between p-3 rounded-xl border border-border/80 bg-muted/20 hover:bg-muted/40 transition gap-2">
                                            <div class="flex items-center gap-2.5 min-w-0">
                                                <span class="size-2.5 rounded-full shrink-0"
                                                    :class="{
                                                        'bg-amber-500 shadow-xs shadow-amber-500/50': st.color === 'amber',
                                                        'bg-sky-500 shadow-xs shadow-sky-500/50': st.color === 'sky',
                                                        'bg-indigo-500 shadow-xs shadow-indigo-500/50': st.color === 'indigo',
                                                        'bg-purple-500 shadow-xs shadow-purple-500/50': st.color === 'purple',
                                                        'bg-emerald-500 shadow-xs shadow-emerald-500/50': st.color === 'emerald',
                                                        'bg-rose-500 shadow-xs shadow-rose-500/50': st.color === 'rose',
                                                        'bg-slate-400': !st.color || st.color === 'slate'
                                                    }"></span>
                                                <div class="min-w-0">
                                                    <div class="flex items-center gap-1.5 flex-wrap">
                                                        <span class="font-bold text-xs text-foreground truncate" x-text="st.label"></span>
                                                        <span x-show="st.terminal" class="px-1.5 py-0.2 rounded text-[9px] font-bold uppercase bg-destructive/15 text-destructive border border-destructive/20">Terminal</span>
                                                        <span x-show="st.initial" class="px-1.5 py-0.2 rounded text-[9px] font-bold uppercase bg-sky-500/15 text-sky-600 dark:text-sky-400 border border-sky-500/20">Initial</span>
                                                    </div>
                                                    <div class="text-[11px] font-mono text-muted-foreground truncate" x-text="st.slug"></div>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-2 shrink-0">
                                                <span x-show="st.sla_hours" class="px-2 py-0.5 rounded-md bg-amber-500/10 text-amber-600 dark:text-amber-400 font-mono text-[10px] font-bold flex items-center gap-1 border border-amber-500/20">
                                                    <svg class="size-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                                    <span x-text="st.sla_hours + 'h SLA'"></span>
                                                </span>
                                                <button type="button" @click="removeWorkflowState(sIdx)" class="text-muted-foreground hover:text-destructive text-sm font-bold px-1 cursor-pointer" title="Remove stage">✕</button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- 2. Domain Roles & Personas Card -->
                            <div class="rounded-xl border border-border bg-card p-5 space-y-3 shadow-xs">
                                <div class="flex items-center justify-between border-b border-border/60 pb-3">
                                    <div class="flex items-center gap-2.5">
                                        <svg class="size-4 text-primary" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                        <h3 class="font-bold text-sm text-foreground">Domain Personas & Scoped Roles</h3>
                                        <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary" x-text="workflowRoles.length"></span>
                                    </div>
                                    <button type="button" @click="showAddRoleModal = true" class="h-8 px-3 rounded-lg bg-primary/10 text-primary hover:bg-primary hover:text-white transition text-xs font-semibold flex items-center gap-1.5 cursor-pointer">
                                        <span>+ Add Role</span>
                                    </button>
                                </div>
                                <p class="text-xs text-muted-foreground">Domain-scoped roles eligible for forward handoffs and transitions. Demo user accounts can be seeded automatically.</p>

                                <!-- Roles List -->
                                <div class="space-y-2 pt-1">
                                    <template x-for="(role, rIdx) in workflowRoles" :key="rIdx">
                                        <div class="flex flex-col sm:flex-row sm:items-center justify-between p-3.5 rounded-xl border border-border/80 bg-muted/20 hover:bg-muted/40 transition gap-3">
                                            <div class="min-w-0 space-y-1">
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <span class="font-bold text-xs text-foreground" x-text="role.name"></span>
                                                    <code class="font-mono text-[10px] px-1.5 py-0.5 rounded bg-muted text-primary border border-border/60" x-text="role.slug"></code>
                                                </div>
                                                <p class="text-[11px] text-muted-foreground leading-snug" x-text="role.description"></p>
                                            </div>
                                            <div class="flex items-center gap-3 shrink-0 self-end sm:self-center">
                                                <label class="flex items-center gap-1.5 text-xs text-muted-foreground cursor-pointer select-none bg-muted/40 px-2.5 py-1 rounded-lg border border-border/50 hover:bg-muted">
                                                    <input type="checkbox" x-model="role.seedUser" class="rounded text-primary size-3.5 border-input">
                                                    <span class="text-[11px] font-medium text-foreground">Demo Account</span>
                                                </label>
                                                <button type="button" @click="removeWorkflowRole(rIdx)" class="text-muted-foreground hover:text-destructive text-sm font-bold px-1.5 py-0.5 cursor-pointer" title="Remove role">✕</button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <!-- Workflow Disabled Info -->
                        <div x-show="!includeWorkflow" class="p-6 rounded-xl border border-dashed border-border text-center space-y-2 bg-muted/10">
                            <p class="text-sm font-bold text-foreground">Workflow Engine is Disabled</p>
                            <p class="text-xs text-muted-foreground max-w-md mx-auto">Enable the checkbox above to design custom lifecycle stages, configure turnaround SLA hours, and manage scoped operational roles.</p>
                        </div>

                        <div class="flex justify-between pt-4">
                            <button @click="step = 2" class="px-5 py-2.5 text-muted-foreground hover:text-foreground text-sm cursor-pointer">← Previous</button>
                            <button @click="step = 4" class="px-6 py-3 bg-primary hover:bg-primary/90 text-white font-bold rounded-xl text-sm transition cursor-pointer">
                                Next: Access Control & RBAC →
                            </button>
                        </div>
                    </div>

                    <!-- Step 4: Access Control & RBAC -->
                    <div x-show="step === 4" class="space-y-6">
                        <div class="border-b border-border pb-4">
                            <h2 class="text-xl font-bold text-foreground flex items-center gap-2">
                                <span class="text-primary">🛡️</span> Access Control & RBAC
                            </h2>
                            <p class="text-xs text-muted-foreground mt-1">Configure granular capabilities, CRUD policies, and API services</p>
                        </div>

                        <div class="space-y-3">
                            <label class="flex items-center space-x-3 cursor-pointer p-4 bg-card rounded-xl border border-border shadow-xs hover:border-primary/40 transition">
                                <input type="checkbox" x-model="includeApi" class="w-4 h-4 rounded text-primary" checked>
                                <div>
                                    <span class="font-bold text-sm text-foreground">REST API Controller</span>
                                    <p class="text-xs text-muted-foreground">Pre-wired endpoints with OpenAPI specifications for Mobile, Web, and External Integrations</p>
                                </div>
                            </label>
                        </div>

                        <!-- RBAC & Security Capabilities Card -->
                        <div class="rounded-xl border border-border bg-card p-5 space-y-3 shadow-xs">
                            <div class="flex items-center justify-between border-b border-border/50 pb-3">
                                <div class="flex items-center gap-2">
                                    <svg class="inline-block size-4 text-primary" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>
                                    <span class="font-bold text-sm text-foreground">RBAC & Security Capabilities</span>
                                    <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary" x-text="permissions.length"></span>
                                </div>
                                <label class="flex items-center gap-2 cursor-pointer text-xs text-muted-foreground">
                                    <input type="checkbox" x-model="autoGenerateRbac" @change="refreshRbacList()" class="rounded border-input text-primary">
                                    <span>Auto-Generate Standard CRUD</span>
                                </label>
                            </div>
                            <p class="text-xs text-muted-foreground">Granular permissions synced to database and guarded by Gate & BaseSliceWebController</p>

                            <!-- Permissions List -->
                            <div class="flex flex-wrap items-center gap-1.5 pt-1">
                                <template x-for="(perm, pIdx) in permissions" :key="pIdx">
                                    <span class="inline-flex items-center gap-1.5 rounded-lg border border-border/80 bg-muted/30 px-2.5 py-1 text-xs font-mono text-foreground">
                                        <span x-text="perm"></span>
                                        <button type="button" @click="removeRbac(pIdx)" class="text-muted-foreground hover:text-destructive text-xs cursor-pointer">✕</button>
                                    </span>
                                </template>
                            </div>

                            <!-- Add Custom Capability -->
                            <div class="flex items-center gap-2 pt-2">
                                <input type="text" x-model="newPermInput" @keydown.enter.prevent="addCustomRbac()" placeholder="e.g. publish, export, approve" class="h-8 rounded-lg border border-input bg-background px-3 text-xs font-mono text-foreground flex-1 max-w-xs focus:ring-1 focus:ring-primary">
                                <button type="button" @click="addCustomRbac()" class="h-8 px-3 rounded-lg border border-border bg-muted/30 text-xs font-semibold hover:bg-muted transition cursor-pointer">+ Add</button>
                            </div>
                        </div>

                        <div class="flex justify-between pt-4">
                            <button @click="step = 3" class="px-5 py-2.5 text-muted-foreground hover:text-foreground text-sm cursor-pointer">← Previous</button>
                            <button @click="step = 5" class="px-6 py-3 bg-primary hover:bg-primary/90 text-white font-bold rounded-xl text-sm transition cursor-pointer">
                                Review & Generate →
                            </button>
                        </div>
                    </div>

                    <!-- Step 5: Review & Generate -->
                    <div x-show="step === 5" class="space-y-6">
                        <div class="border-b border-border pb-4">
                            <h2 class="text-xl font-bold text-foreground flex items-center gap-2">
                                <span class="text-primary">🚀</span> Review & Generate Solution
                            </h2>
                            <p class="text-xs text-muted-foreground mt-1">Review your configuration before scaffolding the slice</p>
                        </div>

                        <!-- 2-Column Responsive Layout: Configuration Summary on Left, Monospace Project Directory Tree on Right -->
                        <div class="flex flex-col xl:flex-row gap-5 items-start w-full">
                            <!-- Left: Project Configuration Summary -->
                            <div class="w-full xl:w-7/12 bg-muted/20 border border-border rounded-2xl p-5 space-y-4" style="flex: 1 1 58%; min-width: 0;">
                                <h3 class="text-sm font-bold text-foreground border-b border-border/60 pb-2 flex items-center gap-2">
                                    <svg class="inline-block size-4 text-primary" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/></svg>
                                    <span>Project Configuration Summary</span>
                                </h3>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                    <div>
                                        <span class="text-muted-foreground block text-[11px]">Project / Slices:</span>
                                        <span class="font-bold text-foreground text-sm" x-text="isMultiSlice() ? (projectName + ' (' + slicesList().length + ' Slices Suite)') : (projectName || 'Unnamed')"></span>
                                    </div>
                                    <div>
                                        <span class="text-muted-foreground block text-[11px]">Domain / Group:</span>
                                        <span class="font-semibold text-primary" x-text="domain || 'General'"></span>
                                    </div>
                                    <div>
                                        <span class="text-muted-foreground block text-[11px]">Root Namespace:</span>
                                        <span class="font-mono text-foreground select-all" x-text="isMultiSlice() ? ('App\\Slices\\' + (domain || 'General') + '\\...') : ('App\\Slices\\' + (projectName || 'Feature'))"></span>
                                    </div>
                                    <div>
                                        <span class="text-muted-foreground block text-[11px]">Output Directory:</span>
                                        <span class="font-mono text-muted-foreground select-all" x-text="isMultiSlice() ? ('app/Slices/' + (domain || 'General') + '/*') : ('app/Slices/' + (projectName || 'Feature'))"></span>
                                    </div>
                                    <div>
                                        <span class="text-muted-foreground block text-[11px]">Target Framework:</span>
                                        <span class="text-foreground font-semibold">Laravel 12+ / 13+</span>
                                    </div>
                                    <div>
                                        <span class="text-muted-foreground block text-[11px]">Platform Type:</span>
                                        <span class="text-foreground font-semibold" x-text="includeFlutter ? 'Web Application + Flutter Mobile Client' : 'Web Application (SPA/Blade)'"></span>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <span class="text-muted-foreground block text-[11px]">Component Strategy:</span>
                                        <span class="text-foreground font-semibold">BlatUI Standard (Blade + Tailwind v4 + Alpine.js + Lucide)</span>
                                    </div>
                                </div>

                                <div class="pt-2 border-t border-border/60 space-y-2">
                                    <h4 class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Included Features</h4>
                                    <div class="space-y-1.5 text-xs">
                                        <div class="flex items-center gap-2 text-foreground">
                                            <span class="text-emerald-500 font-bold">✓</span>
                                            <span>Authentication & RBAC (<strong class="text-primary font-mono" x-text="permissions.length"></strong> capabilities guarded by Gate)</span>
                                        </div>
                                        <div class="flex items-center gap-2 text-foreground">
                                            <span class="text-emerald-500 font-bold">✓</span>
                                            <span>Multi-Table Aggregate Architecture (<strong class="text-foreground" x-text="customFields.filter(f => f.name).length + ' root fields'"></strong><span x-show="childTables.length > 0" x-text="', ' + childTables.length + ' child entities'"></span>)</span>
                                        </div>
                                        <div class="flex items-center gap-2 text-foreground">
                                            <span class="text-emerald-500 font-bold">✓</span>
                                            <span>API Controllers & Client Services (RESTful endpoints with JSON contracts)</span>
                                        </div>
                                        <div class="flex items-center gap-2 text-foreground">
                                            <span class="text-emerald-500 font-bold">✓</span>
                                            <span>Typed Contracts & DTOs (FormBusinessObject, ListingBusinessObject)</span>
                                        </div>
                                        <div class="flex items-center gap-2 text-foreground">
                                            <span class="text-emerald-500 font-bold">✓</span>
                                            <span>BlatUI Component Views (index.blade.php & form.blade.php)</span>
                                        </div>
                                        <template x-if="includeWorkflow">
                                            <div class="flex items-center gap-2 text-emerald-400 font-semibold">
                                                <span>✓</span>
                                                <span>Workflow & State Machine Transitions</span>
                                            </div>
                                        </template>
                                        <template x-if="includeFlutter">
                                            <div class="flex items-center gap-2 text-cyan-400 font-semibold">
                                                <span>✓</span>
                                                <span>Flutter Native Mobile Client Slice (Dart Models & HTTP Client)</span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>

                            <!-- Right: Generated Project Structure (Theme-Aware Code Tree) -->
                            <div class="w-full xl:w-5/12 bg-card dark:bg-slate-950 border border-border dark:border-slate-800 rounded-2xl p-5 space-y-3 font-mono shadow-xs" style="flex: 1 1 42%; min-width: 0;">
                                <div class="flex items-center justify-between border-b border-border dark:border-slate-800/80 pb-2">
                                    <div class="flex items-center gap-2 text-foreground dark:text-slate-200 text-xs font-bold">
                                        <svg class="inline-block size-4 text-amber-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1h-2.5a1 1 0 0 1-.8-.4l-.9-1.2A1 1 0 0 0 15 3h-2a1 1 0 0 0-1 1v5a1 1 0 0 0 1 1Z"/><path d="M20 21a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1h-2.9a1 1 0 0 1-.88-.55l-.42-.85a1 1 0 0 0-.92-.6H13a1 1 0 0 0-1 1v5a1 1 0 0 0 1 1Z"/><path d="M3 5v14a2 2 0 0 0 2 2h7"/><path d="M3 12h5"/></svg>
                                        <span>Generated Project Structure</span>
                                    </div>
                                    <span class="text-[10px] text-muted-foreground dark:text-slate-500 font-mono">LaraSlice v1.0</span>
                                </div>

                                <div class="text-[11px] leading-relaxed text-foreground/80 dark:text-slate-300 space-y-0.5 overflow-x-auto select-all max-h-96 pr-2">
                                    <!-- Multi-Slice Domain Suite Tree View -->
                                    <template x-if="isMultiSlice()">
                                        <div class="space-y-3">
                                            <div class="flex items-center gap-1.5 font-bold text-amber-600 dark:text-amber-300">
                                                <span>📁</span>
                                                <span x-text="'app/Slices/' + (domain ? domain.replace(/[^A-Za-z0-9_-]/g, '') + '/' : '')"></span>
                                            </div>
                                            <template x-for="(sName, sIdx) in slicesList()" :key="sIdx">
                                                <div class="pl-3 border-l-2 border-primary/20 space-y-0.5 my-1">
                                                    <div class="flex items-center gap-1.5 font-bold text-primary">
                                                        <span>📦</span>
                                                        <span x-text="sName + '/'"></span>
                                                    </div>
                                                    <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-foreground dark:text-slate-300">slice.yaml</span></div>
                                                    <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-amber-600 dark:text-amber-400 font-semibold">Contracts/</span><span class="text-foreground dark:text-slate-300" x-text="sName + 'BusinessObjects.php'"></span></div>
                                                    <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-cyan-600 dark:text-cyan-400 font-semibold">Controllers/</span><span class="text-foreground dark:text-slate-300" x-text="sName + 'WebController.php, ' + sName + 'ApiController.php'"></span></div>
                                                    <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-purple-600 dark:text-purple-400 font-semibold">Migrations/</span><span class="text-foreground dark:text-slate-300" x-text="'create_' + sName.toLowerCase().replace(/[^a-z0-9_]/g, '_') + 's_table.php'"></span></div>
                                                    <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-emerald-600 dark:text-emerald-400 font-semibold">Models/</span><span class="text-emerald-600 dark:text-emerald-400 font-semibold" x-text="sName + '.php'"></span></div>
                                                    <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-rose-600 dark:text-rose-400 font-semibold">Resources/views/</span><span class="text-foreground dark:text-slate-300">index.blade.php, form.blade.php</span></div>
                                                    <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-blue-600 dark:text-blue-400 font-semibold">Routes/</span><span class="text-foreground dark:text-slate-300">web.php, api.php</span></div>
                                                    <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-indigo-600 dark:text-indigo-400 font-semibold">Schemas/</span><span class="text-foreground dark:text-slate-300" x-text="sName + 'Schema.php'"></span></div>
                                                    <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">└── <span class="text-teal-600 dark:text-teal-400 font-semibold">Services/</span><span class="text-foreground dark:text-slate-300" x-text="sName + 'SliceService.php'"></span></div>
                                                </div>
                                            </template>
                                        </div>
                                    </template>

                                    <!-- Single Slice Tree View -->
                                    <template x-if="!isMultiSlice()">
                                        <div>
                                            <div class="flex items-center gap-1.5 font-bold text-amber-600 dark:text-amber-300">
                                                <span>📁</span>
                                                <span x-text="'app/Slices/' + (projectName || 'Feature') + '/'"></span>
                                            </div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-foreground dark:text-slate-300">slice.yaml</span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-amber-600 dark:text-amber-400 font-semibold">Contracts/</span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-6">│   ├── <span class="text-foreground dark:text-slate-300" x-text="(projectName || 'Feature') + 'FormBusinessObject.php'"></span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-6">│   ├── <span class="text-foreground dark:text-slate-300" x-text="(projectName || 'Feature') + 'ListingBusinessObject.php'"></span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-6">│   └── <span class="text-foreground dark:text-slate-300" x-text="(projectName || 'Feature') + 'FilterBusinessObject.php'"></span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-cyan-600 dark:text-cyan-400 font-semibold">Controllers/</span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-6">│   ├── <span class="text-foreground dark:text-slate-300" x-text="(projectName || 'Feature') + 'WebController.php'"></span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-6">│   └── <span class="text-foreground dark:text-slate-300" x-text="(projectName || 'Feature') + 'ApiController.php'"></span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-purple-600 dark:text-purple-400 font-semibold">Migrations/</span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-6">│   ├── <span class="text-foreground dark:text-slate-300" x-text="'2026_01_01_000001_create_' + (projectName || 'item').toLowerCase().replace(/[^a-z0-9_]/g, '_') + 's_table.php'"></span></div>
                                            <template x-for="(ct, cIdx) in childTables" :key="cIdx">
                                                <div class="text-muted-foreground/60 dark:text-slate-500 pl-6">│   └── <span class="text-purple-600 dark:text-purple-300 font-semibold" x-text="'2026_01_01_00000' + (cIdx+2) + '_create_' + ct.name + '_table.php'"></span></div>
                                            </template>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-emerald-600 dark:text-emerald-400 font-semibold">Models/</span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-6">│   ├── <span class="text-emerald-600 dark:text-emerald-400 font-semibold" x-text="(projectName || 'Feature') + '.php'"></span></div>
                                            <template x-for="(ct, cIdx) in childTables" :key="cIdx">
                                                <div class="text-muted-foreground/60 dark:text-slate-500 pl-6">│   └── <span class="text-cyan-600 dark:text-cyan-300 font-semibold" x-text="ct.label.replace(/\s+/g, '') + '.php'"></span></div>
                                            </template>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-rose-600 dark:text-rose-400 font-semibold">Resources/views/</span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-6">│   ├── <span class="text-foreground dark:text-slate-300">index.blade.php</span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-6">│   └── <span class="text-foreground dark:text-slate-300">form.blade.php</span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-blue-600 dark:text-blue-400 font-semibold">Routes/</span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-6">│   ├── <span class="text-foreground dark:text-slate-300">web.php</span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-6">│   └── <span class="text-foreground dark:text-slate-300">api.php</span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-indigo-600 dark:text-indigo-400 font-semibold">Schemas/</span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-6">│   └── <span class="text-foreground dark:text-slate-300" x-text="(projectName || 'Feature') + 'Schema.php'"></span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">└── <span class="text-teal-600 dark:text-teal-400 font-semibold">Services/</span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-6">    └── <span class="text-foreground dark:text-slate-300" x-text="(projectName || 'Feature') + 'SliceService.php'"></span></div>
                                        </div>
                                    </template>

                                    <!-- Flutter Mobile Tree -->
                                    <template x-if="includeFlutter">
                                        <div class="pt-3 mt-3 border-t border-border dark:border-slate-800 text-cyan-600 dark:text-cyan-400">
                                            <div class="flex items-center gap-1.5 font-bold">
                                                <span>📱</span>
                                                <span x-text="'flutter_app/lib/slices/' + (isMultiSlice() ? (domain || 'suite').toLowerCase() : (projectName || 'feature').toLowerCase()) + '/'"></span>
                                            </div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-cyan-600 dark:text-cyan-300 font-semibold">models/</span><span class="text-foreground dark:text-slate-300" x-text="(isMultiSlice() ? (domain || 'suite').toLowerCase() : (projectName || 'feature').toLowerCase()) + '_model.dart'"></span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">├── <span class="text-cyan-600 dark:text-cyan-300 font-semibold">services/</span><span class="text-foreground dark:text-slate-300" x-text="(isMultiSlice() ? (domain || 'suite').toLowerCase() : (projectName || 'feature').toLowerCase()) + '_api_service.dart'"></span></div>
                                            <div class="text-muted-foreground/60 dark:text-slate-500 pl-3">└── <span class="text-cyan-600 dark:text-cyan-300 font-semibold">views/</span><span class="text-foreground dark:text-slate-300" x-text="(isMultiSlice() ? (domain || 'suite').toLowerCase() : (projectName || 'feature').toLowerCase()) + '_screen.dart'"></span></div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <div class="pt-4 flex flex-col sm:flex-row justify-between items-center gap-3">
                            <button @click="step = 4" class="px-5 py-2.5 text-muted-foreground hover:text-foreground text-sm cursor-pointer self-start sm:self-auto">← Previous</button>
                            <div class="flex flex-wrap items-center gap-4">
                                <label class="flex items-center gap-2 text-xs text-muted-foreground hover:text-foreground cursor-pointer select-none">
                                    <input type="checkbox" x-model="autoMigrate" class="rounded border-border text-amber-500 focus:ring-amber-400">
                                    <span>⚡ Run migration immediately</span>
                                </label>
                                <button @click="generateSlice()" :disabled="isGenerating" class="px-8 py-3.5 bg-primary hover:bg-primary/90 text-primary-foreground font-extrabold rounded-xl shadow-lg shadow-primary/25 transition flex items-center gap-2 cursor-pointer disabled:opacity-50">
                                    <span x-show="!isGenerating">✨ Generate Vertical Slice</span>
                                    <span x-show="isGenerating">Scaffolding Slice...</span>
                                </button>
                            </div>
                        </div>

                        <!-- Success Result Box with Direct UI Link (Theme-Aware & High Contrast) -->
                        <div x-show="resultData" class="p-6 bg-card border-2 border-emerald-500/40 dark:border-emerald-500/50 rounded-2xl space-y-4 shadow-xl shadow-emerald-500/5">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="size-9 rounded-xl bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 flex items-center justify-center font-bold text-base shadow-xs">✓</div>
                                    <div>
                                        <h3 class="font-extrabold text-foreground text-base tracking-tight">Slice Scaffolded Successfully!</h3>
                                        <p class="text-xs text-emerald-600 dark:text-emerald-400 font-medium" x-text="resultMessage"></p>
                                    </div>
                                </div>
                                <a :href="resultData?.web_url" target="_blank" class="px-4 py-2 bg-primary hover:bg-primary/90 text-primary-foreground font-bold text-xs rounded-xl transition shadow-sm flex items-center gap-1.5 cursor-pointer">
                                    <span>Open Web UI</span>
                                    <span>↗</span>
                                </a>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs bg-muted/40 dark:bg-muted/20 p-4 rounded-xl border border-border">
                                <div>
                                    <span class="text-muted-foreground block mb-0.5 font-medium text-[11px]">Backend Folder Created:</span>
                                    <code class="text-amber-700 dark:text-amber-300 font-mono select-all text-[11px] font-semibold" x-text="resultData?.path"></code>
                                </div>
                                <div>
                                    <span class="text-muted-foreground block mb-0.5 font-medium text-[11px]">Primary Web UI Route:</span>
                                    <code class="text-primary font-mono text-[11px] font-semibold" x-text="resultData?.web_url"></code>
                                </div>
                                <div>
                                    <span class="text-muted-foreground block mb-0.5 font-medium text-[11px]">REST API Endpoint:</span>
                                    <code class="text-sky-600 dark:text-sky-400 font-mono text-[11px] font-semibold" x-text="resultData?.api_url"></code>
                                </div>
                                <div x-show="resultData?.flutterPath">
                                    <span class="text-muted-foreground block mb-0.5 font-medium text-[11px]">Flutter Slice:</span>
                                    <code class="text-emerald-600 dark:text-emerald-400 font-mono text-[11px] font-semibold" x-text="resultData?.flutterPath"></code>
                                </div>
                            </div>

                                                <!-- Multi-Slice Breakdown: Show ALL Generated Slices with Direct Links -->
                            <div x-show="resultData?.allSlices && resultData.allSlices.length > 0" class="space-y-2.5 pt-2 border-t border-border/80">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold text-foreground uppercase tracking-wider">📦 All Slices in this Suite (<span x-text="resultData?.allSlices?.length ?? 0"></span>)</span>
                                    <span class="text-[11px] text-muted-foreground">Each slice is isolated and directly accessible:</span>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                    <template x-for="(sl, sIdx) in (resultData?.allSlices ?? [])" :key="sIdx">
                                        <div class="flex items-center justify-between p-3 rounded-xl border border-border bg-muted/30 hover:bg-muted/60 transition gap-2">
                                            <div class="min-w-0">
                                                <div class="flex items-center gap-2">
                                                    <span class="size-2 rounded-full bg-emerald-500"></span>
                                                    <span class="font-bold text-xs text-foreground truncate" x-text="sl.name"></span>
                                                </div>
                                                <div class="text-[11px] font-mono text-muted-foreground truncate" x-text="sl.web_url"></div>
                                            </div>
                                            <a :href="sl.web_url" target="_blank" class="shrink-0 px-3 py-1.5 rounded-lg bg-primary/10 hover:bg-primary text-primary hover:text-primary-foreground border border-primary/20 text-xs font-bold transition flex items-center gap-1 cursor-pointer">
                                                <span>Open</span>
                                                <span>↗</span>
                                            </a>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Migration Status & 1-Click Action in Generation Message -->
                            <div x-show="migrationSuccess" class="p-4 bg-emerald-500/10 dark:bg-emerald-500/15 border border-emerald-500/30 rounded-xl space-y-2">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2 text-xs font-bold text-emerald-700 dark:text-emerald-300">
                                        <span class="text-emerald-600 dark:text-emerald-400 font-extrabold">✓</span>
                                        <span>Database Migration Live: Tables created and ready for operations!</span>
                                    </div>
                                    <span class="text-[11px] px-2.5 py-0.5 bg-emerald-500/20 text-emerald-800 dark:text-emerald-200 font-mono rounded font-bold">Live</span>
                                </div>
                                <div x-show="migrationOutput" class="text-[11px] font-mono text-muted-foreground bg-muted/60 dark:bg-black/30 p-2.5 rounded-lg overflow-x-auto whitespace-pre-wrap max-h-28" x-text="migrationOutput"></div>
                            </div>

                            <div x-show="!migrationSuccess" class="p-4 bg-amber-500/10 dark:bg-amber-500/15 border border-amber-500/25 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                <div class="flex items-start sm:items-center gap-2.5">
                                    <span class="text-amber-600 dark:text-amber-400 font-bold text-base leading-none">⚡</span>
                                    <div>
                                        <div class="text-xs font-bold text-amber-950 dark:text-amber-200">Database Migration Pending</div>
                                        <div class="text-[11px] text-amber-900/80 dark:text-amber-300/80 mt-0.5">
                                            Execute migration now to create database tables, or run <code class="bg-amber-500/20 dark:bg-black/50 text-amber-950 dark:text-amber-200 px-1.5 py-0.5 rounded font-mono font-bold">php artisan migrate</code> in terminal.
                                        </div>
                                    </div>
                                </div>
                                <button @click="runMigrationNow()" :disabled="isMigrating" class="shrink-0 px-4 py-2 bg-primary hover:bg-primary/90 text-primary-foreground font-extrabold text-xs rounded-xl shadow-sm transition flex items-center justify-center gap-1.5 cursor-pointer disabled:opacity-50">
                                    <span x-show="!isMigrating">⚡ Run Migration Now</span>
                                    <span x-show="isMigrating" class="flex items-center gap-1.5">
                                        <svg class="animate-spin h-3.5 w-3.5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                        </svg>
                                        Migrating...
                                    </span>
                                </button>
                            </div>
                        </div>

                        <div x-show="resultMessage && !resultData" class="p-4 bg-rose-500/10 border border-rose-500/30 text-rose-600 dark:text-rose-400 rounded-xl text-xs font-semibold">
                            ✕ <span x-text="resultMessage"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

<!-- Modal: Add Pipeline Stage -->
                    <div x-show="showAddStateModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
                        <div @click.away="showAddStateModal = false" class="w-full max-w-md bg-card border border-border rounded-2xl p-6 shadow-2xl space-y-4">
                            <div class="flex items-center justify-between border-b border-border pb-3">
                                <h3 class="font-bold text-sm text-foreground">Add Pipeline Stage</h3>
                                <button type="button" @click="showAddStateModal = false" class="text-muted-foreground hover:text-foreground text-xs cursor-pointer">✕</button>
                            </div>
                            <div class="space-y-3 text-xs">
                                <div>
                                    <label class="block font-semibold text-muted-foreground mb-1">Stage Label *</label>
                                    <input type="text" x-model="newState.label" placeholder="e.g. Scrutiny Review" class="w-full px-3 py-2 bg-muted/30 border border-input rounded-xl text-foreground text-xs focus:ring-1 focus:ring-primary focus:outline-none">
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block font-semibold text-muted-foreground mb-1">Slug</label>
                                        <input type="text" x-model="newState.slug" placeholder="e.g. scrutiny_review" class="w-full px-3 py-2 bg-muted/30 border border-input rounded-xl text-foreground text-xs font-mono focus:ring-1 focus:ring-primary focus:outline-none">
                                    </div>
                                    <div>
                                        <label class="block font-semibold text-muted-foreground mb-1">Theme Color</label>
                                        <select x-model="newState.color" class="w-full px-3 py-2 bg-muted/30 border border-input rounded-xl text-foreground text-xs focus:outline-none cursor-pointer">
                                            <option value="slate">Slate (Neutral)</option>
                                            <option value="amber">Amber (Pending)</option>
                                            <option value="sky">Sky (Action)</option>
                                            <option value="indigo">Indigo (Review)</option>
                                            <option value="purple">Purple (Escalated)</option>
                                            <option value="emerald">Emerald (Success)</option>
                                            <option value="rose">Rose (Rejected)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block font-semibold text-muted-foreground mb-1">SLA Hours (Optional)</label>
                                        <input type="number" x-model="newState.sla_hours" placeholder="e.g. 48" class="w-full px-3 py-2 bg-muted/30 border border-input rounded-xl text-foreground text-xs focus:ring-1 focus:ring-primary focus:outline-none">
                                    </div>
                                    <div class="flex items-center pt-5">
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            <input type="checkbox" x-model="newState.terminal" class="size-4 rounded border-input text-primary">
                                            <span class="font-medium text-foreground text-xs">Terminal Final State</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="flex justify-end gap-2 pt-3 border-t border-border">
                                <button type="button" @click="showAddStateModal = false" class="px-4 py-2 rounded-xl text-xs text-muted-foreground hover:text-foreground">Cancel</button>
                                <button type="button" @click="addWorkflowState()" class="px-5 py-2 rounded-xl bg-primary text-primary-foreground text-xs font-bold hover:bg-primary/90">Add Stage</button>
                            </div>
                        </div>
                    </div>

                    <!-- Modal: Add Custom Role -->
                    <div x-show="showAddRoleModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
                        <div @click.away="showAddRoleModal = false" class="w-full max-w-md bg-card border border-border rounded-2xl p-6 shadow-2xl space-y-4">
                            <div class="flex items-center justify-between border-b border-border pb-3">
                                <h3 class="font-bold text-sm text-foreground">Add Slice-Scoped Role</h3>
                                <button type="button" @click="showAddRoleModal = false" class="text-muted-foreground hover:text-foreground text-xs cursor-pointer">✕</button>
                            </div>
                            <div class="space-y-3 text-xs">
                                <div>
                                    <label class="block font-semibold text-muted-foreground mb-1">Role Title *</label>
                                    <input type="text" x-model="newRole.name" placeholder="e.g. Compliance Officer" class="w-full px-3 py-2 bg-muted/30 border border-input rounded-xl text-foreground text-xs focus:ring-1 focus:ring-primary focus:outline-none">
                                </div>
                                <div>
                                    <label class="block font-semibold text-muted-foreground mb-1">Slug</label>
                                    <input type="text" x-model="newRole.slug" placeholder="e.g. compliance_officer" class="w-full px-3 py-2 bg-muted/30 border border-input rounded-xl text-foreground text-xs font-mono focus:ring-1 focus:ring-primary focus:outline-none">
                                </div>
                                <div>
                                    <label class="block font-semibold text-muted-foreground mb-1">Functional Description</label>
                                    <input type="text" x-model="newRole.description" placeholder="Responsible for regulatory scrutiny..." class="w-full px-3 py-2 bg-muted/30 border border-input rounded-xl text-foreground text-xs focus:ring-1 focus:ring-primary focus:outline-none">
                                </div>
                            </div>
                            <div class="flex justify-end gap-2 pt-3 border-t border-border">
                                <button type="button" @click="showAddRoleModal = false" class="px-4 py-2 rounded-xl text-xs text-muted-foreground hover:text-foreground">Cancel</button>
                                <button type="button" @click="addCustomRole()" class="px-5 py-2 rounded-xl bg-primary text-primary-foreground text-xs font-bold hover:bg-primary/90">Add Role</button>
                            </div>
                        </div>
                    </div>

                            
        <!-- October CMS Builder Style Studio Mode -->
        <div x-show="mainTab === 'studio'" class="grid grid-cols-1 md:grid-cols-12 gap-6">
            <!-- Left: Slice Selector (Grouped by Domain with Accordion) -->
            <div class="col-span-12 md:col-span-5 lg:col-span-4 space-y-4">
                <div class="bg-card border border-border rounded-2xl p-5 shadow-xl space-y-3">
                    <div class="flex items-center justify-between pb-2 border-b border-border">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Installed Slices</span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-mono font-bold bg-primary/10 text-primary" x-text="(installedSlices || []).length + ' Total'"></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="showPortModal = true" class="inline-flex items-center gap-1 rounded-md bg-indigo-500/10 border border-indigo-500/30 px-2.5 py-1 text-xs font-bold text-indigo-400 hover:bg-indigo-500/20 transition cursor-pointer shadow-2xs">
                                <span>🚀</span> Port App
                            </button>
                            <button type="button" @click="loadSlices()" class="text-xs text-primary hover:opacity-80 flex items-center gap-1 cursor-pointer">
                                <span>↻</span> Refresh
                            </button>
                        </div>
                    </div>

                    <!-- Filter Search Input -->
                    <div class="relative">
                        <input type="text"
                               x-model="sliceSearchQuery"
                               placeholder="Filter slices or domains..."
                               class="w-full pl-8 pr-3 py-1.5 text-xs bg-muted/30 border border-input rounded-xl focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                        <svg class="w-3.5 h-3.5 text-muted-foreground absolute left-2.5 top-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>

                    <!-- Domain Group Accordion List -->
                    <div class="space-y-3 pt-1">
                        <template x-for="grp in groupedSlices()" :key="grp.domain">
                            <div class="rounded-xl border border-border bg-card/60 shadow-2xs">
                                <!-- Accordion Header -->
                                <div class="px-3.5 py-2.5 bg-muted/40 hover:bg-muted/70 transition flex items-center justify-between cursor-pointer select-none transition-colors"
                                     :class="collapsedDomains[grp.domain] ? 'rounded-xl' : 'rounded-t-xl border-b border-border/40'"
                                     @click="toggleDomainCollapse(grp.domain)">
                                    <div class="flex flex-col gap-1 min-w-0">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <svg class="w-3.5 h-3.5 shrink-0 text-muted-foreground transition-transform duration-200"
                                                 :class="collapsedDomains[grp.domain] ? '-rotate-90' : 'rotate-0'"
                                                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                            <span class="font-bold text-[13px] text-foreground truncate" x-text="grp.domain"></span>
                                            <span x-show="grp.status === 'active'" class="px-1.5 py-0.2 rounded-full text-[9px] font-bold bg-emerald-500/15 text-emerald-600 border border-emerald-500/20">Active</span>
                                            <span x-show="grp.status === 'disabled'" class="px-1.5 py-0.2 rounded-full text-[9px] font-bold bg-rose-500/15 text-rose-500 border border-rose-500/20">Disabled</span>
                                            <span x-show="grp.status === 'partial'" class="px-1.5 py-0.2 rounded-full text-[9px] font-bold bg-amber-500/15 text-amber-600 border border-amber-500/20">Partial</span>
                                        </div>
                                        <div class="flex items-center gap-1.5 pl-5">
                                            <span class="px-1.5 py-0.2 rounded-full text-[10px] font-mono bg-muted text-muted-foreground border border-border/80" x-text="grp.slices.length + ' slice' + (grp.slices.length > 1 ? 's' : '')"></span>
                                            <span class="px-1.5 py-0.2 rounded-full text-[10px] font-mono bg-indigo-500/10 text-indigo-400 border border-indigo-500/20" x-text="grp.totalModels + ' model' + (grp.totalModels > 1 ? 's' : '')"></span>
                                        </div>
                                    </div>

                                    <!-- Domain Actions -->
                                    <div class="flex items-center gap-1 shrink-0" @click.stop>
                                        <!-- Seed Domain Button -->
                                        <button type="button"
                                                @click="seedDomainData(grp.domain, $event)"
                                                title="Seed Demo Data for Domain"
                                                class="p-1 rounded-md text-amber-500 hover:bg-amber-500/15 transition cursor-pointer">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                            </svg>
                                        </button>

                                        <!-- Toggle Domain Active Button -->
                                        <button type="button"
                                                @click="toggleDomainActive(grp.domain, $event)"
                                                :title="grp.slices.some(s => s.active !== false) ? 'Disable Entire Domain (Hide in Nav)' : 'Enable Entire Domain'"
                                                class="p-1 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted transition cursor-pointer">
                                            <svg x-show="grp.slices.some(s => s.active !== false)" class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                            <svg x-show="!grp.slices.some(s => s.active !== false)" class="w-3.5 h-3.5 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />
                                            </svg>
                                        </button>

                                        <!-- Domain Dropdown Menu -->
                                        <div x-data="{ menuOpen: false }" class="relative" @click.stop>
                                            <button type="button" @click="menuOpen = !menuOpen" class="p-1 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted transition cursor-pointer">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
                                                </svg>
                                            </button>
                                            <div x-show="menuOpen" @click.outside="menuOpen = false" x-cloak
                                                 style="right: 0; min-width: 12rem; z-index: 100;"
                                                 class="absolute right-0 mt-1 w-48 bg-card border border-border rounded-xl shadow-2xl z-50 py-1 text-xs font-medium">
                                                <button type="button" @click="menuOpen = false; seedDomainData(grp.domain, $event)" class="w-full text-left px-3 py-1.5 hover:bg-muted text-foreground flex items-center gap-2 cursor-pointer">
                                                    <span class="text-amber-500">⚡</span> Seed Domain Data
                                                </button>
                                                <button type="button" @click="menuOpen = false; toggleDomainActive(grp.domain, $event)" class="w-full text-left px-3 py-1.5 hover:bg-muted text-foreground flex items-center gap-2 cursor-pointer">
                                                    <span>👁</span> Toggle Domain Active
                                                </button>
                                                <button type="button" @click="menuOpen = false; wipeDomainData(grp.domain, $event)" class="w-full text-left px-3 py-1.5 hover:bg-muted text-foreground flex items-center gap-2 cursor-pointer">
                                                    <span>🧹</span> Wipe Domain Data
                                                </button>
                                                <div class="border-t border-border/60 my-1"></div>
                                                <button type="button" @click="menuOpen = false; openDeleteModal('domain', grp.domain, $event)" class="w-full text-left px-3 py-1.5 hover:bg-destructive/10 text-destructive flex items-center gap-2 cursor-pointer">
                                                    <span>🗑</span> Remove Domain...
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Accordion Slices Content -->
                                <div x-show="!collapsedDomains[grp.domain]" class="p-2 space-y-1.5 bg-card rounded-b-xl">
                                    <template x-for="s in grp.slices" :key="s.name">
                                        <div @click="selectSlice(s)"
                                             :class="selectedSlice && selectedSlice.name === s.name ? 'border-primary bg-primary/10 shadow-xs ring-1 ring-primary/40' : 'border-border/60 bg-muted/20 hover:bg-muted/40'"
                                             class="p-2.5 rounded-lg border cursor-pointer transition flex items-center justify-between group">
                                            <div class="min-w-0 flex items-center gap-2">
                                                <!-- Active Indicator Dot -->
                                                <span class="size-2 rounded-full shrink-0"
                                                      :class="s.active !== false ? 'bg-emerald-500 shadow-xs' : 'bg-rose-500/80'"
                                                      :title="s.active !== false ? 'Active & visible in sidebar' : 'Disabled (Hidden from navigation)'"></span>
                                                <div class="min-w-0">
                                                    <div class="flex items-center gap-1.5">
                                                        <h4 class="font-bold text-xs text-foreground truncate" x-text="s.title || s.name"></h4>
                                                        <span x-show="s.active === false" class="px-1.5 py-0.2 rounded text-[9px] font-mono bg-rose-500/15 text-rose-500 font-semibold">Hidden</span>
                                                    </div>
                                                    <p class="text-[10px] text-muted-foreground font-mono truncate" x-text="'Namespace: ' + s.name"></p>
                                                </div>
                                            </div>

                                            <!-- Slice Item Actions -->
                                            <div class="flex items-center gap-1 shrink-0 ml-2" @click.stop>
                                                <span class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-indigo-500/15 text-indigo-400 border border-indigo-500/20" x-text="'v' + (s.version || '1.0.0')"></span>

                                                <!-- Slice Dropdown Menu -->
                                                <div x-data="{ sMenu: false }" class="relative">
                                                    <button type="button" @click="sMenu = !sMenu" class="p-1 rounded text-muted-foreground hover:text-foreground hover:bg-muted transition cursor-pointer">
                                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
                                                        </svg>
                                                    </button>
                                                    <div x-show="sMenu" @click.outside="sMenu = false" x-cloak
                                                         style="right: 0; min-width: 12rem; z-index: 100;"
                                                         class="absolute right-0 mt-1 w-48 bg-card border border-border rounded-xl shadow-2xl z-50 py-1 text-xs font-medium">
                                                        <button type="button" @click="sMenu = false; seedSliceData(s.name, $event)" class="w-full text-left px-3 py-1.5 hover:bg-muted text-foreground flex items-center gap-2 cursor-pointer">
                                                            <span class="text-amber-500">⚡</span> Seed Demo Data
                                                        </button>
                                                        <button type="button" @click="sMenu = false; toggleSliceActive(s, $event)" class="w-full text-left px-3 py-1.5 hover:bg-muted text-foreground flex items-center gap-2 cursor-pointer">
                                                            <span x-text="s.active !== false ? '👁 Disable (Hide in Nav)' : '👁 Enable Slice'"></span>
                                                        </button>
                                                        <button type="button" @click="sMenu = false; wipeSliceData(s.name, $event)" class="w-full text-left px-3 py-1.5 hover:bg-muted text-foreground flex items-center gap-2 cursor-pointer">
                                                            <span>🧹</span> Wipe Table Data
                                                        </button>
                                                        <div class="border-t border-border/60 my-1"></div>
                                                        <button type="button" @click="sMenu = false; openDeleteModal('slice', s.name, $event)" class="w-full text-left px-3 py-1.5 hover:bg-destructive/10 text-destructive flex items-center gap-2 cursor-pointer">
                                                            <span>🗑</span> Delete Slice...
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                        <div x-show="groupedSlices().length === 0" class="rounded-xl border border-dashed border-border bg-muted/20 p-8 text-center">
                            <p class="text-sm font-semibold text-muted-foreground">No slices match "<span x-text="sliceSearchQuery"></span>"</p>
                            <p class="text-xs text-muted-foreground mt-1">Try a different search term or clear the filter.</p>
                            <button type="button" @click="sliceSearchQuery = ''" class="mt-3 text-xs text-primary hover:underline">Clear filter</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Slice Schema & Evolution Studio -->
            <div class="col-span-12 md:col-span-7 lg:col-span-8 space-y-6" x-show="selectedSlice">
                <!-- Slice Header Banner with Quick Actions -->
                <div class="bg-card border border-border rounded-2xl p-6 shadow-xl flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-3">
                            <h2 class="text-2xl font-black text-foreground" x-text="selectedSlice?.title || selectedSlice?.name"></h2>
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold font-mono bg-primary/20 text-primary border border-primary/30" x-text="'v' + (selectedSlice?.version || '1.0.0')"></span>
                            <span :class="selectedSlice?.active !== false ? 'bg-emerald-500/15 text-emerald-500 border-emerald-500/30' : 'bg-rose-500/15 text-rose-500 border-rose-500/30'"
                                  class="px-2.5 py-0.5 rounded-full text-[11px] font-bold border"
                                  x-text="selectedSlice?.active !== false ? '● Active' : '○ Disabled (Hidden)'"></span>
                        </div>
                        <p class="text-xs text-muted-foreground mt-1" x-text="selectedSlice?.description || 'Modular Slice for ' + selectedSlice?.name"></p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="seedSliceData(selectedSlice.name, $event)" title="Seed Realistic Demo Data" class="px-3.5 py-2 bg-amber-500/10 hover:bg-amber-500/20 text-amber-500 border border-amber-500/30 font-bold text-xs rounded-xl transition flex items-center gap-1.5 cursor-pointer shadow-xs">
                            <span>⚡</span> Seed Demo Data
                        </button>
                        <button type="button" @click="toggleSliceActive(selectedSlice, $event)" class="px-3.5 py-2 border font-bold text-xs rounded-xl transition flex items-center gap-1.5 cursor-pointer shadow-xs"
                                :class="selectedSlice?.active !== false ? 'border-border bg-muted/40 hover:bg-muted text-foreground' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-500'">
                            <span x-text="selectedSlice?.active !== false ? '👁 Hide from Nav' : '👁 Show in Nav'"></span>
                        </button>
                        <div x-data="{ dangerOpen: false }" class="relative">
                            <button type="button" @click="dangerOpen = !dangerOpen" title="Destructive actions" class="px-3 py-2 border border-destructive/30 bg-destructive/10 hover:bg-destructive/20 text-destructive font-bold text-xs rounded-xl transition flex items-center gap-1.5 cursor-pointer shadow-xs">
                                <span>⚠</span> Danger
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                            </button>
                            <div x-show="dangerOpen" @click.outside="dangerOpen = false" x-cloak class="absolute right-0 mt-1.5 w-56 rounded-xl border border-border bg-card shadow-xl p-1.5 z-40">
                                <button type="button" @click="dangerOpen = false; wipeSliceData(selectedSlice.name, $event)" class="w-full text-left px-3 py-2 rounded-lg hover:bg-muted text-xs font-semibold text-foreground flex items-center gap-2 cursor-pointer">
                                    <span>🧹</span> Wipe data…
                                    <span class="ml-auto text-[10px] font-normal text-muted-foreground">asks first</span>
                                </button>
                                <button type="button" @click="dangerOpen = false; openDeleteModal('slice', selectedSlice.name, $event)" class="w-full text-left px-3 py-2 rounded-lg hover:bg-destructive/10 text-xs font-semibold text-destructive flex items-center gap-2 cursor-pointer">
                                    <span>🗑</span> Remove slice…
                                    <span class="ml-auto text-[10px] font-normal text-muted-foreground">options</span>
                                </button>
                            </div>
                        </div>
                        <a :href="selectedSlice?.ui_url || '/' + (selectedSlice?.name ? selectedSlice.name.toLowerCase() : '')" target="_blank" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs rounded-xl transition shadow flex items-center gap-1.5 cursor-pointer">
                            <span>Open Slice UI</span>
                            <span>↗</span>
                        </a>
                    </div>
                </div>

                <!-- Slice Tables & Child Entities (Multi-Table Aggregate Manager) -->
                <div class="bg-card border border-border rounded-2xl p-6 shadow-xl space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-border">
                        <div class="flex items-center gap-2.5">
                            <div class="size-8 rounded-lg bg-indigo-500/10 text-indigo-500 flex items-center justify-center font-bold text-sm">
                                <svg class="inline-block size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/></svg>
                            </div>
                            <div>
                                <h3 class="font-bold text-foreground text-base">Slice Tables & Child Entities</h3>
                                <p class="text-xs text-muted-foreground mt-0.5">Aggregate Root database tables and Eloquent relationships</p>
                            </div>
                        </div>

                        <button @click="showAddChildTable = !showAddChildTable; if(showAddChildTable) { const root = selectedSlice?.tables_data?.find(t => t.is_primary); newChildTable.foreignKey = root?.foreign_key || ''; }"
                            class="px-3.5 py-1.5 rounded-xl bg-primary text-primary-foreground font-bold text-xs hover:opacity-90 transition shadow-xs flex items-center gap-1.5">
                            <svg class="inline-block size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                            <span>+ Add Child Table / Entity</span>
                        </button>
                    </div>

                    <!-- List of Registered Tables in this Slice -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <template x-for="tbl in (selectedSlice?.tables_data || [])" :key="tbl.name">
                            <div @click="selectTable(tbl)"
                                 :class="selectedTable?.name === tbl.name ? 'border-primary ring-2 ring-primary/30 bg-primary/10 shadow-sm' : 'border-border bg-muted/10 hover:bg-muted/30 hover:border-border/80'"
                                 class="p-3.5 rounded-xl border transition-all cursor-pointer flex items-center justify-between group">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-1.5">
                                        <span class="size-2 rounded-full shrink-0" :class="tbl.is_primary ? 'bg-primary' : 'bg-indigo-400'"></span>
                                        <span class="font-bold text-xs text-foreground font-mono truncate" x-text="tbl.name"></span>
                                    </div>
                                    <p class="text-[11px] text-muted-foreground mt-1" x-text="(tbl.columns ? tbl.columns.length : 0) + ' column(s) • ' + (tbl.is_primary ? 'Primary Root' : 'Child Entity')"></p>
                                </div>
                                <div class="flex items-center gap-1.5 shrink-0 ml-2">
                                    <span x-show="tbl.is_primary" class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-primary/20 text-primary font-bold">Root</span>
                                    <span x-show="!tbl.is_primary" class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-indigo-500/20 text-indigo-400 font-bold">Child</span>
                                    <span x-show="selectedTable?.name === tbl.name" class="px-1.5 py-0.5 rounded text-[9px] font-bold bg-primary text-white shadow-xs">Active</span>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Interactive Child Table Creator Modal / Panel -->
                    <div x-show="showAddChildTable" x-transition class="p-5 rounded-xl border border-indigo-500/30 bg-indigo-500/5 space-y-4">
                        <div class="flex items-center justify-between pb-2 border-b border-indigo-500/20">
                            <div>
                                <h4 class="font-bold text-sm text-foreground">+ Add New Child Table to <span x-text="selectedSlice?.name || ''"></span></h4>
                                <p class="text-xs text-muted-foreground">Scaffolds child migration, Eloquent model, declarative schema, and links foreign key</p>
                            </div>
                            <button @click="showAddChildTable = false" class="text-xs text-muted-foreground hover:text-foreground">✕ Cancel</button>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                            <div>
                                <label class="block text-[11px] font-bold text-muted-foreground mb-1">Child Table Name *</label>
                                <input type="text" x-model="newChildTable.tableName" placeholder="e.g. product_images, reviews" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:border-primary focus:outline-none" />
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-muted-foreground mb-1">Relationship Type</label>
                                <select x-model="newChildTable.relationType" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:border-primary focus:outline-none">
                                    <option value="hasMany">hasMany (One-to-Many Child Table)</option>
                                    <option value="hasOne">hasOne (One-to-One)</option>
                                    <option value="belongsToMany">belongsToMany (Many-to-Many Pivot)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-muted-foreground mb-1">Parent Foreign Key</label>
                                <input type="text" x-model="newChildTable.foreignKey" placeholder="e.g. product_id" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:border-primary focus:outline-none" />
                            </div>
                        </div>

                        <!-- Initial Fields for Child Table -->
                        <div class="space-y-2">
                            <div class="flex items-center justify-between text-xs font-bold text-muted-foreground">
                                <span>Initial Columns for Child Table</span>
                                <button type="button" @click="addChildTableField()" class="text-primary hover:underline">+ Add Column</button>
                            </div>

                            <template x-for="(col, cIdx) in newChildTable.fields" :key="cIdx">
                                <div class="flex items-center gap-2">
                                    <input type="text" x-model="col.name" placeholder="Column name (e.g. url, title, rating)" class="grow px-3 py-1.5 text-xs bg-background border border-border rounded-lg text-foreground focus:border-primary focus:outline-none" />
                                    <select x-model="col.type" class="w-48 px-2 py-1.5 text-xs bg-background border border-border rounded-lg text-foreground focus:border-primary focus:outline-none">
                                        <option value="string">string (VARCHAR 255)</option>
                                        <option value="text">text (Textarea)</option>
                                        <option value="mediumText">mediumText</option>
                                        <option value="longText">longText</option>
                                        <option value="integer">integer (Int)</option>
                                        <option value="bigInteger">bigInteger (BigInt)</option>
                                        <option value="smallInteger">smallInteger</option>
                                        <option value="tinyInteger">tinyInteger</option>
                                        <option value="boolean">boolean (Toggle/Checkbox)</option>
                                        <option value="decimal">decimal (Price/Currency)</option>
                                        <option value="float">float</option>
                                        <option value="double">double</option>
                                        <option value="date">date (YYYY-MM-DD)</option>
                                        <option value="datetime">datetime (Timestamp)</option>
                                        <option value="time">time</option>
                                        <option value="json">json (Object/Array)</option>
                                        <option value="uuid">uuid</option>
                                        <option value="foreignId">foreignId (Relation)</option>
                                    </select>
                                    <label class="flex items-center gap-1 text-[11px] text-muted-foreground shrink-0">
                                        <input type="checkbox" x-model="col.nullable" class="rounded border-border text-primary" />
                                        <span>Null</span>
                                    </label>
                                    <button type="button" @click="removeChildTableField(cIdx)" class="text-destructive hover:opacity-80 px-1.5 text-xs">✕</button>
                                </div>
                            </template>
                        </div>

                        <div class="flex items-center justify-between pt-2 border-t border-indigo-500/20">
                            <span class="text-xs font-semibold" :class="childTableFeedback.includes('Error') ? 'text-destructive' : 'text-success'" x-text="childTableFeedback"></span>
                            <button type="button" @click="submitChildTable()" :disabled="isCreatingChildTable" class="px-4 py-2 bg-primary text-primary-foreground font-bold text-xs rounded-xl hover:opacity-90 transition flex items-center gap-2">
                                <span x-show="isCreatingChildTable" class="animate-spin">⌛</span>
                                <span x-text="isCreatingChildTable ? 'Scaffolding & Migrating...' : 'Create Child Table & Migration'"></span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Blueprint Builder Style FIELDS Grid (Image 4) -->
                <div class="rounded-2xl border border-border bg-card p-6 shadow-xl space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3.5">
                        <div class="flex items-center gap-3">
                            <h2 class="text-sm font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                                <span>🧬</span>
                                FIELDS
                                <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary" x-text="studioFields.length"></span>
                            </h2>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold"
                                  :class="selectedTable?.is_primary ? 'bg-primary/10 text-primary' : 'bg-indigo-500/10 text-indigo-400'"
                                  x-text="selectedTable?.is_primary ? 'Aggregate Root Table' : 'Child Entity Table'"></span>
                        </div>

                        <!-- Target Table Selector & Add Field Button -->
                        <div class="flex items-center gap-3">
                            <div class="flex items-center gap-2 bg-background px-3 py-1 rounded-xl border border-primary/40 shadow-xs">
                                <span class="text-xs font-bold text-foreground">🎯 Target Table:</span>
                                <select x-model="targetTable" @change="const tbl = (selectedSlice?.tables_data || []).find(t => t.name === targetTable); if (tbl) selectTable(tbl);"
                                        class="px-2 py-0.5 rounded bg-card border border-border text-foreground font-mono text-xs font-bold focus:border-primary focus:outline-none">
                                    <template x-for="t in (selectedSlice?.tables_data || [])" :key="t.name">
                                        <option :value="t.name" :selected="targetTable === t.name" x-text="t.name + (t.is_primary ? ' (Primary Root)' : ' (Child Table)')"></option>
                                    </template>
                                </select>
                            </div>

                            <button type="button" @click="addStudioField()" class="inline-flex items-center gap-1.5 rounded-lg border border-primary/30 bg-primary/10 px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary/20 transition shadow-xs cursor-pointer">
                                <span>+</span> Add Field
                            </button>
                        </div>
                    </div>

                    <!-- Fields Data Grid Table -->
                    <div class="overflow-x-auto rounded-xl border border-border">
                        <table class="w-full min-w-[1080px] text-left text-[13px]">
                            <thead class="bg-muted/50 text-[11px] font-bold uppercase tracking-wider text-muted-foreground border-b border-border">
                                <tr>
                                    <th class="w-12 px-2.5 py-2.5 text-center">#</th>
                                    <th class="w-48 px-3 py-2.5">Field Handle (Column)</th>
                                    <th class="w-48 px-3 py-2.5">Display Label</th>
                                    <th class="w-40 px-3 py-2.5">Data Type</th>
                                    <th class="w-32 px-2.5 py-2.5 text-center">Width</th>
                                    <th class="w-14 px-2 py-2.5 text-center">Req</th>
                                    <th class="w-14 px-2 py-2.5 text-center">Null</th>
                                    <th class="w-12 px-2 py-2.5 text-center" title="Show in Create/Edit Form">Form</th>
                                    <th class="w-12 px-2 py-2.5 text-center" title="Show in Data Table List">Table</th>
                                    <th class="w-12 px-2 py-2.5 text-center" title="Hide on UI">Hide</th>
                                    <th class="w-32 px-2.5 py-2.5">Length / Options</th>
                                    <th class="w-32 px-2.5 py-2.5">Default Value</th>
                                    <th class="w-12 px-2.5 py-2.5 text-right"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/60 bg-card">
                                <template x-for="(field, fIdx) in studioFields" :key="fIdx">
                                    <tr class="hover:bg-muted/20 transition-colors even:bg-muted/10" :class="field.is_existing ? '' : 'bg-primary/5'">
                                        <!-- Reorder Arrows -->
                                        <td class="px-2 py-2 text-center text-muted-foreground font-mono">
                                            <div class="flex items-center justify-center gap-0.5">
                                                <button type="button" @click="moveStudioFieldUp(fIdx)" :disabled="fIdx === 0" title="Move Up" class="p-0.5 rounded text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-20 transition">↑</button>
                                                <button type="button" @click="moveStudioFieldDown(fIdx)" :disabled="fIdx === studioFields.length - 1" title="Move Down" class="p-0.5 rounded text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-20 transition">↓</button>
                                            </div>
                                        </td>
                                        <!-- Handle -->
                                        <td class="px-3 py-2">
                                            <input type="text" x-model="field.handle" placeholder="e.g. sku, price" class="h-8 w-full rounded border border-transparent hover:border-input focus:border-input bg-transparent px-1.5 font-mono text-[13px] font-semibold text-foreground focus:bg-background focus:outline-none">
                                        </td>
                                        <!-- Label -->
                                        <td class="px-3 py-2">
                                            <input type="text" x-model="field.label" placeholder="e.g. Variant SKU" class="h-8 w-full rounded border border-transparent hover:border-input focus:border-input bg-transparent px-1.5 text-[13px] text-foreground focus:bg-background focus:outline-none">
                                        </td>
                                        <!-- Type -->
                                        <td class="px-3 py-2">
                                            <select x-model="field.type" class="h-8 w-full rounded border border-input bg-card px-2 text-[13px] font-medium text-foreground focus:ring-1 focus:ring-primary">
                                                <option value="string">string (varchar)</option>
                                                <option value="text">text (longtext)</option>
                                                <option value="integer">integer</option>
                                                <option value="bigInteger">bigInteger</option>
                                                <option value="decimal">decimal</option>
                                                <option value="float">float</option>
                                                <option value="boolean">boolean</option>
                                                <option value="date">date</option>
                                                <option value="datetime">datetime</option>
                                                <option value="timestamp">timestamp</option>
                                                <option value="foreign_id">foreign_id (FK)</option>
                                                <option value="email">email</option>
                                                <option value="url">url</option>
                                                <option value="json">json</option>
                                                <option value="uuid">uuid</option>
                                            </select>
                                        </td>
                                        <!-- Width (Statamic pills) -->
                                        <td class="px-2.5 py-2 text-center">
                                            <div class="inline-flex rounded-md border border-input bg-card p-0.5 text-[10px] font-mono">
                                                <button type="button" @click="field.width = 33" :class="field.width === 33 ? 'bg-primary text-primary-foreground font-bold rounded' : 'text-muted-foreground hover:text-foreground'" class="px-1.5 py-0.5 transition" title="33% Width">33%</button>
                                                <button type="button" @click="field.width = 50" :class="(field.width === 50 || !field.width) ? 'bg-primary text-primary-foreground font-bold rounded' : 'text-muted-foreground hover:text-foreground'" class="px-1.5 py-0.5 transition" title="50% Width">50%</button>
                                                <button type="button" @click="field.width = 100" :class="field.width === 100 ? 'bg-primary text-primary-foreground font-bold rounded' : 'text-muted-foreground hover:text-foreground'" class="px-1.5 py-0.5 transition" title="100% Width">100%</button>
                                            </div>
                                        </td>
                                        <!-- Required -->
                                        <td class="px-2 py-2 text-center">
                                            <input type="checkbox" x-model="field.required" @change="onStudioRequiredToggle(field)" class="rounded border-input text-primary focus:ring-primary h-3.5 w-3.5 cursor-pointer" title="Required NOT NULL constraint">
                                        </td>
                                        <!-- Nullable -->
                                        <td class="px-2 py-2 text-center">
                                            <input type="checkbox" x-model="field.nullable" @change="onStudioNullableToggle(field)" class="rounded border-input text-amber-500 focus:ring-amber-500 h-3.5 w-3.5 cursor-pointer" title="Allow NULL values">
                                        </td>
                                        <!-- Form Visibility -->
                                        <td class="px-2 py-2 text-center">
                                            <input type="checkbox" x-model="field.show_in_form" :disabled="field.hidden" class="rounded border-input text-primary focus:ring-primary h-3.5 w-3.5 cursor-pointer disabled:opacity-30" title="Show in Create/Edit Form">
                                        </td>
                                        <!-- Table Visibility -->
                                        <td class="px-2 py-2 text-center">
                                            <input type="checkbox" x-model="field.show_in_list" :disabled="field.hidden" class="rounded border-input text-primary focus:ring-primary h-3.5 w-3.5 cursor-pointer disabled:opacity-30" title="Show in Data Table Listing">
                                        </td>
                                        <!-- Hide on UI toggle -->
                                        <td class="px-2 py-2 text-center">
                                            <button type="button" @click="field.hidden = !field.hidden; if(field.hidden){field.show_in_form = false; field.show_in_list = false;}else{field.show_in_form = true; field.show_in_list = true;}" :class="field.hidden ? 'text-rose-500 bg-rose-500/10' : 'text-muted-foreground hover:text-foreground'" class="p-1 rounded transition cursor-pointer" :title="field.hidden ? 'Field is hidden from UI' : 'Click to hide from UI'">
                                                <svg x-show="!field.hidden" class="size-3.5 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                <svg x-show="field.hidden" class="size-3.5 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>
                                            </button>
                                        </td>
                                        <!-- Length / Options -->
                                        <td class="px-2.5 py-2">
                                            <input type="text" x-model="field.length" placeholder="255 or 10,2" class="h-7 w-full rounded border border-input bg-card px-2 font-mono text-xs text-foreground focus:ring-1 focus:ring-primary placeholder:text-muted-foreground/40" title="Column length (e.g. 255 or 10,2)">
                                        </td>
                                        <!-- Default -->
                                        <td class="px-2.5 py-2">
                                            <input type="text" x-model="field.default" placeholder="NULL" class="h-7 w-full rounded border border-input bg-card px-2 font-mono text-xs text-foreground focus:ring-1 focus:ring-primary placeholder:text-muted-foreground/40" title="Default column value">
                                        </td>
                                        <!-- Action -->
                                        <td class="px-2.5 py-2 text-right">
                                            <button type="button" @click="removeStudioField(fIdx)" title="Delete Field" class="p-1 rounded text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition cursor-pointer">
                                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>

                        <!-- Table Footer Add Button -->
                        <div class="border-t border-border bg-muted/20 px-4 py-2.5 flex items-center justify-center">
                            <button type="button" @click="addStudioField()" class="inline-flex items-center gap-1.5 rounded-lg border border-input bg-background px-4 py-1.5 text-xs font-semibold text-foreground hover:bg-muted transition shadow-xs cursor-pointer">
                                <span>+</span> Add Field
                            </button>
                        </div>
                    </div>

                    <!-- UI Config Editor (Page & UI Architecture Plan) -->
                    <div class="rounded-2xl border border-indigo-500/30 bg-card p-5 shadow-sm space-y-4 mt-4" x-data="{ showUiEditor: false }" x-init="if (!uiInitialized) refreshUiFromFields()">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
                            <div>
                                <h2 class="text-sm font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
                                    <span class="flex size-7 items-center justify-center rounded-lg bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 text-sm">📄</span>
                                    <span>Page & UI Architecture</span>
                                    <span class="rounded-full bg-indigo-500/10 px-2 py-0.5 text-[10px] font-bold text-indigo-400">BlatUI 4-Step</span>
                                    <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-400">Editable</span>
                                </h2>
                                <p class="text-xs text-muted-foreground mt-0.5">Full UI config editor — changes flow into <code class="font-mono bg-muted px-1 rounded">slice.yaml</code> on generate. No need to visit Blueprint Studio.</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="refreshUiFromFields()"
                                    class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground" title="Re-detect from fields">
                                    🔄 Re-detect
                                </button>
                                <button type="button" @click="showUiEditor = !showUiEditor"
                                    class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground">
                                    <span x-text="showUiEditor ? 'Hide Editor' : 'Edit UI Config'"></span>
                                </button>
                            </div>
                        </div>
                        <div x-show="showUiEditor" class="space-y-4">
                            <!-- Quick Filters Editor -->
                            <div class="rounded-xl border border-border/80 bg-muted/10 p-3.5">
                                <div class="flex items-center justify-between mb-2">
                                    <p class="text-xs font-bold text-foreground">🔖 Quick Filters <span class="rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] text-primary" x-text="uiQuickFilters.length"></span> <span class="text-[10px] font-normal text-muted-foreground">tab order = display order</span></p>
                                    <div class="flex items-center gap-2" x-data="{ qfField: '' }">
                                        <select x-model="qfField" class="rounded-md border border-border bg-background px-2 py-1 text-[11px]">
                                            <option value="">+ From field...</option>
                                            <template x-for="f in studioFields" :key="f.handle">
                                                <option :value="f.handle" x-text="(f.label || f.handle) + ' (' + f.handle + ')'"></option>
                                            </template>
                                        </select>
                                        <button type="button" @click="if(qfField){ const f = studioFields.find(x => x.handle === qfField); uiQuickFilters.push({handle: qfField, label: f.label || qfField, type: 'select', options_from: 'distinct:' + qfField, searchable: true}); qfField=''; }"
                                            class="text-[11px] font-semibold text-primary hover:underline">+ Add</button>
                                    </div>
                                </div>
                                <div class="space-y-1.5">
                                    <template x-for="(qf, i) in uiQuickFilters" :key="i">
                                        <div class="flex items-center gap-2 rounded-lg border border-border/40 bg-card px-2 py-1.5">
                                            <span class="flex flex-col gap-0.5">
                                                <button type="button" @click="if(i>0){ const t=uiQuickFilters[i-1]; uiQuickFilters[i-1]=uiQuickFilters[i]; uiQuickFilters[i]=t; }" :disabled="i===0" class="text-[9px] text-muted-foreground hover:text-foreground disabled:opacity-20 leading-none">▲</button>
                                                <button type="button" @click="if(i<uiQuickFilters.length-1){ const t=uiQuickFilters[i+1]; uiQuickFilters[i+1]=uiQuickFilters[i]; uiQuickFilters[i]=t; }" :disabled="i===uiQuickFilters.length-1" class="text-[9px] text-muted-foreground hover:text-foreground disabled:opacity-20 leading-none">▼</button>
                                            </span>
                                            <input type="text" x-model="qf.label" placeholder="Label" class="flex-1 rounded-md border border-border bg-background px-2 py-1 text-xs">
                                            <input type="text" x-model="qf.handle" placeholder="handle" class="w-28 rounded-md border border-border bg-background px-2 py-1 text-xs font-mono">
                                            <input type="text" x-model="qf.options_from" placeholder="distinct:field" title="Options source: distinct:field or manual" class="w-32 rounded-md border border-border bg-background px-2 py-1 text-[11px] font-mono text-muted-foreground">
                                            <button type="button" @click="uiQuickFilters.splice(i, 1)" class="text-destructive text-xs px-1">✕</button>
                                        </div>
                                    </template>
                                    <p x-show="!uiQuickFilters.length" class="text-[11px] text-muted-foreground">None. Pick a field above or click 🔄 Re-detect.</p>
                                </div>
                            </div>
                            <!-- Sidebar Filters Editor -->
                            <div class="rounded-xl border border-border/80 bg-muted/10 p-3.5">
                                <div class="flex items-center justify-between mb-2">
                                    <p class="text-xs font-bold text-foreground">🎛️ Sidebar Filters <span class="rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] text-primary" x-text="uiSidebarFilters.length"></span> <span class="text-[10px] font-normal text-muted-foreground">date ranges & FK dropdowns</span></p>
                                    <div class="flex items-center gap-2" x-data="{ sfField: '', sfType: 'date_range' }">
                                        <select x-model="sfField" class="rounded-md border border-border bg-background px-2 py-1 text-[11px]">
                                            <option value="">+ From field...</option>
                                            <template x-for="f in studioFields" :key="f.handle">
                                                <option :value="f.handle" x-text="(f.label || f.handle) + ' (' + f.handle + ')'"></option>
                                            </template>
                                        </select>
                                        <select x-model="sfType" class="rounded-md border border-border bg-background px-2 py-1 text-[11px]">
                                            <option value="date_range">Date range</option>
                                            <option value="select">Dropdown</option>
                                        </select>
                                        <button type="button" @click="if(sfField){ const f = studioFields.find(x => x.handle === sfField); uiSidebarFilters.push({handle: sfField, label: f.label || sfField, type: sfType}); sfField=''; }"
                                            class="text-[11px] font-semibold text-primary hover:underline">+ Add</button>
                                    </div>
                                </div>
                                <div class="space-y-1.5">
                                    <template x-for="(sf, i) in uiSidebarFilters" :key="i">
                                        <div class="flex items-center gap-2 rounded-lg border border-border/40 bg-card px-2 py-1.5">
                                            <span class="rounded px-1.5 py-0.5 text-[10px] font-bold" :class="sf.type === 'date_range' ? 'bg-blue-500/10 text-blue-600' : 'bg-amber-500/10 text-amber-600'" x-text="sf.type === 'date_range' ? '📅' : '▾'"></span>
                                            <input type="text" x-model="sf.label" placeholder="Label" class="flex-1 rounded-md border border-border bg-background px-2 py-1 text-xs">
                                            <input type="text" x-model="sf.handle" placeholder="handle" class="w-28 rounded-md border border-border bg-background px-2 py-1 text-xs font-mono">
                                            <button type="button" @click="uiSidebarFilters.splice(i, 1)" class="text-destructive text-xs px-1">✕</button>
                                        </div>
                                    </template>
                                    <p x-show="!uiSidebarFilters.length" class="text-[11px] text-muted-foreground">None. Pick a field above or click 🔄 Re-detect.</p>
                                </div>
                            </div>
                            <!-- Summary Cards Editor -->
                            <div class="rounded-xl border border-border/80 bg-muted/10 p-3.5">
                                <div class="flex items-center justify-between mb-2">
                                    <p class="text-xs font-bold text-foreground">📊 Summary Cards <span class="rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] text-primary" x-text="uiSummaryCards.length"></span></p>
                                    <button type="button" @click="uiSummaryCards.push({handle: 'amount', label: 'Total', aggregate: 'sum', formatter: 'money'})"
                                        class="text-[11px] font-semibold text-primary hover:underline">+ Add</button>
                                </div>
                                <div class="space-y-1.5">
                                    <template x-for="(sc, i) in uiSummaryCards" :key="i">
                                        <div class="flex items-center gap-2">
                                            <input type="text" x-model="sc.label" placeholder="Label" class="flex-1 rounded-md border border-border bg-background px-2 py-1 text-xs">
                                            <input type="text" x-model="sc.handle" placeholder="field" class="w-24 rounded-md border border-border bg-background px-2 py-1 text-xs font-mono">
                                            <select x-model="sc.aggregate" class="rounded-md border border-border bg-background px-2 py-1 text-xs">
                                                <option value="sum">Sum</option><option value="count">Count</option><option value="avg">Avg</option>
                                            </select>
                                            <button type="button" @click="uiSummaryCards.splice(i, 1)" class="text-destructive text-xs px-1">✕</button>
                                        </div>
                                    </template>
                                    <p x-show="!uiSummaryCards.length" class="text-[11px] text-muted-foreground">None. Click + Add or 🔄 Re-detect.</p>
                                </div>
                            </div>
                            <!-- Row Actions Editor -->
                            <div class="rounded-xl border border-indigo-500/30 bg-card p-3.5">
                                <div class="flex items-center justify-between mb-2">
                                    <p class="text-xs font-bold text-foreground">⚡ Row Actions <span class="rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] text-primary" x-text="uiRowActions.items.length"></span></p>
                                    <div class="flex items-center gap-1">
                                        <button type="button" @click="uiRowActions.display = 'dropdown'" :class="uiRowActions.display === 'dropdown' ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'" class="rounded px-2 py-0.5 text-[11px] font-bold">⋯ Menu</button>
                                        <button type="button" @click="uiRowActions.display = 'inline'" :class="uiRowActions.display === 'inline' ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'" class="rounded px-2 py-0.5 text-[11px] font-bold">Inline</button>
                                        <button type="button" @click="uiRowActions.display = 'hybrid'" :class="uiRowActions.display === 'hybrid' ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'" class="rounded px-2 py-0.5 text-[11px] font-bold">Hybrid</button>
                                    </div>
                                </div>
                                <div class="space-y-1.5">
                                    <template x-for="(a, i) in uiRowActions.items" :key="i">
                                        <div class="flex items-center gap-2">
                                            <input type="text" x-model="a.label" placeholder="Label" class="flex-1 rounded-md border border-border bg-background px-2 py-1 text-xs">
                                            <select x-model="a.placement" class="rounded-md border border-border bg-background px-2 py-1 text-xs">
                                                <option value="inline">Inline</option><option value="dropdown">Dropdown</option>
                                            </select>
                                            <button type="button" @click="uiRowActions.items.splice(i, 1)" class="text-destructive text-xs px-1">✕</button>
                                        </div>
                                    </template>
                                </div>
                                <button type="button" @click="uiRowActions.items.push({handle: 'custom_' + Date.now(), label: 'Custom', icon: 'plus', placement: 'dropdown'})"
                                    class="mt-2 text-[11px] font-semibold text-primary hover:underline">+ Add Action</button>
                            </div>
                            <!-- Bulk Actions -->
                            <div class="rounded-xl border border-border/80 bg-muted/10 p-3.5">
                                <p class="text-xs font-bold text-foreground mb-2">📦 Bulk Actions</p>
                                <div class="flex flex-wrap gap-2">
                                    <label class="flex items-center gap-1.5 text-xs"><input type="checkbox" :checked="uiBulkActions.includes('delete')" @change="$event.target.checked ? uiBulkActions.push('delete') : uiBulkActions.splice(uiBulkActions.indexOf('delete'), 1)" class="rounded"> Delete</label>
                                    <label class="flex items-center gap-1.5 text-xs"><input type="checkbox" :checked="uiBulkActions.includes('export')" @change="$event.target.checked ? uiBulkActions.push('export') : uiBulkActions.splice(uiBulkActions.indexOf('export'), 1)" class="rounded"> Export</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Create & Edit Form Sections Editor -->
                    <div class="rounded-2xl border border-indigo-500/30 bg-card p-5 shadow-sm space-y-4 mt-4" x-data="{ showFormEditor: false }" x-init="if (!uiFormInitialized) refreshFormSections()">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
                            <div>
                                <h2 class="text-sm font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
                                    <span class="flex size-7 items-center justify-center rounded-lg bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 text-sm">📝</span>
                                    <span>Create & Edit Form</span>
                                    <span class="rounded-full bg-indigo-500/10 px-2 py-0.5 text-[10px] font-bold text-indigo-400">Step 3</span>
                                    <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-400" x-text="uiFormSections.length + ' Sections'"></span>
                                </h2>
                                <p class="text-xs text-muted-foreground mt-0.5">Design form section cards — fields, layout, and presentation mode.</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="inline-flex rounded-lg border border-input bg-card p-0.5">
                                    <button type="button" @click="uiFormMode = 'drawer'" :class="uiFormMode === 'drawer' ? 'bg-primary text-primary-foreground font-bold' : 'text-muted-foreground'" class="rounded-md px-2.5 py-1 text-[11px] transition cursor-pointer">🗂️ Drawer</button>
                                    <button type="button" @click="uiFormMode = 'page'" :class="uiFormMode === 'page' ? 'bg-primary text-primary-foreground font-bold' : 'text-muted-foreground'" class="rounded-md px-2.5 py-1 text-[11px] transition cursor-pointer">📄 Page</button>
                                </div>
                                <button type="button" @click="refreshFormSections()" class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground" title="Re-generate from fields">🔄</button>
                                <button type="button" @click="showFormEditor = !showFormEditor" class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground">
                                    <span x-text="showFormEditor ? 'Hide' : 'Edit Sections'"></span>
                                </button>
                            </div>
                        </div>
                        <div x-show="showFormEditor" class="space-y-3">
                            <template x-for="(sec, sIdx) in uiFormSections" :key="sIdx">
                                <div class="rounded-xl border border-border bg-muted/10 p-3.5 space-y-2.5">
                                    <div class="flex items-center gap-2">
                                        <span class="size-6 rounded-md bg-primary/10 text-primary flex items-center justify-center font-bold text-xs" x-text="'S' + (sIdx + 1)"></span>
                                        <input type="text" x-model="sec.title" placeholder="Section title" class="flex-1 rounded-lg border border-input bg-background px-2.5 py-1.5 text-xs font-bold">
                                        <select x-model="sec.columns" class="rounded-lg border border-input bg-background px-2 py-1.5 text-xs">
                                            <option value="1">1 Col</option><option value="2">2 Cols</option><option value="3">3 Cols</option>
                                        </select>
                                        <button type="button" @click="uiFormSections.splice(sIdx, 1)" class="text-rose-500 hover:text-rose-400 text-xs px-1" title="Delete section">🗑️</button>
                                    </div>
                                    <div class="flex flex-wrap gap-1.5">
                                        <template x-for="(fld, fIdx) in sec.fields" :key="fIdx">
                                            <div class="flex items-center gap-1 rounded-lg border border-border/60 bg-card px-2 py-1">
                                                <span class="text-[11px] font-mono font-semibold" x-text="fld.handle"></span>
                                                <select x-model="fld.span" class="rounded border border-input bg-background px-1 py-0.5 text-[10px]">
                                                    <option value="1">1c</option><option value="2">2c</option>
                                                </select>
                                                <button type="button" @click="sec.fields.splice(fIdx, 1)" class="text-rose-500 text-[10px]">✕</button>
                                            </div>
                                        </template>
                                    </div>
                                    <div class="flex items-center gap-2" x-data="{ addFld: '' }">
                                        <select x-model="addFld" class="rounded-lg border border-border bg-background px-2 py-1 text-[11px]">
                                            <option value="">+ Add field...</option>
                                            <template x-for="f in studioFields" :key="f.handle">
                                                <option :value="f.handle" x-text="f.label || f.handle"></option>
                                            </template>
                                        </select>
                                        <button type="button" @click="if(addFld){ const f = studioFields.find(x => x.handle === addFld); if(f) sec.fields.push({handle: f.handle, label: f.label || f.handle, span: 1, width: 50, searchable: false}); addFld=''; }" class="rounded bg-primary/10 text-primary px-2 py-1 text-[11px] font-semibold">Add</button>
                                    </div>
                                </div>
                            </template>
                            <button type="button" @click="uiFormSections.push({title: 'New Section', description: '', columns: 2, fields: []})"
                                class="w-full rounded-xl border border-dashed border-border py-2.5 text-xs font-semibold text-muted-foreground hover:text-foreground hover:border-primary">+ Add Section Card</button>
                        </div>
                    </div>


                    <!-- Slice Studio GitOps: persona roles, views, YAML+JSON sync -->
                    <div class="rounded-2xl border border-emerald-500/30 bg-card p-5 shadow-sm space-y-4 mt-4 max-h-[80vh] overflow-y-auto" x-show="selectedSlice" x-ref="gitopsPanel">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
                            <div>
                                <h2 class="text-sm font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
                                    <span class="flex size-7 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-sm">⚙</span>
                                    <span>Studio Config & GitOps</span>
                                    <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-400">JSON + YAML</span>
                                    <span class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-bold text-primary">History</span>
                                </h2>
                                <p class="text-xs text-muted-foreground mt-0.5">Primary DX surface — persona nav, directory views, dashboard widgets. Saves <code class="font-mono bg-muted px-1 rounded">slice.yaml</code> with version ledger; optional DB workflow sync.</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <label class="text-[11px] font-bold uppercase text-muted-foreground">Sidebar module group</label>
                                <select x-model="navGroup" class="h-8 rounded-lg border border-border bg-background px-2 text-xs w-full max-w-xs mb-2">
                                    <option value="CRM">CRM</option>
                                    <option value="Work">Work</option>
                                    <option value="HR">HR</option>
                                    <option value="Finance">Finance</option>
                                    <option value="Orders">Orders</option>
                                    <option value="Tickets">Tickets</option>
                                    <option value="Events">Events</option>
                                    <option value="Messages">Messages</option>
                                    <option value="Settings">Settings</option>
                                    <option value="General">General</option>
                                </select>
                                <p class="text-[10px] text-muted-foreground mb-2">Demo-aligned section in the app sidebar (not the ERP domain folder name).</p>
                                <label class="text-[11px] font-bold uppercase text-muted-foreground">Persona roles (navigation.roles)</label>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="role in ['admin','employee','client','manager','user']" :key="role">
                                        <label class="flex items-center gap-1.5 text-xs border border-border rounded-lg px-2 py-1 cursor-pointer hover:border-primary" :title="['admin','employee','client'].includes(role) ? 'Core ERP persona' : 'Extended role (use for custom personas)'">
                                            <input type="checkbox" class="rounded" :value="role"
                                                :checked="navRoles.includes(role)"
                                                @change="navRoles = $event.target.checked ? [...new Set([...navRoles, role])] : navRoles.filter(r => r !== role)">
                                            <span x-text="role"></span>
                                        </label>
                                    </template>
                                </div>
                                <p class="text-[10px] text-muted-foreground mt-1">Core: admin / employee / client. Manager / user for custom personas.</p>
                                <label class="flex items-center gap-2 text-xs mt-2">
                                    <input type="checkbox" class="rounded" x-model="navVisible">
                                    <span>Visible in sidebar</span>
                                </label>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[11px] font-bold uppercase text-muted-foreground">Directory views</label>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="v in ['table','board','calendar']" :key="v">
                                        <label class="flex items-center gap-1.5 text-xs border border-border rounded-lg px-2 py-1 cursor-pointer">
                                            <input type="checkbox" class="rounded"
                                                :checked="uiViews.includes(v)"
                                                @change="uiViews = $event.target.checked ? [...new Set([...uiViews, v])] : uiViews.filter(x => x !== v)">
                                            <span x-text="v"></span>
                                        </label>
                                    </template>
                                </div>
                                <label class="text-[11px] font-bold uppercase text-muted-foreground block mt-2">Default view</label>
                                <select x-model="uiDefaultView" class="h-8 rounded-lg border border-border bg-background px-2 text-xs w-full max-w-xs">
                                    <option value="table">table</option>
                                    <option value="board">board</option>
                                    <option value="calendar">calendar</option>
                                </select>
                                <label class="flex items-center gap-2 text-xs mt-2">
                                    <input type="checkbox" class="rounded" :checked="uiInlineEdit.includes('status')"
                                        @change="uiInlineEdit = $event.target.checked ? [...new Set([...uiInlineEdit, 'status'])] : uiInlineEdit.filter(x => x !== 'status')">
                                    <span>Inline edit status (workflow)</span>
                                </label>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2 border-t border-border">
                            <div class="space-y-2">
                                <label class="text-[11px] font-bold uppercase text-muted-foreground">Bulk actions</label>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="ba in ['delete','export','approve','reject','complete']" :key="ba">
                                        <label class="flex items-center gap-1.5 text-xs border border-border rounded-lg px-2 py-1 cursor-pointer">
                                            <input type="checkbox" class="rounded"
                                                :checked="uiBulkActions.includes(ba)"
                                                @change="uiBulkActions = $event.target.checked ? [...new Set([...uiBulkActions, ba])] : uiBulkActions.filter(x => x !== ba)">
                                            <span x-text="ba"></span>
                                        </label>
                                    </template>
                                </div>
                                <p class="text-[10px] text-muted-foreground">Native delete/export + workflow transition handles (approve/reject/complete).</p>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[11px] font-bold uppercase text-muted-foreground">Row actions display</label>
                                <select x-model="uiRowActions.display" class="h-8 rounded-lg border border-border bg-background px-2 text-xs w-full max-w-xs">
                                    <option value="dropdown">Dropdown</option>
                                    <option value="hybrid">Hybrid (view inline + menu)</option>
                                    <option value="inline">Inline icons</option>
                                </select>
                            </div>
                        </div>

                        <div class="space-y-2 pt-2 border-t border-border">
                            <label class="text-[11px] font-bold uppercase text-muted-foreground">Dashboard widgets (index KPI strip)</label>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="(w, wi) in uiDashboardWidgets" :key="wi">
                                    <div class="flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs">
                                        <input type="text" x-model="w.label" class="h-7 w-24 rounded border border-border bg-background px-1 text-[11px]" placeholder="Label">
                                        <input type="text" x-model="w.handle" class="h-7 w-20 rounded border border-border bg-background px-1 text-[11px] font-mono" placeholder="handle">
                                        <select x-model="w.query" class="h-7 rounded border border-border bg-background px-1 text-[11px]">
                                            <option value="count">count</option>
                                            <option value="sum">sum</option>
                                        </select>
                                        <button type="button" @click="uiDashboardWidgets.splice(wi, 1)" class="text-rose-500 text-[10px]">✕</button>
                                    </div>
                                </template>
                                <button type="button" @click="uiDashboardWidgets.push({ type: 'counter', handle: 'total', label: 'Total', aggregate: 'count' })"
                                    class="rounded-lg border border-dashed border-border px-2 py-1 text-[11px] font-semibold text-muted-foreground hover:text-foreground">+ Widget</button>
                            </div>
                        </div>

                        <div class="space-y-3 pt-2 border-t border-border" x-show="selectedSlice">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <label class="text-[11px] font-bold uppercase text-muted-foreground">Workflow (states & transitions)</label>
                                <label class="flex items-center gap-1.5 text-xs">
                                    <input type="checkbox" class="rounded" x-model="includeWorkflow" @change="if (includeWorkflow && !workflowConfig) initDefaultWorkflow()">
                                    <span>Enabled</span>
                                </label>
                            </div>
                            <div x-show="includeWorkflow" class="space-y-2">
                                <div class="flex flex-wrap gap-1.5">
                                    <template x-for="(st, sIdx) in (workflowConfig?.states || [])" :key="sIdx">
                                        <span class="inline-flex items-center gap-1 rounded-lg border border-border bg-muted/40 px-2 py-0.5 text-[11px] font-semibold">
                                            <span class="font-mono" x-text="st.slug || st.name"></span>
                                            <button type="button" class="text-rose-500" @click="workflowConfig.states.splice(sIdx, 1)">✕</button>
                                        </span>
                                    </template>
                                    <button type="button" @click="if (!workflowConfig) initDefaultWorkflow(); workflowConfig.states.push({ slug: 'state_' + (workflowConfig.states.length+1), label: 'New state', color: 'slate' })"
                                        class="rounded-lg border border-dashed border-border px-2 py-0.5 text-[11px] text-muted-foreground">+ State</button>
                                </div>
                                <div class="space-y-1">
                                    <p class="text-[10px] text-muted-foreground font-semibold uppercase">Transitions</p>
                                    <template x-for="(tr, tIdx) in (workflowConfig?.transitions || [])" :key="tIdx">
                                        <div class="flex flex-wrap items-center gap-1 text-[11px]">
                                            <input type="text" x-model="tr.slug" class="h-7 w-24 rounded border border-border bg-background px-1 font-mono" placeholder="slug">
                                            <input type="text" x-model="tr.name" class="h-7 w-28 rounded border border-border bg-background px-1" placeholder="Label">
                                            <input type="text" class="h-7 w-28 rounded border border-border bg-background px-1 font-mono" placeholder="from (comma-sep)"
                                                :value="Array.isArray(tr.from) ? tr.from.join(', ') : (tr.from || '')"
                                                @input="tr.from = $event.target.value.split(',').map(s => s.trim()).filter(Boolean)"
                                                title="Comma-separated source states">
                                            <span class="text-muted-foreground">→</span>
                                            <input type="text" x-model="tr.to" class="h-7 w-24 rounded border border-border bg-background px-1 font-mono" placeholder="to state"
                                                :value="typeof tr.to === 'string' ? tr.to : (tr.to_state || '')"
                                                @input="tr.to = $event.target.value; tr.to_state = $event.target.value">
                                            <button type="button" class="text-rose-500" @click="workflowConfig.transitions.splice(tIdx, 1)">✕</button>
                                        </div>
                                    </template>
                                    <button type="button" @click="if (!workflowConfig) initDefaultWorkflow(); workflowConfig.transitions = workflowConfig.transitions || []; workflowConfig.transitions.push({ slug: 'go', name: 'Go', from: ['draft'], to: 'pending' })"
                                        class="rounded-lg border border-dashed border-border px-2 py-0.5 text-[11px] text-muted-foreground">+ Transition</button>
                                </div>
                                <p class="text-[10px] text-muted-foreground">Saved into <code class="font-mono">slice.yaml</code> workflow + synced to DB on Save.</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 pt-2 border-t border-border">
                            <button type="button" @click="saveStudioConfig()" :disabled="studioConfigSaving"
                                class="rounded-xl bg-primary text-primary-foreground px-4 py-2 text-xs font-bold shadow-sm hover:opacity-90 disabled:opacity-50">
                                <span x-text="studioConfigSaving ? 'Saving…' : 'Save config (JSON + YAML)'"></span>
                            </button>
                            <button type="button" @click="syncManifestPrefer('json')" :disabled="studioConfigSaving"
                                class="rounded-xl border border-border px-3 py-2 text-xs font-semibold hover:bg-muted">
                                Sync → DB (prefer JSON)
                            </button>
                            <button type="button" @click="syncManifestPrefer('yaml')" :disabled="studioConfigSaving"
                                class="rounded-xl border border-border px-3 py-2 text-xs font-semibold hover:bg-muted">
                                Sync from YAML
                            </button>
                            <span class="text-xs text-emerald-500" x-text="studioConfigMessage" x-show="studioConfigMessage"></span>
                        </div>
                        <div class="text-[11px] text-muted-foreground" x-show="selectedSlice?.version_history?.length">
                            History entries: <span class="font-mono font-semibold text-foreground" x-text="selectedSlice?.version_history?.length || 0"></span>
                            · Version <span class="font-mono" x-text="selectedSlice?.version || '1.0.0'"></span>
                        </div>
                    </div>

                    <!-- Schema Action Bar -->
                    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-3 border-t border-border">
                        <div class="flex items-center gap-2 text-xs font-mono text-muted-foreground">
                            <span class="font-bold text-foreground" x-text="studioFields.length + ' column(s) active'"></span>
                            <span x-show="studioFields.filter(f => !f.is_existing && f.handle.trim()).length > 0" class="text-emerald-500 font-semibold"
                                  x-text="'• ' + studioFields.filter(f => !f.is_existing && f.handle.trim()).length + ' new column(s) queued to add'"></span>
                            <span x-show="deletedStudioFields.length > 0" class="text-rose-400 font-semibold"
                                  x-text="'• ' + deletedStudioFields.length + ' column(s) queued to drop'"></span>
                        </div>
                        <button type="button" @click="applyStudioSchema()" :disabled="isSavingStudioFields"
                                class="w-full sm:w-auto px-6 py-2.5 bg-primary hover:bg-primary/90 text-white font-bold text-xs rounded-xl transition shadow-lg shadow-amber-500/20 flex items-center justify-center gap-2 cursor-pointer disabled:opacity-50">
                            <span x-show="!isSavingStudioFields">🚀 Apply Schema Changes & Run Migration</span>
                            <span x-show="isSavingStudioFields">Generating Migration & Updating Database...</span>
                        </button>
                    </div>

                    <div x-show="fieldFeedback" class="p-3 bg-emerald-500/10 border border-emerald-500/30 text-xs text-emerald-400 font-semibold rounded-xl" x-text="fieldFeedback"></div>
                </div>

                <!-- Dedicated SLICE RELATIONSHIPS & FOREIGN KEYS Card (Image 4) -->
                <div class="rounded-2xl border border-border bg-card p-6 shadow-xl space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3.5">
                        <div>
                            <h2 class="text-sm font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                                <svg class="size-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                Slice Relationships & Foreign Keys
                                <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary" x-text="studioRelations.length"></span>
                            </h2>
                            <p class="text-xs text-muted-foreground mt-0.5">
                                Configure Eloquent associations across models in this slice (hasMany, belongsTo, hasOne, belongsToMany) or with external models.
                            </p>
                        </div>
                        <button type="button" @click="addStudioRelationship()" class="inline-flex items-center gap-1.5 rounded-lg border border-primary/30 bg-primary/10 px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary/20 transition shadow-xs cursor-pointer">
                            <span>+</span> Add Relationship
                        </button>
                    </div>

                    <template x-if="studioRelations.length === 0">
                        <div class="rounded-xl border border-dashed border-border p-6 text-center text-xs text-muted-foreground">
                            No cross-model relationships configured yet. Click <span class="font-semibold text-primary">Add Relationship</span> above to connect models with foreign keys.
                        </div>
                    </template>

                    <template x-if="studioRelations.length > 0">
                        <div class="overflow-x-auto rounded-xl border border-border">
                            <table class="w-full min-w-[1080px] text-left text-[13px]">
                                <thead class="bg-muted/50 text-[11px] font-bold uppercase tracking-wider text-muted-foreground border-b border-border">
                                    <tr>
                                        <th class="w-10 px-3 py-2.5 text-center">#</th>
                                        <th class="w-48 px-3 py-2.5">Source Model (From)</th>
                                        <th class="w-36 px-3 py-2.5">Relationship Type</th>
                                        <th class="w-48 px-3 py-2.5">Target Model (To)</th>
                                        <th class="w-44 px-3 py-2.5">Foreign Key Column</th>
                                        <th class="w-40 px-3 py-2.5">Method Name</th>
                                        <th class="w-14 px-3 py-2.5 text-right"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border/60 bg-card">
                                    <template x-for="(rel, rIdx) in studioRelations" :key="rIdx">
                                        <tr class="hover:bg-muted/20 transition-colors">
                                            <td class="px-2 py-2 text-center text-muted-foreground font-mono" x-text="rIdx + 1"></td>
                                            <!-- Source Model -->
                                            <td class="px-3 py-2">
                                                <select x-model="rel.source_model" class="h-7 w-full rounded border border-input bg-card px-2 text-xs font-semibold text-foreground focus:ring-1 focus:ring-primary">
                                                    <template x-for="m in (selectedSlice?.models || [])" :key="'src-' + m.handle">
                                                        <option :value="m.handle" x-text="m.handle + (m.root ? ' (Root)' : '')" :selected="rel.source_model === m.handle"></option>
                                                    </template>
                                                    <template x-if="!selectedSlice?.models || selectedSlice?.models.length === 0">
                                                        <option :value="selectedSlice?.name ? selectedSlice.name.toLowerCase() : 'model'" x-text="(selectedSlice?.name || 'model') + ' (Root)'"></option>
                                                    </template>
                                                </select>
                                            </td>
                                            <!-- Relation Type -->
                                            <td class="px-3 py-2">
                                                <select x-model="rel.type" @change="onStudioRelationTypeChange(rel)" class="h-8 w-full rounded border border-input bg-card px-2 text-[13px] font-medium text-foreground focus:ring-1 focus:ring-primary">
                                                    <option value="belongsTo">belongsTo</option>
                                                    <option value="hasMany">hasMany</option>
                                                    <option value="hasOne">hasOne</option>
                                                    <option value="belongsToMany">belongsToMany</option>
                                                </select>
                                            </td>
                                            <!-- Target Model -->
                                            <td class="px-3 py-2">
                                                <select x-model="rel.model" @change="onStudioRelationTargetChange(rel)" class="h-7 w-full rounded border border-input bg-card px-2 text-xs font-mono text-foreground focus:ring-1 focus:ring-primary">
                                                    <option value="" disabled>Select target model...</option>
                                                    <optgroup label="Current Slice Models">
                                                        <template x-for="m in (selectedSlice?.models || [])" :key="'tgt-' + m.handle">
                                                            <option :value="m.handle" x-text="m.handle + ' (' + (m.table || m.handle + 's') + ')'" :selected="rel.model === m.handle"></option>
                                                        </template>
                                                    </optgroup>
                                                    <optgroup label="External Models">
                                                        <option value="user">user (users)</option>
                                                        <option value="category">category (categories)</option>
                                                        <option value="role">role (roles)</option>
                                                        <option value="permission">permission (permissions)</option>
                                                        <option value="tag">tag (tags)</option>
                                                        <option value="order">order (orders)</option>
                                                    </optgroup>
                                                    <optgroup label="Installed Slices">
                                                        <template x-for="s in installedSlices" :key="'sl-' + s.name">
                                                            <option :value="s.name.toLowerCase()" x-text="s.name"></option>
                                                        </template>
                                                    </optgroup>
                                                </select>
                                            </td>
                                            <!-- Foreign Key -->
                                            <td class="px-3 py-2">
                                                <input type="text" x-model="rel.foreign_key" placeholder="e.g. shop_product_id" class="h-7 w-full rounded border border-input bg-card px-2 font-mono text-xs text-foreground focus:ring-1 focus:ring-primary">
                                            </td>
                                            <!-- Method Name -->
                                            <td class="px-3 py-2">
                                                <input type="text" x-model="rel.method" placeholder="e.g. variants, category" class="h-7 w-full rounded border border-input bg-card px-2 font-mono text-xs font-semibold text-foreground focus:ring-1 focus:ring-primary">
                                            </td>
                                            <!-- Delete Action -->
                                            <td class="px-3 py-2 text-right">
                                                <button type="button" @click="removeStudioRelation(rIdx)" title="Delete Relationship" class="p-1 rounded text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition cursor-pointer">
                                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </template>

                    <!-- Save Relationships Button -->
                    <div class="pt-2 flex justify-end">
                        <button type="button" @click="saveStudioRelationships()" :disabled="isSavingStudioRelations"
                                class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs rounded-xl transition shadow flex items-center gap-2 cursor-pointer disabled:opacity-50">
                            <span x-show="!isSavingStudioRelations">💾 Save & Sync Eloquent Relationships</span>
                            <span x-show="isSavingStudioRelations">Injecting Model Relations...</span>
                        </button>
                    </div>
                </div>

                
                <!-- Progressive disclosure: UI architecture collapsed until requested -->
                <div class="flex items-center justify-between">
                    <button type="button" @click="showAdvancedUi = !showAdvancedUi"
                            class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-xs font-semibold text-foreground hover:bg-accent">
                        <span x-text="showAdvancedUi ? '▾ Hide UI customization' : '▸ Customize directory & forms'"></span>
                    </button>
                </div>
                <div x-show="showAdvancedUi" x-cloak class="rounded-2xl border border-border bg-card p-6 shadow-xl space-y-4" x-data="{ uiTab: 'directory' }">

                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3.5">
                        <div class="flex items-center gap-2.5">
                            <div class="size-8 rounded-lg bg-primary/10 text-primary flex items-center justify-center font-bold text-sm">
                                🎨
                            </div>
                            <div>
                                <h3 class="font-bold text-foreground text-base">Declarative UI Architecture & Layout Studio</h3>
                                <p class="text-xs text-muted-foreground mt-0.5">Composite semantic directory columns, searchable filters, responsive form sections, and drawer modes</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <!-- Link to full visual Blueprint Studio -->
                            <a :href="'/laraslice/wizard/blueprint?slice=' + (selectedSlice?.name || '')" 
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-indigo-500/40 bg-indigo-500/10 text-indigo-400 hover:bg-indigo-500/20 text-xs font-semibold shadow-xs transition">
                                <span>⌘</span>
                                <span>Open in Blueprint Studio</span>
                            </a>
                        </div>
                    </div>

                    <!-- Sub-tabs: Directory (Index) vs Forms -->
                    <div class="flex items-center justify-between border-b border-border/60 pb-2">
                        <div class="inline-flex rounded-lg p-0.5 bg-muted/60 border border-border text-xs">
                            <button type="button" @click="uiTab = 'directory'" :class="uiTab === 'directory' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-3 py-1.5 rounded-md transition cursor-pointer flex items-center gap-1.5">
                                <span>📋 Directory Columns & Semantic Formatters</span>
                            </button>
                            <button type="button" @click="uiTab = 'filters'" :class="uiTab === 'filters' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-3 py-1.5 rounded-md transition cursor-pointer flex items-center gap-1.5">
                                <span>🔍 Multi-Dimensional Filters (Searchable Combobox)</span>
                            </button>
                            <button type="button" @click="uiTab = 'forms'" :class="uiTab === 'forms' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-3 py-1.5 rounded-md transition cursor-pointer flex items-center gap-1.5">
                                <span>📐 Create & Edit Form Sections & Drawer Mode</span>
                            </button>
                        </div>
                    </div>

                    <!-- TAB 1: DIRECTORY COLUMNS & SEMANTIC FORMATTERS -->
                    <div x-show="uiTab === 'directory'" class="space-y-3">
                        <div class="overflow-x-auto rounded-xl border border-border">
                            <table class="w-full min-w-[1080px] text-left text-[13px]">
                                <thead class="bg-muted/50 text-[11px] font-bold uppercase tracking-wider text-muted-foreground border-b border-border">
                                    <tr>
                                        <th class="w-12 px-3 py-2 text-center">In Table</th>
                                        <th class="w-40 px-3 py-2">Field Handle</th>
                                        <th class="w-48 px-3 py-2">Column Header</th>
                                        <th class="w-56 px-3 py-2">Composite Semantic Formatter</th>
                                        <th class="w-20 px-3 py-2 text-center">Sortable</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border/60 bg-card">
                                    <template x-for="(f, fIdx) in studioFields" :key="'col-' + fIdx">
                                        <tr class="hover:bg-muted/20 transition-colors">
                                            <td class="px-3 py-2 text-center">
                                                <input type="checkbox" x-model="f.show_in_list" class="rounded border-input text-primary size-3.5 cursor-pointer">
                                            </td>
                                            <td class="px-3 py-2 font-mono font-semibold text-foreground text-xs" x-text="f.handle"></td>
                                            <td class="px-3 py-2">
                                                <input type="text" x-model="f.label" class="h-7 w-full rounded border border-input bg-card px-2 text-xs text-foreground focus:ring-1 focus:ring-primary">
                                            </td>
                                            <td class="px-3 py-2">
                                                <select x-model="f.formatter" class="h-7 w-full rounded border border-input bg-card px-2 text-xs text-foreground focus:ring-1 focus:ring-primary">
                                                    <option value="text">text (Standard Raw Output)</option>
                                                    <option value="code_badge">code_badge (Mono Badge EMP-01 / INV-2024)</option>
                                                    <option value="composite_user">composite_user (Avatar + Name + Subtitle)</option>
                                                    <option value="status_pill">status_pill (Color-coded Active / Inactive pill)</option>
                                                    <option value="money_breakdown">money_breakdown (Amount + Currency Pill)</option>
                                                    <option value="progress_bar">progress_bar (0-100% Visual Progress)</option>
                                                    <option value="date_time">date_time (Formatted Timestamp)</option>
                                                </select>
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                <input type="checkbox" checked class="rounded border-input text-primary size-3.5 cursor-pointer">
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- TAB 2: MULTI-DIMENSIONAL FILTERS -->
                    <div x-show="uiTab === 'filters'" class="space-y-3">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div class="p-3.5 rounded-xl border border-border bg-muted/20 space-y-2">
                                <div class="flex items-center justify-between border-b border-border/40 pb-1.5">
                                    <span class="font-bold text-xs text-foreground flex items-center gap-1.5">
                                        <span>⚡</span> Quick Filter Bar (Above Table)
                                    </span>
                                    <span class="text-[10px] text-primary font-mono">Instant Filter</span>
                                </div>
                                <p class="text-[11px] text-muted-foreground">Appears directly above the data table for instant keystroke filtering</p>
                                <div class="space-y-1.5 pt-1">
                                    <template x-for="f in studioFields.filter(f => f.type === 'foreign_id' || f.handle.includes('status') || f.handle.includes('type') || f.handle.includes('category'))" :key="'qf-' + f.handle">
                                        <div class="flex items-center justify-between p-2 rounded-lg border border-border bg-card text-xs">
                                            <div class="flex items-center gap-2">
                                                <input type="checkbox" checked class="rounded border-border text-primary size-3.5">
                                                <span class="font-bold text-foreground" x-text="f.label || f.handle"></span>
                                            </div>
                                            <label class="inline-flex items-center gap-1.5 text-[10px] text-indigo-400 font-semibold cursor-pointer">
                                                <input type="checkbox" checked class="rounded border-indigo-500/40 text-indigo-500 size-3">
                                                <span>🔍 Searchable Dropdown (&lt;x-ui.searchable-select&gt;)</span>
                                            </label>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <div class="p-3.5 rounded-xl border border-border bg-muted/20 space-y-2">
                                <div class="flex items-center justify-between border-b border-border/40 pb-1.5">
                                    <span class="font-bold text-xs text-foreground flex items-center gap-1.5">
                                        <span>🎛️</span> Off-Canvas Drawer Filters
                                    </span>
                                    <span class="text-[10px] text-indigo-400 font-mono">Deep Filter Drawer</span>
                                </div>
                                <p class="text-[11px] text-muted-foreground">Slide-over drawer for multi-attribute filtering (date ranges, multi-select tags, secondary dimensions)</p>
                                <div class="p-3 rounded-lg border border-border/80 bg-card text-[11px] text-muted-foreground space-y-1">
                                    <div class="font-semibold text-foreground">BlatUI Off-Canvas Filter Drawer Active</div>
                                    <p>Opens seamlessly when user clicks the "Filters" toolbar button; preserves active filter states in the browser query string.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 3: FORMS & DRAWER MODE -->
                    <div x-show="uiTab === 'forms'" class="space-y-3">
                        <!-- Form Presentation Standard Toggle -->
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3.5 rounded-xl border border-primary/30 bg-primary/5">
                            <div>
                                <span class="font-bold text-xs text-foreground flex items-center gap-1.5">
                                    <span>⚡</span> Form Presentation Architecture
                                </span>
                                <p class="text-[11px] text-muted-foreground mt-0.5">Choose how Add and Edit forms open for users of this slice</p>
                            </div>
                            <div class="inline-flex rounded-lg p-0.5 bg-background border border-border text-xs">
                                <button type="button" class="px-3 py-1.5 rounded-md bg-indigo-600 text-white font-bold shadow-xs transition cursor-pointer flex items-center gap-1.5">
                                    <span>⚡ Instant Slide-Over Drawer (?drawer=create)</span>
                                </button>
                                <button type="button" class="px-3 py-1.5 rounded-md text-muted-foreground hover:text-foreground transition cursor-pointer flex items-center gap-1.5">
                                    <span>📄 Standalone Page (/create)</span>
                                </button>
                            </div>
                        </div>

                        <!-- Form Widths & Searchable Selects Table -->
                        <div class="overflow-x-auto rounded-xl border border-border">
                            <table class="w-full min-w-[1080px] text-left text-[13px]">
                                <thead class="bg-muted/50 text-[11px] font-bold uppercase tracking-wider text-muted-foreground border-b border-border">
                                    <tr>
                                        <th class="w-12 px-3 py-2 text-center">In Form</th>
                                        <th class="w-40 px-3 py-2">Field Handle</th>
                                        <th class="w-44 px-3 py-2">Display Label</th>
                                        <th class="w-44 px-3 py-2 text-center">Field Span Width</th>
                                        <th class="w-48 px-3 py-2 text-center">Searchable Combobox</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border/60 bg-card">
                                    <template x-for="(f, fIdx) in studioFields" :key="'form-' + fIdx">
                                        <tr class="hover:bg-muted/20 transition-colors">
                                            <td class="px-3 py-2 text-center">
                                                <input type="checkbox" x-model="f.show_in_form" class="rounded border-input text-primary size-3.5 cursor-pointer">
                                            </td>
                                            <td class="px-3 py-2 font-mono font-semibold text-foreground text-xs" x-text="f.handle"></td>
                                            <td class="px-3 py-2">
                                                <input type="text" x-model="f.label" class="h-7 w-full rounded border border-input bg-card px-2 text-xs text-foreground focus:ring-1 focus:ring-primary">
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                <div class="inline-flex rounded-md border border-input bg-card p-0.5 text-[10px] font-mono">
                                                    <button type="button" @click="f.width = 33" :class="f.width === 33 ? 'bg-primary text-primary-foreground font-bold rounded' : 'text-muted-foreground hover:text-foreground'" class="px-1.5 py-0.5 transition">33%</button>
                                                    <button type="button" @click="f.width = 50" :class="(f.width === 50 || !f.width) ? 'bg-primary text-primary-foreground font-bold rounded' : 'text-muted-foreground hover:text-foreground'" class="px-1.5 py-0.5 transition">50%</button>
                                                    <button type="button" @click="f.width = 66" :class="f.width === 66 ? 'bg-primary text-primary-foreground font-bold rounded' : 'text-muted-foreground hover:text-foreground'" class="px-1.5 py-0.5 transition">66%</button>
                                                    <button type="button" @click="f.width = 100" :class="f.width === 100 ? 'bg-primary text-primary-foreground font-bold rounded' : 'text-muted-foreground hover:text-foreground'" class="px-1.5 py-0.5 transition">100%</button>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                <label class="inline-flex items-center gap-1.5 text-[10px] text-muted-foreground cursor-pointer">
                                                    <input type="checkbox" :checked="f.type === 'foreign_id' || f.handle.endsWith('_id') || f.searchable" @change="f.searchable = $event.target.checked" class="rounded border-border text-primary size-3.5">
                                                    <span :class="(f.type === 'foreign_id' || f.handle.endsWith('_id') || f.searchable) ? 'text-indigo-400 font-bold' : ''">🔍 Searchable</span>
                                                </label>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Navigation & Permissions Settings (October CMS Builder equivalent) -->
                <div class="bg-card border border-border rounded-2xl p-6 shadow-xl space-y-4">
                    <div class="flex items-center justify-between pb-3 border-b border-border">
                        <div>
                            <h3 class="font-bold text-foreground text-base flex items-center gap-2">
                                <span>🧭</span> Backend Navigation & Permissions
                            </h3>
                            <p class="text-xs text-muted-foreground mt-0.5">Control where this slice appears in admin menus and who can access it</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div>
                            <label class="block text-[10px] uppercase font-bold text-muted-foreground mb-1">Navigation Menu Title</label>
                            <input type="text" x-model="navConfig.title" class="w-full px-3 py-2 bg-muted/30 border border-border rounded-lg text-foreground text-xs focus:border-primary focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase font-bold text-muted-foreground mb-1">Menu Icon & Live Preview</label>
                            <div class="flex items-center gap-2">
                                                                <span class="size-9 shrink-0 rounded-lg bg-primary/10 border border-primary/20 text-primary flex items-center justify-center text-base shadow-xs transition"
                                      :title="'Live Preview: ' + (navConfig.icon || 'box')"
                                      x-html="renderNavIcon(navConfig.icon)">
                                </span>
                                <input type="text" x-model="navConfig.icon" placeholder="shopping-bag, settings, users, shield, file-text" class="flex-1 px-3 py-2 bg-muted/30 border border-border rounded-lg text-foreground text-xs focus:border-primary focus:outline-none font-mono">
                            </div>
                            <!-- Quick Select Icon Chips -->
                            <div class="flex flex-wrap items-center gap-1.5 mt-2">
                                <span class="text-[10px] text-muted-foreground font-semibold">Quick pick:</span>
                                <template x-for="ic in [
                                    { id: 'shopping-bag', label: 'Products' },
                                    { id: 'users', label: 'Users' },
                                    { id: 'shield', label: 'Roles' },
                                    { id: 'settings', label: 'Settings' },
                                    { id: 'file-text', label: 'Invoices' },
                                    { id: 'pie-chart', label: 'Analytics' },
                                    { id: 'layout-dashboard', label: 'Dashboard' },
                                    { id: 'wallet', label: 'Billing' },
                                    { id: 'bell', label: 'Alerts' },
                                    { id: 'message-square', label: 'Chat' },
                                    { id: 'boxes', label: 'Inventory' },
                                    { id: 'tag', label: 'Tags' }
                                ]" :key="ic.id">
                                    <button type="button" 
                                            @click="navConfig.icon = ic.id"
                                            :class="navConfig.icon === ic.id ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'bg-muted/40 hover:bg-muted text-muted-foreground hover:text-foreground border border-border'"
                                            class="px-2 py-0.5 rounded-md text-[10px] flex items-center gap-1 transition cursor-pointer">
                                        <span x-text="ic.label"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase font-bold text-muted-foreground mb-1">Route / Target URL</label>
                            <input type="text" x-model="navConfig.url" placeholder="e.g. /e-commerce/products" class="w-full px-3 py-2 bg-muted/30 border border-border rounded-lg text-foreground text-xs font-mono focus:border-primary focus:outline-none">
                            <p class="text-[10px] text-muted-foreground mt-0.5">Customize URL prefix (e.g. change /e-commerce/shop_products to /e-commerce/products)</p>
                            <div class="flex flex-col sm:flex-row sm:items-center gap-3 mt-2">
                                <label class="flex items-center gap-1.5 cursor-pointer select-none">
                                    <input type="checkbox" x-model="navConfig.redirect_old" class="rounded border-border text-primary size-3.5">
                                    <span class="text-[11px] font-medium text-foreground">Create 301 Permanent Redirect from old URL</span>
                                </label>
                                <label class="flex items-center gap-1.5 cursor-pointer select-none text-rose-400">
                                    <input type="checkbox" x-model="navConfig.hidden" class="rounded border-border text-rose-500 size-3.5">
                                    <span class="text-[11px] font-medium">Hide from Sidebar Navigation</span>
                                </label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase font-bold text-muted-foreground mb-1">Sidebar Section / Group</label>
                            <input type="text" x-model="navConfig.group" placeholder="e.g. E-Commerce, System, Sales" class="w-full px-3 py-2 bg-muted/30 border border-border rounded-lg text-foreground text-xs focus:border-primary focus:outline-none">
                            <p class="text-[10px] text-muted-foreground mt-0.5">Grouping header in sidebar navigation</p>
                        </div>
                                                    <div class="md:col-span-2 p-3 bg-muted/40 rounded-xl border border-border/80 text-[11px] text-muted-foreground space-y-1">
                                <div class="font-bold text-foreground flex items-center gap-1.5 text-xs">
                                    <span>🌐</span> Domain & Group Navigation Ordering
                                </div>
                                <p>
                                    Domains (e.g. <strong>Billing</strong>, <strong>CRM</strong>, <strong>E-Commerce</strong>) act as collapsible section headers grouping your slices.
                                    The order of domain groups in the sidebar is determined by the lowest <strong>Menu Order</strong> among its member slices (e.g. order <code class="font-mono text-primary font-semibold">10</code> appears before order <code class="font-mono text-primary font-semibold">20</code>).
                                </p>
                            </div>
                        <div>
                            <label class="block text-[10px] uppercase font-bold text-muted-foreground mb-1">Menu Order</label>
                            <input type="number" x-model="navConfig.order" class="w-full px-3 py-2 bg-muted/30 border border-border rounded-lg text-foreground text-xs focus:border-primary focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase font-bold text-muted-foreground mb-1">Sidebar Navigation Gate</label>
                            <div class="flex items-center gap-1.5">
                                <select x-model="navConfig.permission" class="w-full px-3 py-2 bg-muted/30 border border-border rounded-lg text-foreground text-xs font-mono focus:border-primary focus:outline-none">
                                    <option value="">Public / Unrestricted (Visible to all)</option>
                                    <template x-for="p in navConfig.permissions" :key="formatPerm(p)">
                                        <option :value="formatPerm(p)" x-text="formatPerm(p)"></option>
                                    </template>
                                </select>
                            </div>
                            <p class="text-[10px] text-muted-foreground mt-0.5">Permission required for this item to appear in the sidebar</p>
                        </div>
                    </div>

                    <!-- Slice Permissions & Capabilities Roster -->
                    <div class="pt-4 border-t border-border/60 space-y-3">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                            <div>
                                <label class="text-xs font-bold text-foreground flex items-center gap-1.5">
                                    <svg class="inline-block size-3.5 text-primary" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>
                                    <span>Declared Slice Permissions & Capabilities</span>
                                    <span class="text-[10px] font-mono px-1.5 py-0.5 rounded-full bg-primary/10 text-primary font-semibold" x-text="(navConfig.permissions || []).length + ' capabilities'"></span>
                                </label>
                                <p class="text-[11px] text-muted-foreground">All authorization keys generated and enforced for this slice. Auto-synced to Roles & Permissions.</p>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <input type="text" x-model="newPermInput" @keydown.enter.prevent="addPermissionToList()" placeholder="e.g. publish, export" class="h-8 px-2.5 bg-background border border-border rounded-lg text-xs font-mono text-foreground focus:border-primary focus:outline-none w-36">
                                <button type="button" @click="addPermissionToList()" class="h-8 px-3 bg-primary text-primary-foreground text-xs font-semibold rounded-lg hover:bg-primary/90 transition flex items-center gap-1 cursor-pointer">
                                    <svg class="inline-block size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                                    <span>Add</span>
                                </button>
                            </div>
                        </div>

                        <!-- Permissions Badges Grid -->
                        <div class="flex flex-wrap gap-2 pt-1">
                            <template x-for="(perm, pIdx) in navConfig.permissions" :key="pIdx">
                                <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border text-xs font-mono transition"
                                     :class="navConfig.permission === formatPerm(perm) ? 'bg-primary/10 border-primary/40 text-primary font-bold shadow-xs' : 'bg-muted/30 border-border text-foreground'">
                                    <span class="size-2 rounded-full" :class="formatPerm(perm).endsWith('.view') ? 'bg-blue-500' : (formatPerm(perm).endsWith('.create') ? 'bg-emerald-500' : (formatPerm(perm).endsWith('.edit') ? 'bg-amber-500' : (formatPerm(perm).endsWith('.delete') ? 'bg-rose-500' : 'bg-purple-500')))"></span>
                                    <span x-text="formatPerm(perm)"></span>
                                    <span x-show="navConfig.permission === formatPerm(perm)" class="text-[9px] uppercase px-1 py-0.2 rounded bg-primary text-primary-foreground font-semibold">Sidebar Gate</span>
                                    <button type="button" @click="navConfig.permissions.splice(pIdx, 1); if (navConfig.permission === formatPerm(perm)) navConfig.permission = formatPerm(navConfig.permissions[0] || '')"
                                            class="text-muted-foreground hover:text-destructive transition ml-1 cursor-pointer font-bold" title="Remove capability">
                                        &times;
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Submenu Dropdown Items (e.g. Categories, Inventory) -->
                    <div class="pt-3 border-t border-border/60 space-y-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <label class="text-xs font-bold text-foreground flex items-center gap-1.5">
                                    <span>📑</span> Dropdown Submenu Links (Child Items)
                                </label>
                                <p class="text-[11px] text-muted-foreground">Define child links that will collapse and expand under this slice in the sidebar (e.g. Categories, Inventory)</p>
                            </div>
                            <button type="button" @click="navConfig.children.push({ label: '', url: '' })"
                                    class="px-2.5 py-1.5 bg-muted hover:bg-muted/80 text-foreground border border-border text-xs font-bold rounded-lg transition cursor-pointer flex items-center gap-1">
                                <span>+</span> Add Submenu Item
                            </button>
                        </div>

                                                <div class="space-y-2" x-show="navConfig.children && navConfig.children.length > 0">
                            <template x-for="(sub, sIdx) in navConfig.children" :key="sIdx">
                                <div class="flex items-center gap-2 bg-muted/20 p-2.5 rounded-xl border border-border/80">
                                    <!-- Move Up / Down Buttons -->
                                    <div class="flex items-center gap-1 shrink-0 mt-3.5">
                                        <button type="button" @click="moveSubmenu(sIdx, -1)" :disabled="sIdx === 0"
                                                class="size-7 rounded-lg bg-background border border-border text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-30 disabled:pointer-events-none flex items-center justify-center transition cursor-pointer text-[10px] font-bold"
                                                title="Move item up">
                                            ▲
                                        </button>
                                        <button type="button" @click="moveSubmenu(sIdx, 1)" :disabled="sIdx === navConfig.children.length - 1"
                                                class="size-7 rounded-lg bg-background border border-border text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-30 disabled:pointer-events-none flex items-center justify-center transition cursor-pointer text-[10px] font-bold"
                                                title="Move item down">
                                            ▼
                                        </button>
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-[9px] uppercase font-bold text-muted-foreground mb-0.5">Label</label>
                                        <input type="text" x-model="sub.label" placeholder="e.g. Categories"
                                               class="w-full px-2.5 py-1.5 bg-background border border-border rounded-lg text-foreground text-xs font-medium focus:border-primary focus:outline-none">
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-[9px] uppercase font-bold text-muted-foreground mb-0.5">URL or Route Name</label>
                                        <input type="text" x-model="sub.url" placeholder="e.g. /products?view=categories or users.index"
                                               class="w-full px-2.5 py-1.5 bg-background border border-border rounded-lg text-foreground text-xs font-mono focus:border-primary focus:outline-none">
                                    </div>
                                    <button type="button" @click="navConfig.children.splice(sIdx, 1)"
                                            class="size-8 mt-3.5 rounded-lg text-muted-foreground hover:text-destructive hover:bg-destructive/10 flex items-center justify-center transition cursor-pointer shrink-0"
                                            title="Remove submenu link">
                                        <svg class="inline-block size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="pt-2 flex justify-end">
                        <button @click="submitNavigation()" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-500 text-foreground font-bold text-xs rounded-lg transition shadow cursor-pointer">
                            Save Navigation Settings
                        </button>
                    </div>
                </div>

                <!-- Version History (October CMS version.yaml equivalent) -->
                <div class="bg-card border border-border rounded-2xl p-6 shadow-xl space-y-4">
                    <div class="flex items-center justify-between pb-3 border-b border-border">
                        <div>
                            <h3 class="font-bold text-foreground text-base flex items-center gap-2">
                                <span>📜</span> Version History & Migration Changelog
                            </h3>
                            <p class="text-xs text-muted-foreground mt-0.5">Chronological record of schema evolutions, migrations, and rollbacks</p>
                        </div>
                    </div>

                    <div class="space-y-2 text-xs">
                        <template x-for="(hist, hIdx) in (selectedSlice?.version_history || [])" :key="hist.version + hist.date + hIdx">
                            <div class="p-3 bg-muted/30 rounded-xl border border-border flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-muted/40 transition">
                                <div class="space-y-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-bold text-primary font-mono text-xs" x-text="'v' + hist.version"></span>
                                        <span class="text-foreground/90 font-medium" x-text="hist.description"></span>
                                        
                                        <!-- Type / Migration Badge -->
                                        <template x-if="hist.migration && hist.migration !== 'undefined'">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-indigo-500/10 text-indigo-400 border border-indigo-500/20" x-text="'Migration: ' + hist.migration"></span>
                                        </template>
                                        <template x-if="(!hist.migration || hist.migration === 'undefined') && (hist.type === 'navigation_update' || hist.description?.includes('Route') || hist.description?.includes('Menu'))">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-500/10 text-amber-500 border border-amber-500/20">🧭 Navigation & Route</span>
                                        </template>
                                        <template x-if="hist.type === 'rollback'">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-purple-500/10 text-purple-400 border border-purple-500/20">↺ Restored Version</span>
                                        </template>
                                        <template x-if="hist.type === 'relationships_update'">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-blue-500/10 text-blue-400 border border-blue-500/20">🔗 Relationships</span>
                                        </template>
                                        <template x-if="(!hist.migration || hist.migration === 'undefined') && !hist.type && !hist.description?.includes('Route') && !hist.description?.includes('Menu')">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/10 text-emerald-500 border border-emerald-500/20">🌱 Baseline Scaffold</span>
                                        </template>
                                    </div>
                                    <div class="text-[10px] text-muted-foreground font-mono flex items-center gap-2">
                                        <span x-text="hist.date"></span>
                                        <span x-show="hist.author" x-text="'• ' + hist.author"></span>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2 shrink-0">
                                    <button type="button"
                                            @click="rollbackVersion(hist, hIdx)"
                                            :disabled="isRollingBack"
                                            class="px-3 py-1.5 bg-background hover:bg-rose-500/10 text-muted-foreground hover:text-rose-400 border border-border hover:border-rose-500/30 rounded-lg text-xs font-semibold transition cursor-pointer flex items-center gap-1.5 shadow-2xs disabled:opacity-40"
                                            :title="'Restore slice to version ' + hist.version">
                                        <span>↺</span>
                                        <span x-text="hIdx === (selectedSlice?.version_history?.length - 1) ? 'Undo This Change' : 'Restore to this Version'"></span>
                                    </button>
                                </div>
                            </div>
                        </template>
                        <div x-show="!selectedSlice?.version_history || selectedSlice?.version_history.length === 0" class="text-muted-foreground text-xs py-2">
                            Initial baseline version v1.0.0. Use "+ Add Field" above to evolve this slice.
                        </div>
                    </div>
                </div>

                <!-- Enterprise Audit Trail & Compliance Activity Log -->
                <div class="bg-card border border-border rounded-2xl p-6 shadow-xl space-y-4">
                    <div class="flex items-center justify-between pb-3 border-b border-border">
                        <div>
                            <h3 class="font-bold text-foreground text-base flex items-center gap-2">
                                <svg class="inline-block size-4 text-primary" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>
                                <span>Enterprise Audit Trail & Activity Logs</span>
                                <span class="px-2 py-0.5 rounded-full bg-primary/10 text-primary text-[10px] font-mono font-bold" x-text="auditLogs.length + ' entries'"></span>
                            </h3>
                            <p class="text-xs text-muted-foreground mt-0.5">Immutable regulatory compliance log capturing entity creations, updates, and deletions</p>
                        </div>
                        <button type="button" @click="showAuditPruneModal = true"
                                    class="px-3 py-1.5 bg-rose-500/10 hover:bg-rose-500/20 text-rose-500 border border-rose-500/30 rounded-lg text-xs font-semibold transition flex items-center gap-1.5 cursor-pointer">
                                <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                                <span>Prune Logs</span>
                            </button>
                            <button type="button" @click="loadAuditLogs(selectedSlice?.name)" :disabled="isLoadingAuditLogs"
                                class="px-3 py-1.5 bg-muted/40 hover:bg-muted text-foreground border border-border rounded-lg text-xs font-semibold transition flex items-center gap-1.5 cursor-pointer disabled:opacity-50">
                            <span :class="isLoadingAuditLogs ? 'animate-spin' : ''">↺</span>
                            <span>Refresh Logs</span>
                        </button>
                    </div>

                                        <!-- Audit Search & Action Filter Toolbar -->
                    <div class="p-3 bg-muted/30 rounded-xl border border-border/80 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                        <div class="flex-1 relative">
                            <input type="text" x-model="auditSearch" @input.debounce.300ms="loadAuditLogs(selectedSlice?.name)"
                                   placeholder="Search by ID, user email, IP, or payload changes..."
                                   class="w-full bg-background border border-border rounded-lg pl-8 pr-3 py-1.5 text-xs text-foreground placeholder:text-muted-foreground focus:ring-1 focus:ring-primary focus:border-primary">
                            <span class="absolute left-2.5 top-2 text-muted-foreground text-xs">🔍</span>
                            <button type="button" x-show="auditSearch" @click="auditSearch = ''; loadAuditLogs(selectedSlice?.name)" class="absolute right-2.5 top-2 text-muted-foreground hover:text-foreground text-xs">✕</button>
                        </div>
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <button type="button" @click="auditActionFilter = 'all'; loadAuditLogs(selectedSlice?.name)"
                                    class="px-2.5 py-1 rounded-md text-[11px] font-semibold transition"
                                    :class="auditActionFilter === 'all' ? 'bg-primary text-primary-foreground shadow-xs' : 'bg-muted/60 text-muted-foreground hover:text-foreground'">
                                All (<span x-text="auditCounts.all || 0"></span>)
                            </button>
                            <button type="button" @click="auditActionFilter = 'created'; loadAuditLogs(selectedSlice?.name)"
                                    class="px-2.5 py-1 rounded-md text-[11px] font-semibold transition"
                                    :class="auditActionFilter === 'created' ? 'bg-emerald-600 text-white shadow-xs' : 'bg-muted/60 text-emerald-600 hover:text-foreground'">
                                Created (<span x-text="auditCounts.created || 0"></span>)
                            </button>
                            <button type="button" @click="auditActionFilter = 'updated'; loadAuditLogs(selectedSlice?.name)"
                                    class="px-2.5 py-1 rounded-md text-[11px] font-semibold transition"
                                    :class="auditActionFilter === 'updated' ? 'bg-amber-600 text-white shadow-xs' : 'bg-muted/60 text-amber-600 hover:text-foreground'">
                                Updated (<span x-text="auditCounts.updated || 0"></span>)
                            </button>
                            <button type="button" @click="auditActionFilter = 'deleted'; loadAuditLogs(selectedSlice?.name)"
                                    class="px-2.5 py-1 rounded-md text-[11px] font-semibold transition"
                                    :class="auditActionFilter === 'deleted' ? 'bg-rose-600 text-white shadow-xs' : 'bg-muted/60 text-rose-600 hover:text-foreground'">
                                Deleted (<span x-text="auditCounts.deleted || 0"></span>)
                            </button>
                        </div>
                    </div>

                    <div class="space-y-3 text-xs">
                        <template x-for="log in auditLogs" :key="log.id">
                            <div class="p-3.5 bg-muted/20 hover:bg-muted/30 rounded-xl border border-border/80 transition space-y-2.5">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <!-- Action Badge -->
                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider"
                                              :class="{
                                                  'bg-emerald-500/10 text-emerald-500 border border-emerald-500/20': log.action === 'created',
                                                  'bg-amber-500/10 text-amber-500 border border-amber-500/20': log.action === 'updated',
                                                  'bg-rose-500/10 text-rose-500 border border-rose-500/20': log.action === 'deleted',
                                                  'bg-blue-500/10 text-blue-400 border border-blue-500/20': !['created', 'updated', 'deleted'].includes(log.action)
                                              }"
                                              x-text="log.action"></span>

                                        <span class="font-bold text-foreground text-xs" x-text="(log.entity_type ? log.entity_type.split('\\').pop() : 'Record') + (log.entity_id ? ' #' + log.entity_id : '')"></span>
                                        <span class="text-muted-foreground">•</span>
                                        <span class="text-[11px] text-foreground/80 font-medium" x-text="log.actor_email || (log.actor_id ? 'User #' + log.actor_id : 'System / Background')"></span>
                                    </div>

                                    <div class="flex items-center gap-3 text-[10px] text-muted-foreground font-mono">
                                        <span x-text="log.created_at"></span>
                                        <button type="button" @click="expandedAuditLogId = (expandedAuditLogId === log.id ? null : log.id)"
                                                class="px-2 py-0.5 rounded bg-muted hover:bg-muted/80 text-foreground transition cursor-pointer font-sans text-[11px] font-semibold flex items-center gap-1">
                                            <span x-text="expandedAuditLogId === log.id ? 'Hide Diff' : 'View Diff'"></span>
                                            <span x-text="expandedAuditLogId === log.id ? '▲' : '▼'" class="text-[8px]"></span>
                                        </button>
                                    </div>
                                </div>

                                <!-- Expanded Field Diff Viewer -->
                                <div x-show="expandedAuditLogId === log.id" class="pt-2 border-t border-border/60 space-y-2">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-[11px]">
                                        <!-- Old Values (if updated or deleted) -->
                                        <div x-show="log.old_values && Object.keys(log.old_values).length > 0" class="bg-card/70 p-2.5 rounded-lg border border-rose-500/20">
                                            <p class="font-bold text-rose-400 text-[10px] uppercase mb-1.5">Previous State</p>
                                            <div class="space-y-1 font-mono text-[10px]">
                                                <template x-for="(val, key) in log.old_values" :key="key">
                                                    <div class="flex items-start justify-between gap-2 border-b border-border/30 pb-0.5">
                                                        <span class="text-muted-foreground font-semibold" x-text="key + ':'"></span>
                                                        <span class="text-rose-300 truncate max-w-[200px]" x-text="typeof val === 'object' ? JSON.stringify(val) : val"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>

                                        <!-- New Values (if created or updated) -->
                                        <div x-show="log.new_values && Object.keys(log.new_values).length > 0" class="bg-card/70 p-2.5 rounded-lg border border-emerald-500/20">
                                            <p class="font-bold text-emerald-400 text-[10px] uppercase mb-1.5">New State</p>
                                            <div class="space-y-1 font-mono text-[10px]">
                                                <template x-for="(val, key) in log.new_values" :key="key">
                                                    <div class="flex items-start justify-between gap-2 border-b border-border/30 pb-0.5">
                                                        <span class="text-muted-foreground font-semibold" x-text="key + ':'"></span>
                                                        <span class="text-emerald-300 truncate max-w-[200px]" x-text="typeof val === 'object' ? JSON.stringify(val) : val"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- IP & User Agent Metadata -->
                                    <div class="flex flex-wrap items-center gap-3 text-[10px] text-muted-foreground font-mono pt-1">
                                        <span x-show="log.ip_address" x-text="'IP: ' + log.ip_address"></span>
                                        <span x-show="log.user_agent" x-text="'Client: ' + log.user_agent" class="truncate max-w-sm"></span>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <div x-show="auditLogs.length === 0 && !isLoadingAuditLogs" class="text-muted-foreground text-xs py-4 text-center border border-dashed border-border rounded-xl">
                            <svg class="inline-block size-6 text-muted-foreground/40 mx-auto mb-1.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>
                            <p class="font-semibold text-foreground/80">No audit records captured yet for this slice.</p>
                            <p class="text-[11px] text-muted-foreground mt-0.5">Model actions utilizing the <code class="text-primary font-mono font-semibold">AuditableSlice</code> trait will automatically appear here with granular field diffs.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Floating Toast Notification -->
        <div x-show="toastMessage" x-cloak
             class="fixed bottom-6 right-6 z-50 max-w-sm px-4 py-3 rounded-2xl shadow-2xl border flex items-center gap-3 animate-in slide-in-from-bottom-5 duration-200"
             :class="toastType === 'error' ? 'bg-destructive text-white border-destructive' : 'bg-card text-foreground border-primary/40 shadow-primary/10'">
            <span class="text-base" x-text="toastType === 'error' ? '✕' : '✓'"></span>
            <span class="text-xs font-semibold leading-relaxed" x-text="toastMessage"></span>
            <button type="button" @click="toastMessage = ''" class="ml-auto text-muted-foreground hover:text-foreground text-xs font-bold cursor-pointer">✕</button>
        </div>

        <!-- Action Running Floating Indicator -->
        <div x-show="isActionRunning" x-cloak
             class="fixed top-6 right-6 z-50 px-4 py-2.5 rounded-2xl shadow-2xl border border-primary/40 bg-card text-foreground flex items-center gap-3 animate-pulse">
            <svg class="animate-spin size-4 text-primary" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            <span class="text-xs font-bold" x-text="actionStatusText || 'Working on slices...'"></span>
        </div>
        <!-- Delete Slice / Domain Confirmation Modal -->
        <div x-show="deleteModal.open" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-background/80 backdrop-blur-sm animate-in fade-in duration-150"
             @keydown.escape.window="if (!deleteModal.isDeleting) deleteModal.open = false">
            <div class="relative w-full max-w-lg bg-card border border-destructive/30 rounded-2xl shadow-2xl p-6 space-y-5 animate-in zoom-in-95 duration-150"
                 @click.outside="if (!deleteModal.isDeleting) deleteModal.open = false">
                <!-- Header -->
                <div class="flex items-start gap-4">
                    <div class="size-11 rounded-xl bg-destructive/15 text-destructive border border-destructive/20 flex items-center justify-center shrink-0 text-xl font-bold">
                        <span>🗑</span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="text-base font-bold text-foreground flex items-center gap-2">
                            <span>Remove</span>
                            <span class="capitalize" x-text="deleteModal.targetType"></span>:
                            <span class="text-destructive font-mono" x-text="deleteModal.targetName"></span>
                        </h3>
                        <p class="text-xs text-muted-foreground mt-1">
                            Choose how you would like to handle the codebase, database tables, and routes for this <span x-text="deleteModal.targetType"></span>.
                        </p>
                    </div>
                    <button type="button" @click="deleteModal.open = false" :disabled="deleteModal.isDeleting"
                            class="text-muted-foreground hover:text-foreground p-1 rounded-lg hover:bg-muted transition cursor-pointer">
                        ✕
                    </button>
                </div>

                <!-- Error Notice -->
                <div x-show="deleteModal.errorMessage" x-cloak class="p-3 bg-destructive/10 border border-destructive/30 rounded-xl text-destructive text-xs flex items-center gap-2">
                    <span>⚠️</span>
                    <span x-text="deleteModal.errorMessage"></span>
                </div>

                <!-- Granular Removal Mode Selection -->
                <div class="space-y-2">
                    <label class="block text-[10px] uppercase font-bold text-muted-foreground">Select Deletion Scope</label>
                    
                    <label class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition"
                           :class="deleteModal.mode === 'complete' ? 'border-destructive bg-destructive/10 text-foreground ring-1 ring-destructive/40' : 'border-border/60 bg-muted/20 hover:bg-muted/40 text-muted-foreground'">
                        <input type="radio" name="deleteMode" value="complete" x-model="deleteModal.mode" class="mt-0.5 text-destructive focus:ring-destructive">
                        <div>
                            <div class="font-bold text-xs text-foreground flex items-center gap-1.5">
                                <span>Complete Removal</span>
                                <span class="text-[10px] px-1.5 py-0.2 rounded bg-destructive text-destructive-foreground font-semibold">Recommended</span>
                            </div>
                            <p class="text-[11px] text-muted-foreground mt-0.5">Removes slice folder, views, controllers, routes, and drops all database tables.</p>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition"
                           :class="deleteModal.mode === 'code_only' ? 'border-primary bg-primary/10 text-foreground ring-1 ring-primary/40' : 'border-border/60 bg-muted/20 hover:bg-muted/40 text-muted-foreground'">
                        <input type="radio" name="deleteMode" value="code_only" x-model="deleteModal.mode" class="mt-0.5 text-primary focus:ring-primary">
                        <div>
                            <div class="font-bold text-xs text-foreground">Code & Routes Only</div>
                            <p class="text-[11px] text-muted-foreground mt-0.5">Deletes slice code, views, and routes, but keeps database tables and records intact.</p>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition"
                           :class="deleteModal.mode === 'db_only' ? 'border-amber-500 bg-amber-500/10 text-foreground ring-1 ring-amber-500/40' : 'border-border/60 bg-muted/20 hover:bg-muted/40 text-muted-foreground'">
                        <input type="radio" name="deleteMode" value="db_only" x-model="deleteModal.mode" class="mt-0.5 text-amber-500 focus:ring-amber-500">
                        <div>
                            <div class="font-bold text-xs text-foreground">Database Tables Only</div>
                            <p class="text-[11px] text-muted-foreground mt-0.5">Drops all database tables, but preserves PHP classes and Blade files.</p>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition"
                           :class="deleteModal.mode === 'wipe_data' ? 'border-amber-500 bg-amber-500/10 text-foreground ring-1 ring-amber-500/40' : 'border-border/60 bg-muted/20 hover:bg-muted/40 text-muted-foreground'">
                        <input type="radio" name="deleteMode" value="wipe_data" x-model="deleteModal.mode" class="mt-0.5 text-amber-500 focus:ring-amber-500">
                        <div>
                            <div class="font-bold text-xs text-foreground">Wipe Data Only (Truncate)</div>
                            <p class="text-[11px] text-muted-foreground mt-0.5">Truncates all records from associated tables while keeping table structures and code.</p>
                        </div>
                    </label>
                </div>

                <!-- Footer Actions -->
                <div class="pt-3 border-t border-border/60 flex items-center justify-end gap-3">
                    <button type="button" @click="deleteModal.open = false" :disabled="deleteModal.isDeleting"
                            class="px-4 py-2 bg-muted hover:bg-muted/80 text-foreground text-xs font-semibold rounded-xl transition cursor-pointer">
                        Cancel
                    </button>
                    <button type="button" @click="confirmDelete()" :disabled="deleteModal.isDeleting"
                            class="px-5 py-2 bg-destructive hover:bg-destructive/90 text-white text-xs font-bold rounded-xl transition shadow flex items-center gap-2 cursor-pointer disabled:opacity-50">
                        <svg x-show="deleteModal.isDeleting" class="animate-spin size-3.5 text-white" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <span x-text="deleteModal.isDeleting ? 'Deleting...' : 'Confirm Remove'"></span>
                    </button>
                </div>
            </div>
        </div>

        @include('laraslice::partials.porting-modal')

    </div>

    <!-- YAML diff confirm modal (no silent overwrite) -->
    <div x-show="showDiffModal" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center bg-black/50 p-4"
         @keydown.escape.window="showDiffModal = false">
        <div class="w-full max-w-3xl rounded-2xl border border-border bg-card shadow-2xl flex flex-col max-h-[85vh]">
            <div class="flex items-center justify-between border-b border-border px-5 py-3">
                <h3 class="text-sm font-bold text-foreground">Review YAML changes before save</h3>
                <button type="button" @click="showDiffModal = false" class="text-muted-foreground hover:text-foreground text-xs">Close</button>
            </div>
            <pre class="flex-1 overflow-auto p-4 text-[11px] font-mono leading-relaxed text-foreground bg-muted/30" x-text="pendingDiff || '(no changes)'"></pre>
            <div class="flex items-center justify-end gap-2 border-t border-border px-5 py-3">
                <button type="button" @click="showDiffModal = false; pendingSavePayload = null"
                        class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-foreground hover:bg-accent">Cancel</button>
                <button type="button" @click="confirmStudioSave()"
                        class="rounded-lg bg-primary px-3 py-1.5 text-xs font-bold text-primary-foreground hover:bg-primary/90">Save & write history</button>
            </div>
        </div>
    </div>

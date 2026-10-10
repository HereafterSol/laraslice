@extends(view()->exists('layouts.app') ? 'layouts.app' : 'laraslice::layout')

@section('content')
<style>[x-cloak] { display: none !important; }</style>
<script>
function larasliceWizard() {
    return {
        mainTab: @js(old('tab', $initialTab ?? (request()->query('tab') ?: (request()->routeIs('*studio*') ? 'studio' : 'wizard')))),
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
            if (preset === 'crm_suite') {
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
                            { name: 'amount', label: 'Deal Amount ($)', type: 'decimal', width: '50%', required: true, nullable: false, optionsText: '14,2', default: '0.00' },
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
                this.projectName = 'Products, Orders, Customers, Categories';
                this.domain = 'E-Commerce';
                this.description = 'Complete E-Commerce Store with Products catalog, Customer Orders, Customers, and Categories';
                this.multiSliceData = {
                    'Products': {
                        fields: [
                            { name: 'name', label: 'Product Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '255', default: '' },
                            { name: 'sku', label: 'SKU', type: 'string', width: '50%', required: true, nullable: false, optionsText: '100', default: '' },
                            { name: 'price', label: 'Price ($)', type: 'decimal', width: '50%', required: true, nullable: false, optionsText: '10,2', default: '0.00' },
                            { name: 'stock_quantity', label: 'Stock Qty', type: 'integer', width: '50%', required: true, nullable: false, optionsText: '', default: '0' },
                            { name: 'status', label: 'Status', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'draft,published,archived', default: 'published' }
                        ],
                        childTables: []
                    },
                    'Orders': {
                        fields: [
                            { name: 'order_number', label: 'Order Number', type: 'string', width: '50%', required: true, nullable: false, optionsText: '100', default: '' },
                            { name: 'customer_id', label: 'Customer ID', type: 'integer', width: '50%', required: true, nullable: false, optionsText: '', default: '' },
                            { name: 'total_amount', label: 'Total Amount ($)', type: 'decimal', width: '50%', required: true, nullable: false, optionsText: '12,2', default: '0.00' },
                            { name: 'status', label: 'Order Status', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'pending,processing,shipped,completed,cancelled', default: 'pending' }
                        ],
                        childTables: []
                    },
                    'Customers': {
                        fields: [
                            { name: 'name', label: 'Full Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: '' },
                            { name: 'email', label: 'Customer Email', type: 'email', width: '50%', required: true, nullable: false, optionsText: '255', default: '' },
                            { name: 'phone', label: 'Phone Number', type: 'string', width: '50%', required: false, nullable: true, optionsText: '50', default: '' }
                        ],
                        childTables: []
                    },
                    'Categories': {
                        fields: [
                            { name: 'name', label: 'Category Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: '' },
                            { name: 'slug', label: 'URL Slug', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: '' },
                            { name: 'description', label: 'Description', type: 'text', width: '100%', required: false, nullable: true, optionsText: '', default: '' }
                        ],
                        childTables: []
                    }
                };
                this.activeSliceIdx = 0;
                this.customFields = JSON.parse(JSON.stringify(this.multiSliceData['Products'].fields));
                this.childTables = [];
                this.wizardTargetTable = 'root';
            } else if (preset === 'billing_suite') {
                this.projectName = 'Invoices, Payments, Subscriptions';
                this.domain = 'Billing';
                this.description = 'Enterprise Billing & Invoicing Suite with recurring Subscriptions and Payment ledgers';
                this.multiSliceData = {
                    'Invoices': {
                        fields: [
                            { name: 'invoice_number', label: 'Invoice #', type: 'string', width: '50%', required: true, nullable: false, optionsText: '50', default: '' },
                            { name: 'amount', label: 'Total Amount ($)', type: 'decimal', width: '50%', required: true, nullable: false, optionsText: '14,2', default: '0.00' },
                            { name: 'due_date', label: 'Due Date', type: 'date', width: '50%', required: true, nullable: false, optionsText: '', default: '' },
                            { name: 'status', label: 'Invoice Status', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'draft,sent,paid,overdue,void', default: 'draft' }
                        ],
                        childTables: []
                    },
                    'Payments': {
                        fields: [
                            { name: 'reference', label: 'Payment Ref #', type: 'string', width: '50%', required: true, nullable: false, optionsText: '100', default: '' },
                            { name: 'invoice_id', label: 'Invoice ID', type: 'integer', width: '50%', required: false, nullable: true, optionsText: '', default: '' },
                            { name: 'amount', label: 'Paid Amount ($)', type: 'decimal', width: '50%', required: true, nullable: false, optionsText: '14,2', default: '0.00' },
                            { name: 'method', label: 'Payment Method', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'card,bank_transfer,stripe,cash', default: 'card' },
                            { name: 'status', label: 'Status', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'pending,succeeded,failed', default: 'succeeded' }
                        ],
                        childTables: []
                    },
                    'Subscriptions': {
                        fields: [
                            { name: 'plan_name', label: 'Plan Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: 'Pro Plan' },
                            { name: 'billing_cycle', label: 'Billing Cycle', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'monthly,yearly,quarterly', default: 'monthly' },
                            { name: 'price', label: 'Subscription Price ($)', type: 'decimal', width: '50%', required: true, nullable: false, optionsText: '10,2', default: '29.00' },
                            { name: 'status', label: 'Status', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'trial,active,past_due,cancelled', default: 'active' }
                        ],
                        childTables: []
                    }
                };
                this.activeSliceIdx = 0;
                this.customFields = JSON.parse(JSON.stringify(this.multiSliceData['Invoices'].fields));
                this.childTables = [];
                this.wizardTargetTable = 'root';
            } else if (preset === 'ecommerce') {
                this.projectName = 'Product';
                this.domain = 'E-Commerce';
                this.description = 'Catalog products with variants, pricing, inventory, and categorization';
                this.customFields = [
                    { name: 'sku', label: 'SKU', type: 'string', width: '50%', required: true, nullable: false, optionsText: '100', default: '' },
                    { name: 'price', label: 'Price ($)', type: 'decimal', width: '50%', required: true, nullable: false, optionsText: '10,2', default: '0.00' },
                    { name: 'cost_price', label: 'Cost Price ($)', type: 'decimal', width: '50%', required: false, nullable: true, optionsText: '10,2', default: '0.00' },
                    { name: 'stock_quantity', label: 'Stock Qty', type: 'integer', width: '50%', required: true, nullable: false, optionsText: '', default: '0' },
                    { name: 'is_featured', label: 'Featured Product', type: 'boolean', width: '33%', required: false, nullable: true, optionsText: '', default: '0' },
                    { name: 'status', label: 'Status', type: 'select', width: '33%', required: true, nullable: false, optionsText: 'draft,published,archived', default: 'draft' }
                ];
                this.childTables = [
                    {
                        name: 'product_variants',
                        label: 'Product Variants',
                        relation: 'hasMany',
                        fields: [
                            { name: 'sku', label: 'Variant SKU', type: 'string', width: '50%', required: true, nullable: false, optionsText: '100', default: '' },
                            { name: 'title', label: 'Option Title', type: 'string', width: '50%', required: true, nullable: false, optionsText: '255', default: '' },
                            { name: 'price_override', label: 'Price Override', type: 'decimal', width: '50%', required: false, nullable: true, optionsText: '10,2', default: '' },
                            { name: 'stock_quantity', label: 'Variant Stock', type: 'integer', width: '50%', required: true, nullable: false, optionsText: '', default: '0' }
                        ]
                    }
                ];
                this.wizardTargetTable = 'root';
            } else if (preset === 'crm') {
                this.projectName = 'Company';
                this.domain = 'CRM';
                this.description = 'B2B Enterprise accounts, pipeline leads, and multi-contact relationships';
                this.customFields = [
                    { name: 'industry', label: 'Industry', type: 'string', width: '50%', required: false, nullable: true, optionsText: '150', default: '' },
                    { name: 'website', label: 'Website URL', type: 'string', width: '50%', required: false, nullable: true, optionsText: '255', default: '' },
                    { name: 'phone', label: 'Office Phone', type: 'string', width: '50%', required: false, nullable: true, optionsText: '50', default: '' },
                    { name: 'annual_revenue', label: 'Annual Revenue', type: 'decimal', width: '50%', required: false, nullable: true, optionsText: '14,2', default: '0.00' },
                    { name: 'status', label: 'Account Tier', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'prospect,customer,churned', default: 'prospect' }
                ];
                this.childTables = [
                    {
                        name: 'contacts',
                        label: 'Key Contacts',
                        relation: 'hasMany',
                        fields: [
                            { name: 'first_name', label: 'First Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '100', default: '' },
                            { name: 'last_name', label: 'Last Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '100', default: '' },
                            { name: 'email', label: 'Work Email', type: 'email', width: '50%', required: true, nullable: false, optionsText: '255', default: '' },
                            { name: 'phone', label: 'Direct Phone', type: 'string', width: '50%', required: false, nullable: true, optionsText: '50', default: '' },
                            { name: 'job_title', label: 'Job Title', type: 'string', width: '50%', required: false, nullable: true, optionsText: '150', default: '' }
                        ]
                    }
                ];
                this.wizardTargetTable = 'root';
            } else if (preset === 'helpdesk') {
                this.projectName = 'Ticket';
                this.domain = 'Helpdesk';
                this.description = 'Customer support ticketing, SLA prioritization, and threaded responses';
                this.customFields = [
                    { name: 'ticket_number', label: 'Ticket #', type: 'string', width: '33%', required: true, nullable: false, optionsText: '50', default: '' },
                    { name: 'priority', label: 'Priority', type: 'select', width: '33%', required: true, nullable: false, optionsText: 'low,medium,high,urgent', default: 'medium' },
                    { name: 'category', label: 'Category', type: 'string', width: '33%', required: false, nullable: true, optionsText: '100', default: 'General' },
                    { name: 'status', label: 'Status', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'open,in_progress,pending,resolved,closed', default: 'open' },
                    { name: 'due_date', label: 'SLA Due Date', type: 'datetime', width: '50%', required: false, nullable: true, optionsText: '', default: '' }
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
                this.wizardTargetTable = 'root';
            } else if (preset === 'blog') {
                this.projectName = 'Article';
                this.domain = 'Content';
                this.description = 'Editorial articles, publishing lifecycle, and discussion comments';
                this.customFields = [
                    { name: 'slug', label: 'URL Slug', type: 'string', width: '50%', required: true, nullable: false, optionsText: '255', default: '' },
                    { name: 'excerpt', label: 'Article Excerpt', type: 'text', width: '100%', required: false, nullable: true, optionsText: '', default: '' },
                    { name: 'status', label: 'Publish State', type: 'select', width: '50%', required: true, nullable: false, optionsText: 'draft,in_review,published,archived', default: 'draft' },
                    { name: 'published_at', label: 'Publication Date', type: 'datetime', width: '50%', required: false, nullable: true, optionsText: '', default: '' }
                ];
                this.childTables = [
                    {
                        name: 'comments',
                        label: 'Discussion Comments',
                        relation: 'hasMany',
                        fields: [
                            { name: 'author_name', label: 'Commenter Name', type: 'string', width: '50%', required: true, nullable: false, optionsText: '150', default: '' },
                            { name: 'author_email', label: 'Commenter Email', type: 'email', width: '50%', required: true, nullable: false, optionsText: '255', default: '' },
                            { name: 'comment_body', label: 'Comment Body', type: 'text', width: '100%', required: true, nullable: false, optionsText: '', default: '' },
                            { name: 'is_approved', label: 'Approved', type: 'boolean', width: '50%', required: false, nullable: true, optionsText: '', default: '0' }
                        ]
                    }
                ];
                this.wizardTargetTable = 'root';
            }
            this.refreshRbacList();
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

        rollbackVersion(hist) {
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
                    target_version: hist.version
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
                return nameMatch || titleMatch || domainMatch;
            });

            list.forEach(s => {
                let domain = (s.domain || s.navigation?.group || '').trim();
                if (!domain) domain = 'General / Core';
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
                    childTables: rawChild
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
        }
    };
}
</script>

<div class="space-y-6" x-data="larasliceWizard()">
        <!-- Brand & Ecosystem Bar -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-5 py-3 rounded-2xl bg-card border border-border/80 shadow-sm text-xs">
            <div class="flex items-center gap-2.5">
                <a href="https://hereaftersol.com" target="_blank" class="font-bold text-foreground hover:text-primary transition flex items-center gap-1.5">
                    <span class="size-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>Hereafter Solutions (H. Sol)</span>
                </a>
                <span class="text-muted-foreground/30">•</span>
                <span class="text-muted-foreground hidden md:inline">Sowing Seeds of Innovation, Harvesting Compassion</span>
            </div>
            <div class="flex items-center gap-4">
                <a href="https://brandup247.com" target="_blank" class="flex items-center gap-1.5 text-muted-foreground hover:text-foreground transition">
                    <span class="text-[10px] px-2 py-0.5 rounded-md bg-muted text-muted-foreground font-semibold">Growth Partner</span>
                    <span class="font-semibold text-foreground">BrandUp</span>
                </a>
                <a href="https://github.com/hereaftersol/laraslice" target="_blank" class="text-muted-foreground hover:text-primary transition font-mono text-[11px]">
                    v1.0.0
                </a>
            </div>
        </div>

        <!-- Top Mode Selector -->
        <div class="flex flex-wrap items-center gap-2.5 mb-8 bg-card p-1.5 rounded-2xl border border-border w-fit shadow-xs">
            <button @click="mainTab = 'wizard'; try { history.replaceState({}, '', '{{ route('laraslice.wizard') }}'); } catch(e){}"
                    :class="mainTab === 'wizard' ? 'bg-primary text-primary-foreground shadow-lg shadow-primary/25 font-bold' : 'text-muted-foreground hover:text-foreground font-medium'"
                    class="px-4 py-2.5 rounded-xl text-xs transition flex items-center gap-2 cursor-pointer">
                <span>🪄</span>
                <span>Scaffold New Slice</span>
            </button>
            <button @click="mainTab = 'studio'; try { history.replaceState({}, '', '{{ route('laraslice.wizard.studio') }}'); } catch(e){}; loadSlices()"
                    :class="mainTab === 'studio' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/25 font-bold' : 'text-muted-foreground hover:text-foreground font-medium'"
                    class="px-4 py-2.5 rounded-xl text-xs transition flex items-center gap-2 cursor-pointer">
                <span>🛠️</span>
                <span>Slice Studio & Field Manager</span>
                <span class="px-2 py-0.5 rounded-full bg-white/20 text-[10px]" x-text="installedSlices.length"></span>
            </button>
            <a href="{{ route('laraslice.wizard.blueprint') }}"
               class="px-4 py-2.5 rounded-xl text-xs transition flex items-center gap-2 border border-border text-foreground hover:bg-muted focus:outline-none focus:ring-2 focus:ring-primary/50">
                <span aria-hidden="true">⌘</span>
                <span>Blueprint Studio</span>
            </a>
            <a href="{{ route('laraslice.wizard.schema_studio') }}"
               class="px-4 py-2.5 rounded-xl text-xs transition flex items-center gap-2 border border-border text-foreground hover:bg-muted focus:outline-none focus:ring-2 focus:ring-primary/50">
                <span>⚡</span>
                <span>Schema Studio</span>
            </a>
        </div>
        <!-- Wizard Mode Grid -->
        <div class="grid grid-cols-1 md:grid-cols-12 gap-6" x-show="mainTab === 'wizard'">
            <!-- Left Steps Sidebar -->
            <div class="col-span-12 md:col-span-5 lg:col-span-4 space-y-6">
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
                    <div class="flex items-center space-x-4 cursor-pointer" @click="step = 2">
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
                    <div class="flex items-center space-x-4 cursor-pointer" @click="step = 3">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold transition"
                             :class="step >= 3 ? 'bg-primary text-white shadow-lg shadow-amber-500/30' : 'bg-gray-800 text-muted-foreground'">
                            3
                        </div>
                        <div>
                            <p class="text-sm font-semibold" :class="step === 3 ? 'text-foreground' : 'text-muted-foreground'">Features & Modules</p>
                            <p class="text-xs text-muted-foreground">Database & workflows</p>
                        </div>
                    </div>

                    <!-- Step 4 Indicator -->
                    <div class="flex items-center space-x-4 cursor-pointer" @click="step = 4">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold transition"
                             :class="step >= 4 ? 'bg-primary text-white shadow-lg shadow-amber-500/30' : 'bg-gray-800 text-muted-foreground'">
                            4
                        </div>
                        <div>
                            <p class="text-sm font-semibold" :class="step === 4 ? 'text-foreground' : 'text-muted-foreground'">Review & Generate</p>
                            <p class="text-xs text-muted-foreground">Generate solution</p>
                        </div>
                    </div>
                </div>

                <!-- Quick Summary Card -->
                <div class="bg-card border border-border rounded-2xl p-6 space-y-3 shadow-xl text-xs">
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
                        <span class="text-green-400 font-bold" x-text="includeWorkflow ? 'Enabled' : 'Standard'"></span>
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

                        <!-- 1-Click Starter Presets -->
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <label class="block text-xs font-semibold uppercase tracking-wider text-muted-foreground">Quick Starter Templates & Domain Suites</label>
                                <span class="text-[10px] font-semibold text-primary">✨ Multi-Slice Domain Supported</span>
                            </div>
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                                <button type="button" @click="applyPreset('crm_suite')" class="p-3 rounded-xl border border-blue-500/40 bg-blue-500/5 hover:bg-blue-500/15 text-left transition cursor-pointer group">
                                    <div class="text-base mb-1">💼</div>
                                    <div class="text-xs font-bold text-foreground group-hover:text-blue-500">CRM Suite (3 Slices)</div>
                                    <div class="text-[10px] text-muted-foreground">Companies, Contacts, Deals</div>
                                </button>
                                <button type="button" @click="applyPreset('ecommerce_suite')" class="p-3 rounded-xl border border-amber-500/40 bg-amber-500/5 hover:bg-amber-500/15 text-left transition cursor-pointer group">
                                    <div class="text-base mb-1">🛒</div>
                                    <div class="text-xs font-bold text-foreground group-hover:text-amber-500">E-Commerce Suite (4 Slices)</div>
                                    <div class="text-[10px] text-muted-foreground">Products, Orders, Customers...</div>
                                </button>
                                <button type="button" @click="applyPreset('billing_suite')" class="p-3 rounded-xl border border-emerald-500/40 bg-emerald-500/5 hover:bg-emerald-500/15 text-left transition cursor-pointer group">
                                    <div class="text-base mb-1">💳</div>
                                    <div class="text-xs font-bold text-foreground group-hover:text-emerald-500">Billing Suite (3 Slices)</div>
                                    <div class="text-[10px] text-muted-foreground">Invoices, Payments, Subs</div>
                                </button>
                                <button type="button" @click="applyPreset('helpdesk')" class="p-3 rounded-xl border border-purple-500/40 bg-purple-500/5 hover:bg-purple-500/15 text-left transition cursor-pointer group">
                                    <div class="text-base mb-1">🎫</div>
                                    <div class="text-xs font-bold text-foreground group-hover:text-purple-500">Helpdesk Ticket</div>
                                    <div class="text-[10px] text-muted-foreground">Single Slice + Workflow</div>
                                </button>
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
                                <table class="w-full text-left text-xs">
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
                                                    <input type="text" x-model="field.name" placeholder="e.g. title, sku" class="h-7 w-full rounded border border-transparent hover:border-input focus:border-input bg-transparent px-1.5 font-mono text-xs font-semibold text-foreground focus:bg-background focus:outline-none">
                                                </td>
                                                <!-- Display Label -->
                                                <td class="px-2.5 py-2">
                                                    <input type="text" x-model="field.label" placeholder="Display Label" class="h-7 w-full rounded border border-transparent hover:border-input focus:border-input bg-transparent px-1.5 text-xs text-foreground focus:bg-background focus:outline-none">
                                                </td>
                                                <!-- Data Type -->
                                                <td class="px-2.5 py-2">
                                                    <select x-model="field.type" class="h-7 w-full rounded border border-input bg-card px-2 text-xs font-medium text-foreground focus:ring-1 focus:ring-primary">
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

                    <!-- Step 3: Features & Database -->
                    <div x-show="step === 3" class="space-y-6">
                        <div class="border-b border-border pb-4">
                            <h2 class="text-xl font-bold text-foreground flex items-center gap-2">
                                <span class="text-primary">⚙️</span> Features & Database
                            </h2>
                            <p class="text-xs text-muted-foreground mt-1">Select modular capabilities and database engine</p>
                        </div>

                        <div class="space-y-3">
                            <label class="flex items-center space-x-3 cursor-pointer p-3 bg-muted/30 rounded-xl border border-border">
                                <input type="checkbox" x-model="includeWorkflow" class="w-4 h-4 rounded text-primary">
                                <div>
                                    <span class="font-bold text-sm text-foreground">Workflow & State Machine Engine</span>
                                    <p class="text-xs text-muted-foreground">Declarative state transitions, guards, and status tracking</p>
                                </div>
                            </label>
                            <label class="flex items-center space-x-3 cursor-pointer p-3 bg-muted/30 rounded-xl border border-border">
                                <input type="checkbox" x-model="includeApi" class="w-4 h-4 rounded text-primary" checked>
                                <div>
                                    <span class="font-bold text-sm text-foreground">REST API Controller</span>
                                    <p class="text-xs text-muted-foreground">Pre-wired endpoints for Mobile, Web, and External Integrations</p>
                                </div>
                            </label>
                        </div>

                        <!-- RBAC & Security Capabilities Card -->
                        <div class="rounded-xl border border-border bg-card p-4 space-y-3">
                            <div class="flex items-center justify-between border-b border-border/50 pb-2">
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
                                        <button type="button" @click="removeRbac(pIdx)" class="text-muted-foreground hover:text-destructive text-xs cursor-pointer">×</button>
                                    </span>
                                </template>
                            </div>

                            <!-- Add Custom Capability -->
                            <div class="flex items-center gap-2 pt-1">
                                <input type="text" x-model="newPermInput" @keydown.enter.prevent="addCustomRbac()" placeholder="e.g. publish, export, approve" class="h-8 rounded-lg border border-input bg-background px-3 text-xs font-mono text-foreground flex-1 max-w-xs focus:ring-1 focus:ring-primary">
                                <button type="button" @click="addCustomRbac()" class="h-8 px-3 rounded-lg border border-border bg-muted/30 text-xs font-semibold hover:bg-muted transition cursor-pointer">+ Add</button>
                            </div>
                        </div>

                        <div class="flex justify-between pt-4">
                            <button @click="step = 2" class="px-5 py-2.5 text-muted-foreground hover:text-foreground text-sm">← Previous</button>
                            <button @click="step = 4" class="px-6 py-3 bg-primary hover:bg-primary/90 text-white font-bold rounded-xl text-sm transition cursor-pointer">
                                Review & Generate →
                            </button>
                        </div>
                    </div>

                    <!-- Step 4: Review & Generate -->
                    <div x-show="step === 4" class="space-y-6">
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
                            <button @click="step = 3" class="px-5 py-2.5 text-muted-foreground hover:text-foreground text-sm cursor-pointer self-start sm:self-auto">← Previous</button>
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
                        <button @click="loadSlices()" class="text-xs text-primary hover:opacity-80 flex items-center gap-1 cursor-pointer">
                            <span>↻</span> Refresh
                        </button>
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
                                    <div class="flex items-center gap-2 min-w-0">
                                        <svg class="w-3.5 h-3.5 text-muted-foreground transition-transform duration-200"
                                             :class="collapsedDomains[grp.domain] ? '-rotate-90' : 'rotate-0'"
                                             fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                        <span class="font-bold text-xs text-foreground truncate" x-text="grp.domain"></span>
                                        <span class="px-1.5 py-0.2 rounded-full text-[10px] font-mono bg-muted text-muted-foreground border border-border/80" x-text="grp.slices.length + ' slice' + (grp.slices.length > 1 ? 's' : '')"></span>
                                        <span class="px-1.5 py-0.2 rounded-full text-[10px] font-mono bg-indigo-500/10 text-indigo-400 border border-indigo-500/20" x-text="grp.totalModels + ' model' + (grp.totalModels > 1 ? 's' : '')"></span>
                                        <span x-show="grp.status === 'active'" class="px-1.5 py-0.2 rounded-full text-[9px] font-bold bg-emerald-500/15 text-emerald-600 border border-emerald-500/20">Active</span>
                                        <span x-show="grp.status === 'disabled'" class="px-1.5 py-0.2 rounded-full text-[9px] font-bold bg-rose-500/15 text-rose-500 border border-rose-500/20">Disabled</span>
                                        <span x-show="grp.status === 'partial'" class="px-1.5 py-0.2 rounded-full text-[9px] font-bold bg-amber-500/15 text-amber-600 border border-amber-500/20">Partial</span>
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
                        <button type="button" @click="wipeSliceData(selectedSlice.name, $event)" title="Wipe (Truncate) Table Records" class="px-3 py-2 border border-border bg-muted/30 hover:bg-muted text-foreground font-bold text-xs rounded-xl transition flex items-center gap-1.5 cursor-pointer shadow-xs">
                            <span>🧹</span> Wipe
                        </button>
                        <button type="button" @click="openDeleteModal('slice', selectedSlice.name, $event)" title="Remove Slice (Granular Options)" class="px-3 py-2 border border-destructive/30 bg-destructive/10 hover:bg-destructive/20 text-destructive font-bold text-xs rounded-xl transition flex items-center gap-1.5 cursor-pointer shadow-xs">
                            <span>🗑</span> Remove
                        </button>
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
                        <table class="w-full text-left text-xs">
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
                                    <tr class="hover:bg-muted/20 transition-colors" :class="field.is_existing ? '' : 'bg-primary/5'">
                                        <!-- Reorder Arrows -->
                                        <td class="px-2 py-2 text-center text-muted-foreground font-mono">
                                            <div class="flex items-center justify-center gap-0.5">
                                                <button type="button" @click="moveStudioFieldUp(fIdx)" :disabled="fIdx === 0" title="Move Up" class="p-0.5 rounded text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-20 transition">↑</button>
                                                <button type="button" @click="moveStudioFieldDown(fIdx)" :disabled="fIdx === studioFields.length - 1" title="Move Down" class="p-0.5 rounded text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-20 transition">↓</button>
                                            </div>
                                        </td>
                                        <!-- Handle -->
                                        <td class="px-3 py-2">
                                            <input type="text" x-model="field.handle" placeholder="e.g. sku, price" class="h-7 w-full rounded border border-transparent hover:border-input focus:border-input bg-transparent px-1.5 font-mono text-xs font-semibold text-foreground focus:bg-background focus:outline-none">
                                        </td>
                                        <!-- Label -->
                                        <td class="px-3 py-2">
                                            <input type="text" x-model="field.label" placeholder="e.g. Variant SKU" class="h-7 w-full rounded border border-transparent hover:border-input focus:border-input bg-transparent px-1.5 text-xs text-foreground focus:bg-background focus:outline-none">
                                        </td>
                                        <!-- Type -->
                                        <td class="px-3 py-2">
                                            <select x-model="field.type" class="h-7 w-full rounded border border-input bg-card px-2 text-xs font-medium text-foreground focus:ring-1 focus:ring-primary">
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
                            <table class="w-full text-left text-xs">
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
                                                <select x-model="rel.type" @change="onStudioRelationTypeChange(rel)" class="h-7 w-full rounded border border-input bg-card px-2 text-xs font-medium text-foreground focus:ring-1 focus:ring-primary">
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
                            <label class="flex items-center gap-1.5 mt-2 cursor-pointer select-none">
                                <input type="checkbox" x-model="navConfig.redirect_old" class="rounded border-border text-primary size-3.5">
                                <span class="text-[11px] font-medium text-foreground">Create 301 Permanent Redirect from old URL (Recommended)</span>
                            </label>
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
                                            @click="rollbackVersion(hist)"
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

    </div>
@endsection

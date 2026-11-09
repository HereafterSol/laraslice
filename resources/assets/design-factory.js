// Studio presets live server-side (blueprints/
// studio-presets.json) and are fetched ONCE, lazily, the
// first time a preset is actually picked — the panel no
// longer ships ~155 KB of example data on every load.
let __studioPresetsPromise = null;
window.__loadStudioPresets = function () {
    if (!__studioPresetsPromise) {
        __studioPresetsPromise = fetch('/laraslice/studio/blueprint/presets', { headers: { 'Accept': 'application/json' } })
            .then(r => r.ok ? r.json() : {})
            .catch(() => ({}));
    }
    return __studioPresetsPromise;
};

window.blueprintStudio = ({ initialSource, initialFormat, initialBlueprint, csrfToken, introspectUrl, studioUrl, allSlicesJson, activeSliceIdx, initialPlanScope, initialPortPlan, installedCatalog, introspectTables }) => ({
        mode: 'visual', // 'visual', 'preview', 'split', 'yaml'
        selectedPreset: '',
        savedPresets: [],
        presetLoading: false,
        activeModelIndex: 0,
        blueprintDirty: false,
        _syncReady: false,
        draftAvailable: null,
        draftStatus: '',
        engineCheck: { state: 'idle', errors: [] },
        _engineCheckTimer: null,
        libraryItems: [],
        librarySlug: null,
        fieldFilters: {},
        source: initialSource || '',
        format: initialFormat || 'yaml',
        // Installed-slice catalog, shipped ONCE as compact data
        // (the slice picker + relation target list render their
        // options from it client-side instead of the server
        // repeating ~160 <option> elements twice per fragment).
        installedCatalog: installedCatalog || { current: '', groups: [] },
        currentSliceName: (installedCatalog && installedCatalog.current) || '',
        introspectTables: introspectTables || [],
        portingModalLoaded: false,
        portingModalLoading: false,
        _portingModalRetried: false,
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
        portPlanData: initialPortPlan || { journeys: [], sidebar_preview: [], summary: null, domain: '' },
        portPlanJsonText: initialPortPlan ? JSON.stringify(initialPortPlan, null, 2) : '',
        portActiveTab: 'sidebar',
        portExecutionLogs: [],
        pageArchTab: 'index',
        showImportYamlModal: false,
        importYamlText: '',
        planScope: initialPlanScope || 'domain',
        yamlViewMode: 'slice',
        domainYamlSource: '',
        selectedUiSlice: 'Projects',
        selectedUiPage: 'index',
        selectedUiSubTab: 'overview',
        uiSliceSearch: '',

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

        fetchPortPlan() {
            this.portError = '';
            // Client-side sanity check: a source is required unless a canned preset/JSON is used.
            if ((this.portPreset === 'custom' || !this.portPreset) && !this.portPlanJsonText && !(this.portSourcePath || '').trim()) {
                this.portError = 'Enter a Source Application Path (root directory of the legacy Laravel app) or switch to the "Custom JSON Architecture Plan" preset.';
                return;
            }
            if (!(this.portDomain || '').trim()) {
                this.portError = 'Enter a Target Domain Suite name (e.g. ERP).';
                return;
            }
            this.portInspecting = true;
            fetch('/laraslice/wizard/blueprint/port-plan', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
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
                    this.portError = '';
                    this.portPlanData = res.plan;
                    this.portPlanJsonText = JSON.stringify(res.plan, null, 2);
                    if (res.inspection) this.portInspectionData = res.inspection;
                    if (!this.selectedUiSlice && res.plan.modules && res.plan.modules[0] && res.plan.modules[0].slices && res.plan.modules[0].slices[0]) {
                        this.selectedUiSlice = res.plan.modules[0].slices[0].name;
                    }
                } else {
                    // Clear stale plan so a failed re-Analyze never masquerades as current.
                    this.portPlanData = null;
                    this.portPlanJsonText = '';
                    this.portInspectionData = null;
                    this.portError = 'Plan generation failed: ' + (res.message || 'Unknown error');
                }
            })
            .catch(e => {
                this.portInspecting = false;
                this.portPlanData = null;
                this.portPlanJsonText = '';
                this.portInspectionData = null;
                this.portError = 'Plan generation error: ' + e.message;
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
            this.portError = '';
            if (!this.portPlanData && !this.portPlanJsonText) {
                this.portError = 'Please inspect the project or load an architecture plan first.';
                return;
            }
            // Validate hand-edited JSON before executing (mirrors the server's modules check).
            let planPayload = this.portPlanData;
            try {
                if (this.portPlanJsonText) {
                    planPayload = JSON.parse(this.portPlanJsonText);
                }
            } catch (e) {
                this.portError = 'The JSON Architecture Plan contains invalid JSON: ' + e.message;
                return;
            }
            if (!planPayload || !Array.isArray(planPayload.modules) || planPayload.modules.length === 0) {
                this.portError = 'The architecture plan is invalid: it must contain a non-empty "modules" array.';
                return;
            }
            if (!confirm('Execute the porting plan? This will scaffold slices, models, and migrations into app/Slices.')) {
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

            fetch('/laraslice/wizard/blueprint/port-execute', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
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
                    this.portExecutionLogs.push(`[${new Date().toLocaleTimeString()}] Reloading Studio...`);
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    this.portExecutionLogs.push(`[${new Date().toLocaleTimeString()}] ❌ Error: ${res.message || 'Execution failed'}`);
                    this.portError = 'Porting execution error: ' + (res.message || 'Unknown error');
                }
            })
            .catch(e => {
                this.portExecuting = false;
                this.portExecutionLogs.push(`[${new Date().toLocaleTimeString()}] ❌ Network Error: ${e.message}`);
                this.portError = 'Port execution error: ' + e.message;
            });
        },

        getActiveSlicePages() {
            if (this.blueprint && this.blueprint.pages) {
                return this.blueprint.pages;
            }
            if (this.slices && this.slices[this.activeSliceIdx] && this.slices[this.activeSliceIdx].blueprint?.pages) {
                return this.slices[this.activeSliceIdx].blueprint.pages;
            }
            const sName = this.blueprint?.name;
            if (this.portPlanData && this.portPlanData.modules) {
                for (let m of this.portPlanData.modules) {
                    if (m.slices) {
                        for (let s of m.slices) {
                            if (s.name === sName && s.pages) return s.pages;
                        }
                    }
                }
            }
            const rootModel = (this.blueprint?.models && this.blueprint.models[0]) || { name: sName || 'Record', fields: [] };
            const childModels = (this.blueprint?.models && this.blueprint.models.slice(1)) || [];
            return {
                index: {
                    title: `${sName || 'Record'} Directory`,
                    description: `Directory for managing ${sName || 'entity'} records.`,
                    // Placeholder figures only: this default
                    // plan is a starting template, and invented
                    // numbers presented as facts would follow
                    // the slice into its generated app. The
                    // counts stay blank until the author sets
                    // them here or the app reports real ones.
                    stats_ribbon: [
                        { label: `Total ${sName || 'Records'}`, value: '—', change: 'Sample figure — set in the UI plan', icon: 'layers', color: 'primary' },
                        { label: 'Active', value: '—', change: 'Sample figure — set in the UI plan', icon: 'check-circle', color: 'emerald' },
                        { label: 'Pending Review', value: '—', change: 'Sample figure — set in the UI plan', icon: 'clock', color: 'amber' },
                        { label: 'Archived', value: '—', change: 'Sample figure — set in the UI plan', icon: 'archive', color: 'slate' }
                    ],
                    filter_bar: {
                        search_placeholder: `Search ${(sName || 'record').toLowerCase()} records...`,
                        filters: [
                            { name: 'status', label: 'All Statuses', type: 'select', options: ['Active', 'Pending', 'Archived'] }
                        ]
                    },
                    columns: rootModel.fields ? rootModel.fields.slice(0, 6).map(f => ({ key: f.handle, label: f.label, type: f.type, sortable: true })) : []
                },
                show: {
                    layout: 'split-workspace',
                    sub_tabs: [
                        { name: 'overview', label: 'Overview', icon: 'info' },
                        ...childModels.map(cm => ({ name: cm.handle, label: cm.name, icon: 'list', count: 0 }))
                    ]
                },
                create_edit: {
                    create: {
                        title: `Create ${rootModel.name}`,
                        sections: [
                            { name: 'Primary Information', fields: (rootModel.fields || []).slice(0, 6).map(f => f.handle) },
                            { name: 'Additional Details', fields: (rootModel.fields || []).slice(6, 12).map(f => f.handle) }
                        ]
                    }
                },
                workflow: this.blueprint?.workflow || {
                    state_column: 'status',
                    states: ['Draft', 'Submitted', 'Under Review', 'Approved', 'Finished']
                }
            };
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
            if (!this.portPlanData || !this.portPlanData.studio_slices) {
                try {
                    const raw = sessionStorage.getItem('laraslice_port_plan') || localStorage.getItem('laraslice_port_plan');
                    if (raw) {
                        this.portPlanData = JSON.parse(raw);
                    }
                } catch(e) {}
            }

            let targetSlices = null;
            if (this.portPlanData && Array.isArray(this.portPlanData.studio_slices) && this.portPlanData.studio_slices.length > 0) {
                targetSlices = this.portPlanData.studio_slices;
            } else if (this.portInspectionData && Array.isArray(this.portInspectionData.studio_slices) && this.portInspectionData.studio_slices.length > 0) {
                targetSlices = this.portInspectionData.studio_slices;
            } else if (this.portPlanData && Array.isArray(this.portPlanData.modules)) {
                targetSlices = this.convertModulesToStudioSlices(this.portPlanData.modules);
            } else {
                try {
                    const rawSlices = sessionStorage.getItem('laraslice_studio_slices') || localStorage.getItem('laraslice_studio_slices');
                    if (rawSlices) {
                        const parsed = JSON.parse(rawSlices);
                        if (Array.isArray(parsed) && parsed.length > 0) {
                            targetSlices = parsed;
                        }
                    }
                } catch(e) {}
            }

            if (!targetSlices || !targetSlices.length) {
                window.__loadStudioPresets().then(presets => {
                    if (presets['legal_dispute_adjudication']) {
                        this.loadStudioPreset('legal_dispute_adjudication');
                    } else {
                        this.portError = 'Please inspect the project schema or load an architecture plan first.';
                    }
                });
                return;
            }

            this.slices = JSON.parse(JSON.stringify(targetSlices));
            this.domainName = this.portPlanData?.domain || this.portInspectionData?.domain || this.portDomain || 'Adjudication';
            this.blueprint.domain = this.domainName;
            this.activeSliceIdx = 0;
            if (this.slices.length > 0 && this.slices[0].blueprint) {
                this.loadParsedBlueprint(JSON.parse(JSON.stringify(this.slices[0].blueprint)));
                this.allRelations = JSON.parse(JSON.stringify(this.slices[0].allRelations || []));
                this.source = this.slices[0].source || '';
                this.syncToYaml();
            }
            if (this.portPlanData?.domain_blueprint_yaml) {
                this.domainYamlSource = this.portPlanData.domain_blueprint_yaml;
            } else if (this.portInspectionData?.domain_blueprint_yaml) {
                this.domainYamlSource = this.portInspectionData.domain_blueprint_yaml;
            } else {
                this.generateDomainYaml();
            }
            this.showPortModal = false;
            this.mode = 'visual';
            try {
                sessionStorage.setItem('laraslice_studio_slices', JSON.stringify(this.slices));
                sessionStorage.setItem('laraslice_active_slice_idx', '0');
            } catch(e) {}
        },

        async choosePreset(presetKey) {
            if (!presetKey) return;
            this.presetLoading = true;
            // Lazily fetched studio presets (see
            // __loadStudioPresets); assigned inside the try
            // so the adjudication fallbacks can consult it.
            let studioPresets = {};
            try {
                if (presetKey.startsWith('saved:')) {
                    const slug = presetKey.replace('saved:', '');
                    const res = await fetch('/laraslice/wizard/port-saved-presets/' + encodeURIComponent(slug), { headers: { 'Accept': 'application/json' } });
                    const data = await res.json();
                    if (data.success && data.preset?.plan) {
                        this.portPlanData = data.preset.plan;
                        sessionStorage.setItem('laraslice_port_plan', JSON.stringify(data.preset.plan));
                        localStorage.setItem('laraslice_port_plan', JSON.stringify(data.preset.plan));
                        this.loadPortedToStudio();
                        return;
                    }
                }

                if (presetKey === 'legal_dispute_adjudication') {
                    this.loadStudioPreset('legal_dispute_adjudication');
                    return;
                }

                // If template preset exists in the studio presets
                studioPresets = await window.__loadStudioPresets();
                if (studioPresets[presetKey]) {
                    this.loadStudioPreset(presetKey);
                    return;
                }

                // Fetch authentic plan from backend
                const domain = presetKey === 'adjudication' ? 'Adjudication' : (presetKey === 'erp' ? 'ERP' : presetKey.charAt(0).toUpperCase() + presetKey.slice(1));
                const res = await fetch('/laraslice/wizard/blueprint/port-plan', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ preset: presetKey, domain: domain, source_type: 'local' })
                });
                const data = await res.json();
                if (data.success && data.plan) {
                    this.portPlanData = data.plan;
                    sessionStorage.setItem('laraslice_port_plan', JSON.stringify(data.plan));
                    localStorage.setItem('laraslice_port_plan', JSON.stringify(data.plan));
                    this.loadPortedToStudio();
                    return;
                }

                // Fallback for adjudication
                if (presetKey === 'adjudication' && studioPresets['legal_dispute_adjudication']) {
                    this.loadStudioPreset('legal_dispute_adjudication');
                }
            } catch (e) {
                console.error('Error loading preset:', e);
                if (presetKey === 'adjudication' && studioPresets['legal_dispute_adjudication']) {
                    this.loadStudioPreset('legal_dispute_adjudication');
                }
            } finally {
                this.presetLoading = false;
                this.selectedPreset = '';
            }
        },

        hasStoredPortPlan() {
            try {
                return !!(sessionStorage.getItem('laraslice_port_plan') || localStorage.getItem('laraslice_port_plan'));
            } catch(e) { return false; }
        },

        getStoredSliceCount() {
            try {
                const raw = sessionStorage.getItem('laraslice_port_plan') || localStorage.getItem('laraslice_port_plan');
                if (raw) {
                    const p = JSON.parse(raw);
                    return p.studio_slices?.length || p.summary?.total_canonical_slices || 0;
                }
            } catch(e) {}
            return 0;
        },

        getStoredDomain() {
            try {
                const raw = sessionStorage.getItem('laraslice_port_plan') || localStorage.getItem('laraslice_port_plan');
                if (raw) {
                    const p = JSON.parse(raw);
                    return p.domain || '';
                }
            } catch(e) {}
            return '';
        },

        generateDomainYaml() {
            this.saveActiveSliceState();
            if (this.portInspectionData && this.portInspectionData.domain_blueprint_yaml) {
                this.domainYamlSource = this.portInspectionData.domain_blueprint_yaml;
                return;
            }
            let lines = [];
            lines.push('schema_version: 1');
            lines.push(`domain: ${this.blueprint.domain || this.portDomain || 'DomainSuite'}`);
            lines.push(`generated_at: "${new Date().toISOString()}"`);
            lines.push(`total_slices: ${this.slices.length}`);
            lines.push('slices:');
            for (let s of this.slices) {
                const bp = s.blueprint || {};
                lines.push(`  - name: ${bp.name || s.name}`);
                lines.push(`    handle: ${bp.handle || s.handle}`);
                lines.push(`    domain: ${bp.domain || this.blueprint.domain || ''}`);
                if (bp.description) lines.push(`    description: "${bp.description}"`);
                if (bp.permissions && bp.permissions.length) {
                    lines.push('    permissions:');
                    for (let p of bp.permissions) lines.push(`      - ${p}`);
                }
                if (bp.models && bp.models.length) {
                    lines.push('    models:');
                    for (let m of bp.models) {
                        lines.push(`      - handle: ${m.handle}`);
                        lines.push(`        table: ${m.table}`);
                        lines.push(`        root: ${m.root ? 'true' : 'false'}`);
                        if (m.fields && m.fields.length) {
                            lines.push('        fields:');
                            for (let f of m.fields) {
                                lines.push(`          - handle: ${f.handle}`);
                                lines.push(`            type: ${f.type}`);
                                lines.push(`            required: ${f.required ? 'true' : 'false'}`);
                                if (f.input_type) lines.push(`            input_type: ${f.input_type}`);
                            }
                        }
                    }
                }
            }
            this.domainYamlSource = lines.join('\n');
        },
        showIntrospectModal: false,
        showNewSliceModal: false,
        newSliceName: '',
        newSliceDomain: '',
        newSliceDescription: '',
        selectedTable: '',
        introspecting: false,
        allRelations: [],
        modelCollapsed: {},
        slices: [],
        activeSliceIdx: 0,

        saveActiveSliceState() {
            if (!this.slices || this.slices.length === 0) return;
            if (this.activeSliceIdx >= this.slices.length) this.activeSliceIdx = 0;
            this.syncToYaml();
            this.slices[this.activeSliceIdx] = {
                name: this.blueprint.name || ('Slice ' + (this.activeSliceIdx + 1)),
                handle: this.blueprint.handle || ('slice_' + (this.activeSliceIdx + 1)),
                blueprint: JSON.parse(JSON.stringify(this.blueprint)),
                allRelations: JSON.parse(JSON.stringify(this.allRelations || [])),
                source: this.source
            };
            try {
                sessionStorage.setItem('laraslice_studio_slices', JSON.stringify(this.slices));
                sessionStorage.setItem('laraslice_active_slice_idx', String(this.activeSliceIdx));
            } catch(e) {}
        },

        switchSlice(idx) {
            if (idx === this.activeSliceIdx || idx < 0 || idx >= this.slices.length) return;
            this.saveActiveSliceState();
            this.activeSliceIdx = idx;
            const targetSlice = this.slices[idx];
            if (targetSlice && targetSlice.blueprint) {
                this.loadParsedBlueprint(JSON.parse(JSON.stringify(targetSlice.blueprint)));
                this.allRelations = JSON.parse(JSON.stringify(targetSlice.allRelations || []));
                this.source = targetSlice.source || '';
                this.syncToYaml();
            }
            this.activeModelIndex = 0;
            if (targetSlice) {
                this.notifyCopilotOfSlice(targetSlice);
            }
            try {
                sessionStorage.setItem('laraslice_active_slice_idx', String(this.activeSliceIdx));
            } catch(e) {}
        },

        notifyCopilotOfSlice(slice) {
            if (!slice) return;
            try {
                const bp = slice.blueprint || this.blueprint || {};
                const models = Array.isArray(bp.models) ? bp.models : [];
                const childModels = models.slice(1).map(m => m.table || m.handle || m.name);
                const tablesData = models.map(m => ({
                    name: m.table || m.handle || m.name,
                    columns_count: (m.fields || []).length
                }));
                window.dispatchEvent(new CustomEvent('laraslice-slice-selected', {
                    detail: {
                        name: slice.name || slice.handle || bp.name,
                        title: slice.name || bp.name || slice.handle,
                        domain: slice.domain || bp.domain || 'Blueprint',
                        version: bp.version || 'Blueprint Low-Code',
                        description: bp.description || 'Declarative YAML/Visual Slice Blueprint',
                        tables_data: tablesData,
                        child_tables: childModels,
                        permissions: bp.permissions || [],
                        is_blueprint: true
                    }
                }));
            } catch (e) {}
        },

        createNewSlice() {
            const name = (this.newSliceName || '').trim();
            if (!name) return alert('Please enter a Slice Name');
            
            const handle = name.toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/^_+|_+$/g, '');
            const baseSlug = handle.replace(/s$/, '') || 'item';
            const modelHandle = baseSlug;
            const domain = (this.newSliceDomain || this.blueprint.domain || '').trim();
            const desc = (this.newSliceDescription || '').trim() || `Manage ${name.toLowerCase()} records`;

            const freshBlueprint = {
                schema_version: 1,
                name: name,
                handle: handle,
                domain: domain,
                description: desc,
                author: this.blueprint.author || 'LaraSlice Team',
                version: '1.0.0',
                permissions: [
                    `${baseSlug}.view`,
                    `${baseSlug}.create`,
                    `${baseSlug}.edit`,
                    `${baseSlug}.delete`
                ],
                models: [
                    {
                        handle: modelHandle,
                        table: handle.endsWith('s') ? handle : handle + 's',
                        root: true,
                        timestamps: true,
                        soft_deletes: false,
                        description: `Primary ${modelHandle} records`,
                        fields: [
                            {
                                handle: 'name',
                                label: 'Name',
                                type: 'string',
                                required: true,
                                nullable: false,
                                width: 50,
                                length: 255,
                                default: '',
                                options: {},
                                optionsText: ''
                            }
                        ],
                        relations: []
                    }
                ]
            };

            // 1. Save currently active slice state so it is NOT overwritten!
            this.saveActiveSliceState();

            // 2. Append new slice to this.slices
            const newSliceObj = {
                name: name,
                handle: handle,
                blueprint: freshBlueprint,
                allRelations: [],
                source: ''
            };
            this.slices.push(newSliceObj);

            // 3. Switch active index to the new slice
            this.activeSliceIdx = this.slices.length - 1;

            // 4. Load the new slice
            this.loadParsedBlueprint(freshBlueprint);
            this.allRelations = [];
            this.syncToYaml();
            newSliceObj.source = this.source;

            // 5. Reset modal & close
            this.showNewSliceModal = false;
            this.newSliceName = '';
            this.newSliceDomain = '';
            this.newSliceDescription = '';
            this.mode = 'visual';
        },

        removeSlice(idx) {
            if (this.slices.length <= 1) return;
            const toRemove = this.slices[idx]?.name || ('Slice ' + (idx + 1));
            if (!confirm(`Are you sure you want to remove slice "${toRemove}"?`)) return;
            this.slices.splice(idx, 1);
            if (this.activeSliceIdx >= this.slices.length) {
                this.activeSliceIdx = this.slices.length - 1;
            } else if (this.activeSliceIdx === idx) {
                this.activeSliceIdx = Math.max(0, idx - 1);
            }
            const current = this.slices[this.activeSliceIdx];
            if (current && current.blueprint) {
                this.loadParsedBlueprint(JSON.parse(JSON.stringify(current.blueprint)));
                this.allRelations = JSON.parse(JSON.stringify(current.allRelations || []));
                this.source = current.source || '';
                this.syncToYaml();
            }
        },

        getStudioSliceFieldsCount(sl) {
            if (!sl || !sl.blueprint || !sl.blueprint.models) return 0;
            return sl.blueprint.models.reduce((acc, m) => acc + ((m.fields && m.fields.length) ? m.fields.length : 0), 0);
        },

        updateActiveSliceName() {
            if (this.slices && this.slices[this.activeSliceIdx]) {
                this.slices[this.activeSliceIdx].name = this.blueprint.name || ('Slice ' + (this.activeSliceIdx + 1));
                this.slices[this.activeSliceIdx].handle = this.blueprint.handle;
            }
        },

        // Unified Start from… dispatcher (presets + examples)
        handleStartFrom(value) {
            if (!value) return;
            if (value.startsWith('preset:')) {
                const key = value.slice(7); // drop "preset:"
                this.choosePreset(key);
                return;
            }
            if (value.startsWith('example:')) {
                const key = value.slice(8); // drop "example:"
                this.loadStudioPreset(key);
                return;
            }
            if (value.startsWith('library:')) {
                const slug = value.slice(8); // drop "library:"
                this.loadLibraryItem(slug);
                return;
            }
            // Fallback: treat as example key
            this.loadStudioPreset(value);
        },

        async loadStudioPreset(presetKey) {

            if (!presetKey) return;
            const presets = await window.__loadStudioPresets();
            if (presets[presetKey]) {
                const presetSlices = JSON.parse(JSON.stringify(presets[presetKey]));
                this.slices = presetSlices.map(s => {
                    if (s.blueprint) {
                        return {
                            name: s.name,
                            handle: s.handle,
                            blueprint: JSON.parse(JSON.stringify(s.blueprint)),
                            allRelations: JSON.parse(JSON.stringify(s.allRelations || (s.blueprint.models || []).flatMap(m => m.relations || []))),
                            source: s.source || ''
                        };
                    }
                    const bp = {
                        schema_version: 1,
                        name: s.name,
                        handle: s.handle,
                        domain: s.domain,
                        description: s.description,
                        author: 'LaraSlice Team',
                        version: '1.0.0',
                        permissions: s.permissions || [],
                        models: s.models || [],
                        workflow: s.workflow || null,
                        roles: s.roles || []
                    };
                    return {
                        name: s.name,
                        handle: s.handle,
                        blueprint: bp,
                        allRelations: (s.models || []).flatMap(m => m.relations || []),
                        source: ''
                    };
                });
                this.activeSliceIdx = 0;
                const first = this.slices[0];
                this.loadParsedBlueprint(first.blueprint);
                this.allRelations = JSON.parse(JSON.stringify(first.allRelations || []));
                if (first.source) {
                    this.source = first.source;
                } else {
                    this.syncToYaml();
                    first.source = this.source;
                }
                this.generateDomainYaml();
                return;
            }
            this.switchTemplate(presetKey);
        },

        switchTemplate(tpl) {
            if (!tpl) return;
            const url = studioUrl || window.location.pathname;
            window.location.href = `${url}?template=${encodeURIComponent(tpl)}`;
        },

        loadSlice(slice) {
            if (!slice) return;
            // Inside the Studio shell, swap only the Design
            // fragment (the shell keeps chrome + URL in
            // sync). Standalone (legacy blueprint page) the
            // full navigation is the correct behaviour.
            if (typeof window.__oneStudioDesignSlice === 'function') {
                window.__oneStudioDesignSlice(slice);
                return;
            }
            const url = studioUrl || window.location.pathname;
            window.location.href = `${url}?slice=${encodeURIComponent(slice)}`;
        },
        newPermInput: '',
        blueprint: {
            schema_version: 1,
            name: '',
            handle: '',
            domain: '',
            description: '',
            author: '',
            version: '1.0.0',
            permissions: [],
            models: []
        },

        generateStandardPermissions() {
            let base = this.blueprint.handle || 'slice';
            if (base.endsWith('s')) {
                base = base.replace(/s$/, '');
            }
            const standard = [
                `${base}.view`,
                `${base}.create`,
                `${base}.edit`,
                `${base}.delete`
            ];
            if (!Array.isArray(this.blueprint.permissions)) {
                this.blueprint.permissions = [];
            }
            standard.forEach(p => {
                if (!this.blueprint.permissions.includes(p)) {
                    this.blueprint.permissions.push(p);
                }
            });
            this.syncToYaml();
        },

        addCustomPermission() {
            let val = (this.newPermInput || '').trim();
            if (!val) return;
            let base = this.blueprint.handle || 'slice';
            if (base.endsWith('s')) {
                base = base.replace(/s$/, '');
            }
            if (!val.includes('.')) {
                val = `${base}.${val}`;
            }
            if (!Array.isArray(this.blueprint.permissions)) {
                this.blueprint.permissions = [];
            }
            if (!this.blueprint.permissions.includes(val)) {
                this.blueprint.permissions.push(val);
            }
            this.newPermInput = '';
            this.syncToYaml();
        },

        removePermission(idx) {
            if (Array.isArray(this.blueprint.permissions)) {
                this.blueprint.permissions.splice(idx, 1);
                this.syncToYaml();
            }
        },

        getGridSpan(width) {
            const w = parseInt(width) || 50;
            if (w >= 100) return 12;
            if (w >= 75) return 9;
            if (w >= 66) return 8;
            if (w >= 50) return 6;
            if (w >= 33) return 4;
            if (w >= 25) return 3;
            return 6;
        },

        catalogGroups() {
            return this.installedCatalog?.groups || [];
        },

        catalogSlices() {
            return (this.installedCatalog?.groups || []).flatMap(g => g.slices || []);
        },

        // The Porting Studio modal (~100 KB of review markup)
        // ships lazily: the panel carries only a slot, and the
        // markup is fetched the first time the modal opens,
        // then injected INSIDE this component's root so its
        // bindings join this scope (initTree on the slot).
        async ensurePortingModal() {
            if (this.portingModalLoaded || this.portingModalLoading) return;
            const slot = document.getElementById('design-porting-modal-slot');
            if (!slot) return;
            this.portingModalLoading = true;
            try {
                const res = await fetch('/laraslice/studio/blueprint/porting-modal', { headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
                if (!res.ok) throw new Error('porting modal fetch failed: ' + res.status);
                slot.innerHTML = await res.text();
                if (window.Alpine && typeof window.Alpine.initTree === 'function') {
                    window.Alpine.initTree(slot);
                }
                this.portingModalLoaded = true;
            } catch (e) {
                // One quiet retry for a transient failure; after
                // that the modal simply stays closed this session
                // rather than error-looping on every open.
                if (!this._portingModalRetried) {
                    this._portingModalRetried = true;
                    setTimeout(() => this.ensurePortingModal(), 1500);
                }
            } finally {
                this.portingModalLoading = false;
            }
        },

        init() {
            // First open of the Porting Studio modal loads its
            // markup into the panel's slot (see ensurePortingModal).
            this.$watch('showPortModal', (v) => { if (v) this.ensurePortingModal(); });
            // Engine-truth validation (F8): keep the chip's verdict
            // in step with the engine's BlueprintValidator as the
            // source changes; checked once on settle too.
            this.$watch('source', () => this.scheduleEngineCheck());
            setTimeout(() => this.scheduleEngineCheck(), 900);
            // Arm dirty tracking after init's own
            // programmatic syncs have run (see syncToYaml).
            setTimeout(() => { this._syncReady = true; }, 0);
            // Draft check after the component settles: an
            // immediate fetch races the shell's fragment
            // mount and can be aborted with the swap.
            setTimeout(() => this.checkDraft(), 600);
            setTimeout(() => this.consumeDraftedBlueprint(), 700);
            // Team library list for the Start-from picker —
            // same delayed, best-effort pattern as the draft
            // check (an immediate fetch races the mount).
            setTimeout(() => this.loadLibraryItems(), 650);
            // Restore active ported plan from Porting Studio (sessionStorage or localStorage)
            try {
                const rawPlan = sessionStorage.getItem('laraslice_port_plan') || localStorage.getItem('laraslice_port_plan');
                if (rawPlan) {
                    const parsedPlan = JSON.parse(rawPlan);
                    if (parsedPlan && (parsedPlan.studio_slices || parsedPlan.modules)) {
                        this.portPlanData = parsedPlan;
                    }
                }
            } catch(e) {}

            // Load saved presets from backend
            fetch('/laraslice/wizard/port-saved-presets', { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(d => { if (d.success) this.savedPresets = d.presets || []; })
                .catch(() => {});

            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('port') === '1') {
                this.showPortModal = true;
            }
            if (urlParams.get('load_port') === '1' || urlParams.get('auto_load_port') === '1') {
                this.loadPortedToStudio();
                return;
            }
            if (urlParams.get('preset')) {
                this.choosePreset(urlParams.get('preset'));
                return;
            }
            // NOTE: Porting Analyze is intentionally NOT auto-fired here.
            // The developer opens the Porting Studio dialog and clicks Analyze explicitly.
            // 1. Restore all slices if passed from backend (e.g. after form validation / plan review)
            if (allSlicesJson) {
                try {
                    const parsed = typeof allSlicesJson === 'string' ? JSON.parse(allSlicesJson) : allSlicesJson;
                    const isCorrupt = Array.isArray(parsed) && parsed[0]?.blueprint?.models?.[0]?.fields?.some((f, idx, arr) => arr.findIndex(x => x.handle === f.handle) !== idx);
                    if (!isCorrupt && Array.isArray(parsed) && parsed.length > 0) {
                        this.slices = parsed;
                        this.activeSliceIdx = parseInt(activeSliceIdx) || 0;
                        if (this.activeSliceIdx >= this.slices.length) this.activeSliceIdx = 0;
                        const activeSl = this.slices[this.activeSliceIdx];
                        if (activeSl && activeSl.blueprint) {
                            this.loadParsedBlueprint(activeSl.blueprint);
                            this.allRelations = JSON.parse(JSON.stringify(activeSl.allRelations || []));
                            this.source = activeSl.source || this.source;
                            this.syncToYaml();
                        }
                        return;
                    }
                } catch (e) {}
            }

            const tplParam = urlParams.get('template');
            if (tplParam) {
                // An explicit template deep-link takes
                // precedence over the slice restore below.
                window.__loadStudioPresets().then(presets => {
                    if (presets[tplParam]) this.loadStudioPreset(tplParam);
                });
                return;
            }

            const sliceParam = urlParams.get('slice');
            if (sliceParam && initialBlueprint) {
                // Restore existing studio slices collection from sessionStorage if present so the domain tabs remain available
                try {
                    const stored = sessionStorage.getItem('laraslice_studio_slices');
                    if (stored) {
                        const parsed = JSON.parse(stored);
                        if (Array.isArray(parsed) && parsed.length > 0) {
                            this.slices = parsed;
                        }
                    }
                } catch(e) {}

                // Load the live requested blueprint directly from disk/controller
                this.loadParsedBlueprint(initialBlueprint);
                this.source = initialSource || this.source;

                // Sync into this.slices collection
                if (Array.isArray(this.slices) && this.slices.length > 0) {
                    const foundIdx = this.slices.findIndex(s => 
                        (s.name && s.name.toLowerCase() === sliceParam.toLowerCase()) || 
                        (s.handle && s.handle.toLowerCase() === sliceParam.toLowerCase())
                    );
                    if (foundIdx !== -1) {
                        this.activeSliceIdx = foundIdx;
                        this.slices[foundIdx].blueprint = JSON.parse(JSON.stringify(this.blueprint));
                        this.slices[foundIdx].source = this.source;
                        this.slices[foundIdx].name = this.blueprint.name;
                    } else {
                        this.slices.push({
                            name: this.blueprint.name || sliceParam,
                            handle: this.blueprint.handle || sliceParam.toLowerCase(),
                            domain: this.blueprint.domain || '',
                            blueprint: JSON.parse(JSON.stringify(this.blueprint)),
                            allRelations: JSON.parse(JSON.stringify(this.allRelations || [])),
                            source: this.source
                        });
                        this.activeSliceIdx = this.slices.length - 1;
                    }
                } else {
                    this.slices = [{
                        name: this.blueprint.name || sliceParam,
                        handle: this.blueprint.handle || sliceParam.toLowerCase(),
                        domain: this.blueprint.domain || '',
                        blueprint: JSON.parse(JSON.stringify(this.blueprint)),
                        allRelations: JSON.parse(JSON.stringify(this.allRelations || [])),
                        source: this.source
                    }];
                    this.activeSliceIdx = 0;
                }

                sessionStorage.setItem('laraslice_active_slice_idx', String(this.activeSliceIdx));
                sessionStorage.setItem('laraslice_studio_slices', JSON.stringify(this.slices));
                this.syncToYaml();
                return;
            }

            // Restore from sessionStorage if available
            try {
                const stored = sessionStorage.getItem('laraslice_studio_slices');
                if (stored) {
                    const parsed = JSON.parse(stored);
                    const isCorrupt = Array.isArray(parsed) && parsed[0]?.blueprint?.models?.[0]?.fields?.some((f, idx, arr) => arr.findIndex(x => x.handle === f.handle) !== idx);
                    if (isCorrupt) {
                        sessionStorage.removeItem('laraslice_studio_slices');
                        sessionStorage.removeItem('laraslice_active_slice_idx');
                    } else if (Array.isArray(parsed) && parsed.length > 0) {
                        this.slices = parsed;
                        const storedIdx = parseInt(sessionStorage.getItem('laraslice_active_slice_idx') || '0');
                        this.activeSliceIdx = (storedIdx >= 0 && storedIdx < this.slices.length) ? storedIdx : 0;
                        const activeSl = this.slices[this.activeSliceIdx];
                        if (activeSl && activeSl.blueprint) {
                            this.loadParsedBlueprint(activeSl.blueprint);
                            this.allRelations = JSON.parse(JSON.stringify(activeSl.allRelations || []));
                            this.source = activeSl.source || this.source;
                            this.syncToYaml();
                        }
                        return;
                    }
                }
            } catch (e) {}

            this.slices = [{
                name: this.blueprint.name || 'Primary Slice',
                handle: this.blueprint.handle || 'primary_slice',
                blueprint: JSON.parse(JSON.stringify(this.blueprint)),
                allRelations: JSON.parse(JSON.stringify(this.allRelations || [])),
                source: this.source
            }];
            this.activeSliceIdx = 0;

            // Initialize collapse state - expand all by default
            this.blueprint.models.forEach((_, idx) => {
                this.modelCollapsed[idx] = false;
            });

            if (this.slices && this.slices[this.activeSliceIdx]) {
                this.notifyCopilotOfSlice(this.slices[this.activeSliceIdx]);
            }
        },

        loadParsedBlueprint(data) {
            if (!data || typeof data !== 'object') return;
            this.blueprint.schema_version = data.schema_version || 1;
            this.blueprint.name = data.name || '';
            this.blueprint.handle = this.toSnakeCase(data.handle || data.name || '');
            this.blueprint.domain = data.domain || '';
            this.blueprint.module = data.module || '';
            this.blueprint.description = data.description || '';
            this.blueprint.author = data.author || '';
            this.blueprint.version = data.version || '1.0.0';
            this.blueprint.permissions = Array.isArray(data.permissions) ? data.permissions : [];
            this.blueprint.workflow = data.workflow || null;
            this.blueprint.roles = Array.isArray(data.roles) ? data.roles : [];
            this.blueprint.navigation = data.navigation || null;
            this.blueprint.pages = data.pages || null;
            if (this.blueprint.permissions.length === 0 && this.blueprint.handle) {
                let base = this.blueprint.handle.replace(/s$/, '');
                this.blueprint.permissions = [`${base}.view`, `${base}.create`, `${base}.edit`, `${base}.delete`];
            }
            if (data.ui && typeof data.ui === 'object') {
                this.blueprint.ui = JSON.parse(JSON.stringify(data.ui));
            } else {
                this.blueprint.ui = null;
            }
            this.blueprint.models = [];
            this.allRelations = [];

            const rawModels = Array.isArray(data.models) ? data.models : [];
            rawModels.forEach(m => {
                const mHandle = this.toSnakeCase(m.handle || m.name || 'model');
                const modelObj = {
                    handle: mHandle,
                    name: m.name || this.toStudlyCase(mHandle),
                    table: m.table || (mHandle + 's'),
                    root: !!m.root,
                    timestamps: m.timestamps !== false,
                    soft_deletes: !!m.soft_deletes,
                    description: m.description || '',
                    fields: [],
                    relations: []
                };

                const rawFields = Array.isArray(m.fields) ? m.fields : [];
                const seenFields = new Set();
                const reservedHandles = ['id', 'created_at', 'updated_at', 'deleted_at'];
                rawFields.forEach(f => {
                    const fHandle = this.toSnakeCase(f.handle || f.name || '');
                    if (!fHandle || reservedHandles.includes(fHandle) || seenFields.has(fHandle)) return;
                    seenFields.add(fHandle);

                    const fType = f.type || 'string';
                    const fOptions = (f.options && typeof f.options === 'object') ? f.options : {};
                    const fOptionsText = Object.entries(fOptions).map(([k, v]) => `${k}: ${v}`).join(', ');

                    const isNullable = f.nullable !== undefined ? !!f.nullable : (f.required !== undefined ? !f.required : true);
                    const isRequired = !isNullable;

                    modelObj.fields.push({
                        handle: fHandle,
                        label: f.label || (fHandle ? (fHandle.charAt(0).toUpperCase() + fHandle.slice(1).replace(/_/g, ' ')) : ''),
                        type: fType,
                        input_type: f.input_type || (fType === 'enum' ? 'select' : (['text', 'json'].includes(fType) ? 'textarea' : (fType === 'boolean' ? 'checkbox' : (fType === 'date' ? 'date' : 'text')))),
                        required: isRequired,
                        nullable: isNullable,
                        hidden: !!f.hidden,
                        width: parseInt(f.width) || (['text', 'json'].includes(fType) ? 100 : 50),
                        length: f.length !== undefined && f.length !== null ? String(f.length) : '',
                        default: f.default !== undefined && f.default !== null ? String(f.default) : '',
                        options: fOptions,
                        optionsText: fOptionsText
                    });
                });

                const rawRelations = Array.isArray(m.relations) ? m.relations : [];
                const seenRelations = new Set();
                rawRelations.forEach(r => {
                    const rName = this.toSnakeCase(r.name || '');
                    if (!rName || seenRelations.has(rName)) return;
                    seenRelations.add(rName);

                    const relObj = {
                        source_model: mHandle,
                        name: rName,
                        type: r.type || 'belongsTo',
                        model: this.toSnakeCase(r.model || ''),
                        foreign_key: r.foreign_key || ''
                    };
                    // The relation's target table must round-trip:
                    // without it the applier cannot record the
                    // field's references, and generation emits a
                    // plain index column instead of the foreign
                    // key constraint the blueprint declared (F10).
                    if (r.table) relObj.table = r.table;
                    if (r.external) relObj.external = true;
                    modelObj.relations.push(relObj);
                    this.allRelations.push(relObj);
                });

                this.blueprint.models.push(modelObj);
            });
            this.ensureUiConfig();
            this.syncToYaml();
        },

        toggleModelCollapse(idx) {
            this.modelCollapsed[idx] = !this.modelCollapsed[idx];
        },

        collapseAllModels() {
            this.blueprint.models.forEach((_, idx) => {
                this.modelCollapsed[idx] = true;
            });
        },

        expandAllModels() {
            this.blueprint.models.forEach((_, idx) => {
                this.modelCollapsed[idx] = false;
            });
        },

        setRootModel(modelIndex) {
            this.blueprint.models.forEach((m, idx) => {
                m.root = (idx === modelIndex);
            });
            this.syncToYaml();
        },

        onRequiredToggle(field) {
            if (field.required) {
                field.nullable = false;
            } else {
                field.nullable = true;
            }
        },

        onNullableToggle(field) {
            if (field.nullable) {
                field.required = false;
            } else {
                field.required = true;
            }
        },

        addRelationship() {
            const src = this.blueprint.models[0]?.handle || 'model';
            const otherModel = this.blueprint.models.find(m => m.handle !== src);
            const tgt = otherModel ? otherModel.handle : 'user';
            const rCount = this.allRelations.length + 1;
            this.allRelations.push({
                source_model: src,
                name: tgt ? (tgt.endsWith('s') ? tgt : tgt + 's') : ('relation_' + rCount),
                type: 'hasMany',
                model: tgt,
                foreign_key: src ? (src + '_id') : ''
            });
            this.syncToYaml();
        },

        removeRelationship(index) {
            this.allRelations.splice(index, 1);
            this.syncToYaml();
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

        get yamlStatus() {
            const raw = this.source || '';
            if (!raw.trim()) return { ok: true, tone: 'neutral', message: 'No blueprint yet' };
            if (typeof jsyaml === 'undefined') return { ok: true, tone: 'neutral', message: 'YAML parser unavailable' };
            try {
                const doc = jsyaml.load(raw);
                if (!doc || typeof doc !== 'object') return { ok: false, tone: 'error', message: 'YAML parsed but is not a mapping' };
                const hasShape = !!(doc.name || doc.handle || (Array.isArray(doc.slices) && doc.slices.length) || (Array.isArray(doc.models) && doc.models.length));
                return hasShape
                    ? { ok: true, tone: 'ok', message: 'YAML valid' }
                    : { ok: false, tone: 'error', message: 'YAML parses but declares no name, models, or slices' };
            } catch (e) {
                return { ok: false, tone: 'error', message: 'YAML error: ' + (e.message || 'parse failure') };
            }
        },

        /**
         * The chip's combined verdict: the local syntax/shape check
         * (yamlStatus) AND the engine's BlueprintValidator verdict
         * (runEngineCheck — the same validator slice:blueprint:plan
         * runs). The Studio must never claim plain "YAML valid" for a
         * blueprint the engine would reject (Day-2 drill F8).
         */
        get validityStatus() {
            const syntax = this.yamlStatus;
            if (!syntax.ok) return { ok: false, tone: 'error', label: 'YAML error', message: syntax.message };
            if (!(this.source || '').trim()) return { ok: true, tone: 'neutral', label: 'YAML valid', message: syntax.message };
            if (this.engineCheck.state === 'invalid') {
                const first = (this.engineCheck.errors || [])[0] || 'invalid blueprint';
                return { ok: false, tone: 'error', label: 'Engine rejects', message: 'The generation engine rejects this blueprint: ' + first };
            }
            if (this.engineCheck.state === 'valid') {
                return { ok: true, tone: 'ok', label: 'YAML valid', message: 'YAML valid — the generation engine accepts this blueprint.' };
            }
            return { ok: true, tone: 'ok', label: 'YAML valid', message: 'YAML valid (engine check pending).' };
        },

        scheduleEngineCheck() {
            if (this._engineCheckTimer) clearTimeout(this._engineCheckTimer);
            this._engineCheckTimer = setTimeout(() => this.runEngineCheck(), 600);
        },

        async runEngineCheck() {
            const source = this.source || '';
            if (!source.trim()) { this.engineCheck = { state: 'idle', errors: [] }; return; }
            const previous = this.engineCheck;
            this.engineCheck = { state: 'checking', errors: previous.errors || [] };
            try {
                const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const res = await fetch('/laraslice/studio/blueprint/validate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ source: source, format: this.format || 'yaml' }),
                });
                if (!res.ok) { this.engineCheck = { state: 'idle', errors: [] }; return; }
                const data = await res.json();
                // A newer edit may have landed while the check ran;
                // only apply the verdict if the source is unchanged.
                if ((this.source || '') !== source) return;
                this.engineCheck = data.valid
                    ? { state: 'valid', errors: [] }
                    : { state: 'invalid', errors: data.errors || ['Invalid blueprint'] };
            } catch (e) {
                this.engineCheck = { state: 'idle', errors: [] };
            }
        },

        parseYamlToBlueprint(yamlText) {
            const raw = yamlText || this.source;
            if (!raw || !raw.trim()) return;

            if (typeof jsyaml === 'undefined') {
                // The parser is a lazy shared asset now: when it
                // lands, re-run this exact parse — no input is lost.
                if (window.__loadJsYaml) window.__loadJsYaml().then(() => this.parseYamlToBlueprint(yamlText)).catch(() => {});
                return;
            }

            try {
                let doc = null;
                if (typeof jsyaml !== 'undefined') {
                    doc = jsyaml.load(raw);
                }
                if (!doc || typeof doc !== 'object') return;

                // 1. Check if this is a Domain Suite YAML (contains 'slices' array)
                if (doc.slices && Array.isArray(doc.slices) && doc.slices.length > 0) {
                    this.domainName = doc.domain || this.domainName;
                    this.blueprint.domain = this.domainName;
                    this.slices = doc.slices.map((s, idx) => {
                        const sName = s.name || ('Slice ' + (idx + 1));
                        const sHandle = this.toSnakeCase(s.handle || sName);
                        const sSource = typeof jsyaml !== 'undefined' ? jsyaml.dump(s, { indent: 2, lineWidth: -1, noRefs: true }) : '';
                        return {
                            name: sName,
                            handle: sHandle,
                            domain: s.domain || this.domainName,
                            blueprint: JSON.parse(JSON.stringify(s)),
                            allRelations: (s.models || []).flatMap(m => (m.relations || []).map(r => {
                                const relEntry = {
                                    source_model: this.toSnakeCase(m.handle || ''),
                                    name: this.toSnakeCase(r.name || ''),
                                    type: r.type || 'belongsTo',
                                    model: this.toSnakeCase(r.model || ''),
                                    foreign_key: r.foreign_key || ''
                                };
                                if (r.table) relEntry.table = r.table;
                                if (r.external) relEntry.external = true;
                                return relEntry;
                            })),
                            source: sSource
                        };
                    });
                    this.activeSliceIdx = 0;
                    if (this.slices.length > 0) {
                        this.loadParsedBlueprint(this.slices[0].blueprint);
                        this.allRelations = JSON.parse(JSON.stringify(this.slices[0].allRelations || []));
                        this.source = this.slices[0].source || '';
                    }
                    this.domainYamlSource = raw;
                    return;
                }

                // 2. Otherwise it is a Single Slice Blueprint
                this.loadParsedBlueprint(doc);
                this.syncToYaml();
            } catch (e) {
                console.warn('YAML Parse Warning:', e);
            }
        },

        /**
         * Design drafts (server-side, per blueprint handle):
         * work in Design no longer evaporates on navigation.
         * A draft is working state — the slice itself only
         * changes through Manage/generation.
         */
        /**
         * Quick-nav chips mirror their sections' own
         * visibility rules — a chip never promises a
         * section the blueprint does not have.
         */
        chipShown(sec) {
            if (sec.id === 'design-sec-workflow') return !!(this.blueprint.workflow && this.blueprint.workflow.states && this.blueprint.workflow.states.length);
            if (sec.id === 'design-sec-roles') return !!(this.blueprint.roles && this.blueprint.roles.length);
            return true;
        },

        async checkDraft() {
            const handle = this.blueprint && this.blueprint.handle;
            if (!handle) return;
            try {
                const res = await fetch('/laraslice/studio/blueprint/draft?handle=' + encodeURIComponent(handle), { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;
                const data = await res.json();
                if (data.exists && data.source && data.source !== this.source) {
                    this.draftAvailable = { saved_at: data.saved_at, source: data.source };
                }
            } catch (e) { /* drafts are best-effort */ }
        },

        /**
         * Consume an AI-drafted blueprint handed over by
         * the shell (kickoff brief → draft-from-brief). It
         * arrives as an unsaved draft in the editor, clearly
         * labelled — generation stays a separate, explicit
         * step.
         */
        consumeDraftedBlueprint() {
            let drafted = null;
            try {
                drafted = sessionStorage.getItem('laraslice_drafted_blueprint');
                if (drafted) sessionStorage.removeItem('laraslice_drafted_blueprint');
            } catch (e) {}
            if (!drafted) return;
            this.source = drafted;
            this.syncFromYaml();
            this.blueprintDirty = true;
            this.draftAvailable = null;
            this.draftStatus = 'AI draft from your kickoff brief — review every field before generating. Nothing was written.';
        },

        loadDraft() {
            if (!this.draftAvailable) return;
            this.source = this.draftAvailable.source;
            this.syncFromYaml();
            this.blueprintDirty = true;
            this.draftStatus = 'Draft loaded — it differs from the installed slice until you generate.';
            this.draftAvailable = null;
        },

        async saveDraft() {
            const handle = this.blueprint && this.blueprint.handle;
            if (!handle) return;
            this.draftStatus = 'Saving draft…';
            try {
                const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const res = await fetch('/laraslice/studio/blueprint/draft', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ handle: handle, source: this.source }),
                });
                const data = await res.json();
                this.draftStatus = data.saved ? ('Draft saved ' + data.saved_at + ' — resume any time from this banner.') : (data.message || 'Draft could not be saved.');
                if (data.saved) this.draftAvailable = null;
            } catch (e) {
                this.draftStatus = 'Draft could not be saved (network).';
            }
        },

        /**
         * Team library (porting/blueprints/*.blueprint.yaml):
         * blueprints shelved as FILES in the app, so the
         * whole team shares them through Git — unlike drafts
         * (personal working state in storage/) and presets
         * (package-shipped starting points). Loading applies
         * the YAML through the same funnel as an AI draft:
         * it lands in the editor, unsaved, for review.
         */
        async loadLibraryItems() {
            try {
                const res = await fetch('/laraslice/studio/blueprint/library', { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;
                const data = await res.json();
                this.libraryItems = data.blueprints || [];
            } catch (e) { /* the library is best-effort */ }
        },

        async loadLibraryItem(slug) {
            if (!slug) return;
            try {
                const res = await fetch('/laraslice/studio/blueprint/library/' + encodeURIComponent(slug), { headers: { 'Accept': 'application/json' } });
                if (!res.ok) { this.draftStatus = 'That library blueprint could not be loaded.'; return; }
                const data = await res.json();
                this.source = data.yaml;
                this.syncFromYaml();
                this.blueprintDirty = true;
                this.draftAvailable = null;
                this.librarySlug = data.slug;
                this.draftStatus = 'Loaded "' + data.name + '" from the team library — review before generating. Nothing was written.';
            } catch (e) {
                this.draftStatus = 'Library blueprint could not be loaded (network).';
            }
        },

        async saveToLibrary() {
            const name = (this.blueprint && this.blueprint.name) || '';
            if (!name) { this.draftStatus = 'Give the blueprint a name before shelving it in the library.'; return; }
            if (this.yamlViewMode === 'domain') { this.draftStatus = 'Switch to Current Slice YAML to shelve a blueprint.'; return; }
            this.draftStatus = 'Shelving in the team library…';
            try {
                const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const res = await fetch('/laraslice/studio/blueprint/library', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ name: name, yaml: this.source }),
                });
                const data = await res.json();
                if (res.ok && data.slug) {
                    this.librarySlug = data.slug;
                    await this.loadLibraryItems();
                    this.draftStatus = 'Shelved "' + data.name + '" in the team library (porting/blueprints/' + data.slug + '.blueprint.yaml) — commit it with your work to share it.';
                } else {
                    this.draftStatus = data.message || 'That blueprint could not be shelved.';
                }
            } catch (e) {
                this.draftStatus = 'Library save failed (network).';
            }
        },

        async deleteLibraryItem() {
            if (!this.librarySlug) return;
            const slug = this.librarySlug;
            try {
                const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const res = await fetch('/laraslice/studio/blueprint/library/' + encodeURIComponent(slug), {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                });
                const data = await res.json();
                if (res.ok && data.deleted) {
                    this.librarySlug = null;
                    await this.loadLibraryItems();
                    this.draftStatus = 'Removed from the team library. The file leaves Git history on your next commit, like any file.';
                } else {
                    this.draftStatus = 'That library blueprint could not be removed.';
                }
            } catch (e) {
                this.draftStatus = 'Library delete failed (network).';
            }
        },

        syncToYaml() {
            // Dirty tracking: every edit path (visual
            // controls AND the direct slice-definition
            // inputs) funnels through here. The flag arms
            // only after init, so loading a slice does not
            // mark its own draft dirty.
            if (this._syncReady) this.blueprintDirty = true;
            const cleanModels = (this.blueprint.models || []).map(m => {
                const mHandle = this.toSnakeCase(m.handle || m.name || 'model');
                const mObj = {
                    handle: mHandle,
                    name: m.name || this.toStudlyCase(mHandle),
                    table: m.table || (mHandle + 's'),
                    root: !!m.root,
                    timestamps: m.timestamps !== false,
                    soft_deletes: !!m.soft_deletes,
                    description: m.description || '',
                    fields: (m.fields || [])
                        .filter(f => !['id', 'created_at', 'updated_at', 'deleted_at'].includes(this.toSnakeCase(f.handle || '')))
                        .map(f => {
                            const fHandle = this.toSnakeCase(f.handle || 'field');
                            const isReq = !!f.required;
                            const isNll = f.nullable !== undefined ? !!f.nullable : !isReq;
                            const finalReq = isNll ? false : true;
                            const finalNll = !finalReq;
                            const fObj = {
                                handle: fHandle,
                                label: f.label || (fHandle.charAt(0).toUpperCase() + fHandle.slice(1).replace(/_/g, ' ')),
                                type: f.type || 'string',
                                input_type: f.input_type || 'text',
                                required: finalReq,
                                nullable: finalNll,
                                width: parseInt(f.width) || 50,
                            };
                            if (f.hidden) fObj.hidden = true;
                            if (f.length) {
                                // The engine (BlueprintValidator) requires an
                                // integer length for string/email/url fields
                                // and a "precision,scale" string for decimal/
                                // float. The visual editor's length input is a
                                // text box, so values arrive as digit-strings;
                                // serialize them in the engine's form here —
                                // the same funnel width already uses — or the
                                // Studio emits YAML its own plan rejects (F8).
                                const lenType = f.type || 'string';
                                if (['decimal', 'float'].includes(lenType)) {
                                    fObj.length = String(f.length);
                                } else if (typeof f.length === 'string' && /^\d+$/.test(f.length.trim())) {
                                    fObj.length = parseInt(f.length, 10);
                                } else {
                                    fObj.length = f.length;
                                }
                            }
                            if (f.default) fObj.default = f.default;
                            if (f.type === 'enum' && f.options && Object.keys(f.options).length) {
                                fObj.options = f.options;
                            }
                            return fObj;
                        }),
                    relations: (this.allRelations && this.allRelations.length > 0
                        ? this.allRelations.filter(r => r.source_model === mHandle)
                        : (m.relations || [])
                    ).map(r => {
                        const relOut = {
                            name: this.toSnakeCase(r.name || 'relation'),
                            type: r.type || 'belongsTo',
                            model: this.toSnakeCase(r.model || ''),
                            foreign_key: r.foreign_key || ''
                        };
                        // Target table / externality round-trip
                        // (see loadParsedBlueprint): dropping them
                        // here silently downgraded declared FK
                        // constraints to plain indexes (F10).
                        if (r.table) relOut.table = r.table;
                        if (r.external) relOut.external = true;
                        return relOut;
                    })
                };
                return mObj;
            });

            // Auto-link child models to root aggregate if missing and not a catalog
            const rootModel = cleanModels.find(m => m.root) || cleanModels[0];
            const isCatalogSlice = !!this.blueprint.taxonomy || /setting|master|catalog|reference/i.test(this.blueprint.name || '');
            if (rootModel && cleanModels.length > 1 && !isCatalogSlice) {
                cleanModels.forEach(cm => {
                    if (!cm.root) {
                        const childFields = (cm.fields || []).map(f => f.handle);
                        let bestFk = childFields.includes(rootModel.handle + '_id') ? (rootModel.handle + '_id') : null;
                        if (!bestFk) {
                            bestFk = rootModel.handle + '_id';
                            if (!childFields.includes(bestFk)) {
                                cm.fields.push({
                                    handle: bestFk,
                                    label: rootModel.name || (rootModel.handle.charAt(0).toUpperCase() + rootModel.handle.slice(1)),
                                    type: 'foreign_id',
                                    input_type: 'select',
                                    required: false,
                                    nullable: true,
                                    width: 50
                                });
                            }
                        }
                        const hasRel = (rootModel.relations || []).some(r => r.model === cm.handle);
                        if (!hasRel) {
                            rootModel.relations.push({
                                name: cm.handle.endsWith('s') ? cm.handle : (cm.handle + 's'),
                                type: 'hasMany',
                                model: cm.handle,
                                foreign_key: bestFk
                            });
                        }
                        const childHasRel = (cm.relations || []).some(r => r.model === rootModel.handle);
                        if (!childHasRel) {
                            cm.relations.push({
                                name: rootModel.handle,
                                type: 'belongsTo',
                                model: rootModel.handle,
                                foreign_key: bestFk
                            });
                        }
                    }
                });
            }

            const bpObj = {
                schema_version: 1,
                name: this.blueprint.name || 'Untitled Slice',
                handle: this.toSnakeCase(this.blueprint.handle || this.blueprint.name || 'untitled_slice'),
                domain: this.blueprint.domain || '',
                description: this.blueprint.description || '',
                author: this.blueprint.author || '',
                version: this.blueprint.version || '1.0.0',
                permissions: this.blueprint.permissions || [],
                models: cleanModels
            };

            if (this.blueprint.workflow && this.blueprint.workflow.states && this.blueprint.workflow.states.length) {
                bpObj.workflow = JSON.parse(JSON.stringify(this.blueprint.workflow));
            }
            if (this.blueprint.roles && this.blueprint.roles.length) {
                bpObj.roles = JSON.parse(JSON.stringify(this.blueprint.roles));
            }
            if (this.blueprint.ui) {
                const rawUi = this.blueprint.ui;
                const cleanUi = {
                    presentation: rawUi.presentation || { create: 'drawer', edit: 'drawer' },
                    directory: {
                        columns: (rawUi.directory?.columns || []).map(c => ({
                            handle: c.handle || c.field || c.key,
                            label: c.label || c.handle,
                            formatter: c.formatter || c.type || 'text',
                            sortable: c.sortable !== false
                        })),
                        quick_filters: (rawUi.directory?.quick_filters || []).map(qf => ({
                            handle: qf.handle || qf.field || qf.key,
                            label: qf.label || qf.handle,
                            is_searchable: qf.is_searchable !== false && qf.searchable !== false
                        })),
                        sidebar_filters: (rawUi.directory?.sidebar_filters || []).map(sf => ({
                            handle: sf.handle || sf.field || sf.key,
                            label: sf.label || sf.handle,
                            type: sf.type || 'select'
                        }))
                    },
                    forms: {
                        presentation: rawUi.forms?.presentation || rawUi.presentation || { create: 'drawer', edit: 'drawer' },
                        sections: (rawUi.forms?.sections || []).map((sec, idx) => ({
                            key: sec.key || this.toSnakeCase(sec.title || ('section_' + (idx + 1))),
                            title: sec.title || ('Section ' + (idx + 1)),
                            grid: parseInt(sec.grid || sec.columns || 3),
                            fields: (sec.fields || []).map(fld => typeof fld === 'string' ? fld : ({
                                handle: fld.handle || fld.field || fld.key,
                                span: parseInt(fld.span || 1),
                                is_searchable: fld.is_searchable !== false && (fld.searchable === true || (fld.handle || '').endsWith('_id'))
                            }))
                        }))
                    }
                };
                if (rawUi.directory?.row_actions) {
                    cleanUi.directory.row_actions = rawUi.directory.row_actions;
                }
                bpObj.ui = cleanUi;
            }


            if (typeof jsyaml !== 'undefined') {
                this.source = jsyaml.dump(bpObj, { indent: 2, lineWidth: -1, noRefs: true });
            } else if (window.__loadJsYaml && !this._yamlSyncQueued) {
                // Parser still in flight: queue ONE re-sync for
                // when it lands, so the YAML rail catches up.
                this._yamlSyncQueued = true;
                window.__loadJsYaml().then(() => { this._yamlSyncQueued = false; this.syncToYaml(); }).catch(() => { this._yamlSyncQueued = false; });
            }

            if (this.slices && this.slices[this.activeSliceIdx]) {
                this.slices[this.activeSliceIdx].name = bpObj.name;
                this.slices[this.activeSliceIdx].handle = bpObj.handle;
                this.slices[this.activeSliceIdx].domain = bpObj.domain;
                this.slices[this.activeSliceIdx].source = this.source;
                this.slices[this.activeSliceIdx].blueprint = JSON.parse(JSON.stringify(bpObj));
                this.slices[this.activeSliceIdx].allRelations = JSON.parse(JSON.stringify(this.allRelations || []));
                try {
                    sessionStorage.setItem('laraslice_studio_slices', JSON.stringify(this.slices));
                    sessionStorage.setItem('laraslice_active_slice_idx', String(this.activeSliceIdx));
                } catch(e) {}
            }
        },

        syncFromDomainYaml() {
            if (!this.domainYamlSource || !this.domainYamlSource.trim()) return;
            this.parseYamlToBlueprint(this.domainYamlSource);
        },

        syncFromYaml() {
            this.parseYamlToBlueprint();
        },

        formatYaml() {
            this.syncToYaml();
        },

        moveFieldUp(mIdx, fIdx) {
            if (fIdx <= 0) return;
            const fields = this.blueprint.models[mIdx].fields;
            const temp = fields[fIdx];
            fields[fIdx] = fields[fIdx - 1];
            fields[fIdx - 1] = temp;
            this.syncToYaml();
        },

        moveFieldDown(mIdx, fIdx) {
            const fields = this.blueprint.models[mIdx].fields;
            if (fIdx >= fields.length - 1) return;
            const temp = fields[fIdx];
            fields[fIdx] = fields[fIdx + 1];
            fields[fIdx + 1] = temp;
            this.syncToYaml();
        },

        moveModelUp(mIdx) {
            if (mIdx <= 0) return;
            const models = this.blueprint.models;
            const temp = models[mIdx];
            models[mIdx] = models[mIdx - 1];
            models[mIdx - 1] = temp;
            this.syncToYaml();
        },

        moveModelDown(mIdx) {
            const models = this.blueprint.models;
            if (mIdx >= models.length - 1) return;
            const temp = models[mIdx];
            models[mIdx] = models[mIdx + 1];
            models[mIdx + 1] = temp;
            this.syncToYaml();
        },

        addModel() {
            const count = this.blueprint.models.length + 1;
            const newHandle = `entity_${count}`;
            this.blueprint.models.push({
                handle: newHandle,
                table: `${newHandle}s`,
                root: count === 1,
                timestamps: true,
                soft_deletes: false,
                description: '',
                fields: [
                    { handle: 'name', label: 'Name', type: 'string', required: true, nullable: false, width: 50, length: 255, default: '' },
                    { handle: 'status', label: 'Status', type: 'string', required: true, nullable: false, width: 50, length: 30, default: 'active' }
                ],
                relations: []
            });
            // Expand the newly added model
            this.modelCollapsed[this.blueprint.models.length - 1] = false;
            this.syncToYaml();
        },

        removeModel(index) {
            const removed = this.blueprint.models[index];
            if (removed) {
                // remove relations associated with this model
                this.allRelations = this.allRelations.filter(r => r.source_model !== removed.handle && r.model !== removed.handle);
            }
            this.blueprint.models.splice(index, 1);
            if (this.blueprint.models.length > 0 && !this.blueprint.models.some(m => m.root)) {
                this.blueprint.models[0].root = true;
            }
            this.syncToYaml();
        },

        updateOptionsFromText(field) {
            if (!field.optionsText) {
                field.options = {};
            } else {
                const map = {};
                field.optionsText.split(',').forEach(item => {
                    const p = item.split(':');
                    if (p.length >= 2) {
                        const k = p[0].trim();
                        const v = p.slice(1).join(':').trim();
                        if (k) map[k] = v || k;
                    } else if (item.trim()) {
                        const k = item.trim();
                        map[k] = k.charAt(0).toUpperCase() + k.slice(1);
                    }
                });
                field.options = map;
            }
            this.syncToYaml();
        },

        ensureUiConfig() {
            if (!this.blueprint) return;
            const rootModel = (this.blueprint.models && this.blueprint.models[0]) || { fields: [] };
            const fields = rootModel.fields || [];

            if (!this.blueprint.ui || typeof this.blueprint.ui !== 'object') {
                this.blueprint.ui = {
                    presentation: { create: 'drawer', edit: 'drawer' },
                    directory: {
                        columns: [],
                        quick_filters: [],
                        sidebar_filters: []
                    },
                    forms: {
                        mode: 'drawer',
                        drawer_width: 'max-w-3xl',
                        sections: []
                    }
                };
            }

            const ui = this.blueprint.ui;
            ui.presentation = ui.presentation || { create: 'drawer', edit: 'drawer' };
            ui.directory = ui.directory || {};
            ui.directory.row_actions = ui.directory.row_actions || {
                display: 'dropdown',
                items: [
                    { handle: 'view', label: 'View Details', icon: 'eye', placement: 'dropdown', type: 'drawer' },
                    { handle: 'edit', label: 'Edit Record', icon: 'pencil', placement: 'dropdown', type: 'drawer' },
                    { handle: 'delete', label: 'Delete Record', icon: 'trash-2', placement: 'dropdown', type: 'delete', danger: true }
                ]
            };
            // Bulk Actions Default (index table multi-select)
            if (!Array.isArray(ui.directory.bulk_actions)) {
                ui.directory.bulk_actions = [
                    { handle: 'export', label: 'Export Selected', type: 'native', icon: 'download' },
                    { handle: 'delete', label: 'Delete Selected', type: 'native', icon: 'trash-2', danger: true },
                ];
            } else {
                ui.directory.bulk_actions = ui.directory.bulk_actions.map(ba => {
                    if (typeof ba === 'string') {
                        return { handle: ba, label: ba.replace(/_/g, ' '), type: 'native', icon: 'zap' };
                    }
                    return ba;
                });
            }
            ui.forms = ui.forms || {};
            ui.forms.mode = ui.presentation?.create || ui.forms.mode || 'drawer';

            // Normalize existing columns
            if (Array.isArray(ui.directory.columns) && ui.directory.columns.length > 0) {
                ui.directory.columns.forEach(col => {
                    col.handle = col.handle || col.key || 'col';
                    col.type = col.type || col.formatter || 'text';
                    col.formatter = col.type;
                    col.sortable = col.sortable !== false;
                });
            }

            // Normalize quick filters
            if (Array.isArray(ui.directory.quick_filters) && ui.directory.quick_filters.length > 0) {
                ui.directory.quick_filters.forEach(qf => {
                    qf.handle = qf.handle || qf.field || '';
                    qf.field = qf.handle;
                    qf.searchable = qf.searchable !== false && (qf.is_searchable !== false);
                    qf.is_searchable = qf.searchable;
                });
            }

            // Normalize sidebar filters
            if (Array.isArray(ui.directory.sidebar_filters) && ui.directory.sidebar_filters.length > 0) {
                ui.directory.sidebar_filters.forEach(sf => {
                    sf.handle = sf.handle || sf.field || '';
                    sf.field = sf.handle;
                    sf.type = sf.type || 'select';
                });
            }

            // Normalize form sections
            if (Array.isArray(ui.forms.sections) && ui.forms.sections.length > 0) {
                ui.forms.sections.forEach(sec => {
                    sec.grid = parseInt(sec.grid || sec.columns || 3);
                    sec.columns = sec.grid;
                    if (Array.isArray(sec.fields)) {
                        sec.fields.forEach(fld => {
                            fld.span = parseInt(fld.span || 1);
                            fld.searchable = fld.searchable !== false && (fld.is_searchable === true || (fld.handle || '').endsWith('_id'));
                            fld.is_searchable = fld.searchable;
                        });
                    }
                });
            }



            if (!Array.isArray(ui.directory.columns) || ui.directory.columns.length === 0) {
                const blacklist = ['id', 'company_id', 'added_by', 'last_updated_by', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];
                const eligible = fields.filter(f => !blacklist.includes(f.handle));
                ui.directory.columns = [
                    { handle: 'code', label: 'Code', type: 'code_badge', width: 'w-24', sortable: true },
                    ...eligible.slice(0, 5).map(f => {
                        let type = 'text';
                        const h = (f.handle || '').toLowerCase();
                        if (h.includes('user') || h.includes('employee') || h.includes('client')) type = 'composite_user';
                        else if (h.includes('status')) type = 'status_pill';
                        else if (h.includes('amount') || h.includes('price') || h.includes('total') || h.includes('budget')) type = 'money_breakdown';
                        else if (h.includes('progress') || h.includes('percent')) type = 'progress_bar';
                        else if (f.type === 'date' || f.type === 'datetime' || f.type === 'timestamp') type = 'date_time';
                        return {
                            handle: f.handle,
                            label: f.label || (f.handle.charAt(0).toUpperCase() + f.handle.slice(1).replace(/_/g, ' ')),
                            type: type,
                            sortable: true
                        };
                    })
                ];
            }

            if (!Array.isArray(ui.directory.quick_filters) || ui.directory.quick_filters.length === 0) {
                const statusField = fields.find(f => (f.handle || '').includes('status'));
                const relField = fields.find(f => (f.handle || '').endsWith('_id') && !['company_id', 'created_by', 'updated_by'].includes(f.handle));
                ui.directory.quick_filters = [];
                if (relField) {
                    ui.directory.quick_filters.push({
                        field: relField.handle,
                        label: relField.label || 'Filter',
                        type: 'searchable_select',
                        searchable: true
                    });
                }
                if (statusField) {
                    ui.directory.quick_filters.push({
                        field: statusField.handle,
                        label: 'Status',
                        type: 'select',
                        searchable: false
                    });
                }
            }

            if (!Array.isArray(ui.forms.sections) || ui.forms.sections.length === 0) {
                const blacklist = ['id', 'company_id', 'added_by', 'last_updated_by', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];
                const formFields = fields.filter(f => !blacklist.includes(f.handle));
                const wideKeywords = ['address', 'description', 'notes', 'memo', 'summary', 'about', 'bio', 'instructions', 'content', 'body', 'remarks'];

                if (formFields.length <= 6) {
                    ui.forms.sections = [
                        {
                            title: 'General Information',
                            description: 'Core parameters and record identification',
                            icon: 'info',
                            columns: 3,
                            fields: formFields.map(f => ({
                                handle: f.handle,
                                label: f.label || (f.handle.charAt(0).toUpperCase() + f.handle.slice(1).replace(/_/g, ' ')),
                                span: wideKeywords.some(k => (f.handle || '').includes(k)) ? 3 : 1,
                                searchable: (f.handle || '').endsWith('_id') || f.type === 'enum',
                                required: !!f.required
                            }))
                        }
                    ];
                } else {
                    const primary = formFields.slice(0, 6);
                    const secondary = formFields.slice(6);
                    ui.forms.sections = [
                        {
                            title: 'Primary Identification & Details',
                            description: 'Core identity, organizational assignment, and relations',
                            icon: 'user',
                            columns: 3,
                            fields: primary.map(f => ({
                                handle: f.handle,
                                label: f.label || (f.handle.charAt(0).toUpperCase() + f.handle.slice(1).replace(/_/g, ' ')),
                                span: wideKeywords.some(k => (f.handle || '').includes(k)) ? 3 : 1,
                                searchable: (f.handle || '').endsWith('_id') || f.type === 'enum',
                                required: !!f.required
                            }))
                        },
                        {
                            title: 'Operational & Extended Terms',
                            description: 'Extended parameters, terms, and custom notes',
                            icon: 'sliders',
                            columns: 3,
                            fields: secondary.map(f => ({
                                handle: f.handle,
                                label: f.label || (f.handle.charAt(0).toUpperCase() + f.handle.slice(1).replace(/_/g, ' ')),
                                span: wideKeywords.some(k => (f.handle || '').includes(k)) ? 3 : 1,
                                searchable: (f.handle || '').endsWith('_id') || f.type === 'enum',
                                required: !!f.required
                            }))
                        }
                    ];
                }
            }
        },

        syncUiToBlueprintAndYaml() {
            // Every visual edit funnels through here: the
            // draft is now ahead of the installed slice.
            this.blueprintDirty = true;
            this.syncToYaml();
        },

        addDirectoryColumn(handle) {
            this.ensureUiConfig();
            const rootModel = (this.blueprint.models && this.blueprint.models[0]) || { fields: [] };
            const f = (rootModel.fields || []).find(x => x.handle === handle);
            const label = f ? (f.label || handle) : handle;
            let type = 'text';
            const h = handle.toLowerCase();
            if (h.includes('user') || h.includes('employee') || h.includes('client')) type = 'composite_user';
            else if (h.includes('status')) type = 'status_pill';
            else if (h.includes('amount') || h.includes('price') || h.includes('total') || h.includes('budget')) type = 'money_breakdown';
            else if (h.includes('progress') || h.includes('percent')) type = 'progress_bar';
            else if (f && (f.type === 'date' || f.type === 'datetime' || f.type === 'timestamp')) type = 'date_time';

            this.blueprint.ui.directory.columns.push({
                handle: handle,
                label: label,
                type: type,
                sortable: true
            });
            this.syncUiToBlueprintAndYaml();
        },

        removeDirectoryColumn(idx) {
            this.ensureUiConfig();
            this.blueprint.ui.directory.columns.splice(idx, 1);
            this.syncUiToBlueprintAndYaml();
        },

        moveDirectoryColumn(idx, dir) {
            this.ensureUiConfig();
            const cols = this.blueprint.ui.directory.columns;
            const target = idx + dir;
            if (target < 0 || target >= cols.length) return;
            const temp = cols[idx];
            cols[idx] = cols[target];
            cols[target] = temp;
            this.syncUiToBlueprintAndYaml();
        },

        addQuickFilter(handle) {
            this.ensureUiConfig();
            const rootModel = (this.blueprint.models && this.blueprint.models[0]) || { fields: [] };
            const f = (rootModel.fields || []).find(x => x.handle === handle);
            this.blueprint.ui.directory.quick_filters.push({
                field: handle,
                label: f?.label || handle,
                type: handle.endsWith('_id') ? 'searchable_select' : 'select',
                searchable: handle.endsWith('_id')
            });
            this.syncUiToBlueprintAndYaml();
        },

        removeQuickFilter(idx) {
            this.ensureUiConfig();
            this.blueprint.ui.directory.quick_filters.splice(idx, 1);
            this.syncUiToBlueprintAndYaml();
        },

        moveQuickFilter(idx, dir) {
            this.ensureUiConfig();
            const list = this.blueprint.ui.directory.quick_filters;
            const target = idx + dir;
            if (target < 0 || target >= list.length) return;
            const temp = list[idx];
            list[idx] = list[target];
            list[target] = temp;
            this.syncUiToBlueprintAndYaml();
        },

        moveSidebarFilter(idx, dir) {
            this.ensureUiConfig();
            const list = this.blueprint.ui.directory.sidebar_filters;
            const target = idx + dir;
            if (target < 0 || target >= list.length) return;
            const temp = list[idx];
            list[idx] = list[target];
            list[target] = temp;
            this.syncUiToBlueprintAndYaml();
        },

        addSidebarFilter(handle) {
            this.ensureUiConfig();
            const rootModel = (this.blueprint.models && this.blueprint.models[0]) || { fields: [] };
            const f = (rootModel.fields || []).find(x => x.handle === handle);
            this.blueprint.ui.directory.sidebar_filters.push({
                field: handle,
                label: f?.label || handle,
                type: f && ['date', 'datetime', 'timestamp'].includes(f.type) ? 'date_range' : 'select',
                searchable: true
            });
            this.syncUiToBlueprintAndYaml();
        },

        removeSidebarFilter(idx) {
            this.ensureUiConfig();
            this.blueprint.ui.directory.sidebar_filters.splice(idx, 1);
            this.syncUiToBlueprintAndYaml();
        },

        addFormSection() {
            this.ensureUiConfig();
            const num = (this.blueprint.ui.forms.sections.length || 0) + 1;
            this.blueprint.ui.forms.sections.push({
                title: 'Section ' + num + ' Details',
                description: 'Additional custom configuration parameters',
                icon: 'sliders',
                columns: 3,
                fields: []
            });
            this.syncUiToBlueprintAndYaml();
        },

        removeFormSection(secIdx) {
            this.ensureUiConfig();
            this.blueprint.ui.forms.sections.splice(secIdx, 1);
            this.syncUiToBlueprintAndYaml();
        },

        moveFormSection(secIdx, dir) {
            this.ensureUiConfig();
            const secs = this.blueprint.ui.forms.sections;
            const target = secIdx + dir;
            if (target < 0 || target >= secs.length) return;
            const temp = secs[secIdx];
            secs[secIdx] = secs[target];
            secs[target] = temp;
            this.syncUiToBlueprintAndYaml();
        },

        addFieldToSection(secIdx, handle) {
            this.ensureUiConfig();
            const rootModel = (this.blueprint.models && this.blueprint.models[0]) || { fields: [] };
            const f = (rootModel.fields || []).find(x => x.handle === handle);
            const wideKeywords = ['address', 'description', 'notes', 'memo', 'summary', 'about', 'bio', 'instructions', 'content', 'body', 'remarks'];

            this.blueprint.ui.forms.sections[secIdx].fields.push({
                handle: handle,
                label: f?.label || handle,
                span: wideKeywords.some(k => handle.includes(k)) ? 3 : 1,
                searchable: handle.endsWith('_id') || f?.type === 'enum',
                required: !!f?.required
            });
            this.syncUiToBlueprintAndYaml();
        },

        removeFieldFromSection(secIdx, fldIdx) {
            this.ensureUiConfig();
            this.blueprint.ui.forms.sections[secIdx].fields.splice(fldIdx, 1);
            this.syncUiToBlueprintAndYaml();
        },

        moveFieldInSection(secIdx, fldIdx, dir) {
            this.ensureUiConfig();
            const fields = this.blueprint.ui.forms.sections[secIdx].fields;
            const target = fldIdx + dir;
            if (target < 0 || target >= fields.length) return;
            const temp = fields[fIdx];
            fields[fldIdx] = fields[target];
            fields[target] = temp;
            this.syncUiToBlueprintAndYaml();
        },


        addBulkAction() {
            this.ensureUiConfig();
            if (!Array.isArray(this.blueprint.ui.directory.bulk_actions)) {
                this.blueprint.ui.directory.bulk_actions = [];
            }
            this.blueprint.ui.directory.bulk_actions.push({
                handle: 'custom_' + Date.now(),
                label: 'Custom action',
                type: 'native',
                icon: 'zap',
            });
            this.syncUiToBlueprintAndYaml();
        },
        addBulkActionPreset(kind) {
            this.ensureUiConfig();
            const list = this.blueprint.ui.directory.bulk_actions || [];
            if (kind === 'standard') {
                const extras = [
                    { handle: 'export', label: 'Export Selected', type: 'native', icon: 'download' },
                    { handle: 'delete', label: 'Delete Selected', type: 'native', icon: 'trash-2', danger: true },
                ];
                extras.forEach(e => { if (!list.find(x => x.handle === e.handle)) list.push(e); });
            } else if (kind === 'workflow') {
                const extras = [
                    { handle: 'submit', label: 'Submit', type: 'workflow', transition: 'submit', icon: 'send' },
                    { handle: 'approve', label: 'Approve', type: 'workflow', transition: 'approve', icon: 'check', requires_remarks: false },
                    { handle: 'reject', label: 'Reject', type: 'workflow', transition: 'reject', icon: 'x', requires_remarks: true, danger: true },
                ];
                extras.forEach(e => { if (!list.find(x => x.handle === e.handle)) list.push(e); });
            }
            this.blueprint.ui.directory.bulk_actions = list;
            this.syncUiToBlueprintAndYaml();
        },
        setRowActionsPreset(preset) {
            this.ensureUiConfig();
            const ra = this.blueprint.ui.directory.row_actions;
            if (preset === 'standard_crud') {
                ra.display = 'dropdown';
                ra.items = [
                    { handle: 'view', label: 'View Details', icon: 'eye', placement: 'dropdown', type: 'drawer' },
                    { handle: 'edit', label: 'Edit Record', icon: 'pencil', placement: 'dropdown', type: 'drawer' },
                    { handle: 'delete', label: 'Delete Record', icon: 'trash-2', placement: 'dropdown', type: 'delete', danger: true }
                ];
            } else if (preset === 'hybrid_view_outside') {
                ra.display = 'hybrid';
                ra.items = [
                    { handle: 'view', label: 'View', icon: 'eye', placement: 'inline', type: 'drawer' },
                    { handle: 'edit', label: 'Edit', icon: 'pencil', placement: 'dropdown', type: 'drawer' },
                    { handle: 'delete', label: 'Delete', icon: 'trash-2', placement: 'dropdown', type: 'delete', danger: true }
                ];
            } else if (preset === 'task_suite') {
                ra.display = 'hybrid';
                ra.items = [
                    { handle: 'timer', label: 'Timer', icon: 'play', placement: 'inline', type: 'event' },
                    { handle: 'view', label: 'View', icon: 'eye', placement: 'dropdown', type: 'drawer' },
                    { handle: 'edit', label: 'Edit', icon: 'pencil', placement: 'dropdown', type: 'drawer' },
                    { handle: 'duplicate', label: 'Duplicate', icon: 'copy', placement: 'dropdown', type: 'route' },
                    { handle: 'delete', label: 'Delete', icon: 'trash-2', placement: 'dropdown', type: 'delete', danger: true }
                ];
            } else if (preset === 'invoice_suite') {
                ra.display = 'dropdown';
                ra.items = [
                    { handle: 'view', label: 'View Details', icon: 'eye', placement: 'dropdown', type: 'drawer' },
                    { handle: 'download_pdf', label: 'Download PDF', icon: 'download', placement: 'dropdown', type: 'route' },
                    { handle: 'send', label: 'Send Email', icon: 'send', placement: 'dropdown', type: 'event' },
                    { handle: 'edit', label: 'Edit Invoice', icon: 'pencil', placement: 'dropdown', type: 'drawer' },
                    { handle: 'add_payment', label: 'Add Payment', icon: 'credit-card', placement: 'dropdown', type: 'drawer' },
                    { handle: 'delete', label: 'Delete', icon: 'trash-2', placement: 'dropdown', type: 'delete', danger: true }
                ];
            } else if (preset === 'inline_compact') {
                ra.display = 'inline';
                ra.items = [
                    { handle: 'view', label: 'View', icon: 'eye', placement: 'inline', type: 'drawer' },
                    { handle: 'edit', label: 'Edit', icon: 'pencil', placement: 'inline', type: 'drawer' },
                    { handle: 'delete', label: 'Delete', icon: 'trash-2', placement: 'inline', type: 'delete', danger: true }
                ];
            }
            this.syncUiToBlueprintAndYaml();
        },

        addRowAction() {
            this.ensureUiConfig();
            const count = (this.blueprint.ui.directory.row_actions.items || []).length + 1;
            this.blueprint.ui.directory.row_actions.items.push({
                handle: `custom_action_${count}`,
                label: `Action ${count}`,
                icon: 'external-link',
                placement: 'dropdown',
                type: 'route',
                danger: false
            });
            this.syncUiToBlueprintAndYaml();
        },

        removeRowAction(idx) {
            this.ensureUiConfig();
            this.blueprint.ui.directory.row_actions.items.splice(idx, 1);
            this.syncUiToBlueprintAndYaml();
        },

        moveRowAction(idx, dir) {
            this.ensureUiConfig();
            const items = this.blueprint.ui.directory.row_actions.items;
            const target = idx + dir;
            if (target < 0 || target >= items.length) return;
            const temp = items[idx];
            items[idx] = items[target];
            items[target] = temp;
            this.syncUiToBlueprintAndYaml();
        },

        quickAddField(modelIndex) {
            const m = this.blueprint.models[modelIndex];
            if (!m) return;
            const fCount = m.fields.length + 1;
            m.fields.push({
                handle: `field_${fCount}`,
                label: `Field ${fCount}`,
                type: 'string',
                required: false,
                nullable: true,
                width: 50,
                length: 255,
                default: ''
            });
            this.syncToYaml();
        },

        removeField(modelIndex, fieldIndex) {
            const m = this.blueprint.models[modelIndex];
            if (!m) return;
            m.fields.splice(fieldIndex, 1);
            this.syncToYaml();
        },

        async introspectTable() {
            if (!this.selectedTable) return;
            this.introspecting = true;

            try {
                const res = await fetch(introspectUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ table: this.selectedTable })
                });

                const data = await res.json();
                if (data.fields) {
                    const modelHandle = this.selectedTable.replace(/s$/, '').toLowerCase();
                    const newModel = {
                        handle: modelHandle,
                        table: this.selectedTable,
                        root: this.blueprint.models.length === 0,
                        timestamps: true,
                        soft_deletes: false,
                        fields: data.fields,
                        relations: []
                    };
                    this.blueprint.models.push(newModel);
                    if (!this.blueprint.name) {
                        this.blueprint.name = data.model + ' Module';
                        this.blueprint.handle = modelHandle + '_module';
                    }
                    this.syncToYaml();
                    this.showIntrospectModal = false;
                    this.selectedTable = '';
                } else if (data.error) {
                    alert('Introspection error: ' + data.error);
                }
            } catch (err) {
                alert('Failed to introspect table: ' + err.message);
            } finally {
                this.introspecting = false;
            }
        }
});

if (window.Alpine) {
    window.Alpine.data('blueprintStudio', window.blueprintStudio);
} else {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('blueprintStudio', window.blueprintStudio);
    });
}

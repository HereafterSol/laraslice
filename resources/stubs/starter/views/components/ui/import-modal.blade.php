@props([
    'title' => 'Import Records',
    'sliceName' => 'Records',
    'sampleColumns' => ['ID', 'Name', 'Email', 'Status'],
    'sampleRows' => [
        ['EMP-101', 'John Doe', 'john.doe@example.com', 'Active'],
        ['EMP-102', 'Jane Smith', 'jane.smith@example.com', 'Active'],
    ],
])

<div 
    x-data="blatImportModal({
        sliceName: @js($sliceName),
        sampleHeaders: @js($sampleColumns),
        sampleData: @js($sampleRows)
    })"
    @open-import-modal.window="open = true"
>
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
        <div 
            @click.outside="open = false"
            x-show="open"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="w-full max-w-lg rounded-2xl bg-card border border-border shadow-2xl overflow-hidden text-xs"
        >
            <!-- Header -->
            <div class="px-5 py-4 border-b border-border flex items-center justify-between bg-muted/20">
                <div class="flex items-center gap-2">
                    <x-lucide-upload class="size-4 text-primary" />
                    <h3 class="text-sm font-bold text-foreground">{{ $title }}</h3>
                </div>
                <button type="button" @click="open = false" class="size-7 rounded-lg hover:bg-muted flex items-center justify-center text-muted-foreground hover:text-foreground cursor-pointer">
                    <x-lucide-x class="size-4" />
                </button>
            </div>

            <!-- Content -->
            <div class="p-5 space-y-4">
                <p class="text-muted-foreground leading-relaxed">
                    Upload a CSV file containing {{ strtolower($sliceName) }} information. Make sure your CSV headers match the required structure.
                </p>

                <!-- Download Sample Template Box -->
                <div class="p-3.5 rounded-xl border border-primary/20 bg-primary/5 flex items-center justify-between">
                    <div>
                        <span class="font-bold text-foreground block">Need a template?</span>
                        <span class="text-muted-foreground text-[11px] block">Download our pre-formatted sample CSV file.</span>
                    </div>
                    <button 
                        type="button" 
                        @click="downloadSample()"
                        class="px-3 py-1.5 rounded-lg border border-primary/30 text-primary hover:bg-primary/10 text-xs font-semibold flex items-center gap-1.5 transition-colors cursor-pointer"
                    >
                        <x-lucide-download class="size-3.5" />
                        <span>Download Sample</span>
                    </button>
                </div>

                <!-- Dropzone -->
                <div class="relative border-2 border-dashed border-border rounded-xl p-6 text-center hover:border-primary/50 transition-colors bg-muted/10">
                    <input 
                        type="file" 
                        accept=".csv" 
                        @change="handleFile($event)"
                        class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                    />
                    <div class="flex flex-col items-center justify-center gap-2">
                        <x-lucide-file-spreadsheet class="size-8 text-muted-foreground/60" />
                        <div>
                            <span class="font-semibold text-foreground block" x-text="fileName || 'Click or drag CSV file to upload'"></span>
                            <span class="text-muted-foreground text-[11px] block mt-0.5">Supports CSV files up to 10MB</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="px-5 py-3 border-t border-border flex items-center justify-end gap-2 bg-muted/20">
                <button type="button" class="px-3 py-1.5 rounded-xl border border-border bg-card text-foreground hover:bg-muted text-xs font-medium cursor-pointer" @click="open = false" :disabled="uploading">
                    Cancel
                </button>
                <button type="button" class="px-3.5 py-1.5 rounded-xl bg-primary text-primary-foreground font-semibold text-xs shadow-xs hover:opacity-90 flex items-center gap-1.5 cursor-pointer disabled:opacity-50" @click="processImport()" :disabled="uploading || !fileName">
                    <x-lucide-upload class="size-3.5" x-show="!uploading" />
                    <x-lucide-loader-2 class="size-3.5 animate-spin" x-show="uploading" x-cloak />
                    <span x-text="uploading ? 'Importing...' : 'Upload & Import'"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
if (typeof window.blatImportModal === 'undefined') {
    window.blatImportModal = function(cfg) {
        return {
            open: false,
            fileName: null,
            uploading: false,
            sliceName: cfg.sliceName || 'Records',
            sampleHeaders: cfg.sampleHeaders || [],
            sampleData: cfg.sampleData || [],
            downloadSample() {
                const headerLine = this.sampleHeaders.map(h => '"' + String(h).replace(/"/g, '""') + '"').join(',');
                const rows = [headerLine];
                for (const r of this.sampleData) {
                    rows.push(r.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(','));
                }
                const blob = new Blob(['\uFEFF' + rows.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'sample_' + this.sliceName.toLowerCase() + '_template.csv';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            },
            handleFile(e) {
                const file = e.target.files[0];
                if (file) {
                    this.fileName = file.name;
                }
            },
            processImport() {
                if (!this.fileName) {
                    alert('Please select a CSV file first.');
                    return;
                }
                this.uploading = true;
                setTimeout(() => {
                    this.uploading = false;
                    this.open = false;
                    this.fileName = null;
                    alert(this.sliceName + ' data imported successfully!');
                    window.location.reload();
                }, 1200);
            }
        };
    };
}
</script>

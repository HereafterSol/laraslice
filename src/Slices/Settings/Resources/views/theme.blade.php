@extends('layouts.app')

@section('content')
<div class="space-y-6" x-data="{
    copied: false,
    updateRadius(val) {
        $store.theme.setRadius(val);
        document.documentElement.setAttribute('data-radius', val);
        document.documentElement.style.setProperty('--radius', val === '0' ? '0rem' : val + 'rem');
    },
    updateInputStyle(style) {
        $store.theme.setInputStyle(style);
        document.documentElement.setAttribute('data-input-style', style);
    },
    updateFont(font) {
        $store.theme.setFont(font);
        document.documentElement.setAttribute('data-font', font);
    },
    updateShadow(shadow) {
        $store.theme.setShadow(shadow);
        document.documentElement.setAttribute('data-shadow', shadow);
    },
    updateSoul(soul) {
        $store.theme.setSoul(soul);
        if (soul === 'default') document.documentElement.removeAttribute('data-soul');
        else document.documentElement.setAttribute('data-soul', soul);
    },
    copyCss() {
        const rad = $store.theme.radius === '0' ? '0rem' : `${$store.theme.radius}rem`;
        const css = `:root {
    --radius: ${rad};
}
/* Base: ${$store.theme.base} | Theme: ${$store.theme.preset} | Mode: ${$store.theme.mode} | Font: ${$store.theme.font} | Style: ${$store.theme.inputStyle} */`;
        navigator.clipboard.writeText(css).then(() => {
            this.copied = true;
            setTimeout(() => this.copied = false, 2000);
        });
    }
}">
    <!-- Header Banner -->
    <div class="flex flex-wrap items-center justify-between gap-4 pb-4 border-b border-border">
        <div>
            <div class="flex items-center gap-2 text-xs text-muted-foreground mb-1">
                <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/') }}" class="hover:text-primary transition-colors">Dashboard</a>
                <span>/</span>
                <span>Settings</span>
                <span>/</span>
                <span class="text-foreground font-semibold">Theme Studio</span>
            </div>
            <h1 class="text-2xl font-black text-foreground tracking-tight flex items-center gap-2.5">
                <span>🎨</span>
                <span>Theme Editor & Design Tokens</span>
            </h1>
            <p class="text-xs text-muted-foreground mt-0.5">
                Pure CSS variables powered by BlatUI & Tailwind v4. Live preview of colors, radius, input style, shadows, and typography.
            </p>
        </div>

        <div class="flex items-center gap-2.5">
            <button type="button" @click="$store.theme.randomize()" class="px-3.5 py-2 text-xs font-semibold rounded-xl border border-border bg-card hover:bg-muted text-foreground transition shadow-xs flex items-center gap-1.5 cursor-pointer">
                <span>🎲</span>
                <span>Random</span>
            </button>
            <button type="button" @click="$store.theme.reset()" class="px-3.5 py-2 text-xs font-semibold rounded-xl border border-border bg-card hover:bg-muted text-foreground transition shadow-xs flex items-center gap-1.5 cursor-pointer">
                <span>↺</span>
                <span>Reset</span>
            </button>
            <button type="button" @click="copyCss()" class="px-4 py-2 text-xs font-bold rounded-xl bg-primary text-primary-foreground hover:opacity-90 transition shadow-md flex items-center gap-1.5 cursor-pointer">
                <span x-show="!copied">📋 Copy CSS</span>
                <span x-show="copied">✓ Copied!</span>
            </button>
        </div>
    </div>

    <!-- Main Grid: Controls on Left, Live Component Preview on Right -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        
        <!-- Left: Customizer Controls Panel -->
        <div class="lg:col-span-4 space-y-6 bg-card border border-border rounded-2xl p-6 shadow-sm">
            
            <!-- 1. Mode (Light / Dark / System) -->
            <div class="space-y-2">
                <label class="block text-xs font-bold text-foreground uppercase tracking-wider">Mode</label>
                <div class="grid grid-cols-3 gap-2 bg-muted/40 p-1 rounded-xl border border-border">
                    <button type="button" @click="$store.theme.setMode('light')"
                            :class="$store.theme.mode === 'light' ? 'bg-card text-foreground font-bold shadow-xs border border-border' : 'text-muted-foreground hover:text-foreground'"
                            class="py-2 text-xs rounded-lg transition flex items-center justify-center gap-1.5 cursor-pointer">
                        <span>☀️</span>
                        <span>Light</span>
                    </button>
                    <button type="button" @click="$store.theme.setMode('dark')"
                            :class="$store.theme.mode === 'dark' ? 'bg-card text-foreground font-bold shadow-xs border border-border' : 'text-muted-foreground hover:text-foreground'"
                            class="py-2 text-xs rounded-lg transition flex items-center justify-center gap-1.5 cursor-pointer">
                        <span>🌙</span>
                        <span>Dark</span>
                    </button>
                    <button type="button" @click="$store.theme.setMode('system')"
                            :class="$store.theme.mode === 'system' ? 'bg-card text-foreground font-bold shadow-xs border border-border' : 'text-muted-foreground hover:text-foreground'"
                            class="py-2 text-xs rounded-lg transition flex items-center justify-center gap-1.5 cursor-pointer">
                        <span>💻</span>
                        <span>System</span>
                    </button>
                </div>
            </div>

            <!-- 2. Base Color Palette (Grayscale family) -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-bold text-foreground uppercase tracking-wider">Base Color</label>
                    <span class="text-[11px] font-mono text-muted-foreground capitalize" x-text="$store.theme.base"></span>
                </div>
                <div class="flex items-center gap-2.5 flex-wrap">
                    <template x-for="b in [
                        { id: 'slate', name: 'Slate', color: '#64748b' },
                        { id: 'gray', name: 'Gray', color: '#6b7280' },
                        { id: 'zinc', name: 'Zinc', color: '#71717a' },
                        { id: 'neutral', name: 'Neutral', color: '#737373' },
                        { id: 'stone', name: 'Stone', color: '#78716c' }
                    ]" :key="b.id">
                        <button type="button" @click="$store.theme.setBase(b.id)"
                                :title="b.name"
                                :class="$store.theme.base === b.id ? 'ring-2 ring-primary ring-offset-2 ring-offset-background scale-110' : 'hover:scale-105 opacity-80 hover:opacity-100'"
                                class="size-8 rounded-full border border-black/10 transition-all flex items-center justify-center cursor-pointer shadow-xs"
                                :style="'background-color: ' + b.color">
                            <span x-show="$store.theme.base === b.id" class="text-white text-xs font-bold">✓</span>
                        </button>
                    </template>
                </div>
            </div>

            <!-- 3. Accent Color (Primary brand color) -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-bold text-foreground uppercase tracking-wider">Accent / Brand Color</label>
                    <span class="text-[11px] font-mono text-primary font-bold capitalize" x-text="$store.theme.preset === 'default' ? 'neutral' : $store.theme.preset"></span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <template x-for="a in [
                        { id: 'indigo', name: 'Indigo', color: '#6366f1' },
                        { id: 'blue', name: 'Blue', color: '#3b82f6' },
                        { id: 'sky', name: 'Sky', color: '#0ea5e9' },
                        { id: 'cyan', name: 'Cyan', color: '#06b6d4' },
                        { id: 'teal', name: 'Teal', color: '#14b8a6' },
                        { id: 'emerald', name: 'Emerald', color: '#10b981' },
                        { id: 'green', name: 'Green', color: '#22c55e' },
                        { id: 'amber', name: 'Amber', color: '#f59e0b' },
                        { id: 'orange', name: 'Orange', color: '#f97316' },
                        { id: 'red', name: 'Red', color: '#ef4444' },
                        { id: 'rose', name: 'Rose', color: '#f43f5e' },
                        { id: 'pink', name: 'Pink', color: '#ec4899' },
                        { id: 'purple', name: 'Purple', color: '#a855f7' },
                        { id: 'violet', name: 'Violet', color: '#8b5cf6' },
                        { id: 'default', name: 'Neutral', color: '#262626' }
                    ]" :key="a.id">
                        <button type="button" @click="$store.theme.setPreset(a.id)"
                                :title="a.name"
                                :class="$store.theme.preset === a.id ? 'ring-2 ring-primary ring-offset-2 ring-offset-background scale-110 shadow-md' : 'hover:scale-105 opacity-85 hover:opacity-100'"
                                class="size-8 rounded-full border border-black/10 transition-all flex items-center justify-center cursor-pointer shadow-2xs shrink-0"
                                :style="'background-color: ' + a.color">
                            <span x-show="$store.theme.preset === a.id" class="text-white text-xs font-bold drop-shadow">✓</span>
                        </button>
                    </template>
                </div>
            </div>

            <!-- 4. Corner Radius -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-bold text-foreground uppercase tracking-wider">Border Radius</label>
                    <span class="text-[11px] font-mono font-bold text-primary" x-text="($store.theme.radius || '0.625') + 'rem'"></span>
                </div>
                <div class="grid grid-cols-6 gap-1.5 bg-muted/40 p-1 rounded-xl border border-border text-xs font-medium">
                    <template x-for="r in [
                        { id: '0', label: '0' },
                        { id: '0.3', label: '0.3' },
                        { id: '0.5', label: '0.5' },
                        { id: '0.625', label: '0.625' },
                        { id: '0.75', label: '0.75' },
                        { id: '1', label: '1' }
                    ]" :key="r.id">
                        <button type="button" @click="updateRadius(r.id)"
                                :class="String($store.theme.radius) === String(r.id) ? 'bg-primary text-primary-foreground font-bold shadow-xs border border-primary' : 'text-muted-foreground hover:text-foreground hover:bg-card'"
                                class="py-1.5 rounded-lg transition text-center cursor-pointer"
                                x-text="r.label">
                        </button>
                    </template>
                </div>
            </div>

            <!-- 5. Input Style -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-bold text-foreground uppercase tracking-wider">Input Style</label>
                    <span class="text-[11px] font-mono text-muted-foreground capitalize" x-text="$store.theme.inputStyle"></span>
                </div>
                <div class="grid grid-cols-3 gap-2 bg-muted/40 p-1 rounded-xl border border-border text-xs font-medium">
                    <button type="button" @click="updateInputStyle('outline')"
                            :class="$store.theme.inputStyle === 'outline' ? 'bg-primary text-primary-foreground font-bold shadow-xs border border-primary' : 'text-muted-foreground hover:text-foreground hover:bg-card'"
                            class="py-1.5 rounded-lg transition text-center cursor-pointer">
                        Outline
                    </button>
                    <button type="button" @click="updateInputStyle('fill')"
                            :class="$store.theme.inputStyle === 'fill' ? 'bg-primary text-primary-foreground font-bold shadow-xs border border-primary' : 'text-muted-foreground hover:text-foreground hover:bg-card'"
                            class="py-1.5 rounded-lg transition text-center cursor-pointer">
                        Fill
                    </button>
                    <button type="button" @click="updateInputStyle('inset')"
                            :class="$store.theme.inputStyle === 'inset' ? 'bg-primary text-primary-foreground font-bold shadow-xs border border-primary' : 'text-muted-foreground hover:text-foreground hover:bg-card'"
                            class="py-1.5 rounded-lg transition text-center cursor-pointer">
                        Inset
                    </button>
                </div>
            </div>

            <!-- 6. Body Font -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-bold text-foreground uppercase tracking-wider">Body Typography</label>
                    <span class="text-[11px] font-mono text-muted-foreground capitalize" x-text="$store.theme.font"></span>
                </div>
                <div class="grid grid-cols-3 gap-2 bg-muted/40 p-1 rounded-xl border border-border text-xs font-medium">
                    <button type="button" @click="updateFont('sans')"
                            :class="$store.theme.font === 'sans' ? 'bg-primary text-primary-foreground font-bold shadow-xs border border-primary' : 'text-muted-foreground hover:text-foreground hover:bg-card'"
                            class="py-1.5 rounded-lg transition text-center cursor-pointer font-sans">
                        Default
                    </button>
                    <button type="button" @click="updateFont('inter')"
                            :class="$store.theme.font === 'inter' ? 'bg-primary text-primary-foreground font-bold shadow-xs border border-primary' : 'text-muted-foreground hover:text-foreground hover:bg-card'"
                            class="py-1.5 rounded-lg transition text-center cursor-pointer font-sans">
                        Inter
                    </button>
                    <button type="button" @click="updateFont('geist')"
                            :class="$store.theme.font === 'geist' ? 'bg-primary text-primary-foreground font-bold shadow-xs border border-primary' : 'text-muted-foreground hover:text-foreground hover:bg-card'"
                            class="py-1.5 rounded-lg transition text-center cursor-pointer font-mono">
                        Geist
                    </button>
                </div>
            </div>

            <!-- 7. Shadow Intensity -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-bold text-foreground uppercase tracking-wider">Shadow Scale</label>
                    <span class="text-[11px] font-mono text-muted-foreground capitalize" x-text="$store.theme.shadow"></span>
                </div>
                <div class="grid grid-cols-4 gap-1.5 bg-muted/40 p-1 rounded-xl border border-border text-xs font-medium">
                    <button type="button" @click="updateShadow('none')"
                            :class="$store.theme.shadow === 'none' ? 'bg-primary text-primary-foreground font-bold shadow-xs border border-primary' : 'text-muted-foreground hover:text-foreground hover:bg-card'"
                            class="py-1.5 rounded-lg transition text-center cursor-pointer">
                        None
                    </button>
                    <button type="button" @click="updateShadow('sm')"
                            :class="$store.theme.shadow === 'sm' ? 'bg-primary text-primary-foreground font-bold shadow-xs border border-primary' : 'text-muted-foreground hover:text-foreground hover:bg-card'"
                            class="py-1.5 rounded-lg transition text-center cursor-pointer">
                        Subtle
                    </button>
                    <button type="button" @click="updateShadow('default')"
                            :class="$store.theme.shadow === 'default' ? 'bg-primary text-primary-foreground font-bold shadow-xs border border-primary' : 'text-muted-foreground hover:text-foreground hover:bg-card'"
                            class="py-1.5 rounded-lg transition text-center cursor-pointer">
                        Base
                    </button>
                    <button type="button" @click="updateShadow('lg')"
                            :class="$store.theme.shadow === 'lg' ? 'bg-primary text-primary-foreground font-bold shadow-xs border border-primary' : 'text-muted-foreground hover:text-foreground hover:bg-card'"
                            class="py-1.5 rounded-lg transition text-center cursor-pointer">
                        Lg
                    </button>
                </div>
            </div>

            <!-- 8. Soul (composed preset) -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-bold text-foreground uppercase tracking-wider">Soul</label>
                    <span class="text-[11px] font-mono text-muted-foreground capitalize" x-text="$store.theme.soul"></span>
                </div>
                <div class="grid grid-cols-2 gap-1.5 bg-muted/40 p-1 rounded-xl border border-border text-xs font-medium">
                    <button type="button" @click="updateSoul('default')"
                            :class="$store.theme.soul === 'default' ? 'bg-primary text-primary-foreground font-bold shadow-xs border border-primary' : 'text-muted-foreground hover:text-foreground hover:bg-card'"
                            class="py-1.5 rounded-lg transition text-center cursor-pointer">
                        Default
                    </button>
                    <button type="button" @click="updateSoul('enterprise')"
                            :class="$store.theme.soul === 'enterprise' ? 'bg-primary text-primary-foreground font-bold shadow-xs border border-primary' : 'text-muted-foreground hover:text-foreground hover:bg-card'"
                            class="py-1.5 rounded-lg transition text-center cursor-pointer">
                        Enterprise
                    </button>
                </div>
                <p class="text-[11px] text-muted-foreground">A composed preset: Enterprise tightens the radius, flattens shadows, mutes the chart ramp, and sets tabular figures on tables — one switch, every surface.</p>
            </div>

        </div>

        <!-- Right: Live Interactive Component Preview -->
        <div class="lg:col-span-8 space-y-6"
             :style="{ fontFamily: $store.theme.font === 'geist' ? 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace' : ($store.theme.font === 'inter' ? '\'Inter\', -apple-system, BlinkMacSystemFont, sans-serif' : 'inherit') }">
            
            <!-- Section 1: Buttons Preview -->
            <div class="bg-card border border-border p-6 transition-all duration-200 space-y-4"
                 :class="{
                     'shadow-none': $store.theme.shadow === 'none',
                     'shadow-xs': $store.theme.shadow === 'sm',
                     'shadow-sm': $store.theme.shadow === 'default',
                     'shadow-xl': $store.theme.shadow === 'lg'
                 }"
                 :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : 'calc(' + ($store.theme.radius || '0.625') + 'rem + 4px)') }">
                <div class="flex items-center justify-between">
                    <div class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Buttons & Interactive Elements</div>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-muted text-muted-foreground" x-text="'radius: ' + ($store.theme.radius || '0.625') + 'rem'"></span>
                </div>
                
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button"
                            :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                            class="px-4 py-2 bg-primary text-primary-foreground font-semibold text-xs shadow-xs hover:opacity-90 transition">
                        Primary
                    </button>
                    <button type="button"
                            :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                            class="px-4 py-2 bg-secondary text-secondary-foreground font-semibold text-xs shadow-2xs hover:bg-secondary/80 transition">
                        Secondary
                    </button>
                    <button type="button"
                            :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                            class="px-4 py-2 border border-border bg-card text-foreground font-semibold text-xs shadow-2xs hover:bg-muted transition">
                        Outline
                    </button>
                    <button type="button"
                            :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                            class="px-4 py-2 text-foreground font-semibold text-xs hover:bg-muted transition">
                        Ghost
                    </button>
                    <button type="button"
                            :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                            class="px-4 py-2 bg-destructive text-destructive-foreground font-semibold text-xs shadow-xs hover:opacity-90 transition">
                        Destructive
                    </button>
                    <button type="button" class="px-2 py-2 text-primary font-semibold text-xs hover:underline transition">
                        Link Button
                    </button>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="button"
                            :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                            class="px-3 py-1 bg-primary text-primary-foreground text-xs font-semibold">Small</button>
                    <button type="button"
                            :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                            class="px-4 py-2 bg-primary text-primary-foreground text-xs font-semibold">Default</button>
                    <button type="button"
                            :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                            class="px-5 py-2.5 bg-primary text-primary-foreground text-sm font-semibold">Large</button>
                    <button type="button"
                            :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                            class="size-8 bg-primary text-primary-foreground flex items-center justify-center text-xs">🚀</button>
                </div>
            </div>

            <!-- Section 2: Badges Preview -->
            <div class="bg-card border border-border p-6 transition-all duration-200 space-y-4"
                 :class="{
                     'shadow-none': $store.theme.shadow === 'none',
                     'shadow-xs': $store.theme.shadow === 'sm',
                     'shadow-sm': $store.theme.shadow === 'default',
                     'shadow-xl': $store.theme.shadow === 'lg'
                 }"
                 :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : 'calc(' + ($store.theme.radius || '0.625') + 'rem + 4px)') }">
                <div class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Badges (Pills vs Shaped)</div>
                <div class="flex flex-wrap items-center gap-3">
                    <span :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                          class="px-3 py-1 text-xs font-semibold bg-primary text-primary-foreground">Default</span>
                    <span :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                          class="px-3 py-1 text-xs font-semibold bg-secondary text-secondary-foreground">Secondary</span>
                    <span :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                          class="px-3 py-1 text-xs font-semibold bg-destructive text-destructive-foreground">Destructive</span>
                    <span :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                          class="px-3 py-1 text-xs font-semibold border border-border text-foreground">Outline</span>
                    <span :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                          class="px-3 py-1 text-xs font-semibold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30">Success</span>
                    <span :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                          class="px-3 py-1 text-xs font-semibold bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/30">Warning</span>
                </div>
            </div>

            <!-- Section 3: Forms & Card Preview -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Form Inputs Preview -->
                <div class="bg-card border border-border p-6 transition-all duration-200 space-y-4"
                     :class="{
                         'shadow-none': $store.theme.shadow === 'none',
                         'shadow-xs': $store.theme.shadow === 'sm',
                         'shadow-sm': $store.theme.shadow === 'default',
                         'shadow-xl': $store.theme.shadow === 'lg'
                     }"
                     :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : 'calc(' + ($store.theme.radius || '0.625') + 'rem + 4px)') }">
                    <div class="flex items-center justify-between">
                        <div class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Form Controls</div>
                        <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-muted text-muted-foreground" x-text="'style: ' + $store.theme.inputStyle"></span>
                    </div>
                    
                    <div class="space-y-3.5 text-xs">
                        <div>
                            <label class="block font-semibold text-foreground mb-1">Email Address</label>
                            <input type="email"
                                   data-slot="input"
                                   placeholder="you@example.com"
                                   :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                                   :class="{
                                       'bg-background border border-border shadow-2xs': $store.theme.inputStyle === 'outline',
                                       'bg-muted border border-transparent shadow-none': $store.theme.inputStyle === 'fill',
                                       'bg-muted/50 border border-border shadow-inner': $store.theme.inputStyle === 'inset'
                                   }"
                                   class="w-full px-3 py-2 text-foreground focus:border-primary focus:outline-none transition" />
                        </div>
                        <div>
                            <label class="block font-semibold text-foreground mb-1">Framework</label>
                            <select data-slot="select-trigger"
                                    :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                                    :class="{
                                        'bg-background border border-border shadow-2xs': $store.theme.inputStyle === 'outline',
                                        'bg-muted border border-transparent shadow-none': $store.theme.inputStyle === 'fill',
                                        'bg-muted/50 border border-border shadow-inner': $store.theme.inputStyle === 'inset'
                                    }"
                                    class="w-full px-3 py-2 text-foreground focus:border-primary focus:outline-none transition">
                                <option>LaraSlice Enterprise</option>
                                <option>BlatUI Modern Blade</option>
                                <option>Tailwind CSS v4</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-2 pt-1">
                            <input type="checkbox" checked id="terms"
                                   :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : '0.25rem') }"
                                   class="size-4 text-primary focus:ring-primary cursor-pointer" />
                            <label for="terms" class="text-muted-foreground cursor-pointer select-none">Accept terms and conditions</label>
                        </div>
                    </div>
                </div>

                <!-- Interactive Card Preview -->
                <div class="bg-card border border-border p-6 transition-all duration-200 space-y-4 flex flex-col justify-between"
                     :class="{
                         'shadow-none': $store.theme.shadow === 'none',
                         'shadow-xs': $store.theme.shadow === 'sm',
                         'shadow-sm': $store.theme.shadow === 'default',
                         'shadow-xl': $store.theme.shadow === 'lg'
                     }"
                     :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : 'calc(' + ($store.theme.radius || '0.625') + 'rem + 4px)') }">
                    <div>
                        <div class="flex items-center justify-between pb-3 border-b border-border">
                            <div>
                                <h3 class="font-bold text-sm text-foreground">Create Project</h3>
                                <p class="text-xs text-muted-foreground">Deploy your new project in one click.</p>
                            </div>
                            <span :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                                  class="size-8 bg-primary/10 text-primary flex items-center justify-center font-bold text-xs">LS</span>
                        </div>
                        
                        <div class="space-y-3 pt-4 text-xs">
                            <div>
                                <label class="block font-semibold text-foreground mb-1">Project Name</label>
                                <input type="text"
                                       value="Acme Inc."
                                       :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                                       :class="{
                                           'bg-background border border-border shadow-2xs': $store.theme.inputStyle === 'outline',
                                           'bg-muted border border-transparent shadow-none': $store.theme.inputStyle === 'fill',
                                           'bg-muted/50 border border-border shadow-inner': $store.theme.inputStyle === 'inset'
                                       }"
                                       class="w-full px-3 py-2 text-foreground focus:border-primary focus:outline-none" />
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-4 border-t border-border">
                        <button type="button"
                                :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                                class="px-3.5 py-1.5 border border-border bg-card text-foreground text-xs font-semibold hover:bg-muted transition">
                            Cancel
                        </button>
                        <button type="button"
                                :style="{ borderRadius: ($store.theme.radius === '0' ? '0rem' : ($store.theme.radius || '0.625') + 'rem') }"
                                class="px-3.5 py-1.5 bg-primary text-primary-foreground text-xs font-bold shadow-xs hover:opacity-90 transition">
                            Deploy Slice
                        </button>
                    </div>
                </div>
            </div>

        </div>

    </div>
</div>
@endsection

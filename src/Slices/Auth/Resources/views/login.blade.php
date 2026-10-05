<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - LaraSlice Core</title>
    <script src="https://unpkg.com/@tailwindcss/browser@4"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex items-center justify-center p-6 relative overflow-hidden">
    <!-- Ambient Background Glow -->
    <div class="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-gradient-to-tr from-indigo-600/20 via-violet-600/10 to-transparent rounded-full blur-3xl pointer-events-none"></div>

    <div class="w-full max-w-md relative z-10" x-data="{ passkeyLoading: false, passkeyError: '' }">
        <!-- Brand Logo Header -->
        <div class="text-center mb-8">
            <div class="inline-flex w-14 h-14 rounded-2xl bg-gradient-to-tr from-indigo-600 to-violet-500 items-center justify-center shadow-xl shadow-indigo-500/25 mb-4">
                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
            </div>
            <h1 class="text-2xl font-black tracking-tight text-white">LaraSlice Enterprise</h1>
            <p class="text-slate-400 text-sm mt-1">Modular Vertical Slice Architecture for Laravel 13</p>
        </div>

        <!-- Login Card -->
        <div x-data="{ email: '{{ old('email', 'admin@laraslice.com') }}', password: 'password', fillDemo() { this.email = 'admin@laraslice.com'; this.password = 'password'; } }" class="bg-slate-900/70 border border-slate-800/80 rounded-2xl p-8 backdrop-blur-xl shadow-2xl">
            <!-- Demo Credentials Helper Badge -->
            <div class="mb-5 p-3 bg-indigo-500/10 border border-indigo-500/25 rounded-xl flex items-center justify-between text-xs">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">DEMO</span>
                    <span class="text-slate-300 font-medium">admin@laraslice.com <span class="text-slate-500">/</span> password</span>
                </div>
                <button type="button" @click="fillDemo()" class="px-2.5 py-1 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-[11px] font-semibold transition cursor-pointer shadow-xs shadow-indigo-600/30">
                    Auto Fill
                </button>
            </div>

            @if(session('success'))
            <div class="mb-5 p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs flex items-center gap-2">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                <span>{{ session('success') }}</span>
            </div>
            @endif

            @if(session('error'))
            <div class="mb-5 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs flex items-center gap-2">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <span>{{ session('error') }}</span>
            </div>
            @endif

            <div x-show="passkeyError" x-cloak class="mb-5 p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs flex items-start gap-2">
                <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <div class="space-y-1">
                    <span x-text="passkeyError"></span>
                    <template x-if="window.location.hostname === '127.0.0.1'">
                        <div class="pt-1">
                            <a :href="window.location.href.replace('127.0.0.1', 'localhost')" class="text-indigo-400 hover:underline font-semibold">
                                👉 Switch to http://localhost:7000 for Passkeys
                            </a>
                        </div>
                    </template>
                </div>
            </div>

            @if($errors->any())
            <div class="mb-5 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs">
                @foreach ($errors->all() as $error)
                    <div>• {{ $error }}</div>
                @endforeach
            </div>
            @endif

            <form action="/login" method="POST" class="space-y-5">
                <input type="hidden" name="latitude" id="geo_lat" value="">
                <input type="hidden" name="longitude" id="geo_lng" value="">
                @csrf
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Email Address</label>
                    <input type="email" id="login_email" name="email" x-model="email" required autofocus class="w-full bg-slate-950/80 border border-slate-800 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 placeholder-slate-600 transition" placeholder="admin@laraslice.com">
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400">Password</label>
                        <a href="#" class="text-xs text-indigo-400 hover:text-indigo-300 transition">Forgot?</a>
                    </div>
                    <input type="password" name="password" x-model="password" required class="w-full bg-slate-950/80 border border-slate-800 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 placeholder-slate-600 transition" placeholder="••••••••">
                </div>

                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2.5 cursor-pointer text-xs text-slate-400">
                        <input type="checkbox" name="remember" class="w-4 h-4 rounded bg-slate-950 border-slate-800 text-indigo-600 focus:ring-0">
                        <span>Remember session</span>
                    </label>
                </div>

                <button type="submit" class="w-full py-3 bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white font-semibold rounded-xl text-sm transition shadow-lg shadow-indigo-500/25 flex items-center justify-center gap-2 cursor-pointer">
                    <span>Sign In to Console</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                </button>
            </form>

            <!-- Divider -->
            <div class="relative my-5">
                <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-slate-800"></div></div>
                <div class="relative flex justify-center text-[10px] uppercase font-bold tracking-widest"><span class="bg-slate-900/90 px-3 text-slate-500 rounded-full">Or passwordless sign-in</span></div>
            </div>

            <!-- 1-Tap Biometric Passkey Sign-in Button (Banking & Modern FinTech Flow) -->
            <div>
                <button type="button" @click="loginWithPasskey()" :disabled="passkeyLoading" class="w-full py-3 bg-slate-950/80 hover:bg-slate-800/80 border border-slate-800 hover:border-indigo-500/50 text-white font-semibold rounded-xl text-xs transition shadow-md flex items-center justify-center gap-2.5 cursor-pointer disabled:opacity-50">
                    <svg class="w-4 h-4 text-indigo-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 004 11a7.96 7.96 0 002.502 5.86"></path></svg>
                    <span x-text="passkeyLoading ? 'Verifying with Windows Hello / Biometrics...' : 'Sign in with Passkey / Biometrics'"></span>
                </button>
                <p class="text-[11px] text-slate-500 text-center mt-2">1-tap instant sign in via Windows Hello, Touch ID, or Face ID</p>
            </div>
        </div>

        <div class="text-center mt-6 text-xs text-slate-500">
            Enterprise Slice Engine • Powered by Laravel 13 & BlatUI
        </div>
    </div>

    <script>
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function (pos) {
                var latEl = document.getElementById("geo_lat");
                var lngEl = document.getElementById("geo_lng");
                if (latEl) latEl.value = pos.coords.latitude;
                if (lngEl) lngEl.value = pos.coords.longitude;
            }, function () {}, { timeout: 5000 });
        }

        function bufferToBase64url(buffer) {
            let binary = '';
            const bytes = new Uint8Array(buffer);
            for (let i = 0; i < bytes.byteLength; i++) {
                binary += String.fromCharCode(bytes[i]);
            }
            return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        }

        function base64urlToBuffer(base64url) {
            let base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
            while (base64.length % 4) { base64 += '='; }
            const binary = window.atob(base64);
            const bytes = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }
            return bytes.buffer;
        }

        async function loginWithPasskey() {
            const rootEl = document.querySelector('[x-data*="passkeyLoading"]');
            const alpine = rootEl ? Alpine.$data(rootEl) : {};
            if (alpine.passkeyLoading !== undefined) alpine.passkeyLoading = true;
            if (alpine.passkeyError !== undefined) alpine.passkeyError = '';

            try {
                if (window.location.hostname === '127.0.0.1') {
                    throw new Error("WebAuthn / Passkeys require a valid domain name (e.g. http://localhost:7000 or an HTTPS .test domain) rather than an IP address (127.0.0.1).");
                }

                if (!window.isSecureContext) {
                    throw new Error("Passkeys (WebAuthn) require a secure context. Open this app over HTTPS (e.g. https://" + window.location.host + ") or via http://localhost.");
                }

                if (!window.PublicKeyCredential) {
                    throw new Error("Passkeys/WebAuthn are not supported on this browser.");
                }

                const emailVal = document.getElementById("login_email")?.value || '';
                const url = new URL("{{ route('login.passkey.options') }}", window.location.origin);
                if (emailVal) url.searchParams.append('email', emailVal);

                const optResp = await fetch(url.toString(), {
                    headers: { 'Accept': 'application/json' }
                });
                if (!optResp.ok) throw new Error("Could not retrieve authentication challenge.");
                const options = await optResp.json();

                const publicKey = options.args?.publicKey || options.publicKey || options;
                publicKey.challenge = base64urlToBuffer(publicKey.challenge);
                if (publicKey.allowCredentials && publicKey.allowCredentials.length > 0) {
                    publicKey.allowCredentials = publicKey.allowCredentials.map(c => ({
                        ...c,
                        id: base64urlToBuffer(c.id)
                    }));
                }

                const assertion = await navigator.credentials.get({ publicKey });
                if (!assertion) throw new Error("Passkey assertion was cancelled or unavailable.");

                const verifyPayload = {
                    clientDataJSON: bufferToBase64url(assertion.response.clientDataJSON),
                    authenticatorData: bufferToBase64url(assertion.response.authenticatorData),
                    signature: bufferToBase64url(assertion.response.signature),
                    credentialId: bufferToBase64url(assertion.rawId),
                    latitude: document.getElementById("geo_lat")?.value || '',
                    longitude: document.getElementById("geo_lng")?.value || '',
                    _token: "{{ csrf_token() }}"
                };

                const verifyResp = await fetch("{{ route('login.passkey.verify') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': "{{ csrf_token() }}"
                    },
                    body: JSON.stringify(verifyPayload)
                });

                const result = await verifyResp.json();
                if (result.success) {
                    window.location.href = result.redirect || '/admin/users/settings';
                } else {
                    if (alpine.passkeyError !== undefined) alpine.passkeyError = result.message || "Passkey verification failed.";
                }
            } catch (err) {
                if (alpine.passkeyError !== undefined) alpine.passkeyError = err.message || "An error occurred with Passkey sign-in.";
            } finally {
                if (alpine.passkeyLoading !== undefined) alpine.passkeyLoading = false;
            }
        }
    </script>
</body>
</html>
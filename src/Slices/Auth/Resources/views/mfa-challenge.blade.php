<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication - LaraSlice</title>
    <script src="https://unpkg.com/@tailwindcss/browser@4"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex items-center justify-center p-6 relative overflow-hidden" 
      x-data="{ 
          mode: '{{ $defaultMode ?? ($user->mfa_channel === "webauthn" && !empty($hasPasskeys) ? "passkey" : (!empty($hasTotp) ? "totp" : (!empty($hasPasskeys) ? "passkey" : "totp"))) }}', 
          altOpen: false,
          passkeyLoading: false, 
          passkeyError: '',
          passkeyNotice: '',
          hasPasskeys: {{ !empty($hasPasskeys) ? 'true' : 'false' }},
          hasTotp: {{ !empty($hasTotp) ? 'true' : 'false' }},
          isNewDevice: {{ !empty($isNewDevice) ? 'true' : 'false' }},
          deviceCodeInput: ''
      }">
    <!-- Ambient Glow -->
    <div class="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-gradient-to-tr from-indigo-600/20 via-violet-600/10 to-transparent rounded-full blur-3xl pointer-events-none"></div>

    <div class="w-full max-w-md relative z-10">
        <!-- Brand Header -->
        <div class="text-center mb-6">
            <div class="inline-flex w-12 h-12 rounded-2xl bg-gradient-to-tr from-indigo-600 to-violet-500 items-center justify-center shadow-xl shadow-indigo-500/25 mb-3">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
            </div>
            <h1 class="text-xl font-black tracking-tight text-white">Identity Verification</h1>
            <p class="text-slate-400 text-xs mt-1">Confirm access for <span class="text-indigo-400 font-semibold">{{ $user->email }}</span></p>
        </div>

        <!-- NEW BROWSER / DEVICE DETECTION BANNER -->
        @if(!empty($isNewDevice))
            <div class="mb-4 p-4 bg-amber-500/10 border border-amber-500/30 rounded-2xl space-y-2">
                <div class="flex items-center gap-2.5 text-amber-300 font-bold text-xs">
                    <svg class="w-4 h-4 text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    <span>New Browser / Workstation Detected</span>
                </div>
                <p class="text-[11px] text-amber-200/90 leading-relaxed">
                    You are signing in from <strong class="text-white">{{ $deviceSummary ?? 'an unrecognized browser' }}</strong> (IP: {{ request()->ip() }}). You can verify with your Authenticator App, or enter a single-use <strong>Device Enrollment Code (DEV-XXXXXX)</strong> to authorize this browser.
                </p>
            </div>
        @else
            <!-- Standard Telemetry Notice -->
            <div class="mb-4 p-3 bg-slate-900/60 border border-slate-800 rounded-xl flex items-center gap-2 text-[11px] text-slate-400">
                <svg class="w-4 h-4 text-indigo-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                <span>Trusted Workstation: Security telemetry active.</span>
            </div>
        @endif

        @if(session('error'))
            <div class="mb-4 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs flex items-center gap-2">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        @if(isset($errors) && $errors->any())
            <div class="mb-4 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs">
                @foreach ($errors->all() as $error)
                    <div>• {{ $error }}</div>
                @endforeach
            </div>
        @endif

        <!-- Notice Banner (e.g. switched after passkey cancel) -->
        <div x-show="passkeyNotice" x-cloak class="mb-4 p-3 rounded-xl bg-indigo-500/10 border border-indigo-500/30 text-indigo-300 text-xs flex items-center gap-2">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <span x-text="passkeyNotice"></span>
        </div>

        <div x-show="passkeyError" x-cloak class="mb-4 p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs flex items-start gap-2.5">
            <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            <div class="space-y-1">
                <span x-text="passkeyError"></span>
                <template x-if="window.location.hostname === '127.0.0.1'">
                    <div class="pt-1">
                        <a :href="window.location.href.replace('127.0.0.1', 'localhost')" class="text-indigo-400 hover:underline font-semibold">
                            👉 Click here to switch to http://localhost:7000
                        </a>
                    </div>
                </template>
            </div>
        </div>

        <!-- Card Container -->
        <div class="bg-slate-900/70 border border-slate-800/80 rounded-2xl p-6 backdrop-blur-xl shadow-2xl space-y-5">
            
            <!-- Flexible Method Selection Pills (Always accessible if multiple factors exist) -->
            @if((!empty($hasPasskeys) && !empty($hasTotp)) || !empty($isNewDevice))
                <div class="flex items-center gap-1.5 p-1 bg-slate-950/80 border border-slate-800/80 rounded-xl text-xs">
                    @if(!empty($hasTotp))
                        <button type="button" @click="mode = 'totp'; passkeyError = ''; passkeyNotice = '';" 
                                :class="mode === 'totp' ? 'bg-indigo-600 text-white font-semibold shadow-xs' : 'text-slate-400 hover:text-slate-200'" 
                                class="flex-1 py-1.5 rounded-lg transition text-center cursor-pointer">
                            📱 Authenticator App
                        </button>
                    @endif
                    @if(!empty($hasPasskeys))
                        <button type="button" @click="mode = 'passkey'; passkeyError = ''; passkeyNotice = '';" 
                                :class="mode === 'passkey' ? 'bg-indigo-600 text-white font-semibold shadow-xs' : 'text-slate-400 hover:text-slate-200'" 
                                class="flex-1 py-1.5 rounded-lg transition text-center cursor-pointer">
                            🔑 Passkey
                        </button>
                    @endif
                    @if(!empty($isNewDevice))
                        <button type="button" @click="mode = 'device'; passkeyError = ''; passkeyNotice = '';" 
                                :class="mode === 'device' ? 'bg-amber-600 text-white font-semibold shadow-xs' : 'text-slate-400 hover:text-slate-200'" 
                                class="flex-1 py-1.5 rounded-lg transition text-center cursor-pointer">
                            💻 Device Code
                        </button>
                    @endif
                </div>
            @endif

            <!-- 1. Passkey Biometric Mode -->
            @if(!empty($hasPasskeys))
            <div x-show="mode === 'passkey'" class="space-y-4 text-center">
                <div class="p-6 rounded-2xl bg-slate-950/80 border border-slate-800 space-y-4">
                    <div class="w-14 h-14 mx-auto rounded-full bg-indigo-500/10 border border-indigo-500/30 flex items-center justify-center text-indigo-400">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
                    </div>
                    <div>
                        <h4 class="text-sm font-bold text-white">Biometric Passkey Sign-In</h4>
                        <p class="text-xs text-slate-400 mt-1">Authenticate using Windows Hello, Touch ID, Face ID, or your hardware security key.</p>
                    </div>

                    <button type="button" @click="authenticateWithPasskey()" :disabled="passkeyLoading" class="w-full py-3 bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white font-semibold rounded-xl text-sm transition shadow-lg shadow-indigo-500/25 flex items-center justify-center gap-2 cursor-pointer disabled:opacity-50">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 004 11a7.96 7.96 0 002.502 5.86"></path></svg>
                        <span x-text="passkeyLoading ? 'Waiting for Biometrics...' : 'Authenticate with Passkey'"></span>
                    </button>

                    @if(!empty($hasTotp))
                        <div class="pt-1">
                            <button type="button" @click="mode = 'totp'" class="text-xs text-indigo-400 hover:underline">
                                Or use 6-digit Authenticator App code instead
                            </button>
                        </div>
                    @endif
                </div>
            </div>
            @endif

            <!-- 2. Device Enrollment Code Mode -->
            <div x-show="mode === 'device'" x-cloak class="space-y-4">
                <form action="{{ route('login.mfa.challenge.verify') }}" method="POST" class="p-4 bg-slate-950/80 border border-slate-800 rounded-2xl space-y-3">
                    @csrf
                    <input type="hidden" name="latitude" id="geo_lat_dev_primary" value="">
                    <input type="hidden" name="longitude" id="geo_lng_dev_primary" value="">
                    <input type="hidden" name="auth_mode" value="device">

                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-amber-400 mb-1">
                            Device Enrollment Code (DEV-XXXXXX)
                        </label>
                        <p class="text-[11px] text-slate-400 mb-3">
                            Enter the single-use code issued by your Administrator or generated from your trusted workstation's Settings:
                        </p>
                        <input type="text" name="device_code" maxlength="14" placeholder="DEV-XXXXXX" required
                            class="w-full bg-slate-900 border border-amber-500/40 rounded-xl px-4 py-3 text-white text-center font-mono font-bold text-lg tracking-widest uppercase focus:outline-none focus:border-amber-500">
                    </div>

                    <button type="submit" class="w-full py-3 bg-gradient-to-r from-amber-600 to-amber-700 hover:from-amber-500 hover:to-amber-600 text-white font-bold rounded-xl text-sm transition shadow-lg shadow-amber-500/25 flex items-center justify-center gap-2 cursor-pointer">
                        <span>Authorize & Bind This Browser</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                    </button>

                    @if(!empty($hasTotp))
                        <div class="text-center pt-1">
                            <button type="button" @click="mode = 'totp'" class="text-xs text-slate-400 hover:text-white">
                                Switch to Authenticator App (TOTP)
                            </button>
                        </div>
                    @endif
                </form>
            </div>

            <!-- 3. Primary Standard Mode: 6-Digit Authenticator App (Default when enrolled via TOTP) -->
            <form action="{{ route('login.mfa.challenge.verify') }}" method="POST" class="space-y-4" x-show="mode === 'totp'">
                @csrf
                <input type="hidden" name="latitude" id="geo_lat" value="">
                <input type="hidden" name="longitude" id="geo_lng" value="">
                <input type="hidden" name="accuracy" id="geo_acc" value="">
                <input type="hidden" name="auth_mode" value="totp">

                <div class="space-y-2">
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400">
                        6-Digit Authenticator Code
                    </label>
                    <div class="relative">
                        <input type="text" name="code" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autofocus
                            placeholder="000000"
                            class="w-full bg-slate-950/80 border border-slate-800 rounded-xl px-4 py-3 text-white text-center font-mono font-bold text-lg tracking-[0.4em] focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 placeholder-slate-700 transition"
                            required>
                    </div>
                    <p class="text-[11px] text-slate-500 text-center">Open Microsoft Authenticator, Google Authenticator, or 1Password</p>
                </div>

                <button type="submit" class="w-full py-3 bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white font-semibold rounded-xl text-sm transition shadow-lg shadow-indigo-500/25 flex items-center justify-center gap-2 cursor-pointer">
                    <span>Verify & Continue</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                </button>
            </form>

            <!-- 4. Progressive Disclosure: "Try Another Way" (Emergency Recovery & Admin Code) -->
            <div class="pt-3 border-t border-slate-800/80">
                <div class="text-center">
                    <button type="button" @click="altOpen = !altOpen" class="text-xs text-indigo-400 hover:text-indigo-300 font-medium transition inline-flex items-center gap-1 cursor-pointer">
                        <span x-text="altOpen ? 'Hide backup options' : 'Having trouble? Try another way'"></span>
                        <svg class="w-3.5 h-3.5 transition-transform" :class="altOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                </div>

                <div x-show="altOpen" x-cloak x-transition class="mt-4 pt-4 border-t border-slate-800/60 space-y-4">
                    <!-- Option A: Recovery Code Form -->
                    <form action="{{ route('login.mfa.challenge.verify') }}" method="POST" class="p-3.5 bg-slate-950/60 border border-slate-800 rounded-xl space-y-2.5">
                        @csrf
                        <input type="hidden" name="latitude" id="geo_lat_rec" value="">
                        <input type="hidden" name="longitude" id="geo_lng_rec" value="">
                        <input type="hidden" name="auth_mode" value="recovery">
                        
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-400">
                            Emergency Backup Code
                        </label>
                        <div class="flex items-center gap-2">
                            <input type="text" name="recovery_code" maxlength="12" placeholder="e.g. A1B2C3D4" required
                                class="flex-1 bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-white font-mono text-xs tracking-widest uppercase focus:outline-none focus:border-indigo-500">
                            <button type="submit" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-700 text-white text-xs font-semibold rounded-lg transition shrink-0">
                                Verify
                            </button>
                        </div>
                        <p class="text-[10px] text-slate-500">Use one of your 8 single-use backup recovery codes</p>
                    </form>

                    <!-- Option B: Admin Device Code Form -->
                    <form action="{{ route('login.mfa.challenge.verify') }}" method="POST" class="p-3.5 bg-slate-950/60 border border-slate-800 rounded-xl space-y-2.5">
                        @csrf
                        <input type="hidden" name="latitude" id="geo_lat_dev" value="">
                        <input type="hidden" name="longitude" id="geo_lng_dev" value="">
                        <input type="hidden" name="auth_mode" value="device">
                        
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-400">
                            Administrator Device Code
                        </label>
                        <div class="flex items-center gap-2">
                            <input type="text" name="device_code" maxlength="14" placeholder="DEV-XXXXXX" required
                                class="flex-1 bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-white font-mono text-xs tracking-widest uppercase focus:outline-none focus:border-indigo-500">
                            <button type="submit" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-700 text-white text-xs font-semibold rounded-lg transition shrink-0">
                                Verify
                            </button>
                        </div>
                        <p class="text-[10px] text-slate-500">Enter a single-use code issued by your Administrator or from Settings</p>
                    </form>
                </div>
            </div>

            <div class="pt-3 border-t border-slate-800/80 flex items-center justify-between text-xs text-slate-400">
                <a href="{{ route('login') }}" class="hover:text-white transition flex items-center gap-1">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                    <span>Cancel Sign In</span>
                </a>
                <span class="text-slate-600">•</span>
                <span class="text-slate-500 font-mono text-[11px]">RFC-6238 Standard</span>
            </div>
        </div>
    </div>

    <script>
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

        async function authenticateWithPasskey() {
            const body = document.querySelector('[x-data]');
            const alpine = body ? Alpine.$data(body) : {};
            alpine.passkeyLoading = true;
            alpine.passkeyError = '';
            alpine.passkeyNotice = '';

            try {
                if (window.location.hostname === '127.0.0.1') {
                    throw new Error("Passkeys (WebAuthn) require a domain name (such as http://localhost:7000) rather than an IP address (127.0.0.1). Please open via http://localhost:7000.");
                }

                if (!window.isSecureContext) {
                    throw new Error("Passkeys (WebAuthn) require a secure context. Open this app over HTTPS (e.g. https://" + window.location.host + ") or via http://localhost.");
                }

                if (!window.PublicKeyCredential) {
                    throw new Error("WebAuthn is not supported by this browser.");
                }

                const optResp = await fetch("{{ route('login.passkey.options') }}", {
                    headers: { 'Accept': 'application/json' }
                });
                if (!optResp.ok) throw new Error("Could not start passkey authentication.");
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
                if (!assertion) throw new Error("Authentication was cancelled.");

                const latEl = document.getElementById("geo_lat");
                const lngEl = document.getElementById("geo_lng");

                const verifyPayload = {
                    credentialId: bufferToBase64url(assertion.rawId),
                    clientDataJSON: bufferToBase64url(assertion.response.clientDataJSON),
                    authenticatorData: bufferToBase64url(assertion.response.authenticatorData),
                    signature: bufferToBase64url(assertion.response.signature),
                    latitude: latEl ? latEl.value : null,
                    longitude: lngEl ? lngEl.value : null,
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
                if (!result.success) {
                    throw new Error(result.message || 'Passkey verification failed.');
                }

                window.location.href = result.redirect || "{{ url('/admin/users/settings') }}";

            } catch (err) {
                alpine.passkeyLoading = false;
                const msg = String(err.message || err || '').toLowerCase();
                
                // If user clicks Cancel in Windows Hello or operation times out
                if (msg.indexOf('not allowed') !== -1 || msg.indexOf('timed out') !== -1 || msg.indexOf('cancelled') !== -1) {
                    if (alpine.hasTotp) {
                        alpine.mode = 'totp';
                        alpine.passkeyNotice = "Passkey sign-in was cancelled. Switched to Authenticator App.";
                    } else if (alpine.isNewDevice) {
                        alpine.mode = 'device';
                        alpine.passkeyNotice = "No passkey enrolled on this browser yet. Enter a Device Code to authorize.";
                    } else {
                        alpine.passkeyError = "Biometric prompt was cancelled. Click 'Authenticate with Passkey' to retry.";
                    }
                } else if (msg.indexOf('no credential') !== -1 || msg.indexOf('no passkey') !== -1) {
                    if (alpine.hasTotp) {
                        alpine.mode = 'totp';
                        alpine.passkeyNotice = "No passkey on this browser. Switched to Authenticator App.";
                    } else {
                        alpine.mode = 'device';
                        alpine.passkeyNotice = "No passkey on this browser. Enter a Device Code to authorize.";
                    }
                } else {
                    alpine.passkeyError = err.message || 'Passkey authentication failed.';
                }
            }
        }

        // Non-blocking, completely optional geolocation telemetry
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function (pos) {
                var lat = pos.coords.latitude;
                var lng = pos.coords.longitude;
                var acc = pos.coords.accuracy;
                ['geo_lat', 'geo_lat_rec', 'geo_lat_dev', 'geo_lat_dev_primary'].forEach(function(id) {
                    var el = document.getElementById(id);
                    if (el) el.value = lat;
                });
                ['geo_lng', 'geo_lng_rec', 'geo_lng_dev', 'geo_lng_dev_primary'].forEach(function(id) {
                    var el = document.getElementById(id);
                    if (el) el.value = lng;
                });
                var accEl = document.getElementById('geo_acc');
                if (accEl) accEl.value = acc;
            }, function (err) {
                // Completely silent fallback when location is disabled/blocked in real world
            }, {
                timeout: 4000,
                maximumAge: 60000
            });
        }
    </script>
</body>
</html>

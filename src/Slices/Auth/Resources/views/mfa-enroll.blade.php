<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Device Enrollment - LaraSlice</title>
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
          activeTab: '{{ ($preferredMethod ?? 'webauthn') === 'webauthn' ? 'passkey' : 'totp' }}',
          passkeyLoading: false,
          passkeyError: '',
          passkeySuccess: '',
          passkeyLabel: 'Primary Workstation',
          recoveryCodes: []
      }">
    <!-- Ambient Glow -->
    <div class="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[650px] h-[650px] bg-gradient-to-tr from-purple-600/20 via-indigo-600/15 to-transparent rounded-full blur-3xl pointer-events-none"></div>

    <div class="w-full max-w-lg relative z-10">
        <!-- Brand Header -->
        <div class="text-center mb-6">
            <div class="inline-flex w-14 h-14 rounded-2xl bg-gradient-to-tr from-purple-600 to-indigo-500 items-center justify-center shadow-xl shadow-purple-500/25 mb-3">
                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
            </div>
            <h1 class="text-2xl font-black tracking-tight text-white">Enroll Security Credentials</h1>
            <p class="text-slate-400 text-xs mt-1">Bind and authorize your device for <span class="text-purple-400 font-semibold">{{ $user->email }}</span></p>
        </div>

        <!-- Role Policy Notice -->
        <div class="mb-5 p-3.5 bg-indigo-500/10 border border-indigo-500/30 rounded-xl flex items-start gap-3 text-xs text-indigo-300">
            <svg class="w-5 h-5 text-indigo-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
            <div class="leading-relaxed">
                <strong class="font-bold text-indigo-200">Device Enrollment Required:</strong> Your credentials were reset or this is your first sign-in. Privileged accounts are strongly recommended to bind a <strong>Biometric Passkey (Windows Hello / Touch ID)</strong> to authorize this computer.
            </div>
        </div>

        @if(session('error'))
            <div class="mb-4 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs flex items-center gap-2">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        <div x-show="passkeyError" x-cloak class="mb-4 p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs flex items-start gap-2.5">
            <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            <div class="space-y-1">
                <span x-text="passkeyError"></span>
                <template x-if="window.location.hostname === '127.0.0.1'">
                    <div class="pt-1">
                        <a :href="window.location.href.replace('127.0.0.1', 'localhost')" class="text-purple-400 hover:underline font-semibold">
                            👉 Click here to switch to http://localhost:7000
                        </a>
                    </div>
                </template>
            </div>
        </div>

        <!-- Mode Switcher Tabs -->
        <div class="flex items-center gap-2 p-1.5 bg-slate-900/90 border border-slate-800 rounded-2xl mb-4 backdrop-blur-xl">
            <button type="button" @click="activeTab = 'passkey'"
                    :class="activeTab === 'passkey' ? 'bg-gradient-to-r from-purple-600 to-indigo-600 text-white shadow-lg shadow-purple-500/25 font-bold' : 'text-slate-400 hover:text-slate-200'"
                    class="flex-1 py-2.5 px-3 rounded-xl text-xs transition flex items-center justify-center gap-2 cursor-pointer">
                <span>🔑 Biometric Passkey</span>
                <span class="px-1.5 py-0.5 rounded text-[10px] bg-purple-400/20 text-purple-200 font-medium">Recommended</span>
            </button>
            <button type="button" @click="activeTab = 'totp'"
                    :class="activeTab === 'totp' ? 'bg-gradient-to-r from-purple-600 to-indigo-600 text-white shadow-lg shadow-purple-500/25 font-bold' : 'text-slate-400 hover:text-slate-200'"
                    class="flex-1 py-2.5 px-3 rounded-xl text-xs transition flex items-center justify-center gap-1.5 cursor-pointer">
                <span>📱 Authenticator App</span>
            </button>
        </div>

        <!-- Card Container -->
        <div class="bg-slate-900/70 border border-slate-800/80 rounded-2xl p-6 backdrop-blur-xl shadow-2xl space-y-6">

            <!-- TAB 1: PASSKEY ENROLLMENT (Enterprise PARITY) -->
            <div x-show="activeTab === 'passkey'" x-cloak class="space-y-5 text-center">
                <div class="p-6 rounded-2xl bg-slate-950/80 border border-slate-800 space-y-4">
                    <div class="w-16 h-16 mx-auto rounded-full bg-purple-500/10 border border-purple-500/30 flex items-center justify-center text-purple-400 shadow-inner">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 004 11a7.96 7.96 0 002.502 5.86"></path></svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white">Enroll Windows Hello / Biometric Passkey</h3>
                        <p class="text-xs text-slate-400 mt-1.5 leading-relaxed">
                            Bind this workstation directly to your account using Windows Hello, fingerprint, Touch ID, or a FIDO2 USB Security Key. No passwords or OTPs needed on this device.
                        </p>
                    </div>

                    <div class="text-left space-y-1.5">
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-400">Device / Workstation Label</label>
                        <input type="text" x-model="passkeyLabel" placeholder="e.g. My Laptop (Windows 11)"
                               class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-purple-500">
                    </div>

                    <button type="button" @click="enrollPasskeyNow()" :disabled="passkeyLoading"
                            class="w-full py-3.5 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-bold rounded-xl text-sm transition shadow-lg shadow-purple-500/25 flex items-center justify-center gap-2 cursor-pointer disabled:opacity-50">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
                        <span x-text="passkeyLoading ? 'Waiting for Biometric Prompt...' : 'Enroll Passkey & Authorize This Device'"></span>
                    </button>
                </div>

                <div class="text-[11px] text-slate-400 flex items-center justify-center gap-2">
                    <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    <span>FIDO2 / WebAuthn Enterprise Standard • End-to-End Encrypted</span>
                </div>
            </div>

            <!-- TAB 2: TOTP AUTHENTICATOR ENROLLMENT -->
            <div x-show="activeTab === 'totp'" x-cloak class="space-y-6">
                <!-- QR Code Section -->
                <div class="flex flex-col sm:flex-row items-center gap-6 p-4 rounded-xl bg-slate-950/80 border border-slate-800">
                    <div class="p-2.5 bg-white rounded-xl shadow-md shrink-0">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data={{ urlencode('otpauth://totp/LaraSlice:' . $user->email . '?secret=' . $secret . '&issuer=LaraSlice') }}" alt="TOTP QR Code" class="w-36 h-36 rounded-lg block" />
                    </div>
                    <div class="space-y-3 flex-1 text-center sm:text-left">
                        <div>
                            <span class="text-xs font-bold text-white uppercase tracking-wider">Manual Setup Key</span>
                            <p class="text-[11px] text-slate-400 mt-0.5">If you can't scan the QR code, enter this key manually into Google or Microsoft Authenticator:</p>
                        </div>
                        <div class="flex items-center justify-center sm:justify-start gap-2">
                            <span class="px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-700 font-mono text-xs font-bold text-purple-400 tracking-widest select-all">
                                {{ chunk_split($secret, 4, ' ') }}
                            </span>
                            <button type="button" onclick="navigator.clipboard.writeText('{{ $secret }}'); alert('Secret key copied!');" class="p-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 transition cursor-pointer" title="Copy Key">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                            </button>
                        </div>
                        <span class="inline-flex items-center gap-1.5 text-[10px] text-emerald-400 font-medium">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            RFC-6238 Standard (30-sec rotation)
                        </span>
                    </div>
                </div>

                <!-- Confirmation Form -->
                <form action="{{ route('login.mfa.enroll.confirm') }}" method="POST" class="space-y-4">
                    @csrf
                    <input type="hidden" name="latitude" id="geo_lat" value="">
                    <input type="hidden" name="longitude" id="geo_lng" value="">

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">
                            Enter 6-Digit Code from Your App to Confirm
                        </label>
                        <input type="text" name="code" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" required autofocus
                            placeholder="000000"
                            class="w-full bg-slate-950/80 border border-slate-800 rounded-xl px-4 py-3 text-white text-center font-mono font-bold text-lg tracking-[0.4em] focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 placeholder-slate-700 transition">
                    </div>

                    <button type="submit" class="w-full py-3.5 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-bold rounded-xl text-sm transition shadow-lg shadow-purple-500/25 flex items-center justify-center gap-2 cursor-pointer">
                        <span>Activate Authenticator & Authorize Device</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                    </button>
                </form>
            </div>

            <div class="pt-3 border-t border-slate-800/80 text-center text-xs text-slate-400">
                <a href="{{ route('login') }}" class="hover:text-white transition">Cancel and Return to Sign In</a>
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

        async function enrollPasskeyNow() {
            const body = document.querySelector('[x-data]');
            const alpine = body ? Alpine.$data(body) : {};
            alpine.passkeyLoading = true;
            alpine.passkeyError = '';

            try {
                if (window.location.hostname === '127.0.0.1') {
                    throw new Error("Passkeys (WebAuthn) require a domain name (such as http://localhost:7000) rather than an IP address (127.0.0.1). Please open via http://localhost:7000.");
                }

                if (!window.isSecureContext) {
                    throw new Error("Passkeys (WebAuthn) require a secure context. Open this app over HTTPS (e.g. https://" + window.location.host + ") or via http://localhost.");
                }

                if (!window.PublicKeyCredential) {
                    throw new Error("WebAuthn / Passkeys are not supported by this browser.");
                }

                const optResp = await fetch("{{ route('login.passkey.enroll.options') }}", {
                    headers: { 'Accept': 'application/json' }
                });
                if (!optResp.ok) throw new Error("Could not initialize passkey registration challenge.");
                const options = await optResp.json();

                const publicKey = options.args?.publicKey || options.publicKey || options;
                publicKey.challenge = base64urlToBuffer(publicKey.challenge);
                if (publicKey.user && publicKey.user.id) {
                    publicKey.user.id = base64urlToBuffer(publicKey.user.id);
                }
                if (publicKey.excludeCredentials && publicKey.excludeCredentials.length > 0) {
                    publicKey.excludeCredentials = publicKey.excludeCredentials.map(c => ({
                        ...c,
                        id: base64urlToBuffer(c.id)
                    }));
                }

                const credential = await navigator.credentials.create({ publicKey });
                if (!credential) throw new Error("Passkey registration was cancelled.");

                const verifyPayload = {
                    clientDataJSON: bufferToBase64url(credential.response.clientDataJSON),
                    attestationObject: bufferToBase64url(credential.response.attestationObject),
                    label: alpine.passkeyLabel || 'Primary Workstation',
                    _token: "{{ csrf_token() }}"
                };

                const verifyResp = await fetch("{{ route('login.passkey.enroll.verify') }}", {
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

                // Redirect to dashboard or settings with authorized cookie
                window.location.href = result.redirect || "{{ url('/admin/users/settings#mfa') }}";

            } catch (err) {
                alpine.passkeyLoading = false;
                alpine.passkeyError = err.message || 'Passkey registration error.';
            }
        }

        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function (pos) {
                var latEl = document.getElementById("geo_lat");
                var lngEl = document.getElementById("geo_lng");
                if (latEl) latEl.value = pos.coords.latitude;
                if (lngEl) lngEl.value = pos.coords.longitude;
            }, function () {});
        }
    </script>
</body>
</html>

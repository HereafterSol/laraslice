<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Locked - LaraSlice Enterprise</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    @if(file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <script src="https://cdn.tailwindcss.com"></script>
        <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    @endif
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="h-full bg-slate-950 flex items-center justify-center p-4 relative overflow-hidden text-slate-100"
      x-data="lockscreenState()">
    <!-- Ambient Backdrop Effects (LaraSlice Purple / Indigo) -->
    <div class="absolute -top-40 -left-40 w-96 h-96 bg-purple-600/20 rounded-full blur-[128px] pointer-events-none"></div>
    <div class="absolute -bottom-40 -right-40 w-96 h-96 bg-indigo-600/20 rounded-full blur-[128px] pointer-events-none"></div>

    @php
        // Mask Email: e.g. admin@laraslice.com -> ad***@laraslice.com
        $rawEmail = $user->email ?? 'admin@laraslice.com';
        $emailParts = explode('@', $rawEmail);
        $userPart = $emailParts[0] ?? 'admin';
        $domainPart = $emailParts[1] ?? 'laraslice.com';

        if (strlen($userPart) <= 2) {
            $maskedEmail = substr($userPart, 0, 1) . '***@' . $domainPart;
        } elseif (strlen($userPart) <= 4) {
            $maskedEmail = substr($userPart, 0, 2) . '***@' . $domainPart;
        } else {
            $maskedEmail = substr($userPart, 0, 2) . '***' . substr($userPart, -1) . '@' . $domainPart;
        }

        // Mask CNIC: e.g. 32301-8275113-7 -> 32301-*******-7
        $rawCnic = $user->detail?->cnic ?? null;
        $maskedCnic = null;
        if ($rawCnic) {
            $cleaned = str_replace(['-', ' '], '', $rawCnic);
            if (strlen($cleaned) === 13) {
                $maskedCnic = substr($cleaned, 0, 5) . '-*******-' . substr($cleaned, -1);
            } else {
                $len = strlen($rawCnic);
                $maskedCnic = substr($rawCnic, 0, min(3, $len)) . '***' . ($len > 5 ? substr($rawCnic, -2) : '');
            }
        }
    @endphp

    <div class="relative w-full max-w-md bg-slate-900/80 backdrop-blur-xl border border-slate-800 rounded-3xl p-8 shadow-2xl shadow-black/80 flex flex-col items-center text-center">
        <!-- Logo / Brand Badge -->
        <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-purple-950/60 border border-purple-800/60 text-purple-400 text-xs font-semibold uppercase tracking-wider mb-6">
            <x-lucide-shield-alert class="size-3.5" />
            Security Lockscreen
        </div>

        <!-- User Avatar with Status Ring -->
        <div class="relative mb-4">
            <div class="w-24 h-24 rounded-full bg-gradient-to-tr from-purple-500 to-indigo-500 p-1 shadow-lg shadow-purple-900/40">
                <div class="w-full h-full rounded-full bg-slate-900 flex items-center justify-center text-2xl font-extrabold text-purple-400">
                    {{ strtoupper(substr($user->name ?? 'Admin', 0, 2)) }}
                </div>
            </div>
            <span class="absolute bottom-1 right-1 w-5 h-5 rounded-full bg-purple-500 border-2 border-slate-900 flex items-center justify-center text-white" title="Active Session">
                <x-lucide-lock class="size-2.5" />
            </span>
        </div>

        <h2 class="text-xl font-bold text-white tracking-tight">{{ $user->name ?? 'Authenticated User' }}</h2>
        
        <!-- Masked Email -->
        <div class="mt-1 flex items-center justify-center gap-1.5 text-xs text-slate-400 font-mono tracking-wide">
            <x-lucide-mail class="size-3 text-slate-500 shrink-0" />
            <span class="bg-slate-950/70 border border-slate-800/80 px-2.5 py-0.5 rounded-full select-none" title="Masked for privacy">{{ $maskedEmail }}</span>
        </div>

        <!-- Masked CNIC -->
        @if(!empty($maskedCnic))
            <div class="mt-1 flex items-center justify-center gap-1.5 text-[11px] text-slate-500 font-mono">
                <x-lucide-id-card class="size-3 text-slate-600 shrink-0" />
                <span class="tracking-wider">CNIC: {{ $maskedCnic }}</span>
            </div>
        @endif

        <div class="my-5 w-full border-t border-slate-800/80"></div>

        <p class="text-xs text-slate-400 mb-6">
            Your session is locked to prevent unauthorized access. Unlock with biometric passkey or password to resume where you left off.
        </p>

        @if(session('error'))
            <div class="w-full p-3.5 mb-5 bg-red-950/40 border border-red-800/60 rounded-xl text-red-300 text-xs flex items-center gap-2.5 text-left">
                <x-lucide-alert-circle class="size-4 text-red-400 shrink-0" />
                <span>{{ session('error') }}</span>
            </div>
        @endif

        <!-- Passkey Error Alert -->
        <div x-show="passkeyError" x-cloak class="w-full p-3.5 mb-4 bg-amber-950/40 border border-amber-800/60 rounded-xl text-amber-300 text-xs flex items-center gap-2.5 text-left">
            <x-lucide-alert-triangle class="size-4 text-amber-400 shrink-0" />
            <span x-text="passkeyError"></span>
        </div>

        <!-- 1. Passkey / Biometrics 1-Tap Unlock -->
        @if(($allowPasskeys ?? true) && ($hasPasskeys ?? false))
            <div class="w-full space-y-3 mb-5">
                <button 
                    type="button" 
                    @click="unlockWithPasskey()" 
                    :disabled="passkeyLoading"
                    class="w-full py-3 px-4 bg-gradient-to-r from-purple-600 via-indigo-600 to-purple-600 hover:from-purple-500 hover:via-indigo-500 hover:to-purple-500 text-white rounded-xl text-sm font-bold shadow-lg shadow-purple-900/30 transition-all flex items-center justify-center gap-2 cursor-pointer active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed">
                    <template x-if="!passkeyLoading">
                        <div class="flex items-center gap-2">
                            <x-lucide-fingerprint class="size-5 text-purple-200" />
                            <span>Unlock with Passkey / Biometrics</span>
                        </div>
                    </template>
                    <template x-if="passkeyLoading">
                        <div class="flex items-center gap-2">
                            <x-lucide-loader-2 class="size-5 animate-spin text-purple-200" />
                            <span>Verifying biometrics...</span>
                        </div>
                    </template>
                </button>

                <div class="relative flex py-1 items-center">
                    <div class="flex-grow border-t border-slate-800/80"></div>
                    <span class="flex-shrink mx-3 text-[11px] text-slate-500 font-medium uppercase tracking-wider">or enter password</span>
                    <div class="flex-grow border-t border-slate-800/80"></div>
                </div>
            </div>
        @endif

        <!-- 2. Password Unlock Form -->
        <form action="{{ route('lockscreen.unlock') }}" method="POST" class="w-full space-y-4">
            @csrf
            <div class="relative text-left">
                <label for="password" class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Password</label>
                <div class="relative">
                    <input type="password" id="password" name="password" required autofocus placeholder="Enter password to unlock..." class="w-full bg-slate-950/70 border border-slate-800 rounded-xl px-4 py-3 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all pr-11">
                    <button type="button" onclick="togglePasswordVisibility()" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-500 hover:text-slate-300 transition-colors">
                        <x-lucide-eye id="eyeIcon" class="size-4" />
                    </button>
                </div>
            </div>

            <button type="submit" class="w-full py-3 px-4 bg-slate-800 hover:bg-slate-700 text-white rounded-xl text-sm font-semibold border border-slate-700/80 transition-all flex items-center justify-center gap-2 cursor-pointer shadow-md">
                <x-lucide-unlock class="size-4" />
                Unlock with Password
            </button>
        </form>

        <div class="mt-6 flex items-center justify-between w-full text-xs text-slate-500">
            <span class="flex items-center gap-1.5">
                <x-lucide-shield-check class="size-3.5 text-purple-500" />
                Protected by LaraSlice
            </span>
            <a href="{{ route('login') }}" class="text-slate-400 hover:text-white underline underline-offset-4 transition-colors">
                Sign in as different user
            </a>
        </div>
    </div>

    <script>
        function togglePasswordVisibility() {
            const input = document.getElementById('password');
            const icon = document.getElementById('eyeIcon');
            if (input.type === 'password') {
                input.type = 'text';
            } else {
                input.type = 'password';
            }
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

        function lockscreenState() {
            return {
                passkeyLoading: false,
                passkeyError: '',
                async unlockWithPasskey() {
                    this.passkeyLoading = true;
                    this.passkeyError = '';

                    try {
                        if (window.location.hostname === '127.0.0.1') {
                            throw new Error("WebAuthn / Passkeys require localhost or a domain name rather than 127.0.0.1.");
                        }

                        if (!window.isSecureContext) {
                            throw new Error("Passkeys (WebAuthn) require a secure context. Open this app over HTTPS (e.g. https://" + window.location.host + ") or via http://localhost.");
                        }

                        if (!window.PublicKeyCredential) {
                            throw new Error("Passkeys/WebAuthn are not supported on this browser.");
                        }

                        const optionsUrl = "{{ route('lockscreen.passkey.options') }}";
                        const optResp = await fetch(optionsUrl, {
                            headers: { 'Accept': 'application/json' }
                        });

                        if (!optResp.ok) {
                            throw new Error("Could not retrieve passkey challenge.");
                        }

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
                        if (!assertion) {
                            throw new Error("Passkey assertion was cancelled or unavailable.");
                        }

                        const verifyPayload = {
                            clientDataJSON: bufferToBase64url(assertion.response.clientDataJSON),
                            authenticatorData: bufferToBase64url(assertion.response.authenticatorData),
                            signature: bufferToBase64url(assertion.response.signature),
                            credentialId: bufferToBase64url(assertion.rawId),
                            _token: "{{ csrf_token() }}"
                        };

                        const verifyUrl = "{{ route('lockscreen.passkey.verify') }}";
                        const verifyResp = await fetch(verifyUrl, {
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
                            window.location.href = result.redirect || "{{ route('security.settings') }}";
                        } else {
                            this.passkeyError = result.message || "Passkey verification failed. Please enter your password.";
                        }
                    } catch (err) {
                        this.passkeyError = err.message || "Passkey verification failed. Please enter your password.";
                    } finally {
                        this.passkeyLoading = false;
                    }
                }
            };
        }
    </script>
</body>
</html>
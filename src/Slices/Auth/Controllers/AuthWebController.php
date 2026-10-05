<?php

namespace LaraSlice\Slices\Auth\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use LaraSlice\Slices\Auth\Services\AuthSliceService;
use LaraSlice\Slices\Users\Models\User;
use LaraSlice\Slices\Users\Models\UserDevice;
use LaraSlice\Slices\Users\Models\UserSecurityLog;
use LaraSlice\Slices\Users\Models\UserAttempt;
use LaraSlice\Slices\Users\Models\UserConnect;
use LaraSlice\Slices\Users\Models\UserCred;
use LaraSlice\Slices\Users\Models\UserCode;
use LaraSlice\Slices\Users\Models\UserFactor;
use LaraSlice\Slices\Users\Models\UserPasskey;
use LaraSlice\Slices\Users\Services\SecurityPolicyService;
use LaraSlice\Slices\Users\Services\WebAuthnService;

class AuthWebController extends Controller
{
    protected AuthSliceService $authService;

    public function __construct(AuthSliceService $service)
    {
        $this->authService = $service;
    }

    /**
     * Display login page.
     */
    public function showLogin(): View
    {
        return view('auth::login');
    }

    /**
     * Process web login with Rate Limiting, Account Lockout & MFA Branching (Enterprise Parity).
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => 'required|string',
            'password' => 'required|string',
        ]);

        $identifier = trim($credentials['email']);
        $password = $credentials['password'];
        $remember = (bool) $request->input('remember', false);

        // Find user by email or CNIC
        $user = User::where('email', $identifier)
            ->orWhereHas('detail', function ($q) use ($identifier) {
                $q->where('cnic', $identifier);
            })
            ->first();

        if (!$user) {
            UserAttempt::record(
                identifier: $identifier,
                request: $request,
                reason: 'unknown_account',
                userId: null
            );

            UserSecurityLog::create([
                'user_id'              => null,
                'identifier_attempted' => $identifier,
                'event_type'           => 'unknown_account',
                'ip_address'           => $request->ip() ?: '127.0.0.1',
                'user_agent'           => $request->userAgent(),
                'created_at'           => now(),
            ]);

            return back()->withErrors([
                'email' => 'The provided credentials do not match our records.',
            ])->onlyInput('email');
        }

        // 1. Account Lockout Check
        if ($user->locked_until && $user->locked_until->isFuture()) {
            $minutes = max(1, (int) ceil(now()->diffInSeconds($user->locked_until) / 60));

            UserAttempt::record(
                identifier: $user->email,
                request: $request,
                reason: 'login_locked_attempt',
                userId: $user->id
            );

            UserSecurityLog::create([
                'user_id'              => $user->id,
                'identifier_attempted' => $user->email,
                'event_type'           => 'login_locked_attempt',
                'ip_address'           => $request->ip() ?: '127.0.0.1',
                'user_agent'           => $request->userAgent(),
                'created_at'           => now(),
            ]);

            return back()->withErrors([
                'email' => "Account temporarily locked due to too many failed sign-in attempts. Try again in {$minutes} minutes.",
            ])->onlyInput('email');
        }

        // 2. Password Verification & Failure Tracking
        if (!Hash::check($password, $user->password)) {
            $user->failed_attempts = ($user->failed_attempts ?? 0) + 1;

            if ($user->failed_attempts >= 5) {
                $user->locked_until = now()->addMinutes(15);
                $user->save();

                UserAttempt::record(
                    identifier: $user->email,
                    request: $request,
                    reason: 'account_locked_out_max_attempts',
                    userId: $user->id
                );

                UserSecurityLog::create([
                    'user_id'              => $user->id,
                    'identifier_attempted' => $user->email,
                    'event_type'           => 'account_locked_out',
                    'ip_address'           => $request->ip() ?: '127.0.0.1',
                    'user_agent'           => $request->userAgent(),
                    'payload'              => ['description' => 'Account locked for 15 minutes after 5 consecutive password failures.'],
                    'created_at'           => now(),
                ]);

                return back()->withErrors([
                    'email' => 'Account locked for 15 minutes due to too many failed sign-in attempts.',
                ])->onlyInput('email');
            }

            $user->save();
            $remaining = 5 - $user->failed_attempts;

            UserAttempt::record(
                identifier: $user->email,
                request: $request,
                reason: "invalid_password ({$user->failed_attempts}/5)",
                userId: $user->id
            );

            UserSecurityLog::create([
                'user_id'              => $user->id,
                'identifier_attempted' => $user->email,
                'event_type'           => 'login_failed',
                'ip_address'           => $request->ip() ?: '127.0.0.1',
                'user_agent'           => $request->userAgent(),
                'payload'              => ['description' => "Password mismatch ({$user->failed_attempts}/5)."],
                'created_at'           => now(),
            ]);

            return back()->withErrors([
                'email' => "Invalid password. You have {$remaining} attempt(s) remaining before temporary lockout.",
            ])->onlyInput('email');
        }

        // Account Status Check
        if ($user->status !== 'active') {
            return back()->withErrors([
                'email' => 'Your account is currently inactive or suspended.',
            ])->onlyInput('email');
        }

        // Reset failed attempts on valid password
        $user->failed_attempts = 0;
        $user->locked_until = null;
        $user->save();

        // 3. Dynamic Database-Backed Multi-Factor Authentication Policy
        $requiresMfa = SecurityPolicyService::requiresMfa($user);

        // Case: No MFA required -> Immediate sign in
        if (!$requiresMfa) {
            Auth::login($user, $remember);
            $request->session()->regenerate();
            [$device, $token] = UserDevice::recordDevice($user, $request, true);

            UserSecurityLog::create([
                'user_id'              => $user->id,
                'identifier_attempted' => $user->email,
                'event_type'           => 'login_success',
                'ip_address'           => $request->ip() ?: '127.0.0.1',
                'user_agent'           => $request->userAgent(),
                'payload'              => [
                    'latitude'  => $request->input('latitude'),
                    'longitude' => $request->input('longitude'),
                    'method'    => 'Password Only (MFA Disabled)',
                ],
                'created_at'           => now(),
            ]);

            return redirect()->intended('/admin/users/settings')
                ->cookie('laraslice_device_token', $token, 60 * 24 * 365, null, null, false, true);
        }

        // 4. Device Recognition / Trust Check (Edge vs Chrome / Multi-Browser detection)
        $isDeviceTrusted = UserDevice::isDeviceTrusted($user, $request);
        $clientDetails = UserDevice::parseClientDetails($request);

        session([
            'mfa_pending_user_id'     => $user->id,
            'mfa_remember'            => $remember,
            'mfa_pending_at'          => now()->timestamp,
            'mfa_new_device_detected' => !$isDeviceTrusted,
            'mfa_device_summary'      => $clientDetails['deviceName'],
        ]);

        // Check if user has an active, confirmed factor (Passkey OR Authenticator TOTP)
        $hasPasskeys = $user->passkeys()->whereNull('revoked_at')->exists();
        $hasTotp = (!empty($user->mfa_confirmed_at) && !empty($user->mfa_secret));
        $isEnrolled = ($hasPasskeys || $hasTotp);

        if (!$isEnrolled) {
            // Factor revoked by Admin or never enrolled -> Send to Enrollment Screen
            if (empty($user->mfa_secret)) {
                $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
                $secret = '';
                for ($i = 0; $i < 16; $i++) {
                    $secret .= $chars[random_int(0, 31)];
                }
                $user->mfa_secret = $secret;
                $user->save();
            }

            UserSecurityLog::create([
                'user_id'              => $user->id,
                'identifier_attempted' => $user->email,
                'event_type'           => 'mfa_enroll_required',
                'ip_address'           => $request->ip() ?: '127.0.0.1',
                'user_agent'           => $request->userAgent(),
                'payload'              => ['description' => 'User redirected to multi-factor enrollment screen.'],
                'created_at'           => now(),
            ]);

            return redirect()->route('login.mfa.enroll');
        }

        // User is enrolled -> Present challenge screen with Geolocation & Device Trust
        UserSecurityLog::create([
            'user_id'              => $user->id,
            'identifier_attempted' => $user->email,
            'event_type'           => 'mfa_challenge_prompt',
            'ip_address'           => $request->ip() ?: '127.0.0.1',
            'user_agent'           => $request->userAgent(),
            'payload'              => [
                'description' => 'User presented with MFA challenge.',
                'new_device'  => !$isDeviceTrusted,
                'browser'     => $clientDetails['deviceName'],
            ],
            'created_at'           => now(),
        ]);

        return redirect()->route('login.mfa.challenge');
    }

    /**
     * Display MFA Challenge View.
     */
    public function showMfaChallenge(): View|RedirectResponse
    {
        $userId = session('mfa_pending_user_id');
        if (!$userId) {
            return redirect()->route('login')->with('error', 'Session timed out. Please sign in again.');
        }

        $user = User::findOrFail($userId);

        $hasPasskeys = $user->passkeys()->whereNull('revoked_at')->exists();
        $hasTotp = (!empty($user->mfa_confirmed_at) && !empty($user->mfa_secret));

        if (!$hasPasskeys && !$hasTotp) {
            return redirect()->route('login.mfa.enroll');
        }

        $isNewDevice = (bool) session('mfa_new_device_detected', false);
        $deviceSummary = (string) session('mfa_device_summary', 'Unknown Browser / Workstation');

        // Determine default mode based on user's active factor and enrollment
        $defaultMode = 'totp';
        if ($user->mfa_channel === 'webauthn' && $hasPasskeys) {
            $defaultMode = 'passkey';
        } elseif ($hasTotp) {
            $defaultMode = 'totp';
        } elseif ($hasPasskeys) {
            $defaultMode = 'passkey';
        }

        return view('auth::mfa-challenge', [
            'user'          => $user,
            'hasPasskeys'   => $hasPasskeys,
            'hasTotp'       => $hasTotp,
            'isNewDevice'   => $isNewDevice,
            'deviceSummary' => $deviceSummary,
            'defaultMode'   => $defaultMode,
        ]);
    }

    /**
     * Verify MFA Challenge (supports 6-digit TOTP, 8-char Recovery Code, or Admin Device Code).
     */
    public function verifyMfaChallenge(Request $request): RedirectResponse
    {
        $userId = session('mfa_pending_user_id');
        if (!$userId) {
            return redirect()->route('login')->with('error', 'Session timed out. Please sign in again.');
        }

        $user = User::findOrFail($userId);
        $remember = (bool) session('mfa_remember', false);
        $verified = false;
        $methodUsed = 'Authenticator App';

        $mode = $request->input('auth_mode', 'totp');

        // 1. Admin-Issued / Self-Service Device Enrollment Code Mode (Enterprise DEV-XXXXXX parity)
        $deviceInput = strtoupper(trim($request->input('device_code') ?: $request->input('code') ?: ''));
        if (!$verified && ($mode === 'device' || str_starts_with($deviceInput, 'DEV-'))) {
            $hash = hash('sha256', $deviceInput);
            $connect = UserConnect::where('user_id', $user->id)
                ->where('code_hash', $hash)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>=', now())
                ->first();

            if ($connect) {
                $connect->update([
                    'used_at' => now(),
                    'used_ip' => $request->ip() ?: '127.0.0.1',
                    'used_ua' => substr($request->userAgent() ?? '', 0, 255),
                ]);
                $verified = true;
                $methodUsed = "Device Enrollment Code ({$deviceInput})";
            }
        }

        // 2. Recovery Code Mode
        $recoveryInput = strtoupper(trim($request->input('recovery_code') ?: ''));
        if (!$verified && ($mode === 'recovery' || strlen($recoveryInput) >= 8)) {
            $cleanCode = str_replace('-', '', $recoveryInput);
            $recRecord = $user->recoveryCodes()->where('code_hash', $cleanCode)->whereNull('used_at')->first();
            if ($recRecord) {
                $recRecord->update(['used_at' => now()]);
                $verified = true;
                $methodUsed = 'Emergency Recovery Code';
            } else {
                // Also check user_codes
                $hash = hash('sha256', $cleanCode);
                $codeRow = UserCode::where('user_id', $user->id)->where('code_hash', $hash)->whereNull('used_at')->first();
                if ($codeRow) {
                    $codeRow->update(['used_at' => now()]);
                    $verified = true;
                    $methodUsed = 'Emergency Recovery Code';
                }
            }
        }

        // 3. Standard 6-Digit RFC-6238 TOTP Mode
        $totpInput = trim($request->input('code') ?: '');
        if (!$verified && strlen($totpInput) === 6) {
            if ($this->validateTotpCode($user->mfa_secret ?? '', $totpInput)) {
                $verified = true;
                $methodUsed = 'Authenticator App (TOTP)';
            }
        }

        if ($verified) {
            Auth::login($user, $remember);
            $request->session()->regenerate();
            [$device, $token] = UserDevice::recordDevice($user, $request, true);

            UserSecurityLog::create([
                'user_id'              => $user->id,
                'identifier_attempted' => $user->email,
                'event_type'           => 'login_success',
                'ip_address'           => $request->ip() ?: '127.0.0.1',
                'user_agent'           => $request->userAgent(),
                'payload'              => [
                    'latitude'  => $request->input('latitude'),
                    'longitude' => $request->input('longitude'),
                    'accuracy'  => $request->input('accuracy'),
                    'method'    => $methodUsed,
                ],
                'created_at'           => now(),
            ]);

            session()->forget(['mfa_pending_user_id', 'mfa_remember', 'mfa_pending_at', 'mfa_new_device_detected', 'mfa_device_summary']);

            return redirect()->intended('/admin/users/settings')
                ->cookie('laraslice_device_token', $token, 60 * 24 * 365, null, null, false, true)
                ->with('success', "Signed in successfully via {$methodUsed}.");
        }

        UserAttempt::record(
            identifier: $user->email,
            request: $request,
            reason: 'mfa_challenge_failed',
            userId: $user->id
        );

        UserSecurityLog::create([
            'user_id'              => $user->id,
            'identifier_attempted' => $user->email,
            'event_type'           => 'mfa_challenge_failed',
            'ip_address'           => $request->ip() ?: '127.0.0.1',
            'user_agent'           => $request->userAgent(),
            'payload'              => ['description' => 'Incorrect verification code or expired single-use token.'],
            'created_at'           => now(),
        ]);

        return back()->with('error', 'The one-time verification code or device enrollment token is incorrect or expired.');
    }

    /**
     * AJAX endpoint to redeem Device Enrollment Code (Enterprise Parity).
     */
    public function redeemDeviceCodeAjax(Request $request): \Illuminate\Http\JsonResponse
    {
        $userId = session('mfa_pending_user_id');
        if (!$userId) {
            return response()->json(['ok' => false, 'message' => 'Session expired. Please sign in again.'], 401);
        }

        $user = User::findOrFail($userId);
        $code = strtoupper(trim($request->input('code') ?: ''));
        if (empty($code)) {
            return response()->json(['ok' => false, 'message' => 'Please enter a device enrollment code.'], 422);
        }

        $hash = hash('sha256', $code);
        $connect = UserConnect::where('user_id', $user->id)
            ->where('code_hash', $hash)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>=', now())
            ->first();

        if (!$connect) {
            UserAttempt::record(
                identifier: $user->email,
                request: $request,
                reason: 'device_code_invalid',
                userId: $user->id
            );
            return response()->json(['ok' => false, 'message' => 'Invalid or expired device enrollment code.'], 422);
        }

        $connect->update([
            'used_at' => now(),
            'used_ip' => $request->ip() ?: '127.0.0.1',
            'used_ua' => substr($request->userAgent() ?? '', 0, 255),
        ]);

        // Authorize this device
        [$device, $token] = UserDevice::recordDevice($user, $request, true);

        UserSecurityLog::log($user->id, 'device_code_redeemed', 'success', "Device enrollment code {$code} redeemed. Browser authorized.");

        return response()->json([
            'ok'      => true,
            'message' => 'Device enrollment code accepted! This browser has been authorized.',
        ])->cookie('laraslice_device_token', $token, 60 * 24 * 365, null, null, false, true);
    }

    /**
     * Display MFA First-Time / Re-enrollment View (Dual-Mode: Passkey & TOTP).
     */
    public function showMfaEnroll(): View|RedirectResponse
    {
        $userId = session('mfa_pending_user_id');
        if (!$userId) {
            return redirect()->route('login')->with('error', 'Session timed out. Please sign in again.');
        }

        $user = User::findOrFail($userId);

        if (empty($user->mfa_secret)) {
            $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
            $secret = '';
            for ($i = 0; $i < 16; $i++) {
                $secret .= $chars[random_int(0, 31)];
            }
            $user->mfa_secret = $secret;
            $user->save();
        }

        $preferredMethod = SecurityPolicyService::preferredMethodFor($user);

        return view('auth::mfa-enroll', [
            'user'            => $user,
            'secret'          => $user->mfa_secret,
            'preferredMethod' => $preferredMethod,
        ]);
    }

    /**
     * Confirm MFA Enrollment via TOTP on First Login.
     */
    public function confirmMfaEnroll(Request $request): RedirectResponse
    {
        $userId = session('mfa_pending_user_id');
        if (!$userId) {
            return redirect()->route('login')->with('error', 'Session timed out. Please sign in again.');
        }

        $user = User::findOrFail($userId);
        $inputCode = trim($request->input('code') ?: '');

        if (!$this->validateTotpCode($user->mfa_secret ?? '', $inputCode)) {
            return back()->with('error', 'The 6-digit confirmation code does not match your Authenticator app. Please ensure your device clock is synchronized and try again.');
        }

        // Lock in confirmation
        $user->mfa_channel = 'totp';
        $user->mfa_confirmed_at = now();
        $user->save();

        // Dual-write user_factors
        UserFactor::updateOrCreate(
            ['user_id' => $user->id],
            [
                'secret_enc'   => encrypt($user->mfa_secret),
                'confirmed_at' => now(),
            ]
        );

        // Generate 8 emergency recovery backup codes
        $plainCodes = $this->generateRecoveryCodes($user);

        Auth::login($user, (bool) session('mfa_remember', false));
        $request->session()->regenerate();
        [$device, $token] = UserDevice::recordDevice($user, $request, true);

        UserSecurityLog::create([
            'user_id'              => $user->id,
            'identifier_attempted' => $user->email,
            'event_type'           => 'mfa_enrolled',
            'ip_address'           => $request->ip() ?: '127.0.0.1',
            'user_agent'           => $request->userAgent(),
            'payload'              => ['description' => 'User configured Authenticator app (TOTP) and bound device on sign-in.'],
            'created_at'           => now(),
        ]);

        session()->forget(['mfa_pending_user_id', 'mfa_remember', 'mfa_pending_at', 'mfa_new_device_detected', 'mfa_device_summary']);

        return redirect()->to('/admin/users/settings#mfa')
            ->cookie('laraslice_device_token', $token, 60 * 24 * 365, null, null, false, true)
            ->with('success', 'Authenticator app successfully activated! Your device has been bound and authorized.');
    }

    /**
     * WebAuthn Registration Options for Pending Enrollment.
     */
    public function passkeyEnrollOptions(Request $request): \Illuminate\Http\JsonResponse
    {
        $userId = session('mfa_pending_user_id');
        if (!$userId) {
            return response()->json(['error' => 'Unauthenticated session'], 401);
        }

        $user = User::findOrFail($userId);
        $service = new WebAuthnService();
        $options = $service->getRegisterArgs($user);

        return response()->json($options);
    }

    /**
     * WebAuthn Registration Verification for Pending Enrollment.
     */
    public function passkeyEnrollVerify(Request $request): \Illuminate\Http\JsonResponse
    {
        $userId = session('mfa_pending_user_id');
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Session expired. Please sign in again.'], 401);
        }

        $user = User::findOrFail($userId);

        $request->validate([
            'clientDataJSON'    => 'required|string',
            'attestationObject' => 'required|string',
            'label'             => 'nullable|string|max:100',
        ]);

        try {
            $service = new WebAuthnService();
            $label = $request->input('label', 'Primary Workstation Passkey');
            $passkey = $service->processRegister(
                $user,
                $request->input('clientDataJSON'),
                $request->input('attestationObject'),
                $label
            );

            // Dual-write user_creds (Enterprise parity)
            UserCred::updateOrCreate(
                ['user_id' => $user->id, 'credential_id' => $passkey->credential_id],
                [
                    'public_key'   => $passkey->public_key,
                    'sign_count'   => $passkey->sign_count,
                    'aaguid'       => $passkey->aaguid,
                    'label'        => $passkey->label,
                    'last_used_at' => now(),
                ]
            );

            // Generate 8 emergency recovery codes
            $plainCodes = $this->generateRecoveryCodes($user);

            // Record this device as trusted
            [$device, $token] = UserDevice::recordDevice($user, $request, true);

            Auth::login($user, (bool) session('mfa_remember', false));
            $request->session()->regenerate();

            UserSecurityLog::log($user->id, 'passkey_enrolled', 'success', "Biometric passkey '{$passkey->label}' enrolled on initial sign-in. Device bound.");

            session()->forget(['mfa_pending_user_id', 'mfa_remember', 'mfa_pending_at', 'mfa_new_device_detected', 'mfa_device_summary']);

            return response()->json([
                'success'        => true,
                'redirect'       => url('/admin/users/settings#mfa'),
                'recovery_codes' => $plainCodes,
                'message'        => 'Passkey enrolled successfully! Device bound and authorized.',
            ])->cookie('laraslice_device_token', $token, 60 * 24 * 365, null, null, false, true);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * WebAuthn Login Options (Challenge & Allowed Credentials).
     */
    public function passkeyLoginOptions(Request $request): \Illuminate\Http\JsonResponse
    {
        $userId = session('mfa_pending_user_id');
        $user = $userId ? User::find($userId) : null;

        $service = new WebAuthnService();
        $options = $service->getLoginArgs($user);

        return response()->json($options);
    }

    /**
     * WebAuthn Login Verification (Assertion).
     */
    public function passkeyLoginVerify(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'clientDataJSON'    => 'required|string',
            'authenticatorData' => 'required|string',
            'signature'         => 'required|string',
            'credentialId'      => 'required|string',
        ]);

        try {
            $service = new WebAuthnService();
            $user = $service->processLogin(
                $request->input('clientDataJSON'),
                $request->input('authenticatorData'),
                $request->input('signature'),
                $request->input('credentialId')
            );

            Auth::login($user, (bool) session('mfa_remember', false));
            $request->session()->regenerate();
            [$device, $token] = UserDevice::recordDevice($user, $request, true);

            UserSecurityLog::create([
                'user_id'              => $user->id,
                'identifier_attempted' => $user->email,
                'event_type'           => 'login_success',
                'ip_address'           => $request->ip() ?: '127.0.0.1',
                'user_agent'           => $request->userAgent(),
                'payload'              => [
                    'latitude'  => $request->input('latitude'),
                    'longitude' => $request->input('longitude'),
                    'method'    => 'FIDO2 / WebAuthn Biometric Passkey',
                ],
                'created_at'           => now(),
            ]);

            session()->forget(['mfa_pending_user_id', 'mfa_remember', 'mfa_pending_at', 'mfa_new_device_detected', 'mfa_device_summary']);

            return response()->json([
                'success'  => true,
                'redirect' => url('/admin/users/settings'),
            ])->cookie('laraslice_device_token', $token, 60 * 24 * 365, null, null, false, true);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Helper to generate 8 emergency recovery codes.
     */
    protected function generateRecoveryCodes(User $user): array
    {
        $user->recoveryCodes()->delete();
        UserCode::where('user_id', $user->id)->delete();

        $plainCodes = [];
        for ($i = 0; $i < 8; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(4)));
            $formatted = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
            $plainCodes[] = $formatted;

            $user->recoveryCodes()->create([
                'code_hash'  => $raw,
                'created_at' => now(),
            ]);

            UserCode::create([
                'user_id'    => $user->id,
                'code_hash'  => hash('sha256', $raw),
                'created_at' => now(),
            ]);
        }

        return $plainCodes;
    }

    /**
     * Validate RFC-6238 TOTP with +/- 1 time step drift window.
     */
    protected function validateTotpCode(string $secret, string $inputCode): bool
    {
        if (strlen($inputCode) !== 6 || !ctype_digit($inputCode) || empty($secret)) {
            return false;
        }

        $currentSlice = (int) floor(time() / 30);

        for ($drift = -1; $drift <= 1; $drift++) {
            if ($this->calculateTotp($secret, $currentSlice + $drift) === $inputCode) {
                return true;
            }
        }

        return false;
    }

    private function calculateTotp(string $secret, int $timeSlice): string
    {
        $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binaryString = '';
        $secret = strtoupper($secret);

        for ($i = 0; $i < strlen($secret); $i++) {
            $pos = strpos($base32Chars, $secret[$i]);
            if ($pos !== false) {
                $binaryString .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
            }
        }

        $secretBytes = '';
        for ($i = 0; $i + 8 <= strlen($binaryString); $i += 8) {
            $secretBytes .= chr(bindec(substr($binaryString, $i, 8)));
        }

        $timeBytes = pack('N*', 0) . pack('N*', $timeSlice);
        $hmac = hash_hmac('sha1', $timeBytes, $secretBytes, true);
        $offset = ord(substr($hmac, -1)) & 0x0F;
        $hashPart = substr($hmac, $offset, 4);
        $value = unpack('N', $hashPart)[1] & 0x7FFFFFFF;
        $totp = $value % 1000000;

        return str_pad((string)$totp, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Process web logout.
     */
    public function logout(): RedirectResponse
    {
        $this->authService->logoutWeb();
        return redirect()->route('login')->with('success', 'You have been successfully logged out.');
    }
}

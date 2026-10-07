<?php

namespace LaraSlice\Slices\Auth\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use LaraSlice\Slices\Auth\Services\AuthSliceService;
use LaraSlice\Slices\Auth\Services\LoginAttemptService;
use LaraSlice\Slices\Users\Models\User;
use LaraSlice\Slices\Users\Models\UserDevice;
use LaraSlice\Slices\Users\Models\UserSecurityLog;
use LaraSlice\Slices\Users\Models\UserAttempt;
use LaraSlice\Slices\Users\Models\UserConnect;
use LaraSlice\Slices\Users\Models\UserCred;
use LaraSlice\Slices\Users\Models\UserFactor;
use LaraSlice\Slices\Users\Models\UserPasskey;
use LaraSlice\Slices\Users\Services\SecurityPolicyService;
use LaraSlice\Slices\Users\Services\WebAuthnService;
use LaraSlice\Slices\Users\Services\TotpService;
use LaraSlice\Slices\Users\Services\RecoveryCodeService;
use LaraSlice\Slices\Users\Services\QrCodeService;

class AuthWebController extends Controller
{
    /** Seconds a password-verified user has to finish the MFA step. */
    public const MFA_PENDING_TTL = 300;

    private const MFA_SESSION_KEYS = ['mfa_pending_user_id', 'mfa_remember', 'mfa_pending_at', 'mfa_new_device_detected', 'mfa_device_summary'];

    public function __construct(
        protected AuthSliceService $authService,
        protected LoginAttemptService $attempts,
        protected TotpService $totp,
        protected RecoveryCodeService $recoveryCodes,
    ) {}

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

        $remember = (bool) $request->input('remember', false);
        $user = $this->attempts->verify($credentials['email'], $credentials['password'], $request);

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
                ->withCookie($this->deviceCookie($token));
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
        $user = $this->pendingUser();
        if (!$user) {
            return redirect()->route('login')->with('error', 'Session timed out. Please sign in again.');
        }

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
        $user = $this->pendingUser();
        if (!$user) {
            return redirect()->route('login')->with('error', 'Session timed out. Please sign in again.');
        }
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
        $recoveryInput = (string) ($request->input('recovery_code') ?: '');
        if (!$verified && ($mode === 'recovery' || strlen(RecoveryCodeService::normalize($recoveryInput)) >= 8)) {
            if ($this->recoveryCodes->consume($user, $recoveryInput)) {
                $verified = true;
                $methodUsed = 'Emergency Recovery Code';
            }
        }

        // 3. Standard 6-Digit RFC-6238 TOTP Mode (each code is accepted once)
        $totpInput = trim($request->input('code') ?: '');
        if (!$verified && strlen($totpInput) === 6) {
            if ($this->totp->verify($user->mfa_secret, $totpInput, $user->id)) {
                $verified = true;
                $methodUsed = 'Authenticator App (TOTP)';
            }
        }

        if ($verified && !$this->attempts->canSignIn($user)) {
            $this->forgetPendingMfa();

            return redirect()->route('login')->withErrors(['email' => 'Your account is currently inactive or locked.']);
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

            $this->forgetPendingMfa();

            return redirect()->intended('/admin/users/settings')
                ->withCookie($this->deviceCookie($token))
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
        $user = $this->pendingUser();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'Session expired. Please sign in again.'], 401);
        }
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

        UserSecurityLog::log($user->id, 'device_code_redeemed', 'success', 'Device enrollment code redeemed. Browser authorized.');

        return response()->json([
            'ok'      => true,
            'message' => 'Device enrollment code accepted! This browser has been authorized.',
        ])->withCookie($this->deviceCookie($token));
    }

    /**
     * Display MFA First-Time / Re-enrollment View (Dual-Mode: Passkey & TOTP).
     */
    public function showMfaEnroll(QrCodeService $qr): View|RedirectResponse
    {
        $user = $this->pendingUser();
        if (!$user) {
            return redirect()->route('login')->with('error', 'Session timed out. Please sign in again.');
        }

        // A password alone must never reveal or replace an existing factor
        if ($this->hasEnrolledFactor($user)) {
            return redirect()->route('login.mfa.challenge');
        }

        if (empty($user->mfa_secret)) {
            $user->mfa_secret = TotpService::generateSecret();
            $user->save();
        }

        $otpauthUri = $this->totp->provisioningUri($user->email, $user->mfa_secret, config('app.name', 'LaraSlice'));

        return view('auth::mfa-enroll', [
            'user'            => $user,
            'secret'          => $user->mfa_secret,
            'otpauthUri'      => $otpauthUri,
            'qrSvg'           => $qr->svg($otpauthUri),
            'preferredMethod' => SecurityPolicyService::preferredMethodFor($user),
        ]);
    }

    /**
     * Confirm MFA Enrollment via TOTP on First Login.
     */
    public function confirmMfaEnroll(Request $request): RedirectResponse
    {
        $user = $this->pendingUser();
        if (!$user) {
            return redirect()->route('login')->with('error', 'Session timed out. Please sign in again.');
        }

        if ($this->hasEnrolledFactor($user)) {
            return redirect()->route('login.mfa.challenge');
        }

        $inputCode = trim($request->input('code') ?: '');

        if (!$this->totp->verify($user->mfa_secret, $inputCode, $user->id)) {
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

        // Generate emergency recovery codes; the plain codes are shown once, on the next page
        $plainCodes = $this->recoveryCodes->generate($user);

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

        $this->forgetPendingMfa();

        return redirect()->to('/admin/users/settings#mfa')
            ->withCookie($this->deviceCookie($token))
            ->with('recovery_codes', $plainCodes)
            ->with('success', 'Authenticator app successfully activated! Save your recovery codes now; they will not be shown again.');
    }

    /**
     * WebAuthn Registration Options for Pending Enrollment.
     */
    public function passkeyEnrollOptions(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $this->pendingUser();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated session'], 401);
        }

        if ($this->hasEnrolledFactor($user)) {
            return response()->json(['error' => 'A second factor is already enrolled. Complete the MFA challenge instead.'], 403);
        }

        $service = new WebAuthnService();
        $options = $service->getRegisterArgs($user);

        return response()->json($options);
    }

    /**
     * WebAuthn Registration Verification for Pending Enrollment.
     */
    public function passkeyEnrollVerify(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $this->pendingUser();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Session expired. Please sign in again.'], 401);
        }

        if ($this->hasEnrolledFactor($user)) {
            return response()->json(['success' => false, 'message' => 'A second factor is already enrolled. Complete the MFA challenge instead.'], 403);
        }

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

            // Generate emergency recovery codes (returned once, in this response)
            $plainCodes = $this->recoveryCodes->generate($user);

            // Record this device as trusted
            [$device, $token] = UserDevice::recordDevice($user, $request, true);

            Auth::login($user, (bool) session('mfa_remember', false));
            $request->session()->regenerate();

            UserSecurityLog::log($user->id, 'passkey_enrolled', 'success', "Biometric passkey '{$passkey->label}' enrolled on initial sign-in. Device bound.");

            $this->forgetPendingMfa();

            return response()->json([
                'success'        => true,
                'redirect'       => url('/admin/users/settings#mfa'),
                'recovery_codes' => $plainCodes,
                'message'        => 'Passkey enrolled successfully! Device bound and authorized.',
            ])->withCookie($this->deviceCookie($token));

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'The passkey could not be registered. Please try again.',
            ], 422);
        }
    }

    /**
     * WebAuthn Login Options (Challenge & Allowed Credentials).
     */
    public function passkeyLoginOptions(Request $request): \Illuminate\Http\JsonResponse
    {
        // Username-first: offer only the pending (or named) account's credentials.
        // With neither, the browser falls back to discoverable passkeys.
        $user = $this->pendingUser();
        if (!$user && $request->filled('email')) {
            $user = $this->attempts->findUser((string) $request->query('email'));
        }

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

            $pending = $this->pendingUser();
            if ($pending && $pending->id !== $user->id) {
                return response()->json(['success' => false, 'message' => 'This passkey belongs to a different account.'], 403);
            }

            if (!$this->attempts->canSignIn($user)) {
                return response()->json(['success' => false, 'message' => 'Your account is currently inactive or locked.'], 403);
            }

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

            $this->forgetPendingMfa();

            return response()->json([
                'success'  => true,
                'redirect' => url('/admin/users/settings'),
            ])->withCookie($this->deviceCookie($token));

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Passkey verification failed. Please try again.',
            ], 422);
        }
    }

    /**
     * The password-verified user awaiting MFA, if that step has not expired.
     */
    protected function pendingUser(): ?User
    {
        $userId = session('mfa_pending_user_id');
        $startedAt = (int) session('mfa_pending_at', 0);

        if (!$userId || $startedAt < now()->timestamp - self::MFA_PENDING_TTL) {
            if ($userId) {
                $this->forgetPendingMfa();
            }

            return null;
        }

        return User::find($userId);
    }

    protected function forgetPendingMfa(): void
    {
        session()->forget(self::MFA_SESSION_KEYS);
    }

    protected function hasEnrolledFactor(User $user): bool
    {
        return $user->passkeys()->whereNull('revoked_at')->exists()
            || (!empty($user->mfa_confirmed_at) && !empty($user->mfa_secret));
    }

    /**
     * Long-lived, HTTP-only trusted-device cookie that follows the session "secure" setting.
     */
    protected function deviceCookie(string $token): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie('laraslice_device_token', $token, 60 * 24 * 365, null, null, config('session.secure'), true, false, config('session.same_site', 'lax'));
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

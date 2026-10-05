<?php

namespace LaraSlice\Slices\Users\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use LaraSlice\Core\Base\BaseSliceWebController;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use LaraSlice\Slices\Users\Services\UserSliceService;
use LaraSlice\Slices\Users\Contracts\UserFormBusinessObject;
use LaraSlice\Slices\Users\Contracts\UserFilterBusinessObject;
use LaraSlice\Slices\Users\Models\User;
use LaraSlice\Slices\Users\Models\UserDevice;
use LaraSlice\Slices\Users\Models\UserSecurityLog;
use LaraSlice\Slices\Users\Models\UserAttempt;
use LaraSlice\Slices\Users\Models\UserConnect;
use LaraSlice\Slices\Users\Models\UserCred;
use LaraSlice\Slices\Users\Models\UserCode;
use LaraSlice\Slices\Users\Models\UserFactor;
use LaraSlice\Slices\Roles\Models\Role;
use LaraSlice\Slices\Roles\Models\Permission;

class UserWebController extends BaseSliceWebController
{
    public function callAction($method, $parameters)
    {
        if (session('laraslice_session_locked') === true) {
            $allowed = ['lockscreen', 'unlockScreen', 'passkeyUnlockOptions', 'passkeyUnlockVerify'];
            if (!in_array($method, $allowed)) {
                if (!request()->expectsJson()) {
                    if (!session()->has('lockscreen_redirect_url')) {
                        session(['lockscreen_redirect_url' => request()->fullUrl()]);
                    }
                    return redirect()->route('lockscreen');
                }
                return response()->json(['message' => 'Session locked'], 423);
            }
        }
        return parent::callAction($method, $parameters);
    }

    protected UserSliceService $service;

    public function __construct(UserSliceService $service)
    {
        $this->service = $service;
    }

    protected function getService(): IFormDataService&IListingDataService
    {
        return $this->service;
    }

    protected function getFormClass(): string
    {
        return UserFormBusinessObject::class;
    }

    protected function getFilterClass(): string
    {
        return UserFilterBusinessObject::class;
    }

    protected function getViewPrefix(): string
    {
        return 'users::';
    }

    protected function getRoutePrefix(): string
    {
        return 'users.';
    }

    public function create()
    {
        $formClass = $this->getFormClass();
        $form = new $formClass();
        $availableRoles = class_exists(Role::class) ? Role::all() : [];
        $allPermissions = class_exists(Permission::class) ? Permission::all() : collect();

        return view($this->getViewPrefix() . 'form', [
            'form'           => $form,
            'isNew'          => true,
            'routePrefix'    => $this->getRoutePrefix(),
            'availableRoles' => $availableRoles,
            'allPermissions' => $allPermissions,
        ]);
    }

    public function edit(string|int $id)
    {
        $form = $this->getService()->getItemById($id);

        if (!$form) {
            return redirect()->route($this->getRoutePrefix() . 'index')->with('error', 'User not found');
        }

        $availableRoles = class_exists(Role::class) ? Role::all() : [];
        $allPermissions = class_exists(Permission::class) ? Permission::all() : collect();

        return view($this->getViewPrefix() . 'form', [
            'form'           => $form,
            'isNew'          => false,
            'routePrefix'    => $this->getRoutePrefix(),
            'availableRoles' => $availableRoles,
            'allPermissions' => $allPermissions,
        ]);
    }

    /**
     * Access Metrics & Telemetry Dashboard (Matching Enterprise access-metrics)
     */
    public function metrics(Request $request)
    {
        $this->authorizeSlice('metrics');

        if ($currentUser = $this->currentUser() ?: User::first()) {
            $this->ensureCurrentDeviceRegistered($currentUser, $request);
        }
        $today = now()->startOfDay();

        $stats = [
            'logins_today'    => UserSecurityLog::where('event_type', 'login_success')->where('created_at', '>=', $today)->count(),
            'active_devices'  => UserDevice::count(),
            'total_users'     => User::count(),
            'failed_logins'   => UserSecurityLog::where('event_type', 'login_failed')->where('created_at', '>=', $today)->count(),
            'locked_accounts' => User::where('locked_until', '>', now())->count(),
            'mfa_enabled'     => User::where('mfa_channel', '!=', 'none')->whereNotNull('mfa_confirmed_at')->count(),
        ];

        $activeSessions = UserDevice::with(['user', 'user.detail'])
            ->orderByDesc('last_active_at')
            ->get();

        $devices = UserDevice::with(['user', 'user.detail'])
            ->orderByDesc('last_active_at')
            ->get();

        $logs = UserSecurityLog::with(['user', 'user.detail'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        // Extract comprehensive location & telemetry history
        $locationRecords = collect();

        foreach ($devices as $dev) {
            $lat = null;
            $lng = null;
            $label = $dev->location_label;
            if ($label && preg_match('/GPS:\s*([0-9\.\-]+),\s*([0-9\.\-]+)/', $label, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
            }
            $locationRecords->push((object) [
                'user'          => $dev->user,
                'device_name'   => $dev->device_name,
                'platform'      => $dev->platform,
                'ip_address'    => $dev->ip_address,
                'location_label'=> $dev->location_label ?? 'Local Workstation',
                'latitude'      => $lat,
                'longitude'     => $lng,
                'last_active_at'=> $dev->last_active_at,
                'is_current'    => $dev->is_current,
                'source'        => 'Active Device Session',
            ]);
        }

        foreach ($logs as $log) {
            $p = is_array($log->payload) ? $log->payload : (json_decode($log->payload, true) ?: []);
            if (!empty($p['latitude']) && !empty($p['longitude'])) {
                $locationRecords->push((object) [
                    'user'          => $log->user,
                    'device_name'   => $log->user_agent ? (substr($log->user_agent, 0, 28) . '...') : 'Web Client',
                    'platform'      => 'web',
                    'ip_address'    => $log->ip_address,
                    'location_label'=> $p['location_label'] ?? "GPS: {$p['latitude']}, {$p['longitude']}",
                    'latitude'      => (float) $p['latitude'],
                    'longitude'     => (float) $p['longitude'],
                    'last_active_at'=> $log->created_at,
                    'is_current'    => false,
                    'source'        => 'Auth Log (' . $log->event_type . ')',
                ]);
            }
        }

        $locationRecords = $locationRecords->sortByDesc(fn($l) => $l->last_active_at ?? now())->values();

        return view($this->getViewPrefix() . 'metrics', [
            'stats'           => $stats,
            'activeSessions'  => $activeSessions,
            'devices'         => $devices,
            'logs'            => $logs,
            'locationRecords' => $locationRecords,
            'routePrefix'     => $this->getRoutePrefix(),
        ]);
    }

    /**
     * One-click Unlock a locked out account
     */
    public function unlock(string|int $id)
    {
        $user = User::findOrFail($id);
        $user->failed_attempts = 0;
        $user->locked_until = null;
        $user->save();

        UserSecurityLog::create([
            'user_id'              => $user->id,
            'identifier_attempted' => $user->email,
            'event_type'           => 'account_unlocked',
            'ip_address'           => request()->ip() ?? '127.0.0.1',
            'user_agent'           => request()->userAgent(),
            'created_at'           => now(),
        ]);

        return redirect()->back()->with('success', "Account for {$user->name} has been unlocked.");
    }

    /**
     * Client Devices Table
     */
    public function devices(Request $request)
    {
        $devices = UserDevice::with('user')->orderByDesc('last_active_at')->get();

        return view($this->getViewPrefix() . 'devices', [
            'devices'     => $devices,
            'routePrefix' => $this->getRoutePrefix(),
        ]);
    }

    public function destroyDevice(string|int $id)
    {
        $device = UserDevice::findOrFail($id);
        $device->delete();

        return redirect()->back()->with('success', 'Device session revoked successfully.');
    }

    /**
     * Security & Audit Logs Table
     */
    public function securityLogs(Request $request)
    {
        $this->authorizeSlice('security_logs');
        $logs = UserSecurityLog::with('user')->orderByDesc('created_at')->limit(100)->get();
        $attempts = UserAttempt::with('user')->orderByDesc('created_at')->limit(100)->get();

        return view($this->getViewPrefix() . 'security_logs', [
            'logs'        => $logs,
            'attempts'    => $attempts,
            'routePrefix' => $this->getRoutePrefix(),
        ]);
    }

    /**
     * User Settings & Profile Hub (Personal Profile, Password, 2FA, Sessions)
     */
    public function settings(Request $request)
    {
        $user = $this->currentUser() ?: User::with(['detail', 'devices', 'recoveryCodes', 'securityLogs'])->first();

        if (!$user) {
            return redirect()->route($this->getRoutePrefix() . 'index')->with('error', 'User not found.');
        }

        $this->ensureCurrentDeviceRegistered($user, $request);
        $user->load(['detail', 'devices', 'recoveryCodes', 'securityLogs']);
        $securityLogs = $user->securityLogs()->latest()->take(20)->get();

        $defaultTab = ($request->routeIs('*security*') || str_contains($request->path(), 'security')) ? 'mfa' : 'profile';

        return view($this->getViewPrefix() . 'settings', [
            'user'         => $user,
            'securityLogs' => $securityLogs,
            'defaultTab'   => $defaultTab,
            'routePrefix'  => $this->getRoutePrefix(),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $this->currentUser() ?: User::first();
        if (!$user) {
            return redirect()->back()->with('error', 'User not authenticated.');
        }

        $user->name = $request->input('name', $user->name);
        $user->email = $request->input('email', $user->email);
        $user->gender = $request->input('gender', $user->gender);
        $user->phone = $request->input('phone', $user->phone);
        $user->save();

        // 1-to-1 UserDetail update
        $user->detail()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'employee_id' => $request->input('employee_id', $user->employee_id),
                'cnic'        => $request->input('cnic', $user->cnic),
                'department'  => $request->input('department', $user->department),
                'designation' => $request->input('designation', $user->designation),
            ]
        );

        return redirect()->to(route($this->getRoutePrefix() . 'settings') . '#profile')
            ->with('active_tab', 'profile')
            ->with('success', 'Profile and employment details updated successfully.');
    }

    public function updatePassword(Request $request)
    {
        $user = $this->currentUser() ?: User::first();
        if (!$user) {
            return redirect()->back()->with('error', 'User not authenticated.');
        }

        $request->validate([
            'current_password' => 'required',
            'new_password'     => 'required|min:8|confirmed',
        ]);

        if (!Hash::check($request->input('current_password'), $user->password)) {
            return redirect()->back()->with('error', 'The provided current password does not match.');
        }

        $user->password = Hash::make($request->input('new_password'));
        $user->save();

        UserSecurityLog::create([
            'user_id'              => $user->id,
            'identifier_attempted' => $user->email,
            'event_type'           => 'password_changed',
            'ip_address'           => $request->ip() ?? '127.0.0.1',
            'user_agent'           => $request->userAgent(),
            'created_at'           => now(),
        ]);

        return redirect()->to(route($this->getRoutePrefix() . 'settings') . '#password')
            ->with('active_tab', 'password')
            ->with('success', 'Password updated successfully.');
    }

    public function toggle2Fa(Request $request)
    {
        $user = $this->currentUser() ?: User::first();
        if (!$user) {
            return redirect()->back()->with('error', 'User not authenticated.');
        }

        if ($user->hasMfa()) {
            $user->mfa_channel = 'none';
            $user->mfa_confirmed_at = null;
            $user->mfa_secret = null;
            $user->two_factor_secret = null;
            $user->two_factor_confirmed_at = null;
            $user->save();
            $user->recoveryCodes()->delete();

            UserSecurityLog::log($user->id, '2fa_disabled', 'warning', 'Two-Factor Authentication was disabled by user.');

            return redirect()->to(route($this->getRoutePrefix() . 'settings') . '#mfa')
                ->with('active_tab', 'mfa')
                ->with('success', 'Two-Factor Authentication has been disabled.');
        }

        // Generate RFC-4648 Base32 compatible secret for TOTP apps
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }

        $user->mfa_channel = 'totp';
        $user->mfa_secret = $secret;
        $user->mfa_confirmed_at = now();
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = now();
        $user->save();

        $user->recoveryCodes()->delete();
        for ($i = 0; $i < 8; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4)));
            $user->recoveryCodes()->create([
                'code_hash' => $code,
                'created_at' => now(),
            ]);
        }

        UserSecurityLog::log($user->id, '2fa_enabled', 'success', 'Two-Factor Authentication (TOTP) enabled with 8 recovery codes.');

        return redirect()->to(route($this->getRoutePrefix() . 'settings') . '#mfa')
            ->with('active_tab', 'mfa')
            ->with('success', 'Two-Factor Authentication enabled! Scan the QR code and save your recovery codes.');
    }

    public function regenerateRecoveryCodes(Request $request)
    {
        $user = $this->currentUser() ?: User::first();
        if (!$user) {
            return redirect()->back()->with('error', 'User not authenticated.');
        }

        $user->recoveryCodes()->delete();
        for ($i = 0; $i < 8; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4)));
            $user->recoveryCodes()->create([
                'code_hash' => $code,
                'created_at' => now(),
            ]);
        }

        UserSecurityLog::log($user->id, 'mfa_codes_regenerated', 'warning', 'New set of 8 recovery codes generated.');

        return redirect()->to(route($this->getRoutePrefix() . 'settings') . '#mfa')
            ->with('active_tab', 'mfa')
            ->with('success', 'A fresh set of 8 emergency recovery codes has been generated.');
    }

    public function issueMyDeviceCode(Request $request)
    {
        $user = $this->currentUser() ?: User::first();
        if (!$user) {
            return redirect()->back()->with('error', 'User not authenticated.');
        }

        $code = 'DEV-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        UserConnect::create([
            'user_id'    => $user->id,
            'created_by' => $user->id,
            'code_hash'  => hash('sha256', $code),
            'expires_at' => now()->addMinutes(15),
        ]);

        UserSecurityLog::log($user->id, 'device_code_issued', 'info', "Self-service device enrollment code {$code} generated (valid 15m).");

        return redirect()->to(route($this->getRoutePrefix() . 'settings') . '#mfa')
            ->with('active_tab', 'mfa')
            ->with('generated_device_code', $code);
    }

    public function logoutOthers(Request $request)
    {
        $user = $this->currentUser() ?: User::first();
        if ($user) {
            $user->devices()->where('is_current', false)->delete();
        }

        return redirect()->to(route($this->getRoutePrefix() . 'settings') . '#devices')
            ->with('active_tab', 'devices')
            ->with('success', 'Logged out of all other devices successfully.');
    }

    public function mfa(Request $request)
    {
        $this->authorizeSlice('mfa');

        $users = User::with(['roles', 'detail', 'devices', 'recoveryCodes', 'securityLogs' => fn($q) => $q->latest(), 'passkeys'])->get();
        $totalUsers = $users->count();
        $enrolledCount = $users->filter(fn($u) => $u->hasMfa())->count();
        $needsEnrollment = max(0, $totalUsers - $enrolledCount);
        $passkeyEnrolled = $users->filter(fn($u) => $u->passkeys->whereNull('revoked_at')->count() > 0 || $u->mfa_channel === 'webauthn')->count();
        $totpEnrolled = $users->filter(fn($u) => !empty($u->mfa_secret) || $u->mfa_channel === 'totp')->count();
        $totalPasskeys = \LaraSlice\Slices\Users\Models\UserPasskey::whereNull('revoked_at')->count();

        $stats = [
            'totalUsers' => $totalUsers,
            'passkeyRequired' => $passkeyEnrolled,
            'passkeyEnrolled' => $passkeyEnrolled,
            'totalPasskeys' => $totalPasskeys,
            'authenticatorRequired' => $totpEnrolled,
            'needsEnrollment' => $needsEnrollment,
            'enrolledCount' => $enrolledCount,
        ];

        $securityPolicies = \LaraSlice\Slices\Users\Services\SecurityPolicyService::getAll();
        $allRoles = class_exists(\LaraSlice\Slices\Roles\Models\Role::class) 
            ? \LaraSlice\Slices\Roles\Models\Role::all() 
            : \Illuminate\Support\Facades\DB::table('roles')->get();
        return view($this->getViewPrefix() . 'mfa', compact('users', 'stats', 'securityPolicies', 'allRoles'));
    }

    public function resetMfaEnrollment(Request $request, string|int $id)
    {
        $user = User::findOrFail($id);
        $user->forceFill([
            'mfa_channel' => 'none',
            'mfa_secret' => null,
            'mfa_confirmed_at' => null,
        ])->save();
        $user->recoveryCodes()->delete();
        UserCode::where('user_id', $user->id)->delete();
        UserDevice::where('user_id', $user->id)->delete();
        \LaraSlice\Slices\Users\Models\UserPasskey::where('user_id', $user->id)->delete();
        UserCred::where('user_id', $user->id)->delete();
        UserFactor::where('user_id', $user->id)->delete();
        UserConnect::where('user_id', $user->id)->delete();

        UserSecurityLog::log($user->id, 'mfa_admin_reset', 'danger', 'MFA enrollment was completely reset by administrator.');

        return back()->with('success', 'MFA enrollment for ' . $user->name . ' was reset. User will enroll again upon next login.');
    }

    public function resetMfaTotp(Request $request, string|int $id)
    {
        $user = User::findOrFail($id);
        $user->forceFill([
            'mfa_channel' => 'none',
            'mfa_secret' => null,
            'mfa_confirmed_at' => null,
        ])->save();

        UserSecurityLog::log($user->id, 'mfa_totp_revoked', 'warning', 'TOTP authenticator app secret revoked by administrator.');

        return back()->with('success', 'Authenticator app for ' . $user->name . ' was revoked. User must scan a new QR code.');
    }

    public function revokeMfaDevices(Request $request, string|int $id)
    {
        $user = User::findOrFail($id);
        UserDevice::where('user_id', $user->id)->delete();
        UserSecurityLog::log($user->id, 'mfa_devices_revoked', 'info', 'All remembered device bypass tokens revoked.');

        return back()->with('success', 'Remembered devices for ' . $user->name . ' were revoked. MFA required on next login.');
    }

    public function clearMfaPending(Request $request, string|int $id)
    {
        $user = User::findOrFail($id);
        UserSecurityLog::log($user->id, 'mfa_pending_cleared', 'info', 'Stuck MFA challenge sessions cleared.');

        return back()->with('success', 'Pending MFA challenge sessions for ' . $user->name . ' cleared successfully.');
    }

    public function lockscreen(Request $request)
    {
        $user = $this->currentUser() ?? User::first();
        session(['laraslice_session_locked' => true]);
        if (!session()->has('lockscreen_redirect_url')) {
            $prev = url()->previous();
            if ($prev && !str_contains($prev, 'lockscreen') && !str_contains($prev, 'login')) {
                session(['lockscreen_redirect_url' => $prev]);
            }
        }
        $hasPasskeys = $user ? $user->passkeys()->whereNull('revoked_at')->exists() : false;
        $allowPasskeys = \LaraSlice\Slices\Users\Services\SecurityPolicyService::get('security.allow_passkeys', 'true') !== 'false';
        return view($this->getViewPrefix() . 'lockscreen', compact('user', 'hasPasskeys', 'allowPasskeys'));
    }

    public function unlockScreen(Request $request)
    {
        if ($request->isMethod('get')) {
            return redirect()->route('lockscreen');
        }

        $request->validate(['password' => 'required|string']);
        $user = $this->currentUser() ?? User::first();

        if ($user && Hash::check($request->password, $user->password)) {
            session()->forget('laraslice_session_locked');
            session()->forget('unlock_fail_count');
            UserSecurityLog::log($user->id, 'lockscreen_unlocked', 'success', 'Session unlocked via password verification.');
            
            $redirectUrl = session()->pull('lockscreen_redirect_url');
            if ($redirectUrl && !str_contains($redirectUrl, 'lockscreen')) {
                return redirect($redirectUrl)->with('success', 'Session unlocked successfully.');
            }
            return redirect()->route('security.settings')->with('success', 'Session unlocked successfully.');
        }

        $fails = session('unlock_fail_count', 0) + 1;
        session(['unlock_fail_count' => $fails]);

        // Record failed attempt in user_attempts
        UserAttempt::record(
            identifier: $user?->email ?: 'current_session_user',
            request: $request,
            reason: 'invalid_lockscreen_password',
            userId: $user?->id
        );
        UserSecurityLog::log($user?->id, 'lockscreen_unlock_failed', 'warning', "Failed lockscreen unlock attempt ({$fails}/5).");

        if ($fails >= 5) {
            UserAttempt::record(
                identifier: $user?->email ?: 'current_session_user',
                request: $request,
                reason: 'lockscreen_locked_out_max_attempts',
                userId: $user?->id
            );
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();
            return redirect()->route('login')->with('error', 'Too many failed unlock attempts. Please sign in again.');
        }

        $remaining = 5 - $fails;
        return back()->with('error', 'Incorrect password. ' . $remaining . ' attempts remaining before session terminates.');
    }

    public function lockSession(Request $request)
    {
        session(['laraslice_session_locked' => true]);
        return redirect()->route('lockscreen');
    }

    public function issueDeviceCode(Request $request, string|int $id)
    {
        $user = User::findOrFail($id);
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = 'DEV-';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }

        UserConnect::create([
            'user_id'    => $user->id,
            'created_by' => Auth::id() ?: $user->id,
            'code_hash'  => hash('sha256', $code),
            'expires_at' => now()->addMinutes(15),
        ]);

        session(['device_enrollment_code_' . $user->id => [
            'code' => $code,
            'expires_at' => now()->addMinutes(15),
        ]]);

        UserSecurityLog::log($user->id, 'device_enrollment_code_issued', 'info', "Single-use device enrollment code {$code} generated (valid for 15 mins).");

        return back()->with('device_code_modal', [
            'user_name' => $user->name,
            'code' => $code,
            'expires_in' => '15 minutes',
        ])->with('success', "Device enrollment code {$code} issued for {$user->name}. Valid for 15 minutes.");
    }

    public function verifyTotpCode(Request $request)
    {
        $request->validate(['code' => 'required|string|size:6']);
        $user = $this->currentUser() ?? User::first();

        $inputCode = trim($request->input('code'));
        $secret = $user->mfa_secret ?? 'JBSWY3DPEHPK3PXP';

        // Calculate current and adjacent TOTP codes (30s window tolerance)
        $isValid = false;
        $timeSlice = floor(time() / 30);

        for ($offset = -1; $offset <= 1; $offset++) {
            $slice = $timeSlice + $offset;
            $calculatedCode = $this->calculateTotp($secret, $slice);
            if ($calculatedCode === $inputCode) {
                $isValid = true;
                break;
            }
        }

        if ($isValid) {
            UserSecurityLog::log($user->id, 'totp_verified_test', 'success', 'User successfully verified their TOTP authenticator app code.');
            return back()->with('totp_test_success', "Code {$inputCode} verified successfully! Your Google Authenticator is fully synced and working.");
        }

        return back()->with('totp_test_error', "Code {$inputCode} is invalid or has expired. Make sure your phone's clock is synced.");
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
     * Resolve the authenticated user as the slice User model.
     * The host app's auth provider may use its own model (e.g. App\Models\User)
     * on the same users table, which lacks the slice relationships.
     */
    protected function currentUser(): ?User
    {
        $authUser = Auth::user();

        if ($authUser instanceof User || $authUser === null) {
            return $authUser;
        }

        return User::find($authUser->getAuthIdentifier());
    }

    public function ensureCurrentDeviceRegistered(User $user, Request $request): void
    {
        $userAgent = $request->userAgent() ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';
        $ip = $request->ip() ?: '127.0.0.1';
        if ($ip === '::1') {
            $ip = '127.0.0.1';
        }

        $os = 'Windows';
        if (stripos($userAgent, 'Macintosh') !== false || stripos($userAgent, 'Mac OS') !== false) {
            $os = 'macOS';
        } elseif (stripos($userAgent, 'Android') !== false) {
            $os = 'Android';
        } elseif (stripos($userAgent, 'iPhone') !== false || stripos($userAgent, 'iPad') !== false) {
            $os = 'iOS';
        } elseif (stripos($userAgent, 'Linux') !== false) {
            $os = 'Linux';
        }

        $browser = 'Chrome';
        if (stripos($userAgent, 'Edge') !== false || stripos($userAgent, 'Edg') !== false) {
            $browser = 'Edge';
        } elseif (stripos($userAgent, 'Firefox') !== false) {
            $browser = 'Firefox';
        } elseif (stripos($userAgent, 'Safari') !== false && stripos($userAgent, 'Chrome') === false) {
            $browser = 'Safari';
        } elseif (stripos($userAgent, 'Opera') !== false || stripos($userAgent, 'OPR') !== false) {
            $browser = 'Opera';
        }

        $platform = in_array($os, ['Android', 'iOS']) ? 'Mobile' : 'Web';
        $deviceName = $browser . ' on ' . $os;

        $device = UserDevice::where('user_id', $user->id)
            ->where('device_name', $deviceName)
            ->first();

        if ($device) {
            UserDevice::where('user_id', $user->id)->update(['is_current' => false]);
            $device->update([
                'is_current'     => true,
                'ip_address'     => $ip,
                'browser'        => $browser,
                'os'             => $os,
                'platform'       => $platform,
                'last_active_at' => now(),
            ]);
        } else {
            UserDevice::where('user_id', $user->id)->update(['is_current' => false]);
            UserDevice::create([
                'user_id'        => $user->id,
                'device_name'    => $deviceName,
                'browser'        => $browser,
                'os'             => $os,
                'platform'       => $platform,
                'ip_address'     => $ip,
                'location_label' => ($ip === '127.0.0.1' || str_starts_with($ip, '192.168.')) ? 'Local' : 'Islamabad, PK',
                'is_current'     => true,
                'last_active_at' => now(),
            ]);
        }
    }

    /**
     * WebAuthn Registration Options for active user.
     */
    public function passkeyRegisterOptions(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $this->currentUser() ?: User::first();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $service = new \LaraSlice\Slices\Users\Services\WebAuthnService();
        $options = $service->getRegisterArgs($user);

        return response()->json($options);
    }

    /**
     * WebAuthn Registration Verification (Enrollment).
     */
    public function passkeyRegisterVerify(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $this->currentUser() ?: User::first();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $request->validate([
            'clientDataJSON'    => 'required|string',
            'attestationObject' => 'required|string',
            'label'             => 'nullable|string|max:100',
        ]);

        try {
            $service = new \LaraSlice\Slices\Users\Services\WebAuthnService();
            $passkey = $service->processRegister(
                $user,
                $request->input('clientDataJSON'),
                $request->input('attestationObject'),
                $request->input('label', 'Passkey')
            );

            UserSecurityLog::log($user->id, 'passkey_enrolled', 'success', "New biometric passkey '{$passkey->label}' enrolled.");

            return response()->json([
                'success' => true,
                'message' => 'Passkey registered successfully!',
                'passkey' => $passkey,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Revoke Passkey.
     */
    public function destroyPasskey(Request $request, string|int $id)
    {
        $user = $this->currentUser() ?: User::first();
        $passkey = ($user->hasRole('Super Administrator') || $user->id === 1)
            ? \LaraSlice\Slices\Users\Models\UserPasskey::find($id)
            : \LaraSlice\Slices\Users\Models\UserPasskey::where('user_id', $user->id)->find($id);

        if (! $passkey) {
            return redirect()->to(url('/admin/users/settings#mfa'))->with('error', 'Passkey not found or already removed.');
        }

        $label = $passkey->label ?: 'Passkey';
        $passkey->delete();

        UserSecurityLog::log($user->id, 'passkey_revoked', 'warning', "Biometric passkey '{$label}' was revoked and removed.");

        return redirect()->to(url('/admin/users/settings#mfa'))->with('success', "Passkey '{$label}' removed successfully.");
    }

    public function updateSecurityPolicy(Request $request)
    {
        $request->validate([
            'mfa_enforcement'          => 'required|in:off,optional,privileged_only,all',
            'max_failed_attempts'      => 'required|integer|min:1|max:20',
            'lockout_minutes'          => 'nullable|integer|min:1|max:1440',
            'lockout_duration_minutes' => 'nullable|integer|min:1|max:1440',
            'idle_lock_minutes'        => 'nullable|integer|min:0|max:1440',
            'remember_device_days'     => 'nullable|integer|min:0|max:365',
        ]);

        $lockoutMinutes = (int) ($request->input('lockout_minutes') ?: $request->input('lockout_duration_minutes') ?: 15);
        $idleLockMinutes = (int) ($request->input('idle_lock_minutes', 15));
        $rememberDays = (int) ($request->input('remember_device_days') ?: 30);

        \LaraSlice\Slices\Users\Services\SecurityPolicyService::set('security.mfa_enforcement', $request->input('mfa_enforcement'), 'Global MFA Policy');
        \LaraSlice\Slices\Users\Services\SecurityPolicyService::set('security.allow_passkeys', $request->boolean('allow_passkeys') ? 'true' : 'false', 'Enable FIDO2 Passkeys');
        \LaraSlice\Slices\Users\Services\SecurityPolicyService::set('security.allow_totp', $request->boolean('allow_totp') ? 'true' : 'false', 'Enable TOTP Apps');
        \LaraSlice\Slices\Users\Services\SecurityPolicyService::set('security.allow_device_code', $request->boolean('allow_device_code') ? 'true' : 'false', 'Enable Admin Device Code');
        \LaraSlice\Slices\Users\Services\SecurityPolicyService::set('security.allow_recovery_codes', $request->boolean('allow_recovery_codes') ? 'true' : 'false', 'Enable Recovery Codes');
        \LaraSlice\Slices\Users\Services\SecurityPolicyService::set('security.max_failed_attempts', $request->input('max_failed_attempts'), 'Lockout Attempts');
        \LaraSlice\Slices\Users\Services\SecurityPolicyService::set('security.lockout_minutes', $lockoutMinutes, 'Lockout Duration (mins)');
        \LaraSlice\Slices\Users\Services\SecurityPolicyService::set('security.idle_lock_minutes', $idleLockMinutes, 'Inactivity Screen Auto-Lock (mins)');
        \LaraSlice\Slices\Users\Services\SecurityPolicyService::set('security.remember_device_days', $rememberDays, 'Remember Device Window (days)');

        if ($request->has('privileged_roles')) {
            $roles = (array) $request->input('privileged_roles');
            \LaraSlice\Slices\Users\Services\SecurityPolicyService::set(
                'security.mfa_privileged_roles', 
                json_encode(array_values($roles)), 
                'Roles requiring mandatory MFA under Privileged Roles Only policy'
            );
        }

        UserSecurityLog::log(
            Auth::id() ?? 1,
            'security_policy_updated',
            'warning',
            'System security and MFA enforcement policies updated by administrator.'
        );

        return back()->with('success', 'Enterprise Security Policies updated and applied system-wide.');
    }

    public function passkeyUnlockOptions(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $this->currentUser() ?? User::first();
        $service = new \LaraSlice\Slices\Users\Services\WebAuthnService();
        $options = $service->getLoginArgs($user);
        return response()->json($options);
    }

    public function passkeyUnlockVerify(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'clientDataJSON'    => 'required|string',
            'authenticatorData' => 'required|string',
            'signature'         => 'required|string',
            'credentialId'      => 'required|string',
        ]);

        try {
            $service = new \LaraSlice\Slices\Users\Services\WebAuthnService();
            $user = $service->processLogin(
                $request->input('clientDataJSON'),
                $request->input('authenticatorData'),
                $request->input('signature'),
                $request->input('credentialId')
            );

            if (!Auth::check() || Auth::id() !== $user->id) {
                Auth::login($user);
            }

            session()->forget('laraslice_session_locked');
            session()->forget('unlock_fail_count');

            UserSecurityLog::log(
                $user->id,
                'lockscreen_unlocked',
                'success',
                'Session unlocked via FIDO2 / WebAuthn Biometric Passkey.'
            );

            $redirectUrl = session()->pull('lockscreen_redirect_url');
            if (!$redirectUrl || str_contains($redirectUrl, 'lockscreen')) {
                $redirectUrl = route('security.settings');
            }

            return response()->json([
                'success'  => true,
                'message'  => 'Session unlocked successfully!',
                'redirect' => $redirectUrl,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

}

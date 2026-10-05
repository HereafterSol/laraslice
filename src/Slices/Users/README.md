# Users Slice (Enterprise Identity & Security Architecture)

The **Users** slice provides enterprise-grade identity, multi-persona profile aggregates, session forensics, and multi-factor hardware security.

---

## 1. Database Architecture & Table Roster

The slice manages a primary aggregate root and 10 child security/identity entities (Enterprise Architecture Parity):

| Table | Model | Responsibility |
|---|---|---|
| `users` | `User` | **Aggregate Root**: Authentication, email, password hash, status, MFA channel. |
| `user_details` | `UserDetail` | 1-to-1 profile aggregate: Govt BPS/CNIC, corporate EmpID, department, address. |
| `user_devices` | `UserDevice` | Active device sessions, platform (`Web`/`App`), browser & OS fingerprinting, IP, geolocation. |
| `user_connect` | `UserConnect` | **Device Enrollment Codes** (`XXXX-XXXX-XXXX`), 10-minute TTL, single-use hardware pairing. |
| `user_creds` | `UserCred` | **WebAuthn / FIDO2 Passkeys** credentials, public keys, and authenticator counters. |
| `user_factors` | `UserFactor` | **TOTP Authenticator App** secrets (encrypted) for Google/Microsoft Authenticator. |
| `user_codes` | `UserCode` | **Emergency Recovery Codes** (SHA-256 hashed single-use backup keys). |
| `user_attempts` | `UserAttempt` | Brute-force tracking, IP & User-Agent forensics, and lockout thresholds. |
| `user_checks` | `UserCheck` | Ephemeral MFA login challenges and pending step tokens. |
| `user_push_devices` | `UserPushDevice` | FCM Push Notification tokens for web and mobile devices. |
| `user_resets` | `UserReset` | Password reset tickets with channel (Web vs App), IP, and audit tracking. |
| `user_tokens` | `UserToken` | Persistent Remember-Me split tokens (selector + verifier hash). |

---

## 2. Full Lifecycle Userstamps & Temporal Audit

Every transactional model in LaraSlice leverages the `$table->auditStamps()` macro:
```php
$table->auditStamps();
```
* **Tier 1 (Intrinsic Stamping)**: Automatically sets `created_by`, `created_at`, `updated_by`, `updated_at`, `deleted_by`, and `deleted_at` on Eloquent lifecycle events via `AuditableSlice` and `SoftDeletes`.
* **Tier 2 (Immutable Event Ledger)**: Captures detailed attribute diffs (`old_values`, `new_values`), actor ID, email, IP, and User-Agent in `laraslice_audit_logs`.

---

## 3. Audit Scaling & Pruning Configuration

In `config/laraslice.php`:
```php
'audit' => [
    'enabled'        => env('LARASLICE_AUDIT_ENABLED', true),
    'retention_days' => (int) env('LARASLICE_AUDIT_RETENTION_DAYS', 90),
    'auto_prune'     => env('LARASLICE_AUDIT_AUTO_PRUNE', false), // By default NO logs delete automatically
],
```

### CLI Command:
```bash
# Prune audit records older than configured default (90 days)
php artisan laraslice:audit:prune

# Custom retention period
php artisan laraslice:audit:prune --days=30

# Force non-interactive run for cron
php artisan laraslice:audit:prune --days=180 --force
```

### Slice Studio UI:
In Slice Studio (`/laraslice/wizard`), administrators can:
* Search logs by entity ID, actor email, IP address, or payload diff.
* Filter by action pills (`All`, `Created`, `Updated`, `Deleted`).
* Click **Prune Logs** to execute an active on-demand retention prune.
---

## 4. Migration History & Schema Evolution

The slice follows strict declarative migrations tracked both in Laravel's `migrations` table and in Slice Studio's **Version History & Migration Changelog**:

* **v1.1.0**: `2026_10_03_220000_enhance_users_table_and_security_devices.php` - Initial enterprise fields (`gender`, `phone`, `dob`, `employee_id`, `department`, `designation`, `cnic`), device tracking, and security audit tables.
* **v1.2.0**: `2026_10_03_230000_create_user_details_table_and_split_profile.php` - Split `users` (pure authentication aggregate) and `user_details` (1-to-1 extended profile aggregate).
* **v1.3.0**: `2026_10_04_210000_create_enterprise_identity_and_device_trust_tables.php` - Enterprise security parity scaffolding: `user_connect`, `user_creds`, `user_factors`, `user_codes`, `user_attempts`, `user_checks`, `user_push_devices`, `user_resets`, `user_tokens`, and `user_devices`.
* **v1.3.1**: `2026_10_05_000000_add_device_trust_columns_to_user_devices_table.php` - Backfills `session_id`, `device_label`, and `is_trusted` on `user_devices` when the v1.1.0 table already existed (fixes `Unknown column 'session_id'` on device enrollment).

---

## 5. Automated Security Attempt Telemetry (`user_attempts`)

Every authentication attempt across Web, API, and Session Lockscreen is automatically tracked in the `user_attempts` table:
* **Trigger Points**:
  - Failed Web login (`AuthWebController@login` -> `invalid_password`, `unknown_account`, `login_locked_attempt`).
  - Failed Lockscreen unlock (`UserWebController@unlockScreen` -> `invalid_lockscreen_password`, `lockscreen_locked_out_max_attempts`).
  - Failed MFA challenges (`AuthWebController@verifyMfaChallenge` -> `mfa_challenge_failed`).
  - Failed API token authentication (`AuthApiController@login` -> `api_invalid_credentials`).
* **Telemetry Collected**:
  - `user_id`: Target user (if matched or session user).
  - `identifier_attempted`: Email, username, or CNIC.
  - `channel`: `Web` or `API`.
  - `ip_address`: Normalized IPv4 / IPv6 client address.
  - `user_agent`: Full browser agent string.
  - `browser`: Detected browser engine (`Chrome`, `Edge`, `Firefox`, `Safari`, `Opera`).
  - `os`: Operating system (`Windows 10/11`, `macOS`, `Linux`, `Android`, `iOS`).
  - `device_type`: `Desktop`, `Mobile`, or `Tablet`.
  - `location_label`: Workstation classification or geolocation string.
  - `reason`: Machine-readable failure reason with failure count.
  - `created_at`: High-precision timestamp.

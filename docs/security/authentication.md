# Authentication and Session Security

## Scope

Authentication is implemented through Laravel Fortify and Laravel's session
guard. The application does not maintain a separate custom authentication
system.

## Enabled authentication capabilities

- Password registration
- Password login
- Password reset
- Email verification
- Two-factor authentication
- Passkeys
- Remember-me authentication

## Password policy

New and changed passwords must:

- contain at least 15 characters;
- contain no more than 128 characters;
- satisfy password confirmation;
- pass the compromised-password check in production.

The application allows passphrases and does not require arbitrary uppercase,
number, or symbol composition.

Existing passwords remain valid until the user changes or resets them.

## Login throttling

Password login attempts are limited to five attempts per minute for each
normalized email-address and client-IP combination.

Two-factor authentication and passkey endpoints have separate throttles.

## Session policy

- Session driver: database
- Session lifetime: 120 minutes of inactivity
- Session payload encryption: enabled
- Cookie HTTP-only: enabled
- SameSite: lax
- Cookie Secure flag: required in production
- Serialization: JSON

Successful authentication regenerates the session identifier.

Logout invalidates the active session and regenerates the CSRF token.

Changing a password invalidates authenticated sessions on other devices.

## Verified routes

The User model implements `MustVerifyEmail`.

Application routes containing project or security data require:

- `auth`
- `auth.session`
- `verified`

## Production requirements

Production must use HTTPS and set:

```dotenv
APP_ENV=production
APP_DEBUG=false
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
```

Authentication secrets, session identifiers, passwords, password-reset tokens,
two-factor secrets, passkey credentials, and complete request payloads must not
be written to application logs.

Deferred work

Organization membership, project authorization, tenant isolation, privileged
command rate limits, and append-only audit events are implemented in AIOS-012
through AIOS-020.

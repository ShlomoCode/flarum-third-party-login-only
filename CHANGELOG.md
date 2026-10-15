# Changelog

## Unreleased

- Require Flarum 1.8.20 or later in the 1.x series for native OAuth response helper compatibility.
- Identify Google users by their trusted email address without saving a Google login-provider link.
- Keep native registration and invitation checks for new Google users; reject registration tokens issued before this policy.

## 0.1.2.2 - 2026-10-14

- Allow administrators to create Google-only accounts with a name and email, without an invitation code or chosen password.
- Keep invitation checks for self-registration and block tokenless registration by guests and ordinary members.

## 0.1.2.1 - 2026-10-13

- Restore the Google login form on Private Facade pages by preserving the native footer array contract.

## 0.1.2 - 2026-10-13

- Block password authentication through `POST /api/token`.
- Return HTTP 403 for password reset submissions using a compatible request handler.
- Preserve native route names used by CSRF middleware and other extensions.
- Add integration checks for password blocking and OAuth compatibility.

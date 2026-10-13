# Changelog

## 0.1.2 - 2026-10-13

- Block password authentication through `POST /api/token`.
- Return HTTP 403 for password reset submissions using a compatible request handler.
- Preserve native route names used by CSRF middleware and other extensions.
- Add integration checks for password blocking and OAuth compatibility.

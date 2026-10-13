# 📰 Flarum Third Party Login only 
Only allow login and sign ups from oAuth extensions.

If you have any feedback, let us know! Do you experience issues? You can report issues on the Flarum Forum or on [GitHub](https://github.com/v17development/flarum-third-party-login-only).

> :warning: **Be careful**: This extension completely disables the Login and Registration forms.

##  ℹ️ When to use this extension?
To be clear, this extension **does not add** oAuth to your Flarum instance but instead disables the `Sign In` and `Sign Up` password-forms for a better authenticating flow user experience. Use [FoF Passport](https://discuss.flarum.org/d/5203) or [FoF oAuth](https://discuss.flarum.org/d/25182) to add oAuth to your forum.

This extension is suitable for organizations or communities that only want to allow sign-in and sign-ups with external oAuth accounts/providers.

## 📥 Installation
This fork preserves the original package name. Configure its repository before installing it:
```
composer config repositories.third-party-login-only vcs https://github.com/ShlomoCode/flarum-third-party-login-only
composer require v17development/flarum-third-party-login-only:0.1.2
```

Before enabling the extension, verify that an administrator can sign in through an enabled OAuth provider. Password login is blocked for administrators too. To require Google, enable only Google in your OAuth extension; this extension does not select providers.

Existing sessions and access tokens remain valid. This extension does not implement invitations or close OAuth registration. Configure invitation requirements separately.

If you lose access, disable the extension through SSH:

```
php flarum extension:disable v17development-third-party-login-only
php flarum cache:clear
```

## ♻ Updating
Run the following command on your server to update the plugin
```
composer update v17development/flarum-third-party-login-only
```

## 🦸 Features
- Disables password login through `POST /login` and `POST /api/token`, and removes the login form
- Disables `/register` if there's no oAuth-sign-in `token` and removes registration form
- Disables `/reset` & `/api/forgot` routes and removes password reset form
- Managing new signups via oAuth extensions is still possible with the `Allow signups` permission
- Makes it possible to add a welcome message for new users

## Google email identity

This feature requires Flarum 1.8.20 or later in the 1.x series, which exposes the native OAuth response helpers used by the factory.

Google sign-in matches the trusted email supplied by FoF OAuth to the forum user's email. An existing account can sign in without a Google provider link. Google identifiers in old provider links are ignored and are not deleted by this change.

New users still complete Flarum's registration flow and any configured invitation checks. Their temporary registration token keeps the trusted email and profile fields, but its provider identifier is empty and its payload contains only an optional avatar URL. Completing registration does not create a Google provider link. Registration tokens issued under the previous linking policy are rejected; the user must restart Google sign-in.

Google accounts for which FoF OAuth only suggests an email, rather than providing a trusted email, are rejected. Changing a user's Google email requires the matching forum email to be updated separately. Other OAuth providers retain their native behavior. No Google credentials, invitation configuration, existing sessions, or user groups are changed by this feature.

## Integration checks

Run the checks on a disposable Flarum 1.8 installation with this extension and FoF OAuth enabled. Enable sign-ups for the OAuth registration control. The script creates and removes synthetic users with `forum.test` addresses; do not run it on a production forum.

```
FLARUM_SITE_PATH=/path/to/forum/site.php php tests/integration/password-login.php
```

The checks cover password login, password reset, existing authenticated tokens, and native Google registration and login callbacks. They do not contact Google's authorization service or verify the browser consent flow. The fork is tested with Flarum 1.8.20.

To check mandatory invitations, enable FoF Doorman 2.0.0 with public registration disabled on a disposable site and run:

```
FLARUM_SITE_PATH=/path/to/forum/site.php php tests/integration/google-email-identity.php
```

This check uses a database transaction for synthetic accounts, registration tokens, provider links, and one-use invitations. It covers email matching, stale Google links, untrusted suggested emails, registration without a saved Google identity, invitation reuse, and unchanged non-Google login. Use the password-login check without mandatory invitations, because its registration control does not submit an invitation.

## Knowledge base articles:
- [Replacing Sign In and Sign Up button](https://community.v17.dev/knowledgebase/41)
- [Authenticating new accounts](https://community.v17.dev/knowledgebase/44)
- [Change and reset password links](https://community.v17.dev/knowledgebase/43)
- [Allow users to change their email](https://community.v17.dev/knowledgebase/42)

## 🔨 Works with:
- [FriendsOfFlarum Passport](https://discuss.flarum.org/d/5203)
- [FriendsOfFlarum oAuth](https://discuss.flarum.org/d/25182)

## 🙋 Questions, feedback?
If you have any questions related to this extension, don't hesistate and reply to this topic or [open an issue](https://github.com/v17development/flarum-third-party-login-only/issues).

## ❤️ Sponsored by Buttonizer
This extension is sponsored by [Buttonizer](https://buttonizer.pro/).

## 🖼️ Screenshots

### Sign In window
After activating this extension, the sign in form is removed, only oAuth buttons are visible. The "Forgot password" link is only visible when a "Forgot password link" is set-up.

If the `Replace Sign In and Sign Up button` setting is enabled, the `Log in` window will not be visible and automatically opens the FoF Passport oAuth sign in window.

![Sign in](https://i.imgur.com/DzMl1cx.png)

### Custom Sign Up text
If set, a custom welcome text will be visible after authenticating with new accounts.
[![Sign Up](https://i.imgur.com/cuOIadi.png)](https://imgur.com/a/ix87nkM)

### Admin settings
[![Admin settings](https://i.imgur.com/oQOsMb7.png)](https://imgur.com/a/ix87nkM)

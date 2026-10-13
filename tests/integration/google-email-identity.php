<?php
declare(strict_types=1);

use Flarum\Api\Client;
use Flarum\Forum\Auth\Registration;
use Flarum\Forum\Auth\ResponseFactory;
use Flarum\Http\AccessToken;
use Flarum\User\LoginProvider;
use Flarum\User\RegistrationToken;
use Flarum\User\User;

$sitePath = getenv('FLARUM_SITE_PATH');
if (!$sitePath || !is_file($sitePath)) throw new RuntimeException('Set FLARUM_SITE_PATH to a disposable Flarum site; never use production.');
$container = (require $sitePath)->bootApp()->getContainer();
$container->make(Flarum\Locale\LocaleManager::class);
$factory = $container->make(ResponseFactory::class);
$api = $container->make(Client::class)->withActor(new User());
$db = $container->make('db')->connection();
$db->beginTransaction();

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo 'PASS: '.$message."\n";
}
function google(ResponseFactory $factory, string $identifier, string $email, bool $trusted = true) {
    return $factory->make('google', $identifier, static function (Registration $registration) use ($email, $trusted): void {
        if ($trusted) $registration->provideTrustedEmail($email);
        else $registration->suggestEmail($email);
        $registration->setPayload(['sub' => 'SYNTHETIC-SUB', 'id' => 'SYNTHETIC-ID', 'email' => $email]);
    });
}
function responseToken($response): RegistrationToken {
    preg_match('/"token":"([^"]+)"/', (string) $response->getBody(), $matches);
    check(isset($matches[1]), 'native callback prepares registration token');
    return RegistrationToken::findOrFail($matches[1]);
}
function signup(Client $api, string $token, string $username, ?string $key = null) {
    $attributes = ['username' => $username, 'email' => 'untrusted-input@example.test', 'token' => $token];
    if ($key !== null) $attributes['fof-doorkey'] = $key;
    return $api->withBody(['data' => ['type' => 'users', 'attributes' => $attributes]])->post('/users');
}

try {
    $db->table('users')->insert(['username' => 'email_existing', 'email' => 'existing@example.test', 'password' => '', 'is_email_confirmed' => 1]);
    $existing = User::where('email', 'existing@example.test')->firstOrFail();
    $beforeLinks = LoginProvider::count();
    $response = google($factory, 'NEW-GOOGLE-SUB', 'existing@example.test');
    check(str_contains((string) $response->getBody(), '"loggedIn":true'), 'existing unlinked account logs in by trusted email');
    check($response->hasHeader('Set-Cookie'), 'native login still issues authentication cookie');
    check((int) AccessToken::orderByDesc('id')->first()->user_id === (int) $existing->id, 'login session belongs to email-matched user');
    check(LoginProvider::count() === $beforeLinks, 'existing login persists no Google link');

    $existing->loginProviders()->create(['provider' => 'google', 'identifier' => 'STALE-GOOGLE-SUB']);
    $beforeTokens = AccessToken::count();
    $registration = responseToken(google($factory, 'STALE-GOOGLE-SUB', 'changed@example.test'));
    check(AccessToken::count() === $beforeTokens, 'stale provider link cannot log in to an old email account');
    check($registration->identifier === '' && $registration->provider === 'google', 'registration token retains provider but no provider identifier');
    check(!array_key_exists('sub', $registration->payload) && !array_key_exists('id', $registration->payload), 'registration payload persists no Google identity');
    check($registration->user_attributes['email'] === 'changed@example.test', 'trusted email remains server-bound in registration token');

    try {
        google($factory, 'STALE-GOOGLE-SUB', 'existing@example.test', false);
        throw new RuntimeException('Untrusted suggested email was accepted');
    } catch (Flarum\User\Exception\PermissionDeniedException $expected) {
        check(AccessToken::count() === $beforeTokens, 'suggested email is rejected even when a legacy link exists');
    }

    check(signup($api, $registration->token, 'email_new_user')->getStatusCode() === 422, 'Google signup without required invite is rejected');
    check(signup($api, $registration->token, 'email_new_user', 'INVALID')->getStatusCode() === 422, 'Google signup with invalid invite is rejected');
    $db->table('doorkeys')->insert(['key' => 'SYNTHETIC-INVITE', 'group_id' => 3, 'max_uses' => 1, 'uses' => 0, 'activates' => 1]);
    $beforeLinks = LoginProvider::count();
    $response = signup($api, $registration->token, 'email_new_user', 'SYNTHETIC-INVITE');
    check($response->getStatusCode() === 201, 'native Google registration with invitation succeeds (HTTP '.$response->getStatusCode().')');
    $newUser = User::where('username', 'email_new_user')->firstOrFail();
    check($newUser->email === 'changed@example.test' && $newUser->is_email_confirmed, 'typed email cannot replace trusted Google email');
    check(LoginProvider::count() === $beforeLinks, 'new registration creates no Google provider link');
    check(!$newUser->isAdmin() && $newUser->groups()->count() === 0, 'registration retains ordinary member permissions');
    check((int) $db->table('doorkeys')->where('key', 'SYNTHETIC-INVITE')->value('uses') === 1, 'native invitation is consumed once');
    check(str_contains((string) google($factory, 'ANOTHER-SUB', 'changed@example.test')->getBody(), '"loggedIn":true'), 'subsequent login uses email without provider link');

    $second = responseToken(google($factory, 'SECOND-SUB', 'second@example.test'));
    check(signup($api, $second->token, 'email_second_user', 'SYNTHETIC-INVITE')->getStatusCode() === 422, 'consumed invitation cannot register another user');
    $old = RegistrationToken::generate('google', 'OLD-PERSISTED-ID', ['email' => 'oldtoken@example.test'], []);
    $old->save();
    check(signup($api, $old->token, 'old_token_user', 'SYNTHETIC-INVITE')->getStatusCode() === 403, 'old Google registration token is rejected instead of persisting a link');

    $legacy = $existing->loginProviders()->create(['provider' => 'github', 'identifier' => 'OTHER-PROVIDER-ID']);
    $response = $factory->make('github', 'OTHER-PROVIDER-ID', static function (): void { throw new RuntimeException('Linked non-Google callback should not reconfigure'); });
    check(str_contains((string) $response->getBody(), '"loggedIn":true') && $legacy->fresh() !== null, 'other providers retain native linked login behavior');
} finally {
    $db->rollBack();
}

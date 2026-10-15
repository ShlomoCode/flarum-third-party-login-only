<?php
declare(strict_types=1);

use Flarum\Api\Client;
use Flarum\Forum\Auth\Registration;
use Flarum\Forum\Auth\ResponseFactory;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\LoginProvider;
use Flarum\User\User;

$sitePath = getenv('FLARUM_SITE_PATH');
if (!$sitePath || !is_file($sitePath)) {
    throw new RuntimeException('Set FLARUM_SITE_PATH to a disposable Flarum site.');
}

$container = (require $sitePath)->bootApp()->getContainer();
$container->make(Flarum\Locale\LocaleManager::class);
$db = $container->make('db')->connection();
$settings = $container->make(SettingsRepositoryInterface::class);
$api = $container->make(Client::class);
$factory = $container->make(ResponseFactory::class);
$suffix = bin2hex(random_bytes(5));
$email = 'admin_created_'.$suffix.'@forum.test';
$username = 'admin_created_'.$suffix;
$chosenPassword = 'client-supplied-password-'.$suffix;
$db->beginTransaction();

function adminCreatedCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
}

function adminCreatedRequest(Client $api, User $actor, string $username, string $email, array $extra = [])
{
    return $api->withActor($actor)->withBody(['data' => [
        'type' => 'users',
        'attributes' => array_merge(['username' => $username, 'email' => $email], $extra),
    ]])->post('/users');
}

try {
    $settings->set('allow_sign_up', '1');
    $settings->set('fof-doorman.allowPublic', '0');

    $guest = new User();
    adminCreatedCheck(
        adminCreatedRequest($api, $guest, $username, $email)->getStatusCode() === 403,
        'guest cannot create an account without OAuth'
    );

    $member = User::register('ordinary_'.$suffix, 'ordinary_'.$suffix.'@forum.test', bin2hex(random_bytes(24)));
    $member->activate();
    $member->save();
    adminCreatedCheck(
        adminCreatedRequest($api, $member, $username, $email)->getStatusCode() === 403,
        'ordinary member cannot create an account without OAuth'
    );

    $admin = User::findOrFail(1);
    adminCreatedCheck($admin->isAdmin(), 'fixture administrator exists');
    $response = adminCreatedRequest($api, $admin, $username, $email, ['password' => $chosenPassword]);
    adminCreatedCheck($response->getStatusCode() === 201, 'administrator creates an account without an invite or OAuth token (HTTP '.$response->getStatusCode().')');

    $created = User::where('email', $email)->firstOrFail();
    adminCreatedCheck((bool) $created->is_email_confirmed, 'administrator-created account is active');
    adminCreatedCheck(!password_verify($chosenPassword, (string) $created->password), 'client-supplied password was ignored');
    adminCreatedCheck(!LoginProvider::where('user_id', $created->id)->exists(), 'administrator creation adds no provider link');

    $namedEmail = 'named_'.$suffix.'@forum.test';
    $namedResponse = $api->withActor($admin)->withBody(['data'=>['attributes'=>['nickname'=>'שלום כהן','email'=>$namedEmail]]])->post('/users');
    adminCreatedCheck($namedResponse->getStatusCode()===201, 'administrator creates account using a Hebrew display name');
    $named = User::where('email', $namedEmail)->firstOrFail();
    adminCreatedCheck($named->nickname==='שלום כהן' && str_starts_with($named->username,'member_'), 'native nickname is saved alongside a generated technical username');

    $login = $factory->make('google', 'synthetic-'.$suffix, static function (Registration $registration) use ($email): void {
        $registration->provideTrustedEmail($email);
    });
    adminCreatedCheck(str_contains((string) $login->getBody(), '"loggedIn":true'), 'trusted Google email logs into administrator-created account');

    $newEmail = 'invite_required_'.$suffix.'@forum.test';
    $registration = $factory->make('google', 'new-'.$suffix, static function (Registration $registration) use ($newEmail): void {
        $registration->provideTrustedEmail($newEmail);
        $registration->setPayload([]);
    });
    preg_match('/"token":"([^"]+)"/', (string) $registration->getBody(), $matches);
    adminCreatedCheck(isset($matches[1]), 'new Google account receives registration token');
    $guestSignup = adminCreatedRequest($api, $guest, 'invite_required_'.$suffix, $newEmail, ['token' => $matches[1]]);
    adminCreatedCheck($guestSignup->getStatusCode() === 422, 'guest Google registration still requires an invitation');
} finally {
    $db->rollBack();
}

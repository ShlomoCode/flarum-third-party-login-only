<?php

declare(strict_types=1);

use Flarum\Api\Client;
use Flarum\Forum\Auth\ResponseFactory;
use Flarum\Http\AccessToken;
use Flarum\Http\SessionAccessToken;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\RegistrationToken;
use Flarum\User\User;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\Uri;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

$sitePath = getenv('FLARUM_SITE_PATH') ?: '/var/www/html/site.php';

if (! is_file($sitePath)) {
    fwrite(STDERR, "Flarum site file not found: $sitePath\n");
    exit(2);
}

$site = require $sitePath;
$app = $site->bootApp();
$container = $app->getContainer();
$settings = $container->make(SettingsRepositoryInterface::class);

if (! $settings->get('allow_sign_up')) {
    fwrite(STDERR, "This integration test requires allow_sign_up enabled.\n");
    exit(2);
}

$handler = $app->getRequestHandler();
$client = $container->make(Client::class)->withActor(new User());
define('TEST_BASE_URL', rtrim((string) $container->make('flarum.config')->url(), '/'));
$suffix = bin2hex(random_bytes(6));
$username = 'login_test_'.$suffix;
$email = $username.'@forum.test';
$password = bin2hex(random_bytes(20));
$oauthUsername = 'oauth_test_'.$suffix;
$oauthEmail = $oauthUsername.'@forum.test';
$providerId = 'integration-'.$suffix;
$fixture = null;
$oauthUser = null;
$registrationToken = null;
$failures = [];

function request(RequestHandlerInterface $handler, string $method, string $path, array $body = [], ?string $accessToken = null, array $cookies = []): ResponseInterface
{
    $request = ServerRequestFactory::fromGlobals(null, [], $body)
        ->withMethod($method)
        ->withUri(new Uri(TEST_BASE_URL.$path))
        ->withCookieParams($cookies);

    if ($accessToken !== null) {
        $request = $request->withHeader('Authorization', 'Token '.$accessToken);
    }

    return $handler->handle($request);
}

function expectStatus(string $case, ResponseInterface $response, int $expected, array &$failures): void
{
    $actual = $response->getStatusCode();

    if ($actual === $expected) {
        echo "PASS $case ($actual)\n";
    } else {
        $location = $response->getHeaderLine('Location');
        $failures[] = "$case: expected $expected, got $actual".($location ? "; redirected to $location" : '');
        echo "FAIL $case ($actual)\n";
    }
}

try {
    $fixture = User::register($username, $email, $password);
    $fixture->activate();
    $fixture->save();

    $existingToken = SessionAccessToken::generate($fixture->id);

    expectStatus(
        'valid password cannot mint an API token',
        request($handler, 'POST', '/api/token', [
            'identification' => $email,
            'password' => $password,
        ]),
        403,
        $failures
    );

    expectStatus(
        'remembered password login cannot mint an API token',
        request($handler, 'POST', '/api/token', [
            'identification' => $email,
            'password' => $password,
            'remember' => true,
        ]),
        403,
        $failures
    );

    expectStatus(
        'guest cannot list access tokens',
        request($handler, 'GET', '/api/access-tokens'),
        401,
        $failures
    );

    expectStatus(
        'existing authenticated token can reach protected API',
        request($handler, 'GET', '/api/access-tokens', [], $existingToken->token),
        200,
        $failures
    );

    $forumPage = request($handler, 'GET', '/login');
    $sessionCookie = explode(';', $forumPage->getHeaderLine('Set-Cookie'), 2)[0];
    $cookieParts = explode('=', $sessionCookie, 2);
    $csrfToken = $forumPage->getHeaderLine('X-CSRF-Token');

    if (count($cookieParts) === 2 && $csrfToken !== '') {
        $cookies = [$cookieParts[0] => $cookieParts[1]];

        expectStatus(
            'password login submission is forbidden',
            request($handler, 'POST', '/login', [
                'identification' => $email,
                'password' => $password,
                'csrfToken' => $csrfToken,
            ], null, $cookies),
            403,
            $failures
        );

        expectStatus(
            'password reset submission is forbidden',
            request($handler, 'POST', '/reset', [
                'password' => $password,
                'csrfToken' => $csrfToken,
            ], null, $cookies),
            403,
            $failures
        );
    } else {
        $failures[] = 'forum session did not provide a cookie and CSRF token';
    }

    expectStatus(
        'forgot-password API is forbidden',
        $client->withBody(['email' => $email])->post('/forgot'),
        403,
        $failures
    );

    expectStatus(
        'password reset token page is forbidden',
        request($handler, 'GET', '/reset/invalid-'.$suffix),
        403,
        $failures
    );

    $registrationToken = RegistrationToken::generate(
        'google',
        $providerId,
        ['email' => $oauthEmail],
        []
    );
    $registrationToken->save();

    expectStatus(
        'registration without OAuth token is forbidden',
        $client->withBody(['data' => ['attributes' => [
            'username' => $oauthUsername,
            'email' => $oauthEmail,
            'password' => $password,
        ]]])->post('/users'),
        403,
        $failures
    );

    expectStatus(
        'registration with invalid OAuth token is rejected',
        $client->withBody(['data' => ['attributes' => [
            'username' => $oauthUsername,
            'email' => $oauthEmail,
            'token' => 'invalid-'.$suffix,
        ]]])->post('/users'),
        403,
        $failures
    );

    $registration = $client->withBody(['data' => ['attributes' => [
        'username' => $oauthUsername,
        'email' => $oauthEmail,
        'token' => $registrationToken->token,
    ]]])->post('/users');
    expectStatus('valid Google registration token creates user', $registration, 201, $failures);

    $oauthUser = User::where('username', $oauthUsername)->first();

    if ($oauthUser && $oauthUser->loginProviders()
        ->where('provider', 'google')
        ->where('identifier', $providerId)
        ->exists()) {
        echo "PASS Google provider is linked to the new user\n";

        $oauthLogin = $container->make(ResponseFactory::class)->make(
            'google',
            $providerId,
            function (): void {
                throw new RuntimeException('A linked provider should not start registration');
            }
        );

        if (strpos((string) $oauthLogin->getBody(), '"loggedIn":true') !== false) {
            echo "PASS linked Google account completes login\n";
        } else {
            $failures[] = 'linked Google account did not complete login';
            echo "FAIL linked Google account completes login\n";
        }

        $rememberCookie = explode(';', $oauthLogin->getHeaderLine('Set-Cookie'), 2)[0];
        $rememberParts = explode('=', $rememberCookie, 2);

        if (count($rememberParts) === 2 && $rememberParts[1] !== '') {
            expectStatus(
                'Google login cookie reaches protected API',
                request($handler, 'GET', '/api/access-tokens', [], null, [
                    $rememberParts[0] => $rememberParts[1],
                ]),
                200,
                $failures
            );
        } else {
            $failures[] = 'linked Google login did not issue a remember cookie';
            echo "FAIL linked Google login issued a remember cookie\n";
        }
    } else {
        $failures[] = 'Google provider was not linked to the new user';
        echo "FAIL Google provider is linked to the new user\n";
    }
} finally {
    if ($registrationToken) {
        RegistrationToken::where('token', $registrationToken->token)->delete();
    }

    if ($oauthUser) {
        AccessToken::where('user_id', $oauthUser->id)->delete();
        $oauthUser->delete();
    }

    if ($fixture) {
        AccessToken::where('user_id', $fixture->id)->delete();
        $fixture->delete();
    }
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures)."\n");
    exit(1);
}

echo "All password and OAuth integration checks passed.\n";

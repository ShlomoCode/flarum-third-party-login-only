<?php

namespace V17Development\FlarumThirdPartyLoginOnly\Auth;

use Flarum\Forum\Auth\Registration;
use Flarum\Forum\Auth\ResponseFactory;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\RegistrationToken;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;

class GoogleEmailResponseFactory extends ResponseFactory
{
    public function make(string $provider, string $identifier, callable $configureRegistration): ResponseInterface
    {
        if ($provider !== 'google') {
            return parent::make($provider, $identifier, $configureRegistration);
        }

        $configureRegistration($registration = new Registration);
        $provided = $registration->getProvided();
        $email = $provided['email'] ?? null;

        // Suggested addresses are not proof of ownership from the OAuth provider.
        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PermissionDeniedException('Google must provide a trusted email address.');
        }

        if ($user = User::where('email', $email)->first()) {
            return $this->makeLoggedInResponse($user);
        }

        // Keep native registration events and invitation checks, but no Google ID.
        $payload = Arr::only((array) $registration->getPayload(), ['avatarUrl']);
        $token = RegistrationToken::generate('google', '', $provided, $payload);
        $token->save();

        return $this->makeResponse(array_merge($provided, $registration->getSuggested(), [
            'token' => $token->token,
            'provided' => array_keys($provided),
        ]));
    }
}

<?php

namespace V17Development\FlarumThirdPartyLoginOnly\Api;

use Flarum\Api\Serializer\CurrentUserSerializer;
use Flarum\Http\RequestUtil;
use Flarum\Api\Controller\AbstractCreateController;
use Flarum\User\Command\RegisterUser;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\RegistrationToken;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

class CreateUserController extends AbstractCreateController
{
    /**
     * {@inheritdoc}
     */
    public $serializer = CurrentUserSerializer::class;

    /**
     * @var Dispatcher
     */
    protected $bus;

    /**
     * @param Dispatcher $bus
     */
    public function __construct(Dispatcher $bus)
    {
        $this->bus = $bus;
    }

    /**
     * {@inheritdoc}
     */
    protected function data(ServerRequestInterface $request, Document $document)
    {
        // Disable normal sign ups (without oAuth token)
        if(!Arr::has($request->getParsedBody(), 'data.attributes.token')) {
            throw new PermissionDeniedException("Route is disabled");
        }

        $token = RegistrationToken::validOrFail(Arr::get($request->getParsedBody(), 'data.attributes.token'));
        if ($token->provider === 'google' && ($token->identifier !== '' || empty($token->user_attributes['email']))) {
            throw new PermissionDeniedException('Restart Google authentication to register by trusted email.');
        }

        return $this->bus->dispatch(
            new RegisterUser(RequestUtil::getActor($request), Arr::get($request->getParsedBody(), 'data', []))
        );
    }
}

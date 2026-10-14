<?php

namespace V17Development\FlarumThirdPartyLoginOnly\Api;

use Flarum\Api\Serializer\CurrentUserSerializer;
use Flarum\Http\RequestUtil;
use Flarum\Api\Controller\AbstractCreateController;
use Flarum\User\Command\RegisterUser;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\RegistrationToken;
use FoF\Doorman\DoorkeyBypassRegistry;
use Illuminate\Contracts\Container\Container;
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

    protected $serviceContainer;

    /**
     * @param Dispatcher $bus
     */
    public function __construct(Dispatcher $bus, Container $container)
    {
        $this->bus = $bus;
        $this->serviceContainer = $container;
    }

    /**
     * {@inheritdoc}
     */
    protected function data(ServerRequestInterface $request, Document $document)
    {
        $data = Arr::get($request->getParsedBody(), 'data', []);
        $actor = RequestUtil::getActor($request);

        if (!Arr::has($data, 'attributes.token')) {
            if (!$actor->isAdmin()) {
                throw new PermissionDeniedException('Route is disabled');
            }

            // Let Flarum generate an unknown password and run its normal registration events.
            $token = RegistrationToken::generate('admin-created', '', [
                'email' => Arr::get($data, 'attributes.email'),
            ], []);
            $token->save();
            Arr::set($data, 'attributes.token', $token->token);
            Arr::forget($data, 'attributes.password');

            try {
                // Doorman's provider bypass applies only to this server-created token.
                if (class_exists(DoorkeyBypassRegistry::class) && $this->serviceContainer->bound(DoorkeyBypassRegistry::class)) {
                    $this->serviceContainer->make(DoorkeyBypassRegistry::class)->registerProvider('admin-created');
                }

                return $this->bus->dispatch(new RegisterUser($actor, $data));
            } finally {
                $token->delete();
            }
        }

        return $this->bus->dispatch(
            new RegisterUser($actor, $data)
        );
    }
}

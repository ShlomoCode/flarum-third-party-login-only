<?php

namespace V17Development\FlarumThirdPartyLoginOnly\Auth;

use Flarum\Forum\Auth\ResponseFactory;
use Flarum\Foundation\AbstractServiceProvider;

class GoogleEmailServiceProvider extends AbstractServiceProvider
{
    public function register()
    {
        $this->container->bind(ResponseFactory::class, GoogleEmailResponseFactory::class);
    }
}

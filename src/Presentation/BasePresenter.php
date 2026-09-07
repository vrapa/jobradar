<?php

declare(strict_types=1);

namespace App\Presentation;

use Nette\Application\UI\Presenter;

abstract class BasePresenter extends Presenter
{
    protected function startup(): void
    {
        parent::startup();
        $response = $this->getHttpResponse();
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('Referrer-Policy', 'no-referrer');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader(
            'Content-Security-Policy',
            "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'",
        );
        $response->setHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}

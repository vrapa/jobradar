<?php

declare(strict_types=1);

namespace App\Presentation\Error;

use Nette\Application\BadRequestException;
use Nette\Application\IPresenter;
use Nette\Application\Request;
use Nette\Application\Responses\CallbackResponse;
use Nette\Application\Response;

final class ErrorPresenter implements IPresenter
{
    public function run(Request $request): Response
    {
        $exception = $request->getParameter('exception');
        $status = $exception instanceof BadRequestException ? $exception->getCode() : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        return new CallbackResponse(static function ($httpRequest, $httpResponse) use ($status): void {
            $httpResponse->setCode($status);
            $httpResponse->setContentType('text/html', 'utf-8');
            echo '<!doctype html><html lang="cs"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Chyba</title><h1>Požadavek se nepodařilo dokončit</h1><p>Zkuste to prosím znovu.</p>';
        });
    }
}

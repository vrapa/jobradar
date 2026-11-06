<?php

declare(strict_types=1);

namespace App\Presentation\Opportunity;

use Nette\Application\UI\Form;

final class TenderStatusFormFactory
{
    /** @param callable(Form, array<string, mixed>): void $onSuccess */
    public function create(string $status, int $lockVersion, callable $onSuccess): Form
    {
        if (!in_array($status, ['active', 'closed'], true) || $lockVersion < 0) {
            throw new \InvalidArgumentException('Stav formuláře výběrového řízení není platný.');
        }
        $form = new Form();
        $form->addHidden('event', $status === 'active' ? 'closed' : 'reopened');
        $form->addHidden('lockVersion', (string) $lockVersion);
        $form->addHidden('idempotencyKey', bin2hex(random_bytes(16)));
        $form->addProtection('Platnost formuláře vypršela. Obnovte detail.');
        $form->addSubmit('save', $status === 'active' ? 'Uzavřít výběrové řízení' : 'Znovu otevřít');
        $form->onSuccess[] = static function (Form $form) use ($onSuccess): void {
            /** @var array<string, mixed> $values */
            $values = $form->getUntrustedValues('array');
            $onSuccess($form, $values);
        };
        return $form;
    }
}

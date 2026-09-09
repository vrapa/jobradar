<?php

declare(strict_types=1);

namespace App\Opportunity;

use Nette\Application\UI\Form;

final class CounterpartyForm
{
    public static function add(Form $form): void
    {
        $form->addSelect('counterpartyValue', 'Protistrana', ['' => 'Neověřeno'] + CounterpartyInput::LABELS);
        $form->addTextArea('counterpartyReason', 'Doložení protistrany')->setHtmlAttribute('rows', 2);
        $form->addText('counterpartyConfidence', 'Jistota 0–1')->setHtmlType('number')->setHtmlAttribute('min', '0')->setHtmlAttribute('max', '1')->setHtmlAttribute('step', '0.001');
    }

    /** @param array<string,mixed> $values */
    public static function read(array $values): CounterpartyInput
    {
        $role = (string) ($values['counterpartyValue'] ?? '');
        $confidence = trim((string) ($values['counterpartyConfidence'] ?? ''));
        if ($confidence !== '' && !is_numeric($confidence)) { throw new \InvalidArgumentException('Jistota musí být číslo.'); }
        return new CounterpartyInput($role === '' ? null : $role, $role === '' ? null : trim((string) ($values['counterpartyReason'] ?? '')), $role === '' || $confidence === '' ? null : (float) $confidence, $role === '' ? null : new \DateTimeImmutable());
    }
}

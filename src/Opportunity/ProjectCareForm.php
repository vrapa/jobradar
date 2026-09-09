<?php

declare(strict_types=1);

namespace App\Opportunity;

use Nette\Application\UI\Form;

final class ProjectCareForm
{
    public static function add(Form $form): void
    {
        $form->addSelect('projectCareValue', 'Převzetí / péče o existující aplikaci', ['' => 'Neověřeno', 'yes' => 'Ano', 'no' => 'Ne']);
        $form->addTextArea('projectCareReason', 'Zdůvodnění klasifikace')->setHtmlAttribute('rows', 2);
        $form->addText('projectCareConfidence', 'Jistota 0–1')->setHtmlType('number')->setHtmlAttribute('min', '0')->setHtmlAttribute('max', '1')->setHtmlAttribute('step', '0.001');
    }

    /** @param array<string, mixed> $values */
    public static function read(array $values): ProjectCareInput
    {
        $value = match ($values['projectCareValue'] ?? '') { 'yes' => true, 'no' => false, default => null };
        $confidence = trim((string) ($values['projectCareConfidence'] ?? ''));
        if ($confidence !== '' && !is_numeric($confidence)) { throw new \InvalidArgumentException('Jistota musí být číslo.'); }
        return new ProjectCareInput($value, $value === null ? null : trim((string) ($values['projectCareReason'] ?? '')), $value === null || $confidence === '' ? null : (float) $confidence, $value === null ? null : new \DateTimeImmutable());
    }
}

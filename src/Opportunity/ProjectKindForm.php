<?php

declare(strict_types=1);

namespace App\Opportunity;

use Nette\Application\UI\Form;

final class ProjectKindForm
{
    public static function add(Form $form): void
    {
        $form->addCheckboxList('projectKindValues', 'Typ projektu', ProjectKindInput::LABELS);
        $form->addTextArea('projectKindReason', 'Doložení typu projektu')->setHtmlAttribute('rows', 2);
        $form->addText('projectKindConfidence', 'Jistota 0–1')->setHtmlType('number')->setHtmlAttribute('min', '0')->setHtmlAttribute('max', '1')->setHtmlAttribute('step', '0.001');
    }

    /** @param array<string, mixed> $values */
    public static function read(array $values): ProjectKindInput
    {
        $selected = $values['projectKindValues'] ?? [];
        if (!is_array($selected) || !array_is_list($selected) || array_any($selected, static fn ($value): bool => !is_string($value))) { throw new \InvalidArgumentException('Neplatné typy projektu.'); }
        if ($selected === []) { return new ProjectKindInput(null); }
        $confidence = trim((string) ($values['projectKindConfidence'] ?? ''));
        if ($confidence !== '' && !is_numeric($confidence)) { throw new \InvalidArgumentException('Jistota musí být číslo.'); }
        return new ProjectKindInput($selected, trim((string) ($values['projectKindReason'] ?? '')), $confidence === '' ? null : (float) $confidence, new \DateTimeImmutable());
    }
}

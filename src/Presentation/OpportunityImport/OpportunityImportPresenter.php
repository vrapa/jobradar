<?php

declare(strict_types=1);

namespace App\Presentation\OpportunityImport;

use App\Opportunity\OpportunityImport;
use App\Opportunity\OpportunityImportService;
use App\Presentation\SecuredPresenter;
use Nette\Application\UI\Form;

final class OpportunityImportPresenter extends SecuredPresenter
{
    public function __construct(private readonly OpportunityImportService $importer)
    {
        parent::__construct();
    }

    protected function createComponentImportForm(): Form
    {
        $form = new Form();
        $form->addText('url', 'URL nabídky')
            ->setHtmlType('url')
            ->setHtmlAttribute('placeholder', 'https://example.com/jobs/php-developer')
            ->setRequired('Zadejte URL nabídky.');
        $form->addText('companyName', 'Společnost');
        $form->addText('originalTitle', 'Původní titulek')
            ->setRequired('Zadejte původní titulek.');
        $form->addTextArea('originalText', 'Původní text')
            ->setHtmlAttribute('rows', 12)
            ->setRequired('Vložte původní text nabídky.');
        $form->addText('sourceLanguage', 'Jazyk originálu')
            ->setHtmlAttribute('placeholder', 'en, de, cs');
        $form->addText('translatedTitle', 'Český titulek');
        $form->addTextArea('translatedText', 'Český překlad')
            ->setHtmlAttribute('rows', 12);
        $form->addTextArea('summary', 'Shrnutí')
            ->setHtmlAttribute('rows', 4);
        $form->addCheckbox('incomplete', 'Zdrojový text je neúplný');
        $form->addProtection('Platnost formuláře vypršela. Zkuste to prosím znovu.');
        $form->addSubmit('send', 'Uložit nabídku');
        $form->onSuccess[] = function (Form $form, array|object $values): void {
            $this->importFormSucceeded($form, (array) $values);
        };

        return $form;
    }

    /** @param array<string, mixed> $values */
    private function importFormSucceeded(Form $form, array $values): void
    {
        try {
            $result = $this->importer->import(new OpportunityImport(
                url: (string) $values['url'],
                originalTitle: (string) $values['originalTitle'],
                originalText: (string) $values['originalText'],
                companyName: $this->nullable($values['companyName'] ?? null),
                translatedTitle: $this->nullable($values['translatedTitle'] ?? null),
                translatedText: $this->nullable($values['translatedText'] ?? null),
                summary: $this->nullable($values['summary'] ?? null),
                sourceLanguage: $this->nullable($values['sourceLanguage'] ?? null),
                incomplete: (bool) ($values['incomplete'] ?? false),
            ), (int) $this->getUser()->getId());
        } catch (\InvalidArgumentException $exception) {
            $form->addError($exception->getMessage());
            return;
        }

        $message = $result->opportunityCreated
            ? 'Nabídka byla uložena.'
            : ($result->versionCreated ? 'Byla uložena nová verze nabídky.' : 'Tato verze nabídky už byla uložená.');
        $this->flashMessage($message, 'success');
        $this->redirect('Opportunity:detail', $result->opportunityId);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}

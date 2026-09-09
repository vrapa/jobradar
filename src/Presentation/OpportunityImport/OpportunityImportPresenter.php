<?php

declare(strict_types=1);

namespace App\Presentation\OpportunityImport;

use App\Opportunity\OpportunityImport;
use App\Opportunity\OpportunityImportService;
use App\Presentation\SecuredPresenter;
use Nette\Application\UI\Form;

final class OpportunityImportPresenter extends SecuredPresenter
{
    public function __construct(private readonly OpportunityImportService $importer, private readonly \App\Search\SourceSettingsService $settings)
    {
        parent::__construct();
    }

    public function renderDefault(): void
    {
        $discovery = null;
        if ($this->getParameter('discovery') !== null) {
            try { $discovery = $this->settings->discoveryDefinition((int) $this->getParameter('discovery')); }
            catch (\InvalidArgumentException $e) { $this->error($e->getMessage(), 404); }
        }
        $this->template->setParameters(['discovery' => $discovery]);
    }

    protected function createComponentImportForm(): Form
    {
        $form = new Form();
        $form->addHidden('discoveryDefinitionId', (string) ($this->getParameter('discovery') ?? ''));
        \App\Opportunity\ProjectCareForm::add($form);
        \App\Opportunity\CounterpartyForm::add($form);
        $form->addText('url', 'URL nabídky')
            ->setHtmlType('url')
            ->addRule(Form::MaxLength, 'URL smí mít nejvýše %d znaků.', 2048)
            ->setHtmlAttribute('placeholder', 'https://example.com/jobs/php-developer')
            ->setRequired('Zadejte URL nabídky.');
        $form->addText('companyName', 'Společnost')
            ->addRule(Form::MaxLength, 'Název společnosti smí mít nejvýše %d znaků.', 255);
        $form->addText('originalTitle', 'Původní titulek')
            ->addRule(Form::MaxLength, 'Titulek smí mít nejvýše %d znaků.', 500)
            ->setRequired('Zadejte původní titulek.');
        $form->addTextArea('originalText', 'Původní text')
            ->setHtmlAttribute('rows', 12)
            ->setRequired('Vložte původní text nabídky.');
        $form->addText('sourceLanguage', 'Jazyk originálu')
            ->addRule(Form::MaxLength, 'Jazyk smí mít nejvýše %d znaků.', 16)
            ->setHtmlAttribute('placeholder', 'en, de, cs');
        $form->addText('translatedTitle', 'Český titulek')
            ->addRule(Form::MaxLength, 'Titulek smí mít nejvýše %d znaků.', 500);
        $form->addTextArea('translatedText', 'Český překlad')
            ->setHtmlAttribute('rows', 12);
        $form->addTextArea('summary', 'Shrnutí')
            ->addRule(Form::MaxLength, 'Shrnutí smí mít nejvýše %d znaků.', 65_535)
            ->setHtmlAttribute('rows', 4);
        $form->addCheckbox('incomplete', 'Zdrojový text je neúplný');
        $form->addProtection('Platnost formuláře vypršela. Zkuste to prosím znovu.');
        $form->addSubmit('send', 'Uložit nabídku');
        $form->onSuccess[] = function (Form $form): void {
            $this->importFormSucceeded($form, (array) $form->getValues());
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
                projectCare: ($values['projectCareValue'] ?? '') === '' ? null : \App\Opportunity\ProjectCareForm::read($values),
                counterparty: ($values['counterpartyValue'] ?? '') === '' ? null : \App\Opportunity\CounterpartyForm::read($values),
                discoveryDefinitionId: ($values['discoveryDefinitionId'] ?? '') === '' ? null : (int) $values['discoveryDefinitionId'],
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

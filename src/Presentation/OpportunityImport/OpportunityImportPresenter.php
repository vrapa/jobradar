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
        $form->addSelect('opportunityType', 'Typ příležitosti', \App\Opportunity\OpportunityType::LABELS)
            ->setDefaultValue(\App\Opportunity\OpportunityType::OFFER)
            ->setRequired();
        \App\Opportunity\ProjectCareForm::add($form);
        \App\Opportunity\CounterpartyForm::add($form);
        \App\Opportunity\ProjectKindForm::add($form);
        $form->addText('url', 'URL příležitosti')
            ->setHtmlType('url')
            ->addRule(Form::MaxLength, 'URL smí mít nejvýše %d znaků.', 2048)
            ->setHtmlAttribute('placeholder', 'https://example.com/jobs/php-developer')
            ->setRequired('Zadejte URL příležitosti.');
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
        $form->addText('contactName', 'Jméno kontaktní osoby')->setMaxLength(255);
        $form->addText('contactRole', 'Role kontaktní osoby')->setMaxLength(255);
        $form->addText('contactChannel', 'Kanál')->setMaxLength(100)->setHtmlAttribute('placeholder', 'LinkedIn, e-mail');
        $form->addText('contactProfileUrl', 'Odkaz na profil nebo konverzaci')->setMaxLength(2048)->setHtmlType('url');
        $form->addTextArea('outreachContext', 'Stručný kontext oslovení')->setHtmlAttribute('rows', 3);
        $form->addProtection('Platnost formuláře vypršela. Zkuste to prosím znovu.');
        $form->addSubmit('send', 'Uložit příležitost');
        $form->onSuccess[] = function (Form $form): void {
            $this->importFormSucceeded($form, (array) $form->getValues());
        };

        return $form;
    }

    /** @param array<string, mixed> $values */
    private function importFormSucceeded(Form $form, array $values): void
    {
        try {
            $type = (string) ($values['opportunityType'] ?? \App\Opportunity\OpportunityType::OFFER);
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
                projectCare: $type !== \App\Opportunity\OpportunityType::OFFER || ($values['projectCareValue'] ?? '') === '' ? null : \App\Opportunity\ProjectCareForm::read($values),
                counterparty: $type !== \App\Opportunity\OpportunityType::OFFER || ($values['counterpartyValue'] ?? '') === '' ? null : \App\Opportunity\CounterpartyForm::read($values),
                discoveryDefinitionId: ($values['discoveryDefinitionId'] ?? '') === '' ? null : (int) $values['discoveryDefinitionId'],
                projectKinds: $type !== \App\Opportunity\OpportunityType::OFFER || ($values['projectKindValues'] ?? []) === [] ? null : \App\Opportunity\ProjectKindForm::read($values),
                opportunityType: $type,
                companyLead: $type === \App\Opportunity\OpportunityType::COMPANY_LEAD ? new \App\Opportunity\CompanyLeadInput(
                    $this->nullable($values['contactName'] ?? null),
                    $this->nullable($values['contactRole'] ?? null),
                    $this->nullable($values['contactChannel'] ?? null),
                    $this->nullable($values['contactProfileUrl'] ?? null),
                    $this->nullable($values['outreachContext'] ?? null),
                ) : null,
            ), (int) $this->getUser()->getId());
        } catch (\InvalidArgumentException $exception) {
            $form->addError($exception->getMessage());
            return;
        }

        $message = $result->opportunityCreated
            ? 'Příležitost byla uložena.'
            : ($result->versionCreated ? 'Byla uložena nová verze příležitosti.' : 'Tato verze příležitosti už byla uložená.');
        $this->flashMessage($message, 'success');
        $this->redirect('Opportunity:detail', $result->opportunityId);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}

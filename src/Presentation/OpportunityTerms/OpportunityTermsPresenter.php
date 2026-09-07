<?php

declare(strict_types=1);

namespace App\Presentation\OpportunityTerms;

use App\Opportunity\OpportunityConflictException;
use App\Opportunity\OpportunityDetail;
use App\Opportunity\OpportunityQueryService;
use App\Opportunity\OpportunityTermsInput;
use App\Opportunity\OpportunityTermsService;
use App\Opportunity\TechnologyInput;
use App\Opportunity\TechnologyView;
use App\Presentation\SecuredPresenter;
use Nette\Application\UI\Form;

final class OpportunityTermsPresenter extends SecuredPresenter
{
    private int $opportunityId;

    public function __construct(
        private readonly OpportunityQueryService $queries,
        private readonly OpportunityTermsService $service,
    ) {
        parent::__construct();
    }

    public function actionDefault(int $id): void
    {
        $opportunity = $this->queries->getDetail($id);
        if ($opportunity === null) {
            $this->error('Nabídka nebyla nalezena.');
        }
        $this->opportunityId = $id;
        $this->template->setParameters(['opportunity' => $opportunity]);

        $form = $this->getComponent('termsForm');
        if ($form instanceof Form && !$form->isSubmitted()) {
            $form->setDefaults($this->defaults($opportunity));
        }
    }

    protected function createComponentTermsForm(): Form
    {
        $form = new Form();
        $form->addHidden('lockVersion');
        $form->addText('rateMin', 'Sazba od')->setHtmlType('number')->setHtmlAttribute('step', '0.01')->setHtmlAttribute('min', '0');
        $form->addText('rateMax', 'Sazba do')->setHtmlType('number')->setHtmlAttribute('step', '0.01')->setHtmlAttribute('min', '0');
        $form->addText('currency', 'Měna')->setHtmlAttribute('maxlength', 3)->setHtmlAttribute('placeholder', 'CZK');
        $form->addSelect('rateUnit', 'Jednotka sazby', [
            '' => 'Neznámá', 'hour' => 'hodina', 'day' => 'den', 'month' => 'měsíc', 'project' => 'projekt',
        ]);
        $form->addText('rateSource', 'Původ sazby')->setHtmlAttribute('placeholder', 'Text nabídky, e-mail náboráře…');
        $form->addText('rateConfidence', 'Jistota sazby')->setHtmlType('number')->setHtmlAttribute('step', '0.001')->setHtmlAttribute('min', '0')->setHtmlAttribute('max', '1');
        $form->addText('engagementMode', 'Forma spolupráce')->setHtmlAttribute('placeholder', 'B2B, HPP, kontrakt…');
        $form->addText('workloadMin', 'Rozsah od')->setHtmlType('number')->setHtmlAttribute('step', '0.01')->setHtmlAttribute('min', '0');
        $form->addText('workloadMax', 'Rozsah do')->setHtmlType('number')->setHtmlAttribute('step', '0.01')->setHtmlAttribute('min', '0');
        $form->addText('workloadUnit', 'Jednotka rozsahu')->setHtmlAttribute('placeholder', 'hodin/měsíc, FTE…');
        $form->addText('durationText', 'Délka spolupráce');
        $form->addSelect('remoteMode', 'Režim práce', [
            '' => 'Neznámý', 'remote' => 'Remote', 'hybrid' => 'Hybrid', 'onsite' => 'Na místě',
        ]);
        $form->addSelect('workFromCzechia', 'Práce z ČR', [
            '' => 'Neověřeno', 'yes' => 'Ano', 'no' => 'Ne',
        ]);
        $form->addText('location', 'Lokalita');
        $form->addText('workTimezone', 'Časové pásmo');
        $form->addText('workingLanguage', 'Pracovní jazyk');
        $form->addText('communicationMode', 'Komunikační režim');
        $form->addTextArea('requiredTechnologies', 'Povinné technologie')->setHtmlAttribute('rows', 2);
        $form->addTextArea('advantageTechnologies', 'Technologie výhodou')->setHtmlAttribute('rows', 2);
        $form->addTextArea('otherTechnologies', 'Další technologie')->setHtmlAttribute('rows', 2);
        $form->addProtection('Platnost formuláře vypršela. Zkuste to prosím znovu.');
        $form->addSubmit('send', 'Uložit podmínky');
        $form->onSuccess[] = function (Form $form, array|object $values): void {
            $this->termsFormSucceeded($form, (array) $values);
        };

        return $form;
    }

    /** @param array<string, mixed> $values */
    private function termsFormSucceeded(Form $form, array $values): void
    {
        try {
            $this->service->save(
                opportunityId: $this->opportunityId,
                expectedLockVersion: (int) $values['lockVersion'],
                terms: new OpportunityTermsInput(
                    rateMin: $this->decimal($values['rateMin'] ?? null),
                    rateMax: $this->decimal($values['rateMax'] ?? null),
                    currency: $this->upper($values['currency'] ?? null),
                    rateUnit: $this->nullable($values['rateUnit'] ?? null),
                    engagementMode: $this->nullable($values['engagementMode'] ?? null),
                    rateSource: $this->nullable($values['rateSource'] ?? null),
                    rateConfidence: $this->decimal($values['rateConfidence'] ?? null),
                    workloadMin: $this->decimal($values['workloadMin'] ?? null),
                    workloadMax: $this->decimal($values['workloadMax'] ?? null),
                    workloadUnit: $this->nullable($values['workloadUnit'] ?? null),
                    durationText: $this->nullable($values['durationText'] ?? null),
                    remoteMode: $this->nullable($values['remoteMode'] ?? null),
                    workFromCzechia: match ($values['workFromCzechia'] ?? '') {
                        'yes' => true, 'no' => false, default => null,
                    },
                    location: $this->nullable($values['location'] ?? null),
                    workTimezone: $this->nullable($values['workTimezone'] ?? null),
                    workingLanguage: $this->nullable($values['workingLanguage'] ?? null),
                    communicationMode: $this->nullable($values['communicationMode'] ?? null),
                ),
                technologies: [
                    ...$this->technologies($values['requiredTechnologies'] ?? null, 'required'),
                    ...$this->technologies($values['advantageTechnologies'] ?? null, 'advantage'),
                    ...$this->technologies($values['otherTechnologies'] ?? null, 'unknown'),
                ],
                actorUserId: (int) $this->getUser()->getId(),
            );
        } catch (OpportunityConflictException $exception) {
            $this->flashMessage($exception->getMessage(), 'warning');
            $this->redirect('this');
        } catch (\InvalidArgumentException $exception) {
            $form->addError($exception->getMessage());
            return;
        }

        $this->flashMessage('Podmínky a technologie byly uloženy.', 'success');
        $this->redirect('Opportunity:detail', $this->opportunityId);
    }

    /** @return array<string, mixed> */
    private function defaults(OpportunityDetail $opportunity): array
    {
        $terms = $opportunity->terms;
        return [
            'lockVersion' => $opportunity->lockVersion,
            'rateMin' => $terms?->rateMin,
            'rateMax' => $terms?->rateMax,
            'currency' => $terms?->currency,
            'rateUnit' => $terms->rateUnit ?? '',
            'engagementMode' => $terms?->engagementMode,
            'rateSource' => $terms?->rateSource,
            'rateConfidence' => $terms?->rateConfidence,
            'workloadMin' => $terms?->workloadMin,
            'workloadMax' => $terms?->workloadMax,
            'workloadUnit' => $terms?->workloadUnit,
            'durationText' => $terms?->durationText,
            'remoteMode' => $terms->remoteMode ?? '',
            'workFromCzechia' => $terms?->workFromCzechia === null ? '' : ($terms->workFromCzechia ? 'yes' : 'no'),
            'location' => $terms?->location,
            'workTimezone' => $terms?->workTimezone,
            'workingLanguage' => $terms?->workingLanguage,
            'communicationMode' => $terms?->communicationMode,
            'requiredTechnologies' => $this->technologyNames($opportunity->technologies, 'required'),
            'advantageTechnologies' => $this->technologyNames($opportunity->technologies, 'advantage'),
            'otherTechnologies' => $this->technologyNames($opportunity->technologies, 'unknown'),
        ];
    }

    /** @return list<TechnologyInput> */
    private function technologies(mixed $value, string $level): array
    {
        $items = preg_split('/[,\r\n]+/u', (string) $value) ?: [];
        return array_values(array_map(
            static fn (string $name): TechnologyInput => new TechnologyInput($name, $level),
            array_filter(array_map('trim', $items), static fn (string $name): bool => $name !== ''),
        ));
    }

    /** @param list<TechnologyView> $technologies */
    private function technologyNames(array $technologies, string $level): string
    {
        return implode(', ', array_map(
            static fn (TechnologyView $technology): string => $technology->name,
            array_filter($technologies, static fn (TechnologyView $technology): bool => $technology->requirementLevel === $level),
        ));
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function decimal(mixed $value): ?string
    {
        $value = $this->nullable($value);
        return $value === null ? null : str_replace(',', '.', $value);
    }

    private function upper(mixed $value): ?string
    {
        $value = $this->nullable($value);
        return $value === null ? null : mb_strtoupper($value);
    }
}

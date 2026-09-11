<?php

declare(strict_types=1);

namespace App\Presentation\Home;

use App\Decision\OpportunityDecision;
use App\Decision\OpportunityDecisionService;
use App\Opportunity\OpportunityConflictException;
use App\Opportunity\OpportunityQueryService;
use App\Presentation\SecuredPresenter;
use App\Search\SourceQueryService;
use Nette\Application\UI\Form;
use Nette\Application\UI\Multiplier;
use Nette\Forms\Controls\SubmitButton;

final class HomePresenter extends SecuredPresenter
{
    public function __construct(
        private readonly OpportunityQueryService $opportunities,
        private readonly SourceQueryService $sourceQueries,
        private readonly OpportunityDecisionService $decisions,
    ) {
        parent::__construct();
    }

    public function renderDefault(): void
    {
        $items = $this->opportunities->listCurrent((int) $this->getUser()->getId());
        if ($this->getParameter('decision') === 'undecided') {
            $items = array_values(array_filter($items, static fn ($item): bool => $item->decision === OpportunityDecision::Undecided));
        }
        $care = (string) ($this->getParameter('care') ?? 'all');
        if (!in_array($care, ['all','yes','no','unknown'], true)) { $care = 'all'; }
        if ($care !== 'all') { $items = array_values(array_filter($items, static fn ($o): bool => $o->projectCare === match ($care) { 'yes' => true, 'no' => false, default => null })); }
        $party = (string) ($this->getParameter('party') ?? 'all');
        if (!in_array($party, ['all','unknown','owner','supplier','recruiter'], true)) { $party = 'all'; }
        if ($party !== 'all') { $items = array_values(array_filter($items, static fn ($o): bool => $o->counterparty === ($party === 'unknown' ? null : $party))); }
        $this->template->setParameters([
            'opportunities' => $items,
            'onlyUndecided' => $this->getParameter('decision') === 'undecided',
            'opportunityCount' => count($items),
            'care' => $care,
            'party' => $party,
            'coverage' => $this->sourceQueries->latestCoverageSummary((int) $this->getUser()->getId()),
            'unresolvedSources' => $this->sourceQueries->unresolvedSources((int) $this->getUser()->getId()),
            'executor' => $this->sourceQueries->executorStatus((int) $this->getUser()->getId()),
        ]);
    }

    public function renderUninteresting(): void
    {
        $items = $this->opportunities->listUninteresting((int) $this->getUser()->getId());
        $this->template->setParameters([
            'opportunities' => $items,
            'opportunityCount' => count($items),
        ]);
    }

    public function renderReactionQueue(): void
    {
        $items = $this->opportunities->listReactionQueue((int) $this->getUser()->getId());
        $this->template->setParameters([
            'opportunities' => $items,
            'opportunityCount' => count($items),
        ]);
    }

    public function renderAwaitingResponse(): void
    {
        $items = $this->opportunities->listAwaitingResponse((int) $this->getUser()->getId());
        $this->template->setParameters(['opportunities' => $items, 'opportunityCount' => count($items)]);
    }

    /** @return Multiplier<Form> */
    protected function createComponentQuickDecision(): Multiplier
    {
        return new Multiplier(function (string $opportunityId): Form {
            $id = filter_var($opportunityId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $opportunity = is_int($id)
                ? $this->opportunities->getDetail($id, (int) $this->getUser()->getId())
                : null;
            if ($opportunity === null) {
                throw new \InvalidArgumentException('Nabídka pro rychlé rozhodnutí nebyla nalezena.');
            }
            $form = new Form();
            $form->addHidden('lockVersion', (string) $opportunity->decisionState->lockVersion);
            $form->addSelect('reason', 'Důvod nezájmu', [
                '' => 'Důvod nezájmu…',
                'low_rate' => 'Nízká sazba',
                'workload' => 'Nevhodný rozsah',
                'not_remote' => 'Nelze remote z ČR',
                'language_communication' => 'Jazyk nebo komunikace',
                'technology' => 'Nevhodné technologie',
                'wordpress_small_web' => 'WordPress nebo malý web',
                'expired' => 'Neaktuální nabídka',
                'duplicate' => 'Duplicita',
                'other' => 'Jiný důvod',
            ])->setDefaultValue($opportunity->decisionState->reason ?? '');
            $form->addProtection('Platnost rychlého rozhodnutí vypršela. Obnovte stránku.');
            $form->addSubmit('react', 'Reagovat');
            $form->addSubmit('uninteresting', 'Nezajímavé');
            $form->addSubmit('undo', 'Zpět');
            $form->onSuccess[] = function (Form $form) use ($id): void {
                $this->quickDecisionSucceeded($form, (array) $form->getValues(), $id);
            };
            return $form;
        });
    }

    /** @param array<string, mixed> $values */
    private function quickDecisionSucceeded(Form $form, array $values, int $opportunityId): void
    {
        $react = $form['react'];
        $uninteresting = $form['uninteresting'];
        if (!$react instanceof SubmitButton || !$uninteresting instanceof SubmitButton) {
            throw new \LogicException('Formulář rychlého rozhodnutí není správně sestaven.');
        }
        $decision = $react->isSubmittedBy()
            ? OpportunityDecision::React
            : ($uninteresting->isSubmittedBy() ? OpportunityDecision::Uninteresting : OpportunityDecision::Undecided);
        try {
            $result = $this->decisions->setManualDecision(
                (int) $this->getUser()->getId(),
                $opportunityId,
                (int) ($values['lockVersion'] ?? -1),
                $decision,
                is_string($values['reason'] ?? null) ? $values['reason'] : null,
            );
        } catch (OpportunityConflictException $exception) {
            $this->flashMessage($exception->getMessage(), 'warning');
            $this->redirect('this');
        } catch (\InvalidArgumentException $exception) {
            $form->addError($exception->getMessage());
            return;
        }
        $this->flashMessage($result->changed ? 'Rozhodnutí bylo uloženo.' : 'Rozhodnutí už bylo aktuální.', 'success');
        $this->redirect('this');
    }
}

<?php

declare(strict_types=1);

namespace App\Presentation\Opportunity;

use App\Decision\OpportunityDecision;
use App\Decision\OpportunityDecisionService;
use App\Opportunity\OpportunityConflictException;
use App\Opportunity\OpportunityQueryService;
use App\Presentation\SecuredPresenter;
use Nette\Application\UI\Form;
use Nette\Forms\Controls\SubmitButton;

final class OpportunityPresenter extends SecuredPresenter
{
    private int $opportunityId;

    public function __construct(
        private readonly OpportunityQueryService $opportunities,
        private readonly OpportunityDecisionService $decisions,
    ) {
        parent::__construct();
    }

    public function actionDetail(int $id): void
    {
        $opportunity = $this->opportunities->getDetail($id, (int) $this->getUser()->getId());
        if ($opportunity === null) {
            $this->error('Nabídka nebyla nalezena.');
        }
        $this->opportunityId = $id;
        $this->template->setParameters(['opportunity' => $opportunity]);

        $form = $this->getComponent('decisionForm');
        if ($form instanceof Form && !$form->isSubmitted()) {
            $form->setDefaults([
                'lockVersion' => $opportunity->decisionState->lockVersion,
                'reason' => $opportunity->decisionState->reason ?? '',
                'note' => $opportunity->decisionState->note,
            ]);
        }
    }

    protected function createComponentDecisionForm(): Form
    {
        $form = new Form();
        $form->addHidden('lockVersion');
        $form->addSelect('reason', 'Důvod nezájmu', [
            '' => 'Vyberte důvod',
            'low_rate' => 'Nízká sazba',
            'workload' => 'Nevhodný rozsah',
            'not_remote' => 'Nelze remote z ČR',
            'language_communication' => 'Jazyk nebo komunikace',
            'technology' => 'Nevhodné technologie',
            'wordpress_small_web' => 'WordPress nebo malý web',
            'expired' => 'Neaktuální nabídka',
            'duplicate' => 'Duplicita',
            'other' => 'Jiný důvod',
        ]);
        $form->addTextArea('note', 'Poznámka')->setHtmlAttribute('rows', 2);
        $form->addProtection('Platnost formuláře vypršela. Zkuste to prosím znovu.');
        $form->addSubmit('react', 'Reagovat');
        $form->addSubmit('uninteresting', 'Nezajímavé');
        $form->addSubmit('undo', 'Zpět na nerozhodnuto');
        $form->onSuccess[] = function (Form $form, array|object $values): void {
            $this->decisionFormSucceeded($form, (array) $values);
        };
        return $form;
    }

    /** @param array<string, mixed> $values */
    private function decisionFormSucceeded(Form $form, array $values): void
    {
        $react = $form['react'];
        $uninteresting = $form['uninteresting'];
        if (!$react instanceof SubmitButton || !$uninteresting instanceof SubmitButton) {
            throw new \LogicException('Formulář rozhodnutí není správně sestaven.');
        }
        $decision = $react->isSubmittedBy()
            ? OpportunityDecision::React
            : ($uninteresting->isSubmittedBy() ? OpportunityDecision::Uninteresting : OpportunityDecision::Undecided);
        try {
            $result = $this->decisions->setManualDecision(
                userId: (int) $this->getUser()->getId(),
                opportunityId: $this->opportunityId,
                expectedLockVersion: (int) $values['lockVersion'],
                decision: $decision,
                reason: (string) ($values['reason'] ?? ''),
                note: (string) ($values['note'] ?? ''),
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

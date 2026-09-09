<?php

declare(strict_types=1);

namespace App\Presentation\Opportunity;

use App\Assessment\AssessmentQueryService;
use App\Action\ActionItemService;
use App\Decision\OpportunityDecision;
use App\Decision\OpportunityDecisionService;
use App\Decision\DecisionQueryService;
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
        private readonly AssessmentQueryService $assessments,
        private readonly DecisionQueryService $decisionQueries,
        private readonly \App\Opportunity\ProjectCareService $projectCare,
        private readonly \App\Opportunity\CounterpartyService $counterparty,
        private readonly ActionItemService $actionItems,
        private readonly \App\Application\ApplicationWorkflowService $workflow,
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
        $actionItems = $this->actionItems->listForOpportunity((int) $this->getUser()->getId(), $id);
        $this->template->setParameters([
            'opportunity' => $opportunity,
            'assessment' => $this->assessments->getCurrent($id),
            'decisionHistory' => $this->decisionQueries->history((int) $this->getUser()->getId(), $id),
            'actionItems' => $actionItems,
            'applicationHistory' => $this->workflow->history((int) $this->getUser()->getId(), $id),
            'workflowLabels' => \App\Application\ApplicationWorkflowService::LABELS,
            'openActionItemCount' => count(array_filter($actionItems, static fn (array $item): bool => $item['status'] === 'open')),
        ]);

        $form = $this->getComponent('decisionForm');
        if ($form instanceof Form && !$form->isSubmitted()) {
            $form->setDefaults([
                'lockVersion' => $opportunity->decisionState->lockVersion,
                'reason' => $opportunity->decisionState->reason ?? '',
                'note' => $opportunity->decisionState->note,
            ]);
        }
    }

    protected function createComponentActionItemForm(): Form
    {
        $form = new Form();
        $form->addSelect('type', 'Typ kroku', ActionItemService::TYPES)->setRequired();
        $form->addText('title', 'Konkrétní další krok')->setRequired()->setMaxLength(255);
        $form->addTextArea('details', 'Podrobnosti')->setHtmlAttribute('rows', 2);
        $form->addText('dueAt', 'Termín')->setHtmlType('datetime-local');
        $form->addProtection('Platnost formuláře vypršela. Zkuste to prosím znovu.');
        $form->addSubmit('send', 'Přidat navazující úkol');
        $form->onSuccess[] = function (Form $form): void {
            $values = (array) $form->getValues();
            try {
                $dueAt = null;
                if (trim((string) ($values['dueAt'] ?? '')) !== '') {
                    $local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) $values['dueAt'], new \DateTimeZone('Europe/Prague'));
                    if (!$local instanceof \DateTimeImmutable) {
                        throw new \InvalidArgumentException('Termín nemá platný formát.');
                    }
                    $dueAt = $local->setTimezone(new \DateTimeZone('UTC'));
                }
                $this->actionItems->create(
                    (int) $this->getUser()->getId(),
                    $this->opportunityId,
                    (string) $values['type'],
                    (string) $values['title'],
                    (string) ($values['details'] ?? ''),
                    $dueAt,
                );
            } catch (\InvalidArgumentException $exception) {
                $form->addError($exception->getMessage());
                return;
            }
            $this->flashMessage('Navazující úkol byl uložen v JobRadaru. Do Todoistu se přenese až samostatnou synchronizací.', 'success');
            $this->redirect('this');
        };
        return $form;
    }

    protected function createComponentApplicationForm(): Form
    {
        $userId = (int) $this->getUser()->getId();
        $offer = $this->opportunities->getDetail($this->opportunityId, $userId);
        if ($offer === null) { $this->error('Nabídka nenalezena.'); }
        $form = new Form();
        $form->addHidden('lockVersion', (string) $offer->decisionState->lockVersion);
        $form->addHidden('idempotencyKey', bin2hex(random_bytes(16)));
        $events = match ($offer->decisionState->workflowStatus) {
            'none', 'preparing', 'awaiting_approval' => ['prepared' => 'Reakce je připravena ke schválení', 'submitted' => 'Reakce byla skutečně odeslána', 'closed' => 'Uzavřít jednání'],
            'submitted', 'awaiting_response' => ['response_received' => 'Přišla odpověď', 'closed' => 'Uzavřít jednání'],
            default => ['closed' => 'Uzavřít jednání'],
        };
        $form->addSelect('event', 'Co se stalo', $events)->setRequired();
        $form->addTextArea('reference', 'Podklad nebo potvrzení')->setRequired()->setMaxLength(2000);
        $zone = new \DateTimeZone('Europe/Prague');
        $form->addText('occurredAt', 'Kdy se to stalo (Praha)')->setHtmlType('datetime-local')->setRequired()->setDefaultValue((new \DateTimeImmutable('now', $zone))->format('Y-m-d\TH:i'));
        $form->addSelect('channel', 'Kanál odeslání', ['portal' => 'Pracovní portál', 'email' => 'E-mail', 'other' => 'Jiný kanál']);
        $form->addText('approvalReference', 'Kde je výslovné schválení odeslání')->setMaxLength(2000);
        $form->addCheckbox('confirmSent', 'Potvrzuji, že reakce byla se souhlasem skutečně odeslána a mám potvrzení odeslání.');
        $form->addText('followUpAt', 'Kdy zkontrolovat odpověď (Praha)')->setHtmlType('datetime-local')->setDefaultValue((new \DateTimeImmutable('+7 days', $zone))->format('Y-m-d\TH:i'));
        $options = [];
        foreach ($this->actionItems->listForOpportunity($userId, $this->opportunityId) as $item) {
            if ($item['status'] === 'open' && $item['action_type'] !== 'follow_up') { $options[(int) $item['id']] = (string) $item['title']; }
        }
        $form->addMultiSelect('completeIds', 'Přípravné úkoly dokončené odesláním', $options);
        $form->addProtection();
        $form->addSubmit('save', 'Uložit stav reakce');
        $form->onSuccess[] = function (Form $form) use ($userId): void {
            $v = (array) $form->getValues();
            try {
                $input = ['event' => (string) $v['event'], 'expected_lock_version' => (int) $v['lockVersion'], 'idempotency_key' => (string) $v['idempotencyKey'], 'reference' => (string) $v['reference'], 'occurred_at' => self::localTime((string) $v['occurredAt'])];
                if ($v['event'] === 'submitted') {
                    if (!$v['confirmSent']) { throw new \InvalidArgumentException('Potvrďte skutečné odeslání. Koncept nestačí.'); }
                    $input += ['channel' => (string) $v['channel'], 'approval_reference' => (string) $v['approvalReference'], 'follow_up_at' => self::localTime((string) $v['followUpAt']), 'complete_action_item_ids' => array_map(intval(...), $v['completeIds'])];
                }
                $this->workflow->record($userId, $this->opportunityId, $input);
            } catch (\InvalidArgumentException|OpportunityConflictException $e) {
                $form->addError($e->getMessage()); return;
            }
            $this->flashMessage($v['event'] === 'submitted' ? 'Odeslání zaznamenáno. Nabídka čeká na odpověď; připomínka a dokončené úkoly se synchronizují do Todoistu.' : 'Stav reakce byl uložen.', 'success');
            $this->redirect('this');
        };
        return $form;
    }

    private static function localTime(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new \DateTimeZone('Europe/Prague'));
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d\TH:i') !== $value || \DateTimeImmutable::getLastErrors() !== false) { throw new \InvalidArgumentException('Vyplňte platné datum a čas.'); }
        return $date->format(DATE_ATOM);
    }

    protected function createComponentActionItemStatusForm(): Form
    {
        $openItems = array_filter(
            $this->actionItems->listForOpportunity((int) $this->getUser()->getId(), $this->opportunityId),
            static fn (array $item): bool => $item['status'] === 'open',
        );
        $options = [];
        foreach ($openItems as $item) {
            $options[(int) $item['id']] = (string) $item['title'];
        }
        $form = new Form();
        $form->addSelect('actionItemId', 'Otevřený úkol', $options)->setRequired();
        $form->addProtection('Platnost formuláře vypršela. Zkuste to prosím znovu.');
        $form->addSubmit('complete', 'Označit jako hotové');
        $form->addSubmit('cancel', 'Zrušit úkol');
        $form->onSuccess[] = function (Form $form): void {
            $values = (array) $form->getValues();
            $complete = $form['complete'];
            if (!$complete instanceof SubmitButton) {
                throw new \LogicException('Formulář navazujícího úkolu není správně sestaven.');
            }
            try {
                if ($complete->isSubmittedBy()) {
                    $this->actionItems->complete((int) $this->getUser()->getId(), (int) $values['actionItemId']);
                } else {
                    $this->actionItems->cancel((int) $this->getUser()->getId(), (int) $values['actionItemId']);
                }
            } catch (\InvalidArgumentException $exception) {
                $form->addError($exception->getMessage());
                return;
            }
            $this->flashMessage('Stav navazujícího úkolu byl uložen. Rozhodnutí ani stav žádosti se nezměnily.', 'success');
            $this->redirect('this');
        };
        return $form;
    }

    protected function createComponentProjectCareForm(): Form
    {
        $opportunity = $this->opportunities->getDetail($this->opportunityId, (int) $this->getUser()->getId());
        if ($opportunity === null) { $this->error('Nabídka nenalezena.'); }
        $form = new Form();
        $form->addHidden('lockVersion', (string) $opportunity->lockVersion);
        \App\Opportunity\ProjectCareForm::add($form);
        $latest = $opportunity->projectCareHistory[0] ?? null;
        $form->onAnchor[] = static function (Form $form) use ($latest): void {
            if (!$form->isSubmitted() && $latest !== null) {
                $form->setDefaults(['projectCareValue' => $latest['value'] === null ? '' : ($latest['value'] ? 'yes' : 'no'), 'projectCareReason' => $latest['reason'], 'projectCareConfidence' => $latest['confidence']]);
            }
        };
        $form->addProtection();
        $form->addSubmit('send', 'Uložit klasifikaci');
        $form->onSuccess[] = function (Form $form): void {
            $v = (array) $form->getValues();
            try { $this->projectCare->save($this->opportunityId, (int) $v['lockVersion'], \App\Opportunity\ProjectCareForm::read($v), (int) $this->getUser()->getId()); }
            catch (\InvalidArgumentException|OpportunityConflictException $e) { $form->addError($e->getMessage()); return; }
            $this->flashMessage('Klasifikace uložena do historie.', 'success');
            $this->redirect('this');
        };
        return $form;
    }

    protected function createComponentCounterpartyForm(): Form
    {
        $opportunity = $this->opportunities->getDetail($this->opportunityId, (int) $this->getUser()->getId());
        if ($opportunity === null) { $this->error('Nabídka nenalezena.'); }
        $form = new Form();
        $form->addHidden('lockVersion', (string) $opportunity->lockVersion);
        \App\Opportunity\CounterpartyForm::add($form);
        $latest = $opportunity->counterpartyHistory[0] ?? null;
        $form->onAnchor[] = static function (Form $form) use ($latest): void {
            if (!$form->isSubmitted() && $latest !== null) {
                $form->setDefaults(['counterpartyValue' => $latest['value'] ?? '', 'counterpartyReason' => $latest['reason'], 'counterpartyConfidence' => $latest['confidence']]);
            }
        };
        $form->addProtection();
        $form->addSubmit('send', 'Uložit klasifikaci');
        $form->onSuccess[] = function (Form $form): void {
            $v = (array) $form->getValues();
            try { $this->counterparty->save($this->opportunityId, (int) $v['lockVersion'], \App\Opportunity\CounterpartyForm::read($v), (int) $this->getUser()->getId()); }
            catch (\InvalidArgumentException|OpportunityConflictException $e) { $form->addError($e->getMessage()); return; }
            $this->flashMessage('Klasifikace uložena do historie.', 'success');
            $this->redirect('this');
        };
        return $form;
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
        $form->onSuccess[] = function (Form $form): void {
            $this->decisionFormSucceeded($form, (array) $form->getValues());
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

<?php

declare(strict_types=1);

namespace App\Presentation\SearchRequest;

use App\Presentation\SecuredPresenter;
use App\Search\SearchRequestControlService;
use App\Search\SourceQueryService;
use Nette\Application\UI\Form;
use Nette\Forms\Controls\SubmitButton;

final class SearchRequestPresenter extends SecuredPresenter
{
    private int $requestId;

    public function __construct(
        private readonly SourceQueryService $queries,
        private readonly SearchRequestControlService $controls,
        private readonly \App\Search\AccessPreparationService $preparation,
    ) {
        parent::__construct();
    }

    public function actionDetail(int $id): void
    {
        $request = $this->queries->getRequestDetail((int) $this->getUser()->getId(), $id);
        if ($request === null) {
            $this->error('Kontrola nebyla nalezena.');
        }
        $this->requestId = $id;
        $this->template->setParameters(['request' => $request, 'preparation' => $this->preparation->status((int) $this->getUser()->getId(), $id)]);
    }

    protected function createComponentControlForm(): Form
    {
        $form = new Form();
        $form->addProtection('Platnost formuláře vypršela. Zkuste to prosím znovu.');
        $form->addSubmit('resume', 'Ověřit přihlášení a pokračovat');
        $form->addSubmit('cancel', 'Zrušit kontrolu');
        $form->onSuccess[] = function (Form $form): void {
            $this->controlFormSucceeded($form);
        };
        return $form;
    }

    protected function createComponentAccessForm(): Form
    {
        $form = new Form();
        $form->addProtection();
        $form->addSubmit('confirm', 'Přihlášení mám připravené – pokračovat v kontrole');
        $form->onSuccess[] = function (Form $form): void {
            try { $this->preparation->confirm((int) $this->getUser()->getId(), $this->requestId); }
            catch (\InvalidArgumentException $e) { $form->addError($e->getMessage()); return; }
            $this->flashMessage('Kontrola může pokračovat. Codex přístup znovu ověří.', 'success');
            $this->redirect('this');
        };
        return $form;
    }

    private function controlFormSucceeded(Form $form): void
    {
        $resume = $form['resume'];
        if (!$resume instanceof SubmitButton) {
            throw new \LogicException('Formulář kontroly není správně sestaven.');
        }
        try {
            if ($resume->isSubmittedBy()) {
                $this->controls->requestResume((int) $this->getUser()->getId(), $this->requestId);
                $this->flashMessage('Pokračování kontroly bylo výslovně vyžádáno.', 'success');
            } else {
                $this->controls->cancel((int) $this->getUser()->getId(), $this->requestId);
                $this->flashMessage('Kontrola byla zrušena. Dosavadní výsledky zůstaly uložené.', 'success');
            }
        } catch (\InvalidArgumentException $exception) {
            $form->addError($exception->getMessage());
            return;
        }
        $this->redirect('this');
    }
}

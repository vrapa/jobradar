<?php

declare(strict_types=1);

namespace App\Presentation;

use Nette\Application\UI\Form;

abstract class SecuredPresenter extends BasePresenter
{
    protected function startup(): void
    {
        parent::startup();
        if (!$this->getUser()->isLoggedIn()) {
            $this->redirect('Sign:in', ['backlink' => $this->storeRequest()]);
        }
    }

    protected function createComponentSignOutForm(): Form
    {
        $form = new Form();
        $form->addProtection('Platnost formuláře vypršela. Zkuste to prosím znovu.');
        $form->addSubmit('send', 'Odhlásit');
        $form->onSuccess[] = function (): void {
            $this->getUser()->logout(true);
            $this->flashMessage('Byli jste bezpečně odhlášeni.', 'success');
            $this->redirect('Sign:in');
        };

        return $form;
    }
}

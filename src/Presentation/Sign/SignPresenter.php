<?php

declare(strict_types=1);

namespace App\Presentation\Sign;

use App\Security\LoginRateLimitException;
use App\Presentation\BasePresenter;
use Nette\Application\Attributes\Persistent;
use Nette\Application\UI\Form;
use Nette\Security\AuthenticationException;

final class SignPresenter extends BasePresenter
{
    #[Persistent]
    public string $backlink = '';

    public function actionIn(): void
    {
        if ($this->getUser()->isLoggedIn()) {
            $this->redirect('Home:');
        }
    }

    protected function createComponentSignInForm(): Form
    {
        $form = new Form();
        $form->addEmail('email', 'E-mail')
            ->setHtmlAttribute('autocomplete', 'username')
            ->setRequired('Zadejte e-mail.');
        $form->addPassword('password', 'Heslo')
            ->setHtmlAttribute('autocomplete', 'current-password')
            ->setRequired('Zadejte heslo.');
        $form->addProtection('Platnost formuláře vypršela. Zkuste to prosím znovu.');
        $form->addSubmit('send', 'Přihlásit se');
        $form->onSuccess[] = function (Form $form): void {
            $this->signInFormSucceeded($form, $form->getValues());
        };

        return $form;
    }

    /** @param array<string, mixed>|object $values */
    private function signInFormSucceeded(Form $form, array|object $values): void
    {
        $credentials = (array) $values;
        try {
            $this->getUser()->setExpiration('12 hours');
            $this->getUser()->login((string) $credentials['email'], (string) $credentials['password']);
        } catch (LoginRateLimitException $exception) {
            $form->addError($exception->getMessage());
            return;
        } catch (AuthenticationException) {
            $form->addError('E-mail nebo heslo není správné.');
            return;
        }

        if ($this->backlink !== '') {
            $this->restoreRequest($this->backlink);
        }
        $this->redirect('Home:');
    }
}

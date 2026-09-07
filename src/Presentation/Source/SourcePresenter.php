<?php

declare(strict_types=1);

namespace App\Presentation\Source;

use App\Presentation\SecuredPresenter;
use App\Search\SearchRequestService;
use App\Search\SourceQueryService;
use Nette\Application\UI\Form;

final class SourcePresenter extends SecuredPresenter
{
    public function __construct(
        private readonly SourceQueryService $queries,
        private readonly SearchRequestService $requests,
    ) {
        parent::__construct();
    }

    public function renderDefault(): void
    {
        $sources = $this->queries->activeCheckableSources();
        $this->template->setParameters([
            'sources' => $sources,
            'recentRequests' => $this->queries->recentRequests((int) $this->getUser()->getId()),
        ]);
    }

    protected function createComponentSearchRequestForm(): Form
    {
        $sources = $this->queries->activeCheckableSources();
        $options = [];
        foreach ($sources as $source) {
            $options[$source->id] = sprintf('%s (priorita %s)', $source->name, $source->priority);
        }
        $form = new Form();
        $form->addCheckboxList('sources', 'Zdroje', $options)
            ->setRequired('Vyberte alespoň jeden zdroj.');
        $form->addHidden('idempotencyKey', bin2hex(random_bytes(20)));
        $form->addProtection('Platnost formuláře vypršela. Zkuste to prosím znovu.');
        $form->addSubmit('send', 'Spustit vybranou kontrolu')
            ->setDisabled($options === []);
        $form->onSuccess[] = function (Form $form, array|object $values): void {
            $this->searchRequestFormSucceeded($form, (array) $values);
        };
        return $form;
    }

    /** @param array<string, mixed> $values */
    private function searchRequestFormSucceeded(Form $form, array $values): void
    {
        $selected = $values['sources'] ?? [];
        if (!is_array($selected)) {
            $form->addError('Výběr zdrojů není platný.');
            return;
        }
        try {
            $result = $this->requests->request(
                (int) $this->getUser()->getId(),
                array_values(array_map('intval', $selected)),
                (string) ($values['idempotencyKey'] ?? ''),
            );
        } catch (\InvalidArgumentException $exception) {
            $form->addError($exception->getMessage());
            return;
        }
        $this->flashMessage(
            $result->created
                ? sprintf('Kontrola #%d čeká na místní runner.', $result->requestId)
                : sprintf('Kontrola #%d už byla vytvořena.', $result->requestId),
            'success',
        );
        $this->redirect('this');
    }
}

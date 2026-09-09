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
        private readonly \App\Search\SourceSettingsService $settings,
    ) {
        parent::__construct();
    }

    public function renderDefault(): void
    {
        $sources = $this->queries->activeCheckableSources();
        $this->template->setParameters([
            'sources' => $sources,
            'manualSources' => $this->settings->manualSources(),
            'definitions' => array_column(array_map(fn ($s): array => ['id' => $s->id, 'items' => $this->settings->definitions($s->id)], $sources), 'items', 'id'),
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
        $selected = (int) ($this->getParameter('source') ?? 0);
        $form->onAnchor[] = static function (Form $form) use ($options, $selected, $sources): void {
            if (!$form->isSubmitted()) {
                $form->setDefaults(['sources' => isset($options[$selected]) ? [$selected] : array_values(array_map(static fn ($s): int => $s->id, array_filter($sources, static fn ($s): bool => $s->priority === 'A')))]);
            }
        };
        $form->addHidden('idempotencyKey', bin2hex(random_bytes(20)));
        $form->addCheckbox('prepareAccess', 'Nejprve připravit přihlášení v Chrome – Práce')->setDefaultValue(true);
        $form->addProtection('Platnost formuláře vypršela. Zkuste to prosím znovu.');
        $form->addSubmit('send', 'Spustit vybranou kontrolu')
            ->setDisabled($options === []);
        $form->onSuccess[] = function (Form $form): void {
            $this->searchRequestFormSucceeded($form, (array) $form->getValues());
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
                (bool) ($values['prepareAccess'] ?? false),
            );
        } catch (\InvalidArgumentException $exception) {
            $form->addError($exception->getMessage());
            return;
        }
        $this->flashMessage(
            $result->created
                ? sprintf('Kontrola #%d byla zařazena. Čeká na Codex na vašem počítači.', $result->requestId)
                : sprintf('Kontrola #%d už byla vytvořena.', $result->requestId),
            'success',
        );
        $this->redirect('SearchRequest:detail', $result->requestId);
    }
}

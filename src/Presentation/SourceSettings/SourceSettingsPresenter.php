<?php

declare(strict_types=1);

namespace App\Presentation\SourceSettings;

use App\Presentation\SecuredPresenter;
use App\Search\SourceSettingsService;
use Nette\Application\UI\Form;

final class SourceSettingsPresenter extends SecuredPresenter
{
    private int $sourceId;
    public function __construct(private readonly SourceSettingsService $settings) { parent::__construct(); }

    public function actionDefault(int $id): void
    {
        if (!$this->getUser()->isInRole('admin')) { $this->error('Pouze pro administrátora.', 403); }
        $this->sourceId = $id;
        try { $source = $this->settings->get($id); }
        catch (\InvalidArgumentException) { $this->error('Zdroj nebyl nalezen.', 404); }
        $definitions = $this->settings->definitions($id);
        $this->template->setParameters(['source' => $source, 'definitions' => $definitions]);
        $form = $this->getComponent('settingsForm');
        if ($form instanceof Form && !$form->isSubmitted()) {
            $defaults = ['version' => $source['lock_version'], 'priority' => $source['priority'], 'active' => true, 'tags' => json_decode((string) ($source['purpose_tags_json'] ?? '[]'), true)];
            foreach ($definitions as $definition) {
                if ((int) $this->getParameter('definition') === (int) $definition['id']) {
                    $defaults += ['name' => $definition['name'], 'query' => $definition['query_text']];
                    $defaults['active'] = (bool) $definition['active'];
                }
            }
            $form->setDefaults($defaults);
        }
        if (!in_array($source['source_type'], ['manual','manual_search'], true)) {
            $planForm = $this->getComponent('planForm');
            if ($planForm instanceof Form && !$planForm->isSubmitted()) {
                $active = array_values(array_filter($definitions, static fn ($d): bool => (bool) $d['active']));
                usort($active, static fn ($a, $b): int => [$b['version'], $b['id']] <=> [$a['version'], $a['id']]);
                $d = $active[0] ?? null;
                $steps = $d === null ? [] : ($d['steps_json'] === null ? [['key' => 'original', 'name' => 'Původní hledání', 'query' => $d['query_text'], 'filters' => json_decode((string) ($d['filters_json'] ?? '{}'), true), 'limit' => (int) ($d['result_limit'] ?? 20)]] : json_decode((string) $d['steps_json'], true));
                $planForm->setDefaults(['version' => $source['lock_version'], 'steps' => json_encode($steps, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'limit' => $d['result_limit'] ?? 20, 'guidance' => $d['review_guidance'] ?? '']);
            }
        }
    }

    protected function createComponentSettingsForm(): Form
    {
        $source = $this->settings->get($this->sourceId);
        $form = new Form();
        $form->addHidden('version');
        $form->addSelect('priority', 'Priorita', ['A' => 'A – nejdůležitější', 'B' => 'B – běžná', 'C' => 'C – doplňková']);
        $form->addCheckboxList('tags', 'Zaměření zdroje', \App\Search\SearchPlan::TAGS);
        if ($source['source_type'] === 'manual_search') {
            $form->addText('name', 'Název dotazu (prázdné = změnit jen prioritu)')->addRule(Form::MaxLength, 'Nejvýše 255 znaků.', 255);
            $form->addTextArea('query', 'Vyhledávací dotaz')->addRule(Form::MaxLength, 'Nejvýše 2000 znaků.', 2000);
            $form->addCheckbox('active', 'Dotaz aktivní');
        }
        $form->addProtection();
        $form->addSubmit('send', 'Uložit');
        $form->onSuccess[] = function (Form $form): void {
            $v = (array) $form->getValues();
            try { $this->settings->save((int) $this->getUser()->getId(), $this->sourceId, (int) $v['version'], (string) $v['priority'], $v['name'] ?? null, $v['query'] ?? null, (bool) ($v['active'] ?? true), (array) $v['tags']); }
            catch (\InvalidArgumentException $e) { $form->addError($e->getMessage()); return; }
            $this->flashMessage('Nastavení zdroje uloženo.', 'success');
            $this->redirect('Source:');
        };
        return $form;
    }

    protected function createComponentPlanForm(): Form
    {
        $form = new Form();
        $form->addHidden('version');
        $form->addTextArea('steps', 'Kroky hledání (JSON)')->setRequired();
        $form->addInteger('limit', 'Společný limit výsledků')->setRequired()->addRule(Form::Range, 'Limit musí být 1–1000.', [1,1000]);
        $form->addTextArea('guidance', 'Pokyny k posouzení')->addRule(Form::MaxLength, 'Nejvýše 10000 znaků.', 10000);
        $form->addTextArea('verification', 'Doložení podpory hledání')->setRequired('Popište ověření dotazů a filtrů na portálu.')->addRule(Form::MaxLength, 'Nejvýše 2000 znaků.', 2000);
        $form->addProtection();
        $form->addSubmit('send', 'Uložit novou verzi zadání');
        $form->onSuccess[] = function (Form $form): void {
            $v = (array) $form->getValues();
            try { $this->settings->savePlan((int) $this->getUser()->getId(), $this->sourceId, (int) $v['version'], $v['steps'], (int) $v['limit'], $v['guidance'], $v['verification']); }
            catch (\InvalidArgumentException $e) { $form->addError($e->getMessage()); return; }
            $this->flashMessage('Nové zadání uloženo. Rozepsané kontroly zůstávají beze změny.', 'success');
            $this->redirect('this');
        };
        return $form;
    }
}

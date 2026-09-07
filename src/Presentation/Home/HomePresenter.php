<?php

declare(strict_types=1);

namespace App\Presentation\Home;

use App\Opportunity\OpportunityQueryService;
use App\Presentation\SecuredPresenter;

final class HomePresenter extends SecuredPresenter
{
    public function __construct(private readonly OpportunityQueryService $opportunities)
    {
        parent::__construct();
    }

    public function renderDefault(): void
    {
        $items = $this->opportunities->listCurrent((int) $this->getUser()->getId());
        $this->template->setParameters([
            'opportunities' => $items,
            'opportunityCount' => count($items),
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
}

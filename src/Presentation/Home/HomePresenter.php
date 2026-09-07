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
        $items = $this->opportunities->listCurrent();
        $this->template->setParameters([
            'opportunities' => $items,
            'opportunityCount' => count($items),
        ]);
    }
}

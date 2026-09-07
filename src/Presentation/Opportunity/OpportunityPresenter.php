<?php

declare(strict_types=1);

namespace App\Presentation\Opportunity;

use App\Opportunity\OpportunityQueryService;
use App\Presentation\SecuredPresenter;

final class OpportunityPresenter extends SecuredPresenter
{
    public function __construct(private readonly OpportunityQueryService $opportunities)
    {
        parent::__construct();
    }

    public function renderDetail(int $id): void
    {
        $opportunity = $this->opportunities->getDetail($id);
        if ($opportunity === null) {
            $this->error('Nabídka nebyla nalezena.');
        }

        $this->template->setParameters(['opportunity' => $opportunity]);
    }
}

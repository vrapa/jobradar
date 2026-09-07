<?php

declare(strict_types=1);

namespace App\Runner;

use App\Search\SourceRunResult;
use App\Search\SourceRunScope;

interface RunnerSourceAdapterInterface
{
    public function supports(RunnerSource $source): bool;

    public function scope(RunnerSource $source): SourceRunScope;

    public function traverse(RunnerSource $source): SourceRunResult;
}

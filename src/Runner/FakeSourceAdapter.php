<?php

declare(strict_types=1);

namespace App\Runner;

use App\Search\SourceRunResult;
use App\Search\SourceRunScope;

final class FakeSourceAdapter implements RunnerSourceAdapterInterface
{
    public function supports(RunnerSource $source): bool
    {
        return $source->type === 'fake';
    }

    public function scope(RunnerSource $source): SourceRunScope
    {
        return new SourceRunScope('Úplný syntetický průchod falešného zdroje pro test runneru.');
    }

    public function traverse(RunnerSource $source): SourceRunResult
    {
        return new SourceRunResult(
            status: 'complete',
            pagesTraversed: 1,
            displayedCount: 0,
            detailOpenedCount: 0,
            storedCount: 0,
            updatedCount: 0,
            duplicateCount: 0,
            rejectedCount: 0,
        );
    }
}

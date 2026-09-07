<?php

declare(strict_types=1);

namespace App\Runner;

use App\Search\SourceRunResult;
use App\Search\SourceRunScope;

final class RunnerWorker
{
    /** @param list<RunnerSourceAdapterInterface> $adapters */
    public function __construct(
        private readonly RunnerApiClientInterface $api,
        private readonly array $adapters,
    ) {
    }

    public function workOnce(): RunnerWorkResult
    {
        $sources = [];
        foreach ($this->api->listSources() as $source) {
            $sources[$source->id] = $source;
        }
        $lease = $this->api->claimLease();
        if ($lease === null) {
            return new RunnerWorkResult('idle', null, 0);
        }

        $processed = 0;
        $status = 'completed';
        foreach ($lease->sourceIds as $sourceId) {
            $source = $sources[$sourceId] ?? null;
            if (!$source instanceof RunnerSource) {
                $this->api->startSource(
                    $lease->runId,
                    $sourceId,
                    $lease->token,
                    new SourceRunScope('Ověření metadat zdroje bez průchodu externího obsahu.'),
                );
                $this->api->finishSource($lease->runId, $sourceId, $lease->token, new SourceRunResult(
                    'error',
                    incompleteReason: 'Zdroj přidělený během nebyl dostupný v API registru; externí obsah nebyl procházen.',
                    errorCode: 'source_metadata_unavailable',
                ));
                $processed++;
                $status = 'partial';
                continue;
            }
            $adapter = $this->adapterFor($source);
            $scope = $adapter?->scope($source)
                ?? new SourceRunScope('Předběžné ověření dostupnosti adaptéru bez průchodu externího zdroje.');
            $this->api->startSource($lease->runId, $sourceId, $lease->token, $scope);
            if ($adapter === null) {
                $result = new SourceRunResult(
                    'error',
                    incompleteReason: 'Pro typ zdroje není nainstalovaný adaptér; externí obsah nebyl procházen.',
                    errorCode: 'adapter_unavailable',
                );
            } else {
                try {
                    $result = $adapter->traverse($source);
                } catch (\Throwable) {
                    $result = new SourceRunResult(
                        'error',
                        incompleteReason: 'Adaptér selhal; podrobnosti z nedůvěryhodného zdroje nebyly uloženy do logu.',
                        errorCode: 'adapter_failure',
                    );
                }
            }
            $this->api->finishSource($lease->runId, $sourceId, $lease->token, $result);
            $processed++;
            if ($result->status === 'waiting_for_login') {
                $status = 'waiting_for_login';
                break;
            }
            if ($result->status !== 'complete') {
                $status = 'partial';
            }
        }

        return new RunnerWorkResult($status, $lease->requestId, $processed);
    }

    private function adapterFor(RunnerSource $source): ?RunnerSourceAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($source)) {
                return $adapter;
            }
        }
        return null;
    }
}

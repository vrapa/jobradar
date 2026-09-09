<?php

declare(strict_types=1);

namespace App\Search;

/** Immutable JSON contract stored on the pinned search definition. */
final class SearchPlan
{
    public const TAGS = ['client_requests' => 'Klientské poptávky', 'contracts' => 'Projektové kontrakty', 'jobs' => 'Pracovní nabídky', 'subcontracting' => 'Subdodávky'];

    /** @return list<array{key:string,name:string,query:string,mode:string,filters:array<string,mixed>,limit:int}> */
    public static function parse(string $json, int $totalLimit): array
    {
        try { $steps = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new \InvalidArgumentException('Kroky nejsou platný JSON.', previous: $e); }
        if ($totalLimit < 1 || $totalLimit > 1000 || !is_array($steps) || !array_is_list($steps) || count($steps) < 1 || count($steps) > 10) {
            throw new \InvalidArgumentException('Zadání vyžaduje 1–10 kroků a společný limit 1–1000.');
        }
        $result = [];
        foreach ($steps as $step) {
            if (!is_array($step) || array_diff(array_keys($step), ['key','name','query','mode','filters','limit']) !== []
                || !in_array($step['mode'] ?? 'keywords', ['keywords','category','skill'], true)
                || !is_string($step['key'] ?? null) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $step['key'])
                || !is_string($step['name'] ?? null) || trim($step['name']) === '' || mb_strlen($step['name']) > 255
                || !is_string($step['query'] ?? null) || trim($step['query']) === '' || mb_strlen($step['query']) > 2000
                || !is_int($step['limit'] ?? null) || $step['limit'] < 1 || $step['limit'] > $totalLimit
                || !is_array($step['filters'] ?? []) || (($step['filters'] ?? []) !== [] && array_is_list($step['filters']))) {
                throw new \InvalidArgumentException('Neplatný krok: key, name, query, filters (objekt), limit.');
            }
            if (in_array($step['key'], array_column($result, 'key'), true)) { throw new \InvalidArgumentException('Klíče kroků musí být jedinečné.'); }
            $result[] = ['key' => $step['key'], 'name' => trim($step['name']), 'query' => trim($step['query']), 'mode' => $step['mode'] ?? 'keywords', 'filters' => $step['filters'] ?? [], 'limit' => $step['limit']];
        }
        return $result;
    }
}

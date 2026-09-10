<?php

declare(strict_types=1);

namespace App\Opportunity;

final class AttachmentReviewInput
{
    /** @return array<string,mixed> */
    public static function schema(): array
    {
        return ['type' => 'array', 'description' => 'Attachment evidence. Pending creates one follow-up task; resolution needs findings/evidence and the current expectedRevision. Never submits or changes decisions.', 'maxItems' => 50, 'items' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['key', 'name', 'status', 'reason', 'observedAt'],
            'properties' => [
                'key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'status' => ['type' => 'string', 'enum' => ['pending', 'reviewed', 'not_needed']],
                'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000],
                'questions' => ['type' => 'string', 'maxLength' => 4000],
                'findings' => ['type' => 'string', 'maxLength' => 20000],
                'evidence' => ['type' => 'string', 'maxLength' => 4000],
                'observedAt' => ['type' => 'string', 'format' => 'date-time'],
                'expectedRevision' => ['type' => 'integer', 'minimum' => 1],
            ],
        ]];
    }

    /** @return list<array<string,mixed>> */
    public static function parse(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) > 50) {
            throw new \InvalidArgumentException('Přílohy musí být seznam nejvýše 50 záznamů.');
        }
        $keys = [];
        foreach ($items as &$item) {
            if (!is_array($item) || array_diff(array_keys($item), ['key', 'name', 'status', 'reason', 'questions', 'findings', 'evidence', 'observedAt', 'expectedRevision']) !== []) {
                throw new \InvalidArgumentException('Neplatná pole přílohy.');
            }
            foreach (['key' => 160, 'name' => 255, 'status' => 32, 'reason' => 4000, 'questions' => 4000, 'findings' => 20000, 'evidence' => 4000, 'observedAt' => 64] as $field => $max) {
                $value = $item[$field] ?? '';
                if (!is_string($value) || mb_strlen($value) > $max) { throw new \InvalidArgumentException('Neplatný text přílohy: ' . $field); }
                $item[$field] = trim($value);
            }
            if ($item['key'] === '' || isset($keys[$item['key']]) || $item['name'] === '' || $item['reason'] === '' || !in_array($item['status'], ['pending', 'reviewed', 'not_needed'], true)) {
                throw new \InvalidArgumentException('Příloha vyžaduje jedinečný klíč, název, stav a konkrétní důvod.');
            }
            $keys[$item['key']] = true;
            if (($item['status'] === 'pending' && $item['questions'] === '') || ($item['status'] === 'reviewed' && ($item['findings'] === '' || $item['evidence'] === ''))) {
                throw new \InvalidArgumentException('Čekající příloha vyžaduje otázky; přečtená příloha zjištění a doklad přečtení.');
            }
            if (isset($item['expectedRevision']) && (!is_int($item['expectedRevision']) || $item['expectedRevision'] < 1)) { throw new \InvalidArgumentException('Neplatná revize přílohy.'); }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $item['observedAt'])) { throw new \InvalidArgumentException('Příloha vyžaduje čas pozorování včetně pásma.'); }
            try { $date = new \DateTimeImmutable($item['observedAt']); }
            catch (\Exception) { throw new \InvalidArgumentException('Neplatný čas pozorování přílohy.'); }
            if (\DateTimeImmutable::getLastErrors() !== false) { throw new \InvalidArgumentException('Neplatné datum pozorování přílohy.'); }
            if ($date > new \DateTimeImmutable('+5 minutes')) { throw new \InvalidArgumentException('Pozorování přílohy nemůže být v budoucnosti.'); }
        }
        unset($item);
        return $items;
    }
}

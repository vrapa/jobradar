<?php

declare(strict_types=1);

namespace App\Opportunity;

final readonly class ProjectKindInput
{
    public const LABELS = [
        'new_build' => 'Nový vývoj a návrh řešení',
        'takeover' => 'Převzetí nebo modernizace',
        'prototype_to_production' => 'Dokončení prototypu pro provoz',
    ];

    /** @var non-empty-list<string>|null */
    public ?array $values;

    /** @param array<mixed>|null $values */
    public function __construct(
        ?array $values,
        public ?string $reason = null,
        public ?float $confidence = null,
        public ?\DateTimeImmutable $verifiedAt = null,
    ) {
        if ($values !== null) {
            if ($values === [] || !array_is_list($values) || count($values) !== count(array_unique($values)) || array_diff($values, array_keys(self::LABELS)) !== []) {
                throw new \InvalidArgumentException('Neplatné typy projektu.');
            }
            new ProjectCareInput(true, $reason, $confidence, $verifiedAt);
        } elseif ($reason !== null || $confidence !== null || $verifiedAt !== null) {
            throw new \InvalidArgumentException('Neověřený typ projektu nemá potvrzené podklady.');
        }
        /** @var non-empty-list<string>|null $values */
        $this->values = $values;
    }

    public static function fromPayload(mixed $data): ?self
    {
        if ($data === null) { return null; }
        if (!is_array($data) || !array_key_exists('values', $data) || array_diff(array_keys($data), ['values', 'reason', 'confidence', 'verifiedAt']) !== []) {
            throw new \InvalidArgumentException('Neplatná klasifikace typu projektu.');
        }
        if ($data['values'] === null) { return new self(null); }
        if (!is_array($data['values']) || !array_is_list($data['values']) || array_any($data['values'], static fn ($value): bool => !is_string($value))) {
            throw new \InvalidArgumentException('Typy projektu musí být pole podporovaných hodnot nebo null.');
        }
        $evidence = ProjectCareInput::fromPayload([
            'value' => true,
            'reason' => $data['reason'] ?? null,
            'confidence' => $data['confidence'] ?? null,
            'verifiedAt' => $data['verifiedAt'] ?? null,
        ]);
        if ($evidence === null) { throw new \LogicException('Chybí podklady typu projektu.'); }
        return new self($data['values'], $evidence->reason, $evidence->confidence, $evidence->verifiedAt);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => ['object', 'null'],
            'required' => ['values'],
            'additionalProperties' => false,
            'properties' => [
                'values' => [
                    'type' => ['array', 'null'],
                    'minItems' => 1,
                    'maxItems' => count(self::LABELS),
                    'uniqueItems' => true,
                    'items' => ['type' => 'string', 'enum' => array_keys(self::LABELS)],
                ],
                'reason' => ['type' => ['string', 'null']],
                'confidence' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1],
                'verifiedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            ],
        ];
    }
}

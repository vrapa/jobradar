<?php

declare(strict_types=1);

namespace App\Opportunity;

final readonly class CounterpartyInput
{
    public const LABELS = ['owner' => 'Vlastník / provozovatel aplikace', 'supplier' => 'Agentura / hlavní dodavatel', 'recruiter' => 'Náborový prostředník'];

    public function __construct(public ?string $value, public ?string $reason = null, public ?float $confidence = null, public ?\DateTimeImmutable $verifiedAt = null)
    {
        if ($value !== null && !isset(self::LABELS[$value])) { throw new \InvalidArgumentException('Neplatná role protistrany.'); }
        new ProjectCareInput($value === null ? null : true, $reason, $confidence, $verifiedAt);
    }

    public static function fromPayload(mixed $data): ?self
    {
        if ($data === null) { return null; }
        if (!is_array($data) || !array_key_exists('value', $data) || ($data['value'] !== null && !is_string($data['value']))) { throw new \InvalidArgumentException('Neplatná protistrana.'); }
        $evidence = ProjectCareInput::fromPayload([...$data, 'value' => $data['value'] === null ? null : true]);
        if ($evidence === null) { throw new \LogicException('Chybí podklady protistrany.'); }
        return new self($data['value'], $evidence->reason, $evidence->confidence, $evidence->verifiedAt);
    }

    /** @return array<string,mixed> */
    public static function schema(): array
    {
        $schema = ProjectCareInput::schema();
        $schema['properties']['value'] = ['type' => ['string','null'], 'enum' => ['owner','supplier','recruiter',null]];
        return $schema;
    }
}

<?php

declare(strict_types=1);

namespace App\Opportunity;

final readonly class ProjectCareInput
{
    public function __construct(public ?bool $value, public ?string $reason = null, public ?float $confidence = null, public ?\DateTimeImmutable $verifiedAt = null)
    {
        if ($value !== null && (trim((string) $reason) === '' || $confidence === null || $verifiedAt === null)) {
            throw new \InvalidArgumentException('Klasifikace vyžaduje zdůvodnění, jistotu a čas ověření.');
        }
        if ($confidence !== null && (!is_finite($confidence) || $confidence < 0 || $confidence > 1)) {
            throw new \InvalidArgumentException('Jistota musí být mezi 0 a 1.');
        }
    }

    public static function fromPayload(mixed $data): ?self
    {
        if ($data === null) { return null; }
        if (!is_array($data) || !array_key_exists('value', $data) || array_diff(array_keys($data), ['value','reason','confidence','verifiedAt']) !== []) {
            throw new \InvalidArgumentException('Neplatná klasifikace projectCare.');
        }
        if (($data['value'] !== null && !is_bool($data['value'])) || (isset($data['reason']) && !is_string($data['reason'])) || (isset($data['confidence']) && !is_float($data['confidence']) && !is_int($data['confidence']))) {
            throw new \InvalidArgumentException('Neplatné hodnoty klasifikace.');
        }
        $time = null;
        if (isset($data['verifiedAt'])) {
            if (!is_string($data['verifiedAt']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $data['verifiedAt'])) { throw new \InvalidArgumentException('Čas ověření musí být ISO 8601 včetně pásma.'); }
            try { $time = new \DateTimeImmutable($data['verifiedAt']); }
            catch (\Exception $e) { throw new \InvalidArgumentException('Neplatný čas ověření.', previous: $e); }
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) { throw new \InvalidArgumentException('Neplatný čas ověření.'); }
            $time = $time->setTimezone(new \DateTimeZone('UTC'));
        }
        return new self($data['value'], $data['reason'] ?? null, isset($data['confidence']) ? (float) $data['confidence'] : null, $time);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return ['type' => ['object','null'], 'required' => ['value'], 'additionalProperties' => false, 'properties' => [
            'value' => ['type' => ['boolean','null']], 'reason' => ['type' => ['string','null']],
            'confidence' => ['type' => ['number','null'], 'minimum' => 0, 'maximum' => 1],
            'verifiedAt' => ['type' => ['string','null'], 'format' => 'date-time'],
        ]];
    }
}

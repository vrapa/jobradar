<?php

declare(strict_types=1);

namespace App\Opportunity;

final readonly class CompanyLeadInput
{
    public function __construct(
        public ?string $contactName = null,
        public ?string $contactRole = null,
        public ?string $channel = null,
        public ?string $profileUrl = null,
        public ?string $context = null,
    ) {
        $this->length($contactName, 255, 'Jméno kontaktu');
        $this->length($contactRole, 255, 'Role kontaktu');
        $this->length($channel, 100, 'Kanál kontaktu');
        $this->length($profileUrl, 2048, 'Odkaz na profil nebo konverzaci');
        $this->length($context, 65_535, 'Kontext oslovení');
        if ($profileUrl !== null && trim($profileUrl) !== '') {
            $parts = parse_url(trim($profileUrl));
            if (filter_var(trim($profileUrl), FILTER_VALIDATE_URL) === false || !is_array($parts)
                || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass'])) {
                throw new \InvalidArgumentException('Odkaz na profil nebo konverzaci musí být bezpečná HTTP(S) URL.');
            }
        }
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => ['object', 'null'],
            'additionalProperties' => false,
            'properties' => [
                'contactName' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'contactRole' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'channel' => ['type' => ['string', 'null'], 'maxLength' => 100],
                'profileUrl' => ['type' => ['string', 'null'], 'maxLength' => 2048],
                'context' => ['type' => ['string', 'null'], 'maxLength' => 65535],
            ],
        ];
    }

    /** @param mixed $payload */
    public static function fromPayload(mixed $payload): ?self
    {
        if ($payload === null) {
            return null;
        }
        if (!is_array($payload) || array_is_list($payload)
            || array_diff(array_keys($payload), ['contactName', 'contactRole', 'channel', 'profileUrl', 'context']) !== []) {
            throw new \InvalidArgumentException('Údaje firemního kontaktu nejsou platné.');
        }
        foreach ($payload as $value) {
            if ($value !== null && !is_string($value)) {
                throw new \InvalidArgumentException('Pole firemního kontaktu musí být text nebo null.');
            }
        }
        return new self(
            self::nullable($payload['contactName'] ?? null),
            self::nullable($payload['contactRole'] ?? null),
            self::nullable($payload['channel'] ?? null),
            self::nullable($payload['profileUrl'] ?? null),
            self::nullable($payload['context'] ?? null),
        );
    }

    private function length(?string $value, int $maximum, string $label): void
    {
        if ($value !== null && mb_strlen(trim($value)) > $maximum) {
            throw new \InvalidArgumentException(sprintf('%s smí mít nejvýše %d znaků.', $label, $maximum));
        }
    }

    private static function nullable(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);
        return $value === '' ? null : $value;
    }
}

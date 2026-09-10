<?php

declare(strict_types=1);

namespace App\Opportunity;

final class OpportunityJsonMapper
{
    private const ALLOWED_KEYS = [
        'url', 'originalTitle', 'originalText', 'companyName', 'translatedTitle',
        'translatedText', 'summary', 'sourceLanguage', 'incomplete',
        'projectCare', 'discoveryDefinitionId', 'counterparty', 'attachmentReviews',
    ];

    public function __construct(private readonly UrlNormalizer $urlNormalizer)
    {
    }

    /** @return list<OpportunityImport> */
    public function map(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('JSON není platný: ' . $exception->getMessage(), previous: $exception);
        }
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Kořenem JSON musí být objekt nabídky nebo pole nabídek.');
        }

        $items = array_is_list($decoded) ? $decoded : [$decoded];
        $imports = [];
        foreach ($items as $index => $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new \InvalidArgumentException(sprintf('Položka %d musí být JSON objekt.', $index + 1));
            }
            $unknown = array_diff(array_keys($item), self::ALLOWED_KEYS);
            if ($unknown !== []) {
                throw new \InvalidArgumentException(sprintf(
                    'Položka %d obsahuje neznámá pole: %s.',
                    $index + 1,
                    implode(', ', $unknown),
                ));
            }
            $url = $this->requiredString($item, 'url', $index);
            if (isset($item['discoveryDefinitionId']) && (!is_int($item['discoveryDefinitionId']) || $item['discoveryDefinitionId'] < 1)) { throw new \InvalidArgumentException('Neplatná definice nalezení.'); }
            $this->urlNormalizer->normalize($url);
            $imports[] = new OpportunityImport(
                url: $url,
                originalTitle: $this->requiredString($item, 'originalTitle', $index),
                originalText: $this->requiredString($item, 'originalText', $index),
                companyName: $this->optionalString($item, 'companyName', $index),
                translatedTitle: $this->optionalString($item, 'translatedTitle', $index),
                translatedText: $this->optionalString($item, 'translatedText', $index),
                summary: $this->optionalString($item, 'summary', $index),
                sourceLanguage: $this->optionalString($item, 'sourceLanguage', $index),
                incomplete: $this->optionalBool($item, 'incomplete', $index),
                projectCare: ProjectCareInput::fromPayload($item['projectCare'] ?? null),
                discoveryDefinitionId: $item['discoveryDefinitionId'] ?? null,
                counterparty: CounterpartyInput::fromPayload($item['counterparty'] ?? null),
                attachmentReviews: AttachmentReviewInput::parse($item['attachmentReviews'] ?? []),
            );
        }

        return $imports;
    }

    /** @param array<string, mixed> $item */
    private function requiredString(array $item, string $key, int $index): string
    {
        $value = $this->optionalString($item, $key, $index);
        if ($value === null) {
            throw new \InvalidArgumentException(sprintf('Položce %d chybí povinné pole %s.', $index + 1, $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $item */
    private function optionalString(array $item, string $key, int $index): ?string
    {
        if (!array_key_exists($key, $item) || $item[$key] === null) {
            return null;
        }
        if (!is_string($item[$key])) {
            throw new \InvalidArgumentException(sprintf('Pole %s v položce %d musí být text nebo null.', $key, $index + 1));
        }
        $value = trim($item[$key]);
        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $item */
    private function optionalBool(array $item, string $key, int $index): bool
    {
        if (!array_key_exists($key, $item)) {
            return false;
        }
        if (!is_bool($item[$key])) {
            throw new \InvalidArgumentException(sprintf('Pole %s v položce %d musí být true nebo false.', $key, $index + 1));
        }
        return $item[$key];
    }
}

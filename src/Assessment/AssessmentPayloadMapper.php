<?php

declare(strict_types=1);

namespace App\Assessment;

final class AssessmentPayloadMapper
{
    /** @var list<string> */
    private const ROOT_KEYS = [
        'opportunityId', 'expectedLockVersion', 'candidateProfileId', 'scoringRuleSetId',
        'recommendation', 'coverage', 'confidence', 'summary', 'scoreMin', 'scoreMax',
        'verifiedPoints', 'authorType', 'modelIdentifier', 'breakdowns', 'findings',
    ];
    /** @var list<string> */
    private const BREAKDOWN_KEYS = ['area', 'explanation', 'weight', 'scoreMin', 'scoreMax', 'evidence'];
    /** @var list<string> */
    private const FINDING_KEYS = ['type', 'severity', 'text', 'evidenceReference'];

    public function map(string $json): AssessmentPayload
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('JSON posouzení není platný: ' . $exception->getMessage(), previous: $exception);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('Kořenem posouzení musí být JSON objekt.');
        }
        $this->assertKnownKeys($data, self::ROOT_KEYS, 'posouzení');
        $authorType = $this->string($data, 'authorType');
        if (!in_array($authorType, ['assistant', 'system'], true)) {
            throw new \InvalidArgumentException('CLI přijímá pouze autora assistant nebo system.');
        }

        return new AssessmentPayload(
            opportunityId: $this->integer($data, 'opportunityId'),
            expectedLockVersion: $this->integer($data, 'expectedLockVersion'),
            assessment: new AssessmentInput(
                candidateProfileId: $this->integer($data, 'candidateProfileId'),
                scoringRuleSetId: $this->integer($data, 'scoringRuleSetId'),
                recommendation: AssessmentRecommendation::from($this->string($data, 'recommendation')),
                coverage: $this->decimal($data, 'coverage', true) ?? '',
                confidence: $this->decimal($data, 'confidence', true) ?? '',
                summary: $this->string($data, 'summary'),
                scoreMin: $this->decimal($data, 'scoreMin'),
                scoreMax: $this->decimal($data, 'scoreMax'),
                verifiedPoints: $this->decimal($data, 'verifiedPoints'),
                authorType: $authorType,
                modelIdentifier: $this->optionalString($data, 'modelIdentifier'),
            ),
            breakdowns: $this->breakdowns($data['breakdowns'] ?? []),
            findings: $this->findings($data['findings'] ?? []),
        );
    }

    /** @return list<AssessmentBreakdownInput> */
    private function breakdowns(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Pole breakdowns musí být pole objektů.');
        }
        $result = [];
        foreach ($value as $index => $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new \InvalidArgumentException(sprintf('Rozklad %d musí být objekt.', $index + 1));
            }
            $this->assertKnownKeys($item, self::BREAKDOWN_KEYS, 'breakdown');
            $result[] = new AssessmentBreakdownInput(
                area: $this->string($item, 'area'),
                explanation: $this->string($item, 'explanation'),
                weight: $this->decimal($item, 'weight'),
                scoreMin: $this->decimal($item, 'scoreMin'),
                scoreMax: $this->decimal($item, 'scoreMax'),
                evidence: $this->optionalString($item, 'evidence'),
            );
        }
        return $result;
    }

    /** @return list<AssessmentFindingInput> */
    private function findings(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Pole findings musí být pole objektů.');
        }
        $result = [];
        foreach ($value as $index => $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new \InvalidArgumentException(sprintf('Nález %d musí být objekt.', $index + 1));
            }
            $this->assertKnownKeys($item, self::FINDING_KEYS, 'finding');
            $result[] = new AssessmentFindingInput(
                type: $this->string($item, 'type'),
                severity: $this->string($item, 'severity'),
                text: $this->string($item, 'text'),
                evidenceReference: $this->optionalString($item, 'evidenceReference'),
            );
        }
        return $result;
    }

    /** @param array<string, mixed> $data */
    private function integer(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new \InvalidArgumentException(sprintf('Pole %s musí být celé číslo.', $key));
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $this->optionalString($data, $key);
        if ($value === null) {
            throw new \InvalidArgumentException(sprintf('Pole %s musí být neprázdný text.', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    private function optionalString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (!is_string($data[$key])) {
            throw new \InvalidArgumentException(sprintf('Pole %s musí být text nebo null.', $key));
        }
        $value = trim($data[$key]);
        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $data */
    private function decimal(array $data, string $key, bool $required = false): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            if ($required) {
                throw new \InvalidArgumentException(sprintf('Pole %s je povinné.', $key));
            }
            return null;
        }
        if (!is_string($data[$key])) {
            throw new \InvalidArgumentException(sprintf('Pole %s musí být desetinné číslo zapsané jako JSON řetězec.', $key));
        }
        return trim($data[$key]);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     */
    private function assertKnownKeys(array $data, array $allowed, string $section): void
    {
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Sekce %s obsahuje neznámá pole: %s.', $section, implode(', ', $unknown)));
        }
    }
}

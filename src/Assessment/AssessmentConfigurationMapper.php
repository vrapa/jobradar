<?php

declare(strict_types=1);

namespace App\Assessment;

final class AssessmentConfigurationMapper
{
    /** @var list<string> */
    private const ROOT_KEYS = ['profile', 'ruleSet'];
    /** @var list<string> */
    private const PROFILE_KEYS = ['name', 'version', 'description', 'parameters'];
    /** @var list<string> */
    private const RULE_KEYS = ['name', 'version', 'description', 'rules'];

    public function map(string $json): AssessmentConfigurationInput
    {
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('JSON konfigurace není platný: ' . $exception->getMessage(), previous: $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \InvalidArgumentException('Kořenem konfigurace musí být JSON objekt.');
        }
        $this->assertKnownKeys($decoded, self::ROOT_KEYS, 'konfigurace');
        $profile = $this->object($decoded, 'profile');
        $ruleSet = $this->object($decoded, 'ruleSet');
        $this->assertKnownKeys($profile, self::PROFILE_KEYS, 'profile');
        $this->assertKnownKeys($ruleSet, self::RULE_KEYS, 'ruleSet');

        return new AssessmentConfigurationInput(
            profileName: $this->string($profile, 'name'),
            profileVersion: $this->integer($profile, 'version'),
            profileDescription: $this->string($profile, 'description'),
            profileParameters: $this->object($profile, 'parameters'),
            ruleSetName: $this->string($ruleSet, 'name'),
            ruleSetVersion: $this->integer($ruleSet, 'version'),
            ruleSetDescription: $this->string($ruleSet, 'description'),
            rules: $this->object($ruleSet, 'rules'),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function object(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException(sprintf('Pole %s musí být JSON objekt.', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('Pole %s musí být neprázdný text.', $key));
        }
        return trim($value);
    }

    /** @param array<string, mixed> $data */
    private function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new \InvalidArgumentException(sprintf('Pole %s musí být celé číslo.', $key));
        }
        return $value;
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

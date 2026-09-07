<?php

declare(strict_types=1);

namespace App\Assessment;

final readonly class AssessmentConfigurationInput
{
    /**
     * @param array<string, mixed> $profileParameters
     * @param array<string, mixed> $rules
     */
    public function __construct(
        public string $profileName,
        public int $profileVersion,
        public string $profileDescription,
        public array $profileParameters,
        public string $ruleSetName,
        public int $ruleSetVersion,
        public string $ruleSetDescription,
        public array $rules,
    ) {
        if (trim($this->profileName) === '' || trim($this->ruleSetName) === '') {
            throw new \InvalidArgumentException('Profil i sada pravidel musí mít název.');
        }
        if ($this->profileVersion < 1 || $this->ruleSetVersion < 1) {
            throw new \InvalidArgumentException('Verze profilu a pravidel musí být kladná celá čísla.');
        }
        if (trim($this->profileDescription) === '' || trim($this->ruleSetDescription) === '') {
            throw new \InvalidArgumentException('Profil i sada pravidel musí mít popis.');
        }
        if (mb_strlen($this->profileName) > 255 || mb_strlen($this->ruleSetName) > 255) {
            throw new \InvalidArgumentException('Název profilu nebo pravidel je příliš dlouhý.');
        }
    }
}

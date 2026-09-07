<?php

declare(strict_types=1);

namespace App\Opportunity;

final readonly class OpportunityVersion
{
    public function __construct(
        public int $id,
        public string $originalTitle,
        public ?string $translatedTitle,
        public string $originalText,
        public ?string $translatedText,
        public bool $incomplete,
        public \DateTimeInterface $acquiredAt,
        public bool $current,
    ) {
    }
}

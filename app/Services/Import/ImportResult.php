<?php

namespace App\Services\Import;

class ImportResult
{
    public function __construct(
        public readonly int   $inserted,
        public readonly int   $skipped,
        public readonly array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function toArray(): array
    {
        return [
            'inserted' => $this->inserted,
            'skipped'  => $this->skipped,
            'errors'   => $this->errors,
        ];
    }
}

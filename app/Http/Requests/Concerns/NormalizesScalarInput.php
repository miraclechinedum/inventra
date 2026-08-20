<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Str;

trait NormalizesScalarInput
{
    protected function normalizedString(string $key, bool $uppercase = false): ?string
    {
        $value = $this->input($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $uppercase ? Str::upper($value) : $value;
    }

    protected function mergeStringNormalizations(array $keys, array $uppercaseKeys = []): void
    {
        $values = [];

        foreach ($keys as $key) {
            if (is_string($this->input($key))) {
                $values[$key] = $this->normalizedString($key, in_array($key, $uppercaseKeys, true));
            }
        }

        $this->merge($values);
    }
}

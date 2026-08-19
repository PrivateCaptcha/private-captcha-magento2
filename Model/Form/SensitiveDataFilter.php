<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Form;

use PrivateCaptcha\PrivateCaptcha\Model\Config;

class SensitiveDataFilter
{
    /**
     * Returns only explicitly allowed scalar form values that are not sensitive.
     *
     * @param array $data Untrusted form or persisted state data.
     * @param array $allowed Explicit form-specific allowlist tree.
     * @phpstan-param array<array-key, mixed> $data
     * @phpstan-param array<array-key, mixed> $allowed
     * @return array<array-key, mixed>
     */
    public function filter(array $data, array $allowed): array
    {
        $filtered = [];
        foreach ($allowed as $key => $rule) {
            if ($key === '*' || !is_string($key) || $this->isSensitiveKey($key) || !array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if ($rule === true && (is_scalar($value) || $value === null)) {
                $filtered[$key] = $value;
                continue;
            }

            if (is_array($rule) && is_array($value)) {
                $nested = $this->filter($value, $rule);
                if ($nested !== []) {
                    $filtered[$key] = $nested;
                }
            }
        }

        if (($allowed['*'] ?? null) === true) {
            foreach ($data as $key => $value) {
                if ((is_int($key) || (is_string($key) && ctype_digit($key))) &&
                    (is_scalar($value) || $value === null)) {
                    $filtered[$key] = $value;
                }
            }
        }

        return $filtered;
    }

    /**
     * Rejects sensitive names even if a future allowlist is configured incorrectly.
     *
     * @param string $key Form-state key.
     */
    private function isSensitiveKey(string $key): bool
    {
        $withWordBoundaries = preg_replace('/([a-z])([A-Z])/', '$1_$2', $key) ?? '';
        $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $withWordBoundaries));
        $segments = array_filter(explode('_', $normalized));
        $compact = str_replace('_', '', $normalized);

        if ($normalized === strtolower(str_replace('-', '_', Config::SOLUTION_FIELD)) ||
            array_intersect($segments, [
            'password',
            'token',
            'secret',
            'key',
            'redirect',
            'return',
            'uenc',
            'authorization',
            'credential',
        ]) !== []) {
            return true;
        }

        foreach ([
            'password',
            'passwd',
            'pwd',
            'passphrase',
            'token',
            'secret',
            'apikey',
            'formkey',
            'captchasolution',
            'backurl',
            'continue',
            'destination',
            'successurl',
            'errorurl',
            'jwt',
            'csrf',
            'nonce',
            'sessionid',
            'authorization',
            'credential',
            'privatecaptchasolution',
        ] as $sensitiveTerm) {
            if (str_contains($compact, $sensitiveTerm)) {
                return true;
            }
        }

        return false;
    }
}

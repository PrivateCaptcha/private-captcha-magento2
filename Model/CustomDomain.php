<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model;

use InvalidArgumentException;

class CustomDomain
{
    public function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('#^https?://#i', '', $value) ?? '';
        $value = preg_replace('#^(?:api|cdn|portal)\.#i', '', $value) ?? '';
        $value = preg_replace('#/$#', '', $value) ?? '';

        if (
            $value === ''
            || strpbrk($value, '/?#@:') !== false
            || !str_contains($value, '.')
            || preg_match('/^[0-9.]+$/', $value) === 1
            || filter_var($value, FILTER_VALIDATE_IP) !== false
            || filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw new InvalidArgumentException('Custom Domain must be a valid hostname.');
        }

        return strtolower($value);
    }

    public function getScriptUrl(string $rootDomain): string
    {
        return 'https://cdn.' . $rootDomain . '/widget/js/privatecaptcha.js';
    }

    public function getPuzzleEndpoint(string $rootDomain): string
    {
        return 'https://api.' . $rootDomain . '/puzzle';
    }
}

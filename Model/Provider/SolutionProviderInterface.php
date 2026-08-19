<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Provider;

interface SolutionProviderInterface
{
    public const SOLUTION_FIELD = 'private-captcha-solution';
    public const MAX_SOLUTION_BYTES = 262144;

    public function getSolution(): ?string;
}

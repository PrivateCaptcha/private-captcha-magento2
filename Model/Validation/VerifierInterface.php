<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Validation;

interface VerifierInterface
{
    public function isValid(string $solution, int $storeId, string $form): bool;
}

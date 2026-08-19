<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Adminhtml;

class ConfigSaveValidationResult
{
    /**
     * @param list<int> $websiteIdsToDisable
     */
    public function __construct(
        public readonly bool $settingsTestFailed,
        public readonly array $websiteIdsToDisable = []
    ) {
    }
}

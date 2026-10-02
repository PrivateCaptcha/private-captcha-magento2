<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Script implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'default', 'label' => __('Default')],
            ['value' => 'extended', 'label' => __('Extended (experimental)')],
        ];
    }
}

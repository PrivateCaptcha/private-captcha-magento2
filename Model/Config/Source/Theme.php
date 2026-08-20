<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Theme implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'light', 'label' => __('Light')],
            ['value' => 'dark', 'label' => __('Dark')],
        ];
    }
}

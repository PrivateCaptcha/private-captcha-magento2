<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class StartMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'auto', 'label' => __('Automatic')],
            ['value' => 'click', 'label' => __('On Click')],
        ];
    }
}

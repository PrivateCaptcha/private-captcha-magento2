<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Language implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'auto', 'label' => __('Automatic')],
            ['value' => 'en', 'label' => __('English')],
            ['value' => 'de', 'label' => __('German')],
            ['value' => 'es', 'label' => __('Spanish')],
            ['value' => 'fr', 'label' => __('French')],
            ['value' => 'it', 'label' => __('Italian')],
            ['value' => 'nl', 'label' => __('Dutch')],
            ['value' => 'sv', 'label' => __('Swedish')],
            ['value' => 'no', 'label' => __('Norwegian')],
            ['value' => 'pl', 'label' => __('Polish')],
            ['value' => 'fi', 'label' => __('Finnish')],
            ['value' => 'et', 'label' => __('Estonian')],
            ['value' => 'uk', 'label' => __('Ukrainian')],
            ['value' => 'tr', 'label' => __('Turkish')],
        ];
    }
}

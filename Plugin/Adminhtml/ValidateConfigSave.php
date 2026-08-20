<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Plugin\Adminhtml;

use Magento\Config\Model\Config as MagentoConfig;
use PrivateCaptcha\PrivateCaptcha\Model\Adminhtml\ConfigSaveHandler;

class ValidateConfigSave
{
    public function __construct(
        private readonly ConfigSaveHandler $handler
    ) {
    }

    public function aroundSave(MagentoConfig $subject, callable $proceed): MagentoConfig
    {
        if ($subject->getSection() !== 'private_captcha') {
            return $proceed() ?? $subject;
        }

        return $this->handler->aroundSave(
            $subject,
            static fn (): MagentoConfig => $proceed() ?? $subject
        );
    }

    public function afterSave(MagentoConfig $subject, ?MagentoConfig $result): MagentoConfig
    {
        // Preserve Magento's fluent result when another plugin returns no value.
        $result ??= $subject;

        if ($subject->getSection() !== 'private_captcha') {
            return $result;
        }

        return $this->handler->afterSave($subject, $result);
    }
}

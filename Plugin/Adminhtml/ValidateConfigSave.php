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
            return $proceed();
        }

        return $this->handler->aroundSave($subject, $proceed);
    }

    public function afterSave(MagentoConfig $subject, MagentoConfig $result): MagentoConfig
    {
        if ($subject->getSection() !== 'private_captcha') {
            return $result;
        }

        return $this->handler->afterSave($subject, $result);
    }
}

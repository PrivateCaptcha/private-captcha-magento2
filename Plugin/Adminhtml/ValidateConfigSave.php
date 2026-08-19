<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Plugin\Adminhtml;

use Magento\Config\Model\Config as MagentoConfig;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use PrivateCaptcha\PrivateCaptcha\Model\Adminhtml\ConfigSaveValidator;

class ValidateConfigSave
{
    private const CACHE_TYPES = ['config', 'layout', 'block_html', 'full_page'];
    private const LOCK_NAME = 'private_captcha_config_save';
    private const LOCK_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly ConfigSaveValidator $validator,
        private readonly TypeListInterface $cacheTypeList,
        private readonly LockManagerInterface $lockManager
    ) {
    }

    public function aroundSave(MagentoConfig $subject, callable $proceed): MagentoConfig
    {
        if ($subject->getSection() !== 'private_captcha') {
            return $proceed();
        }

        // Serialize validation and persistence because Core saves each scope independently.
        if (!$this->lockManager->lock(self::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS)) {
            throw new LocalizedException(__('Private Captcha configuration is already being saved. Please try again.'));
        }

        try {
            $this->validator->reinit();
            $this->validator->validate($subject);

            return $proceed();
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    public function afterSave(MagentoConfig $subject, MagentoConfig $result): MagentoConfig
    {
        if ($subject->getSection() !== 'private_captcha') {
            return $result;
        }

        foreach (self::CACHE_TYPES as $cacheType) {
            $this->cacheTypeList->cleanType($cacheType);
        }

        return $result;
    }
}

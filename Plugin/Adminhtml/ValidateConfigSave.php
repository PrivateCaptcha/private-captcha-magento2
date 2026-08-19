<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Plugin\Adminhtml;

use Magento\Config\Model\Config as MagentoConfig;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\ScopeInterface;
use PrivateCaptcha\PrivateCaptcha\Model\Adminhtml\ConfigSaveValidator;
use PrivateCaptcha\PrivateCaptcha\Model\Config;

class ValidateConfigSave
{
    private const CACHE_TYPES = ['config', 'layout', 'block_html', 'full_page'];
    private const LOCK_NAME = 'private_captcha_config_save';
    private const LOCK_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly ConfigSaveValidator $validator,
        private readonly TypeListInterface $cacheTypeList,
        private readonly LockManagerInterface $lockManager,
        private readonly ManagerInterface $messageManager,
        private readonly WriterInterface $configWriter,
        private readonly ResourceConnection $resourceConnection
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

        $connection = $this->resourceConnection->getConnection();
        $transactionStarted = false;
        try {
            $connection->beginTransaction();
            $transactionStarted = true;
            $this->validator->reinit();
            $validationResult = $this->validator->validate($subject);
            $result = $proceed();
            foreach ($validationResult->websiteIdsToDisable as $websiteId) {
                $this->disableWebsiteForms($websiteId);
            }
            $connection->commit();
            $transactionStarted = false;

            if ($validationResult->settingsTestFailed) {
                $this->messageManager->addErrorMessage(__(
                    'Private Captcha settings test failed. Form protections have been disabled to prevent lockout. '
                    . 'Please verify your API Key, Site Key, and domain settings.'
                ));
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($transactionStarted) {
                $connection->rollBack();
                $this->validator->reinit();
            }

            throw $exception;
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    private function disableWebsiteForms(int $websiteId): void
    {
        foreach (Config::FORM_PATHS as $path) {
            $this->configWriter->save($path, '0', ScopeInterface::SCOPE_WEBSITES, $websiteId);
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

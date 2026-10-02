<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use PrivateCaptcha\PrivateCaptcha\Model\Config;

/**
 * Supplies public Website and Store View configuration for one widget instance.
 */
class WidgetConfig implements ArgumentInterface
{
    /**
     * @param Config $config Website-scoped protection and Store-scoped presentation configuration.
     */
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Builds public widget settings for an enabled and fully configured form.
     *
     * @param string $form Form identifier configured by the module.
     * @param string $instanceId Stable native form-instance identifier.
     * @param int|null $storeId Store used to resolve protection and presentation configuration.
     * @return array{
     *     id: string,
     *     store_variable: string,
     *     script_url: string,
     *     site_key: string,
     *     solution_field: string,
     *     theme: string,
     *     language: string,
     *     start_mode: string,
     *     eu: bool,
     *     debug: bool,
     *     puzzle_endpoint: ?string,
     *     styles: string
     * }|null
     */
    public function getWidgetConfig(string $form, string $instanceId, ?int $storeId = null): ?array
    {
        if (!$this->config->isFormEnabled($form, $storeId)) {
            return null;
        }

        $siteKey = $this->config->getSiteKey($storeId);
        if (trim($siteKey) === '') {
            return null;
        }

        $identifier = hash('sha256', $form . ':' . $instanceId);

        return [
            'id' => 'private-captcha-' . $identifier,
            'store_variable' => 'privateCaptcha_' . $identifier,
            'script_url' => $this->config->getScriptUrl($storeId),
            'site_key' => $siteKey,
            'solution_field' => Config::SOLUTION_FIELD,
            'theme' => $this->config->getTheme($storeId),
            'language' => $this->config->getLanguage($storeId),
            'start_mode' => $this->config->getStartMode($storeId),
            'eu' => $this->config->isEuIsolation($storeId),
            'debug' => $this->config->isDebugMode($storeId),
            'puzzle_endpoint' => $this->config->getPuzzleEndpoint($storeId),
            'styles' => $this->config->getCustomStyles($storeId),
        ];
    }
}

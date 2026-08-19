<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Block\Adminhtml\System\Config\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PrivateCaptcha\PrivateCaptcha\Model\Config\Coexistence;

class CoexistenceWarning extends Field
{
    public function __construct(
        Context $context,
        private readonly Coexistence $coexistence,
        private readonly StoreManagerInterface $storeManager,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null
    ) {
        parent::__construct($context, $data, $secureRenderer);
    }

    public function render(AbstractElement $element): string
    {
        $warnings = $this->getWarnings($element);
        if ($warnings === []) {
            return '';
        }

        $items = '';
        foreach ($warnings as $warning) {
            $items .= '<li>' . $this->escapeHtml((string) __(
                'Website "%1": Private Captcha and %2 are enabled for %3.',
                $warning['website'],
                $warning['engine'],
                $warning['form']
            )) . '</li>';
        }

        $html = '<td colspan="4"><div class="message message-warning warning"><strong>'
            . $this->escapeHtml(__('CAPTCHA overlap detected.'))
            . '</strong><ul>' . $items . '</ul><p>'
            . $this->escapeHtml(__(
                'Effective Website settings. Select the named Website scope to review or disable Private Captcha.'
            ))
            . '</p></div></td>';

        return $this->_decorateRowHtml($element, $html);
    }

    /**
     * @return list<array{website: string, form: string, engine: string}>
     */
    private function getWarnings(AbstractElement $element): array
    {
        $scope = $element->getData('scope');
        if ($scope === ScopeInterface::SCOPE_WEBSITES) {
            return $this->getWarningsForWebsite($this->storeManager->getWebsite((int) $element->getData('scope_id')));
        }

        if ($scope !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
            return [];
        }

        $warnings = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            array_push($warnings, ...$this->getWarningsForWebsite($website));
        }

        return $warnings;
    }

    /**
     * @return list<array{website: string, form: string, engine: string}>
     */
    private function getWarningsForWebsite(WebsiteInterface $website): array
    {
        $warnings = [];
        $websiteCode = (string) $website->getCode();

        foreach ($this->coexistence->getOverlaps($websiteCode) as $overlap) {
            $warnings[] = ['website' => $websiteCode] + $overlap;
        }

        return $warnings;
    }
}

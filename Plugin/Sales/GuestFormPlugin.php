<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Plugin\Sales;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\BlockFactory;
use Magento\Sales\Block\Widget\Guest\Form;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\ViewModel\WidgetConfig;

class GuestFormPlugin
{
    /**
     * @param BlockFactory $blockFactory Creates the detached widget outside the current layout tree.
     * @param WidgetConfig $widgetConfig Public Private Captcha widget configuration.
     */
    public function __construct(
        private readonly BlockFactory $blockFactory,
        private readonly WidgetConfig $widgetConfig
    ) {
    }

    /**
     * Appends one detached widget immediately after each native Sales form.
     *
     * @param Form $subject Native Orders and Returns form block.
     * @param string $result Rendered native block HTML.
     */
    public function afterToHtml(Form $subject, string $result): string
    {
        if (!$this->isCoreForm($subject) ||
            $result === '' ||
            preg_match('#</form\s*>#i', $result) !== 1) {
            return $result;
        }

        $instanceId = $subject->getNameInLayout();
        if (!is_string($instanceId) || $instanceId === '') {
            return $result;
        }

        $widget = $this->renderWidget($instanceId);
        if ($widget === '') {
            return $result;
        }

        return (string) preg_replace_callback(
            '#</form\s*>#i',
            static fn (array $matches): string => $matches[0] . $widget,
            $result,
            1
        );
    }

    /**
     * Renders the existing generic widget template with a native block-derived identity.
     *
     * @param string $instanceId Native Sales layout block name.
     */
    private function renderWidget(string $instanceId): string
    {
        $block = $this->blockFactory->createBlock(Template::class);
        if (!$block instanceof Template) {
            return '';
        }

        return $block
            ->setTemplate('PrivateCaptcha_PrivateCaptcha::widget.phtml')
            ->setData('view_model', $this->widgetConfig)
            ->setData('private_captcha_form', Config::FORM_ORDERS_RETURNS)
            ->setData('private_captcha_instance_id', $instanceId)
            ->setData('private_captcha_placement', 'before-toolbar')
            ->setData('private_captcha_detached_target', 'previous-form')
            ->toHtml();
    }

    /**
     * Accepts Magento's concrete block and its generated interceptor, but no third-party subclasses.
     *
     * @param Form $subject Sales guest form candidate.
     */
    private function isCoreForm(Form $subject): bool
    {
        return get_class($subject) === Form::class ||
            get_class($subject) === Form::class . '\\Interceptor';
    }
}

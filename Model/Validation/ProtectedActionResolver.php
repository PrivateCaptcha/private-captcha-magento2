<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Validation;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Request\Http;
use Magento\Review\Helper\Data as ReviewHelper;
use Magento\Review\Model\Review\Config as ReviewConfig;
use Magento\SendFriend\Helper\Data as SendFriendHelper;
use PrivateCaptcha\PrivateCaptcha\Model\Config;

class ProtectedActionResolver
{
    /**
     * @param Config $config Website-scoped form configuration.
     * @param CustomerSession $customerSession Lazy customer-session proxy for eligibility rules.
     * @param array $protectedActions Data-driven controller protection rules.
     * @param ReviewHelper|null $reviewHelper Current-store guest review eligibility.
     * @param ReviewConfig|null $reviewConfig Current-store review availability.
     * @param SendFriendHelper|null $sendFriendHelper Current-store SendFriend eligibility.
     * @phpstan-param array<string, array{
     *     controller: string,
     *     method: string,
     *     form?: string,
     *     guest_only?: bool,
     *     customer_only?: bool,
     *     review_eligible?: bool,
     *     sendfriend_eligible?: bool
     * }> $protectedActions
     */
    public function __construct(
        private readonly Config $config,
        private readonly CustomerSession $customerSession,
        private readonly array $protectedActions = [],
        private readonly ?ReviewHelper $reviewHelper = null,
        private readonly ?ReviewConfig $reviewConfig = null,
        private readonly ?SendFriendHelper $sendFriendHelper = null
    ) {
    }

    /**
     * Resolves the enabled form associated with an action class and HTTP method.
     *
     * @param object $action Resolved controller action, including interceptor subclasses.
     * @param Http $request Frontend HTTP request dispatched with the action.
     * @return string|null Protected form identifier, or null when the action is ineligible.
     */
    public function resolve(object $action, Http $request): ?string
    {
        foreach ($this->protectedActions as $form => $definition) {
            if (!$this->matches($action, $request, $definition)) {
                continue;
            }

            $configuredForm = $definition['form'] ?? $form;
            if (!is_string($configuredForm) || !$this->config->isFormEnabled($configuredForm)) {
                return null;
            }

            if (($definition['guest_only'] ?? false) === true &&
                $this->customerSession->isLoggedIn()) {
                return null;
            }

            if (($definition['customer_only'] ?? false) === true &&
                !$this->customerSession->isLoggedIn()) {
                return null;
            }

            if (($definition['review_eligible'] ?? false) === true && !$this->isReviewEligible()) {
                return null;
            }

            if (($definition['sendfriend_eligible'] ?? false) === true && !$this->isSendFriendEligible($request)) {
                return null;
            }

            return $configuredForm;
        }

        return null;
    }

    /**
     * Determines whether an enabled Product Review action will take Magento's guest-login path.
     *
     * Magento persists that request before redirecting, so its transient inputs need filtering
     * even though no CAPTCHA verification should occur.
     *
     * @param object $action Resolved controller action, including interceptor subclasses.
     * @param Http $request Frontend HTTP request dispatched with the action.
     */
    public function shouldSanitizeIneligibleReview(object $action, Http $request): bool
    {
        $definition = $this->protectedActions[Config::FORM_PRODUCT_REVIEW] ?? null;
        if (!is_array($definition) ||
            !$this->matches($action, $request, $definition) ||
            !$this->config->isFormEnabled(Config::FORM_PRODUCT_REVIEW)) {
            return false;
        }

        return !$this->customerSession->isLoggedIn() &&
            ($this->reviewHelper === null || !$this->reviewHelper->getIsGuestAllowToWrite());
    }

    /**
     * Determines whether an enabled SendFriend POST will take Magento's guest-authentication path.
     *
     * Magento may preserve the request before redirecting to login, so module transient fields need
     * filtering even though no CAPTCHA verification should occur.
     *
     * @param object $action Resolved controller action, including interceptor subclasses.
     * @param Http $request Frontend HTTP request dispatched with the action.
     */
    public function shouldSanitizeIneligibleEmailToFriend(object $action, Http $request): bool
    {
        $definition = $this->protectedActions[Config::FORM_EMAIL_TO_FRIEND] ?? null;
        if (!is_array($definition) ||
            !$this->matches($action, $request, $definition) ||
            !$this->config->isFormEnabled(Config::FORM_EMAIL_TO_FRIEND) ||
            !$this->isPositiveInteger($request->getParam('id')) ||
            $this->sendFriendHelper === null ||
            !$this->sendFriendHelper->isEnabled()) {
            return false;
        }

        return !$this->customerSession->isLoggedIn() && !$this->sendFriendHelper->isAllowForGuest();
    }

    /**
     * Matches a resolved action to a configured controller rule.
     *
     * @param object $action Resolved controller action, including interceptor subclasses.
     * @param Http $request Frontend HTTP request dispatched with the action.
     * @param array $definition Data-driven controller protection rule.
     */
    private function matches(object $action, Http $request, array $definition): bool
    {
        return is_string($definition['controller'] ?? null) &&
            is_string($definition['method'] ?? null) &&
            is_a($action, $definition['controller']) &&
            strcasecmp($request->getMethod(), $definition['method']) === 0;
    }

    /**
     * Determines whether native Product Review accepts the current customer state.
     */
    private function isReviewEligible(): bool
    {
        if ($this->reviewConfig === null || !$this->reviewConfig->isEnabled()) {
            return false;
        }

        return $this->customerSession->isLoggedIn() ||
            ($this->reviewHelper !== null && $this->reviewHelper->getIsGuestAllowToWrite());
    }

    /**
     * Determines whether Magento SendFriend accepts the current store and customer state.
     *
     * @param Http $request Frontend request containing the native product route ID.
     */
    private function isSendFriendEligible(Http $request): bool
    {
        if (!$this->isPositiveInteger($request->getParam('id')) ||
            $this->sendFriendHelper === null ||
            !$this->sendFriendHelper->isEnabled()) {
            return false;
        }

        return $this->customerSession->isLoggedIn() || $this->sendFriendHelper->isAllowForGuest();
    }

    /**
     * Accepts canonical positive numeric route IDs only.
     *
     * @param mixed $value Untrusted route parameter value.
     */
    private function isPositiveInteger(mixed $value): bool
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1)) {
            return false;
        }

        $integer = (int) $value;

        return $integer > 0 && (string) $integer === (string) $value;
    }
}

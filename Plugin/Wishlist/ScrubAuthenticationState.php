<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Plugin\Wishlist;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Wishlist\Controller\AbstractIndex;
use Magento\Wishlist\Controller\Index\Send;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\Form\SensitiveDataFilter;

class ScrubAuthenticationState
{
    private const FIELDS = [
        'emails' => true,
        'message' => true,
        'rss_url' => true,
    ];

    /**
     * @param CustomerSession $customerSession Native Wishlist authentication state.
     * @param Config $config Website-scoped Private Captcha configuration.
     * @param SensitiveDataFilter $sensitiveDataFilter Removes untrusted transient inputs.
     * @param UrlInterface $urlBuilder Builds a canonical continuation URL.
     */
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly Config $config,
        private readonly SensitiveDataFilter $sensitiveDataFilter,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * Removes transient data after Magento has validated the form key and saved an unauthenticated request.
     *
     * @param AbstractIndex $subject Native Wishlist controller.
     * @param mixed $result Native controller response.
     * @param RequestInterface $request Current frontend request.
     * @return mixed Native controller response.
     */
    public function afterDispatch(AbstractIndex $subject, mixed $result, RequestInterface $request): mixed
    {
        if (!$subject instanceof Send ||
            !$request instanceof Http ||
            !$request->isPost() ||
            !$this->config->isFormEnabled(Config::FORM_WISHLIST_SHARE)) {
            return $result;
        }

        $savedRequest = $this->customerSession->__call('getData', ['before_wishlist_request']);
        if (!is_array($savedRequest)) {
            return $result;
        }
        $wishlistId = $this->getWishlistId($request->getParam('wishlist_id'));
        $safeRequest = $this->sensitiveDataFilter->filter($savedRequest, self::FIELDS);
        $wishlistId = $this->getWishlistId($savedRequest['wishlist_id'] ?? null) ?? $wishlistId;
        if ($wishlistId !== null) {
            $safeRequest['wishlist_id'] = $wishlistId;
        }
        $this->customerSession->__call('setData', ['before_wishlist_request', $safeRequest]);
        $this->customerSession->__call('setData', ['before_request_params', $safeRequest]);

        $parameters = $wishlistId === null ? [] : ['wishlist_id' => $wishlistId];
        $this->customerSession->__call('setBeforeWishlistUrl', [
            $this->urlBuilder->getUrl('wishlist/index/share', $parameters),
        ]);

        return $result;
    }

    /**
     * Accepts canonical positive Wishlist IDs from the native route.
     *
     * @param mixed $value Untrusted route parameter.
     */
    private function getWishlistId(mixed $value): ?int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1)) {
            return null;
        }

        $wishlistId = (int) $value;

        return $wishlistId > 0 && (string) $wishlistId === (string) $value ? $wishlistId : null;
    }
}

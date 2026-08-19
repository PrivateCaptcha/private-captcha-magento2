<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Csp;

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use PrivateCaptcha\PrivateCaptcha\Model\Config;

class CustomDomainPolicyCollector implements PolicyCollectorInterface
{
    private const FETCH_DIRECTIVES = ['script-src', 'frame-src', 'style-src', 'connect-src'];

    public function __construct(private readonly Config $config)
    {
    }

    public function collect(array $defaultPolicies = []): array
    {
        $customDomain = $this->config->getCustomDomain();
        if ($customDomain === '') {
            return $defaultPolicies;
        }

        $origins = ['https://cdn.' . $customDomain, 'https://api.' . $customDomain];
        foreach (self::FETCH_DIRECTIVES as $directive) {
            $defaultPolicies[] = new FetchPolicy($directive, false, $origins);
        }

        return $defaultPolicies;
    }
}

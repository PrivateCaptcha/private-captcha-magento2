<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Provider\Failure;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http;
use Magento\Framework\App\Response\RedirectInterface;

class InternalRedirect
{
    /**
     * @param RedirectInterface $redirect Magento internal redirect service.
     * @param array $routes Explicit form-to-route mapping.
     * @param HttpRequest|null $request Current frontend request for validated route parameters.
     * @phpstan-param array<string, string|array{
     *     route: string,
     *     parameters: array<string, 'optional_positive_int'|'positive_int'>,
     *     fallback?: string
     * }> $routes
     */
    public function __construct(
        private readonly RedirectInterface $redirect,
        private readonly array $routes = [],
        private readonly ?HttpRequest $request = null
    ) {
    }

    /**
     * Redirects only when a form has a configured internal three-part route.
     *
     * @param Http $response Frontend HTTP response.
     * @param string $form Resolved protected form identifier.
     * @return bool Whether a redirect was applied.
     */
    public function redirect(Http $response, string $form): bool
    {
        $definition = $this->routes[$form] ?? null;
        if (is_string($definition)) {
            if (preg_match('#\A[a-z0-9_]+/[a-z0-9_]+/[a-z0-9_]+\z#', $definition) !== 1) {
                return false;
            }

            $this->redirect->redirect($response, $definition);

            return true;
        }

        if (!is_array($definition)) {
            return false;
        }

        $route = $definition['route'];
        if (preg_match('#\A[a-z0-9_]+/[a-z0-9_]+/[a-z0-9_]+\z#', $route) !== 1) {
            return false;
        }

        if ($this->request === null) {
            return false;
        }
        $parameters = $definition['parameters'];

        $validatedParameters = [];
        foreach ($parameters as $key => $rule) {
            $value = $this->getPositiveInteger($key, $rule);
            if ($value === null) {
                if ($rule === 'optional_positive_int') {
                    continue;
                }

                return $this->redirectToFallback($response, $definition);
            }
            $validatedParameters[$key] = $value;
        }

        $this->redirect->redirect($response, $route, $validatedParameters);

        return true;
    }

    /**
     * Redirects to an explicit internal fallback when request parameters are invalid.
     *
     * @param Http $response Frontend HTTP response.
     * @param array $definition Configured parameterized route definition.
     */
    private function redirectToFallback(Http $response, array $definition): bool
    {
        $fallback = $definition['fallback'] ?? null;
        if (!is_string($fallback) ||
            preg_match('#\A[a-z0-9_]+/[a-z0-9_]+/[a-z0-9_]+\z#', $fallback) !== 1) {
            return false;
        }

        $this->redirect->redirect($response, $fallback);

        return true;
    }

    /**
     * Returns a validated positive request parameter.
     *
     * @param mixed $key Parameter name from the configured route definition.
     * @param mixed $rule Validation rule from the configured route definition.
     */
    private function getPositiveInteger(mixed $key, mixed $rule): ?int
    {
        if (!is_string($key) ||
            $key === '' ||
            !in_array($rule, ['optional_positive_int', 'positive_int'], true) ||
            $this->request === null) {
            return null;
        }

        $value = $this->request->getParam($key);
        if (!is_int($value) && (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1)) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 && (string) $integer === (string) $value ? $integer : null;
    }
}

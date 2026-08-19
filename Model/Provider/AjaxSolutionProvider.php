<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Provider;

use Magento\Framework\App\Request\Http;

class AjaxSolutionProvider implements SolutionProviderInterface
{
    public function __construct(
        private readonly Http $request
    ) {
    }

    public function getSolution(): ?string
    {
        $content = $this->request->getContent();
        if (!is_string($content)) {
            return null;
        }

        try {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        $solution = $payload[self::SOLUTION_FIELD] ?? null;
        unset($payload[self::SOLUTION_FIELD]);
        $this->removeSolutionFromParameters();
        try {
            $this->request->setContent(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            $this->request->setContent('{}');

            return null;
        }

        if (!is_string($solution) || $solution === '' || strlen($solution) > self::MAX_SOLUTION_BYTES) {
            return null;
        }

        return $solution;
    }

    /**
     * Removes duplicate parameter values before a native controller can persist them.
     */
    private function removeSolutionFromParameters(): void
    {
        foreach ([$this->request->getPost(), $this->request->getQuery()] as $parameters) {
            if ($parameters instanceof \ArrayAccess) {
                $parameters->offsetUnset(self::SOLUTION_FIELD);
            }
        }

        $this->request->setParam(self::SOLUTION_FIELD, null);
    }
}

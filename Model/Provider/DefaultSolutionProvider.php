<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Provider;

use Magento\Framework\App\Request\Http;

class DefaultSolutionProvider implements SolutionProviderInterface
{
    public function __construct(
        private readonly Http $request
    ) {
    }

    public function getSolution(): ?string
    {
        if (!$this->request->isPost()) {
            return null;
        }

        $solution = $this->request->getPostValue(self::SOLUTION_FIELD);
        $this->removeSolution();
        if (!is_string($solution) || $solution === '' || strlen($solution) > self::MAX_SOLUTION_BYTES) {
            return null;
        }

        return $solution;
    }

    /**
     * Removes the solution before native controllers can persist request data.
     */
    private function removeSolution(): void
    {
        foreach ([$this->request->getPost(), $this->request->getQuery()] as $parameters) {
            if ($parameters instanceof \ArrayAccess) {
                $parameters->offsetUnset(self::SOLUTION_FIELD);
            }
        }

        $this->request->setParam(self::SOLUTION_FIELD, null);
    }
}

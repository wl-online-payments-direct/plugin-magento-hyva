<?php
declare(strict_types=1);

namespace Worldline\ThemeHyva\Model\Webhook;

class AdditionalIconsProcessor
{
    /**
     * @var array
     */
    private $processors = [];

    /**
     * @param array $processors
     */
    public function __construct(
        array $processors = []
    ) {
        $this->processors = $processors;
    }

    /**
     * Process
     *
     * @param string $method
     * @param int $storeId
     * @return array
     */
    public function process(string $method, int $storeId): array
    {
        if (!$processor = $this->getProcessor($method)) {
            return [];
        }

        return $processor->getIcons($storeId);
    }

    /**
     * Get process
     *
     * @param string $method
     * @return mixed|null
     */
    private function getProcessor(string $method)
    {
        return $this->processors[$method] ?? null;
    }
}

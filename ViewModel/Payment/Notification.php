<?php

declare(strict_types=1);

namespace Worldline\ThemeHyva\ViewModel\Payment;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Worldline\PaymentCore\Model\Checkout\ConfigProvider;

class Notification implements ArgumentInterface
{
    /**
     * @var ConfigProvider
     */
    private ConfigProvider $configProvider;

    public function __construct(
        ConfigProvider $configProvider
    ) {
        $this->configProvider = $configProvider;
    }

    /**
     * Get config provider
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->configProvider->getConfig();
    }
}

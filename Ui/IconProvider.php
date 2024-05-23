<?php

declare(strict_types=1);

namespace Worldline\ThemeHyva\Ui;

use Magento\Framework\View\Asset\Source;
use Magento\Payment\Model\CcConfig;
use Worldline\PaymentCore\Ui\PaymentIconsProvider;

class IconProvider
{
    /**
     * @var CcConfig
     */
    private $ccConfig;

    /**
     * @var Source
     */
    private $assetSource;

    /**
     * @var PaymentIconsProvider
     */
    private $paymentIconsProvider;

    public function __construct(
        CcConfig $ccConfig,
        Source   $assetSource,
        PaymentIconsProvider $paymentIconsProvider
    ) {
        $this->ccConfig = $ccConfig;
        $this->assetSource = $assetSource;
        $this->paymentIconsProvider = $paymentIconsProvider;
    }

    /**
     * Get icon by type
     *
     * @param string $type
     * @return array|mixed
     */
    public function getIconByType(string $type): mixed
    {
        $icons = [];
        $types = $this->ccConfig->getCcAvailableTypes();
        foreach ($types as $code => $label) {
            if (!array_key_exists($code, $icons)) {
                $asset = $this->ccConfig->createAsset('Magento_Payment::images/cc/' . strtolower($code) . '.png');
                $placeholder = $this->assetSource->findSource($asset);
                if ($placeholder) {
                    [$width, $height] = $this->paymentIconsProvider->getDimensions($asset);
                    $icons[$code] = [
                        'url' => $asset->getUrl(),
                        'width' => $width,
                        'height' => $height,
                        'title' => __($label),
                    ];
                }
            }
        }
        return $icons[$type] ?? [
            'url' => '',
            'width' => 0,
            'height' => 0,
            'title' => '',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Worldline\ThemeHyva\Plugin;

use Hyva\Checkout\Model\ConfigData\HyvaThemes\SystemConfigPayment;
use Hyva\Checkout\Model\MethodMetaData;
use Magento\Framework\View\Element\Template as TemplateBlock;
use Magento\Framework\View\Layout;
use Magento\Store\Model\StoreManagerInterface;
use Worldline\ThemeHyva\Model\Webhook\AdditionalIconsProcessor;

class MethodMetaDataPlugin
{
    /**
     * @var Layout
     */
    private Layout $layout;
    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;
    /**
     * @var SystemConfigPayment
     */
    private SystemConfigPayment $systemConfigPayment;
    /**
     * @var AdditionalIconsProcessor
     */
    private AdditionalIconsProcessor $additionalIconsProcessor;

    /**
     * @param Layout $layout
     * @param StoreManagerInterface $storeManager
     * @param SystemConfigPayment $systemConfigPayment
     * @param AdditionalIconsProcessor $additionalIconsProcessor
     */
    public function __construct(
        Layout                   $layout,
        StoreManagerInterface    $storeManager,
        SystemConfigPayment      $systemConfigPayment,
        AdditionalIconsProcessor $additionalIconsProcessor
    ) {
        $this->layout = $layout;
        $this->storeManager = $storeManager;
        $this->systemConfigPayment = $systemConfigPayment;
        $this->additionalIconsProcessor = $additionalIconsProcessor;
    }

    /**
     * Added validation by additional icons provider field
     *
     * @param MethodMetaData $subject
     * @param bool $result
     * @return bool
     */
    public function afterCanRenderIcon(MethodMetaData $subject, bool $result): bool
    {
        if ($subject->getData('additional_icons_provider') || $subject->getData('additional_icon_provider')) {
            return $this->systemConfigPayment->canDisplayMethodIcons();
        }
        return $result;
    }

    /**
     * Added render images by additional icons provider
     *
     * @param MethodMetaData $subject
     * @param string $result
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function afterRenderIcon(MethodMetaData $subject, string $result): string
    {
        $iconsProvider = $subject->getData('additional_icons_provider');
        $storeId = (int)$this->storeManager->getStore()->getId();

        if ($iconsProvider) {
            $icons = $this->additionalIconsProcessor->process($iconsProvider['method'], $storeId);
            foreach ($icons as $icon) {
                if ($icon['url']) {
                    $block = $this->layout->createBlock(TemplateBlock::class);
                    $block->setData('icon_url', $icon['url']);
                    $result .= $block->setTemplate($iconsProvider['template'])->toHtml();
                }
            }
        }

        $iconProvider = $subject->getData('additional_icon_provider');
        if ($iconProvider) {
            $icons = $this->additionalIconsProcessor->process($iconProvider['method'], $storeId);
            if (isset($iconProvider['key']) && isset($icons[$iconProvider['key']])) {
                $icon = $icons[$iconProvider['key']];
                $block = $this->layout->createBlock(TemplateBlock::class);
                $block->setData('icon_url', $icon['url']);
                $result .= $block->setTemplate($iconProvider['template'])->toHtml();
            }
        }

        return $result;
    }
}

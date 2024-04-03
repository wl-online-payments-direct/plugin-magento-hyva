<?php

declare(strict_types=1);

namespace Worldline\ThemeHyva\Observer;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Event\Observer as Event;
use Magento\Framework\Event\ObserverInterface;

class RegisterModuleForHyvaConfig implements ObserverInterface
{
    /**
     * @var ComponentRegistrar
     */
    private ComponentRegistrar $componentRegistrar;

    /**
     * @param ComponentRegistrar $componentRegistrar
     */
    public function __construct(ComponentRegistrar $componentRegistrar)
    {
        $this->componentRegistrar = $componentRegistrar;
    }

    /**
     * Component Registrar
     *
     * @param Event $event
     * @return void
     */
    public function execute(Event $event): void
    {
        $config = $event->getData('config');
        $extensions = $config->hasData('extensions') ? $config->getData('extensions') : [];

        $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, 'Worldline_ThemeHyva');

        // Only use the path relative to the Magento base dir
        $extensions[] = ['src' => substr($path, strlen(BP) + 1)];

        $config->setData('extensions', $extensions);
    }
}

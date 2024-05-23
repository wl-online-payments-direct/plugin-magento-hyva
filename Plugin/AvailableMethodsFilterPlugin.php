<?php

declare(strict_types=1);

namespace Worldline\ThemeHyva\Plugin;

use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\Data\PaymentMethodInterface;
use Magento\Quote\Api\PaymentMethodManagementInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;
use Worldline\PaymentCore\Api\Data\PaymentProductsDetailsInterface;
use Worldline\PaymentCore\Api\Ui\PaymentIconsProviderInterface;
use Worldline\RedirectPayment\Gateway\Config\Config as RedirectPaymentConfig;

/**
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 */
class AvailableMethodsFilterPlugin
{
    /**
     * @var bool
     */
    private $redirectVault = false;

    /**
     * @var RedirectPaymentConfig
     */
    private $redirectPaymentConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var SessionCheckout
     */
    private $sessionCheckout;

    /**
     * @var PaymentIconsProviderInterface
     */
    private $iconProvider;

    public function __construct(
        RedirectPaymentConfig $redirectPaymentConfig,
        StoreManagerInterface $storeManager,
        SessionCheckout $sessionCheckout,
        PaymentIconsProviderInterface $iconProvider
    ) {
        $this->redirectPaymentConfig = $redirectPaymentConfig;
        $this->storeManager = $storeManager;
        $this->sessionCheckout = $sessionCheckout;
        $this->iconProvider = $iconProvider;
    }

    /**
     * Modified get payment methods list
     *
     * @param PaymentMethodManagementInterface $subject
     * @param PaymentMethodInterface[] $result
     * @return PaymentMethodInterface[]
     * @throws LocalizedException
     * @throws NoSuchEntityException
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetList(PaymentMethodManagementInterface $subject, array $result): array
    {
        $filteredMethods = [];
        $storeId = (int) $this->storeManager->getStore()->getId();
        $quote = $this->sessionCheckout->getQuote();
        $this->redirectVault = false;

        foreach ($result as $method) {
            $code = $method->getCode();
            if (str_contains($code, 'worldline_redirect_payment') && str_contains($code, 'vault')) {
                $this->redirectVault = true;
            }
        }

        foreach ($result as $method) {
            if (!$this->shouldFilterMethod($method, $storeId, $quote)) {
                $filteredMethods[] = $method;
            }
        }

        return $filteredMethods;
    }

    /**
     * Should filter method
     *
     * @param MethodInterface $method
     * @param int $storeId
     * @param Quote $quote
     * @return bool
     */
    private function shouldFilterMethod(MethodInterface $method, int $storeId, Quote $quote): bool
    {
        $code = $method->getCode();

        if ($this->redirectVault && $code === 'worldline_redirect_payment') {
            $this->redirectVault = false;
            return false;
        }

        if (str_contains($code, 'worldline_redirect_payment') && $code !== 'worldline_redirect_payment_vault') {
            $vault = str_contains($code, 'vault');

            if ($vault) {
                return true;
            }

            $payProductId = (int)str_replace('worldline_redirect_payment_', '', $code);

            if (!$this->isValidPaymentProduct($payProductId, $storeId)) {
                return true;
            }

            if ($payProductId === PaymentProductsDetailsInterface::SEPA_DIRECT_DEBIT_PRODUCT_ID
                && (float)$quote->getGrandTotal() < 0.00001) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int $payProductId
     * @param int $storeId
     * @return bool
     */
    private function isValidPaymentProduct(int $payProductId, int $storeId): bool
    {
        if (!$this->redirectPaymentConfig->isPaymentProductActive($payProductId, $storeId)
            || !$this->iconProvider->getIconById($payProductId, $storeId)) {
            return false;
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Worldline\ThemeHyva\Magewire\Payment\Method;

use Hyva\Checkout\Magewire\Checkout\Payment\MethodList as MagewireMethodList;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Customer\Model\Session as SessionCustomer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Model\MethodList;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Vault\Api\PaymentMethodListInterface;
use Magento\Vault\Model\CustomerTokenManagement;
use Worldline\ThemeHyva\Ui\IconProvider;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class WorldlineRedirectVault extends WorldlineHostedCheckout
{
    public $currentMethod = '';
    private SessionCheckout $sessionCheckout;
    private MethodList $methodList;
    private CartRepositoryInterface $cartRepository;
    private MagewireMethodList $magewireMethodList;

    public function __construct(
        SessionCustomer $sessionCustomer,
        SessionCheckout $sessionCheckout,
        StoreManagerInterface $storeManager,
        Json $jsonSerializer,
        PaymentMethodListInterface $vaultPaymentList,
        CustomerTokenManagement $customerTokenManagement,
        IconProvider $iconProvider,
        CartRepositoryInterface $cartRepository,
        MethodList $methodList,
        MagewireMethodList $magewireMethodList,
        string $methodCode = 'worldline_redirect_payment_vault'
    ) {
        parent::__construct($sessionCustomer, $sessionCheckout, $storeManager, $jsonSerializer, $vaultPaymentList, $customerTokenManagement, $iconProvider, $cartRepository, $methodCode);
        $this->sessionCheckout = $sessionCheckout;
        $this->methodList = $methodList;
        $this->cartRepository = $cartRepository;
        $this->magewireMethodList = $magewireMethodList;
    }

    /**
     * Updated Payment Method
     *
     * @param string $value
     * @return string
     */
    public function updatedMethod(string $value): string
    {
        $this->currentMethod = $value;
        $this->magewireMethodList->updatedMethod($value);
        return $value;
    }

    /**
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getTokensByMethod(): array
    {
        $quote = $this->sessionCheckout->getQuote();
        $methods = $this->methodList->getAvailableMethods($quote);
        $filteredMethods = [];
        foreach ($methods as $method) {
            $code = $method->getCode();
            if (str_contains($code, 'worldline_redirect_payment') && str_contains($code, 'vault')
                && !str_contains($code, 'worldline_redirect_payment_vault')) {
                $filteredMethods[] = $method;
            }
        }
        return $filteredMethods;
    }

    /**
     * Evaluate Completion
     *
     * @param EvaluationResultFactory $resultFactory
     * @return EvaluationResultInterface
     */
    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        if (empty($this->currentPublicHash) && empty($this->currentMethod)) {
            return $resultFactory->createErrorMessageEvent()
                ->withCustomEvent('payment:method:error')
                ->withMessage((string)__('Please choose a card'));
        }
        return $resultFactory->createSuccess();
    }
}

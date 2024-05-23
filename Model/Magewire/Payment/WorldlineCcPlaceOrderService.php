<?php

declare(strict_types=1);

namespace Worldline\ThemeHyva\Model\Magewire\Payment;

use Hyva\Checkout\Exception\CheckoutException;
use Hyva\Checkout\Model\Magewire\Payment\AbstractPlaceOrderService;
use Magento\Customer\Model\Session as SessionCustomer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Api\PaymentMethodManagementInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;
use OnlinePayments\Sdk\DeclinedPaymentException;
use Worldline\CreditCard\Model\ReturnRequestProcessor;
use Worldline\CreditCard\WebApi\CreatePaymentManagement;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class WorldlineCcPlaceOrderService extends AbstractPlaceOrderService
{
    private const SUCCESS_URL = 'checkout/onepage/success';
    private const WAITING_URL = 'worldline/returns/waiting';
    private const FAIL_URL = 'worldline/returns/failed';

    /**
     * @var CreatePaymentManagement
     */
    private $createPaymentManagement;

    /**
     * @var SessionCustomer
     */
    private $sessionCustomer;

    /**
     * @var PaymentMethodManagementInterface
     */
    private $paymentMethodManagement;

    /**
     * @var QuoteIdToMaskedQuoteIdInterface
     */
    private $quoteIdToMaskedQuoteId;

    /**
     * @var string
     */
    private $redirectPath;

    /**
     * @var bool
     */
    private $canRedirected = true;

    /**
     * @var UrlInterface
     */
    private $url;

    /**
     * @var ReturnRequestProcessor
     */
    private $returnRequestProcessor;

    public function __construct(
        CartManagementInterface          $cartManagement,
        CreatePaymentManagement          $createPaymentManagement,
        SessionCustomer                  $sessionCustomer,
        PaymentMethodManagementInterface $paymentMethodManagement,
        QuoteIdToMaskedQuoteIdInterface  $quoteIdToMaskedQuoteId,
        UrlInterface                     $url,
        ReturnRequestProcessor           $returnRequestProcessor,
        string                           $redirectPath = 'checkout'
    ) {
        parent::__construct($cartManagement);
        $this->createPaymentManagement = $createPaymentManagement;
        $this->sessionCustomer = $sessionCustomer;
        $this->paymentMethodManagement = $paymentMethodManagement;
        $this->quoteIdToMaskedQuoteId = $quoteIdToMaskedQuoteId;
        $this->redirectPath = $redirectPath;
        $this->url = $url;
        $this->returnRequestProcessor = $returnRequestProcessor;
    }

    /**
     * Place Order payment method
     *
     * @param Quote $quote
     * @return int
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function placeOrder(Quote $quote): int
    {
        $quoteId = (int)$quote->getId();
        $maskedId = $this->getQuoteMaskId($quoteId);
        $quotePayment = $this->getPayment($quote);
        $billingAddress = $quote->getBillingAddress();
        $billingAddress->setQuoteId(null);
        $billingAddress->setAddressId(null);

        try {
            if ($this->sessionCustomer->isLoggedIn()) {
                $this->redirectPath = $this->createPaymentManagement->createRequest(
                    $quoteId,
                    $quotePayment,
                    $billingAddress
                );
            } else {
                $this->redirectPath = $this->createPaymentManagement->createGuestRequest(
                    $maskedId,
                    $quotePayment,
                    $quote->getCustomerEmail(),
                    $billingAddress
                );
            }
            if (empty($this->redirectPath)) {
                if ($token = $quotePayment->getAdditionalInformation()['hosted_tokenization_id']) {
                    $this->redirectPath = $this->getReturnUrl($token);
                } else {
                    throw new CheckoutException(__('URI is not valid'));
                }
            }
            return 1;
        } catch (DeclinedPaymentException|LocalizedException $exception) {
            $this->canRedirected = false;
            throw new CheckoutException(__($exception->getMessage()));
        }
    }

    /**
     * Get return url
     *
     * @param string $hostedTokenizationId
     * @return string
     */
    public function getReturnUrl(string $hostedTokenizationId): string
    {
        $url = $this->url->getRouteUrl(self::SUCCESS_URL);

        try {
            $orderState = $this->returnRequestProcessor->processRequest(null, $hostedTokenizationId);
            if ($orderState->getState() === ReturnRequestProcessor::WAITING_STATE) {
                $url = $this->url->getRouteUrl(self::WAITING_URL, ['incrementId' => $orderState->getIncrementId()]);
            }
        } catch (LocalizedException $exception) {
            $url = $this->url->getRouteUrl(self::FAIL_URL);
        }

        return $url;
    }

    /**
     * Can Redirect
     *
     * @return bool
     */
    public function canRedirect(): bool
    {
        return $this->canRedirected;
    }

    /**
     * Get  Redirect url
     *
     * @param Quote $quote
     * @param int|null $orderId
     * @return string
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getRedirectUrl(Quote $quote, ?int $orderId = null): string
    {
        return $this->redirectPath;
    }

    /**
     * Get Masked id by Quote Id
     *
     * @param int $quoteId
     * @return string|null
     * @throws LocalizedException
     */
    private function getQuoteMaskId(int $quoteId): ?string
    {
        try {
            $maskedId = $this->quoteIdToMaskedQuoteId->execute($quoteId);
        } catch (NoSuchEntityException $exception) {
            throw new LocalizedException(__('Current user does not have an active cart.'));
        }

        return $maskedId;
    }

    /**
     * Get quote payment
     *
     * @param CartInterface $quote
     * @return PaymentInterface
     * @throws NoSuchEntityException
     */
    private function getPayment(CartInterface $quote): PaymentInterface
    {
        return $this->paymentMethodManagement->get($quote->getId());
    }
}

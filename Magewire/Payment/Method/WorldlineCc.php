<?php

declare(strict_types=1);

namespace Worldline\ThemeHyva\Magewire\Payment\Method;

use Exception;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Customer\Model\Session as SessionCustomer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Vault\Api\PaymentMethodListInterface;
use Magento\Vault\Model\CustomerTokenManagement;
use Worldline\CreditCard\Ui\ConfigProvider\CreateHostedTokenizationResponseProcessor;
use Worldline\CreditCard\WebApi\CalculateSurchargeManagement;
use Worldline\PaymentCore\Api\Config\GeneralSettingsConfigInterface;
use Worldline\PaymentCore\Api\QuoteTotalInterface;
use Worldline\PaymentCore\Api\SurchargingQuoteRepositoryInterface;
use Worldline\ThemeHyva\Ui\IconProvider;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 */
class WorldlineCc extends WorldlineHostedCheckout
{
    /**
     * @var SessionCustomer
     */
    private $sessionCustomer;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var CreateHostedTokenizationResponseProcessor
     */
    private $createHostedTokenizationResponseProcessor;

    /**
     * @var GeneralSettingsConfigInterface
     */
    private $generalSettings;

    /**
     * @var QuoteTotalInterface
     */
    private $quoteTotal;

    /**
     * @var SurchargingQuoteRepositoryInterface
     */
    private $surchargingQuoteRepository;

    /**
     * @var CalculateSurchargeManagement
     */
    private $calculateSurchargeManagement;

    /**
     * @var QuoteIdToMaskedQuoteIdInterface
     */
    private $quoteIdToMaskedQuoteId;

    /**
     * @var string
     */
    private $methodCode;

    /**
     * @var bool
     */
    public $isOscValid = false;

    /**
     * @var bool
     */
    public $iframeIsLoaded = false;

    public function __construct(
        SessionCustomer                           $sessionCustomer,
        SessionCheckout                           $sessionCheckout,
        StoreManagerInterface                     $storeManager,
        Json                                      $jsonSerializer,
        PaymentMethodListInterface                $vaultPaymentList,
        CustomerTokenManagement                   $customerTokenManagement,
        IconProvider                              $iconProvider,
        CartRepositoryInterface                   $cartRepository,
        CreateHostedTokenizationResponseProcessor $createHostedTokenizationResponseProcessor,
        GeneralSettingsConfigInterface            $generalSettings,
        QuoteTotalInterface                       $quoteTotal,
        SurchargingQuoteRepositoryInterface       $surchargingQuoteRepository,
        CalculateSurchargeManagement              $calculateSurchargeManagement,
        QuoteIdToMaskedQuoteIdInterface           $quoteIdToMaskedQuoteId,
        string                                    $methodCode = 'worldline_cc'
    ) {
        parent::__construct(
            $sessionCustomer,
            $sessionCheckout,
            $storeManager,
            $jsonSerializer,
            $vaultPaymentList,
            $customerTokenManagement,
            $iconProvider,
            $cartRepository,
            $methodCode
        );
        $this->sessionCustomer = $sessionCustomer;
        $this->storeManager = $storeManager;
        $this->createHostedTokenizationResponseProcessor = $createHostedTokenizationResponseProcessor;
        $this->generalSettings = $generalSettings;
        $this->quoteTotal = $quoteTotal;
        $this->surchargingQuoteRepository = $surchargingQuoteRepository;
        $this->calculateSurchargeManagement = $calculateSurchargeManagement;
        $this->quoteIdToMaskedQuoteId = $quoteIdToMaskedQuoteId;
        $this->methodCode = $methodCode;
    }

    /**
     * Check isApplySurcharge in payment
     *
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function isApplySurcharge(): bool
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        if ($this->generalSettings->isApplySurcharge($storeId)) {
            $quote = $this->getQuote();
            $quoteTotal = $this->quoteTotal->getTotalAmount($quote);
            $surchargingQuote = $this->surchargingQuoteRepository->getByQuoteId((int)$quote->getId());
            if ((float)$quote->getGrandTotal() > 0.00001
                && (!$surchargingQuote->getId() || (float)$surchargingQuote->getQuoteTotalAmount() !== $quoteTotal)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Calculate Surcharge
     *
     * @param string $hostedTokenizationId
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function calculateSurcharge(string $hostedTokenizationId): void
    {
        $quote = $this->getQuote();
        $cartId = (int)$quote->getId();
        try {
            if ($this->sessionCustomer->isLoggedIn()) {
                $this->calculateSurchargeManagement->calculate($cartId, $hostedTokenizationId);
            } else {
                $cartId = $this->getQuoteMaskId($cartId);
                $this->calculateSurchargeManagement->calculateForGuest(
                    $cartId,
                    $hostedTokenizationId,
                    $quote->getCustomerEmail() ?? ''
                );
            }
            $this->isOscValid = true;
            $this->emit('payment_method_selected');
        } catch (Exception $e) {
            $this->isOscValid = false;
            $this->dispatchErrorMessage(__($e->getMessage()));
        }
        $this->dispatchBrowserEvent('magewire:loader:done');
    }

    /**
     * Get iframe url
     *
     * @return string
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getIframeUrl(): string
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        $createHostedTokenizationResponse = $this->createHostedTokenizationResponseProcessor->buildAndProcess($storeId);

        return 'https://payment.' . $createHostedTokenizationResponse->getPartialRedirectUrl();
    }

    /**
     * Evaluate Completion
     *
     * @param EvaluationResultFactory $resultFactory
     * @return EvaluationResultInterface
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        if (empty($this->currentPublicHash) && str_contains($this->getMethodCode(), '_vault')) {
            return $resultFactory->createErrorMessageEvent()
                ->withCustomEvent('payment:method:error')
                ->withMessage((string)__('Please choose a card'));
        }

        if ($this->isApplySurcharge() && !$this->isOscValid) {
            return $resultFactory->createErrorMessage()
                ->withMessage((string)__('Please calculate surcharge for your card'));
        }

        return $resultFactory->createSuccess();
    }

    /**
     * Get Masked id by Quote Id
     *
     * @param int $quoteId
     * @return string|null
     * @throws LocalizedException
     */
    protected function getQuoteMaskId(int $quoteId): ?string
    {
        try {
            $maskedId = $this->quoteIdToMaskedQuoteId->execute($quoteId);
        } catch (NoSuchEntityException $exception) {
            throw new LocalizedException(__('Current user does not have an active cart.'));
        }

        return $maskedId;
    }

    /**
     * Set public hash
     *
     * @param string $currentToken
     * @return void
     */
    public function setPublicHash(string $currentToken): void
    {
        foreach ($this->getTokens() as $token) {
            $publicHash = $token->getPublicHash();
            if ($currentToken === $publicHash) {
                $this->currentPublicHash = $currentToken;
                $this->dispatchBrowserEvent('restart-worldline-cc-vault', [
                    'publicHash' => $token->getPublicHash(),
                    'token' => $token->getGatewayToken()
                ]);
            }
        }
    }
}

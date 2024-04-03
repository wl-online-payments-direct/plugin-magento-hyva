<?php

declare(strict_types=1);

namespace Worldline\ThemeHyva\Magewire\Payment\Method;

use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Customer\Model\Session as SessionCustomer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentMethodListInterface;
use Magento\Vault\Model\CustomerTokenManagement;
use Magewirephp\Magewire\Component;
use Worldline\ThemeHyva\Ui\IconProvider;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class WorldlineHostedCheckout extends Component implements EvaluationInterface
{
    /**
     * @var PaymentTokenInterface[]
     */
    protected $tokens;
    /**
     * @var array
     */
    protected $loader = [
        'authorize' => true,
        'cancel' => true,
        'edit' => true,
        'createBillingAgreement' => false
    ];
    /**
     * @var SessionCustomer
     */
    private SessionCustomer $sessionCustomer;
    /**
     * @var SessionCheckout
     */
    private SessionCheckout $sessionCheckout;
    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;
    /**
     * @var Json
     */
    private Json $jsonSerializer;
    /**
     * @var PaymentMethodListInterface
     */
    private PaymentMethodListInterface $vaultPaymentList;
    /**
     * @var string
     */
    private string $methodCode;
    /**
     * @var CustomerTokenManagement
     */
    private CustomerTokenManagement $customerTokenManagement;
    /**
     * @var IconProvider
     */
    private IconProvider $iconProvider;
    /**
     * @var CartRepositoryInterface
     */
    private CartRepositoryInterface $cartRepository;
    /**
     * @var string
     */
    public string $currentPublicHash = '';

    /**
     * @param SessionCustomer $sessionCustomer
     * @param SessionCheckout $sessionCheckout
     * @param StoreManagerInterface $storeManager
     * @param Json $jsonSerializer
     * @param PaymentMethodListInterface $vaultPaymentList
     * @param CustomerTokenManagement $customerTokenManagement
     * @param IconProvider $iconProvider
     * @param CartRepositoryInterface $cartRepository
     * @param string $methodCode
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        SessionCustomer            $sessionCustomer,
        SessionCheckout            $sessionCheckout,
        StoreManagerInterface      $storeManager,
        Json                       $jsonSerializer,
        PaymentMethodListInterface $vaultPaymentList,
        CustomerTokenManagement    $customerTokenManagement,
        IconProvider               $iconProvider,
        CartRepositoryInterface    $cartRepository,
        string                     $methodCode = 'worldline_hosted_checkout'
    ) {
        $this->sessionCustomer = $sessionCustomer;
        $this->sessionCheckout = $sessionCheckout;
        $this->storeManager = $storeManager;
        $this->methodCode = $methodCode;
        $this->jsonSerializer = $jsonSerializer;
        $this->vaultPaymentList = $vaultPaymentList;
        $this->customerTokenManagement = $customerTokenManagement;
        $this->iconProvider = $iconProvider;
        $this->cartRepository = $cartRepository;
    }

    /**
     * Set Method code
     *
     * @param string $methodCode
     * @return string
     */
    public function setMethodCode(string $methodCode): string
    {
        $this->methodCode = $methodCode;
        return $this->methodCode;
    }

    /**
     * Get Method Code
     *
     * @return string
     */
    public function getMethodCode(): string
    {
        return $this->methodCode;
    }

    /**
     * Apply Additional information to quota
     *
     * @param string $data
     * @return string
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function setAdditionalInformation(string $data): string
    {
        $jsonData = $this->jsonSerializer->unserialize($data);
        $quote = $this->sessionCheckout->getQuote();
        $quotePayment = $quote->getPayment();
        foreach ($jsonData as $key => $value) {
            $quotePayment->setAdditionalInformation($key, $value);
        }
        $this->cartRepository->save($quote);
        return $data;
    }

    /**
     * Evaluate Completion
     *
     * @param EvaluationResultFactory $resultFactory
     * @return EvaluationResultInterface
     */
    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        if (empty($this->currentPublicHash) && str_contains($this->getMethodCode(), '_vault')) {
            return $resultFactory->createErrorMessageEvent()
                ->withCustomEvent('payment:method:error')
                ->withMessage((string)__('Please choose a card'));
        }
        return $resultFactory->createSuccess();
    }

    /**
     * Get Quote
     *
     * @return CartInterface
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getQuote(): CartInterface
    {
        return $this->sessionCheckout->getQuote();
    }

    /**
     * Get token details
     *
     * @return string
     */
    public function getTokenDetails(): string
    {
        if ($token = $this->getMethodCodeToken()) {
            return $token->getTokenDetails() ?: '{}';
        }
        return '{}';
    }

    /**
     * Get Public Hash
     *
     * @return string
     */
    public function getPublicHash(): string
    {
        if ($token = $this->getMethodCodeToken()) {
            return $token->getPublicHash();
        }
        return '';
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
                $this->dispatchBrowserEvent('restart-worldline-hosted-vault', [
                    'publicHash' => $token->getPublicHash(),
                    'token' => $token->getGatewayToken()
                ]);
            }
        }
    }

    /**
     * Get icon by type
     *
     * @param string $type
     * @return array|mixed
     */
    public function getIconByType(string $type): mixed
    {
        return $this->iconProvider->getIconByType($type);
    }

    /**
     * Get details
     *
     * @param string $details
     * @return array|bool|float|int|mixed|string|null
     */
    public function getDetails(string $details): mixed
    {
        return $this->jsonSerializer->unserialize($details);
    }

    /**
     * Get Gateway Token
     *
     * @return string
     */
    public function getToken(): string
    {
        if ($token = $this->getMethodCodeToken()) {
            return $token->getGatewayToken();
        }
        return '';
    }

    /**
     * Get customer tokens
     *
     * @return array
     */
    protected function getTokens(): array
    {
        if (empty($this->tokens)) {
            $this->tokens = $this->customerTokenManagement->getCustomerSessionTokens();
        }
        return $this->tokens;
    }

    /**
     * Get tokens by current method
     *
     * @return array
     */
    public function getTokensByMethod(): array
    {
        $tokens = [];
        foreach ($this->getTokens() as $token) {
            $paymentCode = $token->getPaymentMethodCode();
            if ($this->getMethodCode() === $paymentCode . '_vault') {
                $tokens[] = $token;
            }
        }
        return $tokens;
    }

    /**
     * Get methodCode token
     *
     * @return PaymentTokenInterface|null
     */
    private function getMethodCodeToken(): ?PaymentTokenInterface
    {
        foreach ($this->getTokens() as $token) {
            $paymentCode = $token->getPaymentMethodCode();
            if ($this->getMethodCode() === $paymentCode . '_vault') {
                return $token;
            }
        }
        return null;
    }

    /**
     * Get customer id
     *
     * @return mixed
     */
    public function getCustomerId(): mixed
    {
        if ($this->sessionCustomer->isLoggedIn()) {
            return $this->sessionCustomer->getCustomer()->getId();
        }
        return '';
    }

    /**
     * Get is active paymentToken
     *
     * @return bool
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function isActivePaymentTokenEnabler(): bool
    {
        if ($this->sessionCustomer->isLoggedIn()) {
            try {
                $storeId = (int)$this->storeManager->getStore()->getId();
                $activeVaultList = $this->vaultPaymentList->getActiveList($storeId);
                foreach ($activeVaultList as $activeVault) {
                    if ($activeVault->getCode() === $this->getMethodCode() . '_vault') {
                        return $activeVault->isActive($storeId);
                    }
                }
            } catch (NoSuchEntityException $exception) {
                throw new LocalizedException(__($exception->getMessage()));
            }
        }
        return false;
    }
}

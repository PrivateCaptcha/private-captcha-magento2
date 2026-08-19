<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Integration\Controller;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Session\Generic;
use Magento\Review\Model\Rating;
use Magento\Review\Model\Rating\Option;
use Magento\Review\Model\ResourceModel\Review as ReviewResource;
use Magento\TestFramework\TestCase\AbstractController;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\Validation\VerifierInterface;
use PrivateCaptcha\PrivateCaptcha\Observer\ValidatePredispatch;

final class ProductReviewPostTest extends AbstractController
{
    private const PRODUCT_ID = 1;

    private CustomerSession $customerSession;

    private Generic $reviewSession;

    private ReviewResource $reviewResource;

    private ResourceConnection $resourceConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->customerSession = $objectManager->get(CustomerSession::class);
        $this->reviewSession = $objectManager->get('Magento\\Review\\Model\\Session');
        $this->reviewResource = $objectManager->get(ReviewResource::class);
        $this->resourceConnection = $objectManager->get(ResourceConnection::class);
        $this->reviewSession->unsFormData()->unsRedirectUrl();
        if ($this->customerSession->isLoggedIn()) {
            $this->customerSession->logout();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->reviewSession)) {
            $this->reviewSession->unsFormData()->unsRedirectUrl();
        }
        if (isset($this->customerSession) && $this->customerSession->isLoggedIn()) {
            $this->customerSession->logout();
        }
        $this->restoreDependencies();
        parent::tearDown();
        $this->removeIgnitionHandlers();
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Review/_files/product_review_with_rating.php
     * @magentoConfigFixture current_store catalog/review/active 1
     * @magentoConfigFixture current_store catalog/review/allow_guest 1
     * @magentoConfigFixture base_website private_captcha/protected_forms/product_review 0
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testDisabledReviewSubmissionRemainsNativeAndDoesNotVerify(): void
    {
        $verifier = new ProductReviewPostTestVerifier(false);
        $this->replaceDependencies($verifier);
        $reviewCount = $this->reviewCount();
        $voteCount = $this->voteCount();

        try {
            $this->submit($this->reviewPost(false));

            self::assertSame([], $verifier->calls);
            self::assertSame($reviewCount + 1, $this->reviewCount());
            self::assertSame($voteCount + count($this->ratings()), $this->voteCount());
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Review/_files/product_review_with_rating.php
     * @magentoConfigFixture current_store catalog/review/active 1
     * @magentoConfigFixture current_store catalog/review/allow_guest 1
     * @magentoConfigFixture base_website private_captcha/protected_forms/product_review 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testEnabledEligibleReviewFormRendersOnePublicWidget(): void
    {
        $this->getRequest()->setMethod(Http::METHOD_GET);
        $this->dispatch('catalog/product/view/id/1');

        $body = $this->getResponse()->getBody();
        self::assertSame(1, substr_count($body, 'class="private-captcha"'));
        self::assertStringNotContainsString('private-api-key', $body);
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Review/_files/product_review_with_rating.php
     * @magentoConfigFixture current_store catalog/review/active 1
     * @magentoConfigFixture current_store catalog/review/allow_guest 1
     * @magentoConfigFixture base_website private_captcha/protected_forms/product_review 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testMissingSolutionPreventsReviewAndVoteAndPersistsOnlySafeState(): void
    {
        $verifier = new ProductReviewPostTestVerifier(true);
        $this->replaceDependencies($verifier);
        $reviewCount = $this->reviewCount();
        $voteCount = $this->voteCount();

        try {
            $this->submit($this->reviewPost(false));

            self::assertSame([], $verifier->calls);
            $this->assertNoSideEffects($reviewCount, $voteCount);
            $this->assertSafeReviewState();
            $this->assertProductRedirect();
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Review/_files/product_review_with_rating.php
     * @magentoConfigFixture current_store catalog/review/active 1
     * @magentoConfigFixture current_store catalog/review/allow_guest 1
     * @magentoConfigFixture base_website private_captcha/protected_forms/product_review 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testInvalidSolutionPreventsReviewAndVote(): void
    {
        $verifier = new ProductReviewPostTestVerifier(false);
        $this->replaceDependencies($verifier);
        $reviewCount = $this->reviewCount();
        $voteCount = $this->voteCount();

        try {
            $this->submit($this->reviewPost(true));

            self::assertSame([['solution', Config::FORM_PRODUCT_REVIEW]], $verifier->calls);
            $this->assertNoSideEffects($reviewCount, $voteCount);
            $this->assertSafeReviewState();
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Review/_files/product_review_with_rating.php
     * @magentoConfigFixture current_store catalog/review/active 1
     * @magentoConfigFixture current_store catalog/review/allow_guest 1
     * @magentoConfigFixture base_website private_captcha/protected_forms/product_review 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testValidSolutionCreatesOneReviewAndEachNativeVote(): void
    {
        $verifier = new ProductReviewPostTestVerifier(true);
        $this->replaceDependencies($verifier);
        $reviewCount = $this->reviewCount();
        $voteCount = $this->voteCount();

        try {
            $this->submit($this->reviewPost(true));

            self::assertSame([['solution', Config::FORM_PRODUCT_REVIEW]], $verifier->calls);
            self::assertSame($reviewCount + 1, $this->reviewCount());
            self::assertSame($voteCount + count($this->ratings()), $this->voteCount());
            self::assertNull($this->reviewSession->getData('form_data'));
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Review/_files/product_review_with_rating.php
     * @magentoConfigFixture current_store catalog/review/active 1
     * @magentoConfigFixture current_store catalog/review/allow_guest 0
     * @magentoConfigFixture base_website private_captcha/protected_forms/product_review 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testIneligibleGuestKeepsNativeLoginBehaviorWithoutVerification(): void
    {
        $verifier = new ProductReviewPostTestVerifier(false);
        $this->replaceDependencies($verifier);
        $reviewCount = $this->reviewCount();
        $voteCount = $this->voteCount();

        try {
            $this->submit($this->reviewPost(true));

            self::assertSame([], $verifier->calls);
            $this->assertNoSideEffects($reviewCount, $voteCount);
            $this->assertSafeReviewState();
            self::assertTrue($this->getResponse()->isRedirect());
            self::assertStringContainsString(
                'customer/account/login',
                $this->getResponse()->getHeader('Location')->getFieldValue()
            );
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Review/_files/product_review_with_rating.php
     * @magentoConfigFixture current_store catalog/review/active 1
     * @magentoConfigFixture current_store catalog/review/allow_guest 0
     * @magentoConfigFixture base_website private_captcha/protected_forms/product_review 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testAuthenticatedReviewRequiresAndUsesVerification(): void
    {
        $verifier = new ProductReviewPostTestVerifier(true);
        $this->replaceDependencies($verifier);
        $this->customerSession->setCustomerId(1);
        $reviewCount = $this->reviewCount();
        $voteCount = $this->voteCount();

        try {
            $this->submit($this->reviewPost(true));

            self::assertSame([['solution', Config::FORM_PRODUCT_REVIEW]], $verifier->calls);
            self::assertSame($reviewCount + 1, $this->reviewCount());
            self::assertSame($voteCount + count($this->ratings()), $this->voteCount());
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Review/_files/product_review_with_rating.php
     * @magentoConfigFixture current_store catalog/review/active 1
     * @magentoConfigFixture current_store catalog/review/allow_guest 1
     * @magentoConfigFixture base_website private_captcha/protected_forms/product_review 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testNativeValidationFailureAfterValidSolutionPersistsOnlySafeReviewState(): void
    {
        $verifier = new ProductReviewPostTestVerifier(true);
        $this->replaceDependencies($verifier);
        $reviewCount = $this->reviewCount();
        $voteCount = $this->voteCount();
        $post = $this->reviewPost(true);
        $post['detail'] = '';

        try {
            $this->submit($post);

            self::assertSame([['solution', Config::FORM_PRODUCT_REVIEW]], $verifier->calls);
            $this->assertNoSideEffects($reviewCount, $voteCount);
            $this->assertSafeReviewState($post['detail']);
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    private function reviewPost(bool $withSolution): array
    {
        $post = [
            'nickname' => 'Ada Lovelace',
            'title' => 'Analytical Engine',
            'detail' => 'Excellent machine.',
            'form_key' => (string) \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
                ->get(FormKey::class)
                ->getFormKey(),
            'ratings' => $this->ratings(),
            'api_token' => 'token',
            'return_url' => 'https://attacker.example.test',
        ];

        if ($withSolution) {
            $post['private-captcha-solution'] = 'solution';
        }

        return $post;
    }

    /**
     * @return array<int, string>
     */
    private function ratings(): array
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $ratings = [];
        $ratingCollection = $objectManager->create(Rating::class)->getCollection();
        foreach ($ratingCollection as $rating) {
            $option = $objectManager->create(Option::class)->getCollection()
                ->addRatingFilter((int) $rating->getId())
                ->getFirstItem();
            $ratings[(int) $rating->getId()] = (string) $option->getId();
        }

        return $ratings;
    }

    /**
     * @param array<string, string|array<int, string>> $post Review request payload.
     */
    private function submit(array $post): void
    {
        $this->getRequest()->setMethod(Http::METHOD_POST)->setPostValue($post);
        $this->dispatch('review/product/post/id/1');
    }

    private function reviewCount(): int
    {
        return (int) $this->reviewResource->getTotalReviews(self::PRODUCT_ID);
    }

    private function voteCount(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('rating_option_vote');

        return (int) $connection->fetchOne(
            $connection->select()->from($table, 'COUNT(*)')->where('entity_pk_value = ?', self::PRODUCT_ID)
        );
    }

    private function assertNoSideEffects(int $reviewCount, int $voteCount): void
    {
        self::assertSame($reviewCount, $this->reviewCount());
        self::assertSame($voteCount, $this->voteCount());
    }

    private function assertSafeReviewState(string $detail = 'Excellent machine.'): void
    {
        self::assertSame([
            'nickname' => 'Ada Lovelace',
            'title' => 'Analytical Engine',
            'detail' => $detail,
        ], $this->reviewSession->getData('form_data'));
    }

    private function assertProductRedirect(): void
    {
        self::assertTrue($this->getResponse()->isRedirect());
        self::assertStringContainsString(
            'catalog/product/view/id/' . self::PRODUCT_ID,
            $this->getResponse()->getHeader('Location')->getFieldValue()
        );
    }

    private function replaceDependencies(ProductReviewPostTestVerifier $verifier): void
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $objectManager->removeSharedInstance(ValidatePredispatch::class, true);
        $objectManager->addSharedInstance($verifier, VerifierInterface::class, true);
    }

    private function restoreDependencies(): void
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $objectManager->removeSharedInstance(ValidatePredispatch::class, true);
        $objectManager->removeSharedInstance(VerifierInterface::class, true);
    }

    private function removeIgnitionHandlers(): void
    {
        $errorHandler = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        if (is_array($errorHandler) && $errorHandler[0] instanceof \Swissup\Ignition\Model\Ignition) {
            restore_error_handler();
        }

        $exceptionHandler = set_exception_handler(static function (): void {
        });
        restore_exception_handler();
        if (is_array($exceptionHandler) && $exceptionHandler[0] instanceof \Swissup\Ignition\Model\Ignition) {
            restore_exception_handler();
        }
    }
}

final class ProductReviewPostTestVerifier implements VerifierInterface
{
    /** @var array<int, array{string, string}> */
    public array $calls = [];

    public function __construct(
        private readonly bool $result
    ) {
    }

    public function isValid(string $solution, int $storeId, string $form): bool
    {
        $this->calls[] = [$solution, $form];

        return $this->result;
    }
}

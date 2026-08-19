<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Unit\Model\Form;

use Magento\Customer\Api\AddressMetadataInterface;
use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Model\Metadata\Form;
use Magento\Customer\Model\Metadata\FormFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\Request\Http;
use Laminas\Stdlib\Parameters;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\Form\SensitiveDataFilter;
use PrivateCaptcha\PrivateCaptcha\Model\Form\StateManager;

final class StateManagerTest extends TestCase
{
    /**
     * @return array<string, array{storage: string, key: string, fields: array<string, true>, native_validation_fields: array<string, true>}>
     */
    private function contactPolicy(): array
    {
        return [
            Config::FORM_CONTACT => [
                'storage' => 'data_persistor',
                'key' => 'contact_us',
                'fields' => [
                    'name' => true,
                    'email' => true,
                    'telephone' => true,
                    'comment' => true,
                ],
                'native_validation_fields' => [
                    'hideit' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{storage: string, key: string, fields: array<string, mixed>, transient_fields: array<string, true>}>
     */
    private function registrationPolicy(): array
    {
        return [
            Config::FORM_CUSTOMER_REGISTRATION => [
                'storage' => 'customer_session',
                'key' => 'customer_form_data',
                'fields' => [
                    'firstname' => true,
                    'lastname' => true,
                    'email' => true,
                    'is_subscribed' => true,
                    'street' => ['*' => true],
                ],
                'transient_fields' => [
                    'form_key' => true,
                    'password' => true,
                    'password_confirmation' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{storage: string, key: string, fields: array<string, array<string, true|array<string, true>>>, transient_fields: array<string, true>, native_validation_fields: array<string, array<string, true>>, route_parameters: array<string, string>}>
     */
    private function emailToFriendPolicy(): array
    {
        return [
            Config::FORM_EMAIL_TO_FRIEND => [
                'storage' => 'catalog_session',
                'key' => 'sendfriend_form_data',
                'fields' => [
                    'sender' => [
                        'name' => true,
                        'email' => true,
                        'message' => true,
                    ],
                    'recipients' => [
                        'name' => ['*' => true],
                        'email' => ['*' => true],
                    ],
                ],
                'transient_fields' => [
                    'form_key' => true,
                    'g-recaptcha-response' => true,
                ],
                'native_validation_fields' => [
                    'captcha' => ['product_sendtofriend_form' => true],
                ],
                'route_parameters' => [
                    'id' => 'positive_int',
                    'cat_id' => 'positive_int',
                ],
            ],
        ];
    }

    public function testFailureWithNoAllowedDataClearsTheNativeState(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $persistor->expects(self::once())->method('clear')->with('contact_us');
        $request = $this->createStub(Http::class);
        $request->method('getPostValue')->willReturn([
            'form_key' => 'form-key',
            'private-captcha-solution' => 'solution',
        ]);

        $this->stateManager($persistor)->persistFailure(Config::FORM_CONTACT, $request);
    }

    public function testEmailToFriendSanitizeRequestRetainsOnlyNativeValidationInputsAndRouteIds(): void
    {
        $persistor = $this->createStub(DataPersistorInterface::class);
        $query = new Parameters([0 => ['private-captcha-solution' => 'query-solution']]);
        $request = $this->createMock(Http::class);
        $request->method('getPostValue')->willReturn([
            'sender' => [
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.test',
                'message' => 'Hello',
            ],
            'recipients' => [
                'name' => [0 => 'Charles Babbage'],
                'email' => [0 => 'charles@example.test'],
            ],
            'form_key' => 'form-key',
            'captcha' => ['product_sendtofriend_form' => 'NativeCaptcha', 'token' => 'token'],
            'g-recaptcha-response' => 'NativeReCaptcha',
            'private-captcha-solution' => 'solution',
            'api_token' => 'token',
            'return_url' => 'https://attacker.example.test',
        ]);
        $request->expects(self::exactly(2))
            ->method('getParam')
            ->willReturnCallback(static fn(string $key): ?string => match ($key) {
                'id' => '42',
                'cat_id' => '9',
                default => null,
            });
        $request->method('getQuery')->willReturn($query);
        $request->expects(self::once())->method('clearParams');
        $routeParameters = [];
        $request->expects(self::exactly(2))
            ->method('setParam')
            ->willReturnCallback(static function (string $key, int $value) use (&$routeParameters): void {
                $routeParameters[$key] = $value;
            });
        $request->expects(self::once())
            ->method('setPostValue')
            ->with([
                'sender' => [
                    'name' => 'Ada Lovelace',
                    'email' => 'ada@example.test',
                    'message' => 'Hello',
                ],
                'recipients' => [
                    'name' => [0 => 'Charles Babbage'],
                    'email' => [0 => 'charles@example.test'],
                ],
                'captcha' => ['product_sendtofriend_form' => 'NativeCaptcha'],
                'form_key' => 'form-key',
                'g-recaptcha-response' => 'NativeReCaptcha',
            ]);

        $this->stateManager($persistor, $this->emailToFriendPolicy())
            ->sanitizeRequest(Config::FORM_EMAIL_TO_FRIEND, $request);

        self::assertSame(['id' => 42, 'cat_id' => 9], $routeParameters);
        self::assertFalse($query->offsetExists(0));
    }

    public function testScrubClearsMalformedNativeState(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $persistor->method('get')->with('contact_us')->willReturn('secret');
        $persistor->expects(self::once())->method('clear')->with('contact_us');

        $this->stateManager($persistor)->scrub(Config::FORM_CONTACT);
    }

    public function testSanitizeRegistrationRequestRetainsSafeVisibleCustomAttributes(): void
    {
        $persistor = $this->createStub(DataPersistorInterface::class);
        $customerForm = $this->createStub(Form::class);
        $customerAttribute = $this->createStub(\Magento\Customer\Api\Data\AttributeMetadataInterface::class);
        $customerAttribute->method('isUserDefined')->willReturn(true);
        $customerAttribute->method('getAttributeCode')->willReturn('marketing_opt_in');
        $customerRedirectAttribute = $this->createStub(\Magento\Customer\Api\Data\AttributeMetadataInterface::class);
        $customerRedirectAttribute->method('isUserDefined')->willReturn(true);
        $customerRedirectAttribute->method('getAttributeCode')->willReturn('success_url');
        $customerMultiselectAttribute = $this->createStub(\Magento\Customer\Api\Data\AttributeMetadataInterface::class);
        $customerMultiselectAttribute->method('isUserDefined')->willReturn(true);
        $customerMultiselectAttribute->method('getAttributeCode')->willReturn('interests');
        $customerMultiselectAttribute->method('getFrontendInput')->willReturn('multiselect');
        $customerForm->method('getAllowedAttributes')->willReturn([
            $customerAttribute,
            $customerRedirectAttribute,
            $customerMultiselectAttribute,
        ]);
        $addressForm = $this->createStub(Form::class);
        $addressAttribute = $this->createStub(\Magento\Customer\Api\Data\AttributeMetadataInterface::class);
        $addressAttribute->method('isUserDefined')->willReturn(true);
        $addressAttribute->method('getAttributeCode')->willReturn('delivery_note');
        $addressRedirectAttribute = $this->createStub(\Magento\Customer\Api\Data\AttributeMetadataInterface::class);
        $addressRedirectAttribute->method('isUserDefined')->willReturn(true);
        $addressRedirectAttribute->method('getAttributeCode')->willReturn('error_url');
        $addressMultiselectAttribute = $this->createStub(\Magento\Customer\Api\Data\AttributeMetadataInterface::class);
        $addressMultiselectAttribute->method('isUserDefined')->willReturn(true);
        $addressMultiselectAttribute->method('getAttributeCode')->willReturn('access_options');
        $addressMultiselectAttribute->method('getFrontendInput')->willReturn('multiselect');
        $addressForm->method('getAllowedAttributes')->willReturn([
            $addressAttribute,
            $addressRedirectAttribute,
            $addressMultiselectAttribute,
        ]);
        $formFactory = $this->createStub(FormFactory::class);
        $formFactory->method('create')->willReturnCallback(
            static function (string $entityType, string $formCode) use ($customerForm, $addressForm): Form {
                return match ([$entityType, $formCode]) {
                    [CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER, 'customer_account_create'] => $customerForm,
                    [AddressMetadataInterface::ENTITY_TYPE_ADDRESS, 'customer_register_address'] => $addressForm,
                };
            }
        );
        $request = $this->createMock(Http::class);
        $request->method('getPostValue')->willReturn([
            'firstname' => 'Ada',
            'marketing_opt_in' => '1',
            'success_url' => 'https://attacker.example.test/success',
            'interests' => [0 => 'email', 1 => 'events'],
            'address' => [
                'delivery_note' => 'Leave at reception',
                'error_url' => 'https://attacker.example.test/error',
                'access_options' => [0 => 'concierge', 1 => 'lift'],
                'api_token' => 'token',
            ],
            'password' => 'Password1!',
        ]);
        $request->method('getQuery')->willReturn(new Parameters());
        $request->expects(self::once())->method('clearParams');
        $request->expects(self::once())
            ->method('setPostValue')
            ->with([
                'firstname' => 'Ada',
                'marketing_opt_in' => '1',
                'interests' => [0 => 'email', 1 => 'events'],
                'address' => [
                    'delivery_note' => 'Leave at reception',
                    'access_options' => [0 => 'concierge', 1 => 'lift'],
                ],
                'password' => 'Password1!',
            ]);

        $this->stateManager($persistor, $this->registrationPolicy(), $formFactory)
            ->sanitizeRequest(Config::FORM_CUSTOMER_REGISTRATION, $request);
    }

    public function testFilterRejectsSensitiveKeysRecursivelyEvenWhenListed(): void
    {
        $filter = new SensitiveDataFilter();

        self::assertSame([
            'profile' => [
                'email' => 'ada@example.test',
            ],
        ], $filter->filter([
            'profile' => [
                'email' => 'ada@example.test',
                'password' => 'password',
                'verificationToken' => 'token',
                'pwd' => 'password',
                'continue' => '/private',
            ],
            'form_key' => 'form-key',
        ], [
            'profile' => [
                'email' => true,
                'password' => true,
                'verificationToken' => true,
                'pwd' => true,
                'continue' => true,
            ],
            'form_key' => true,
        ]));
    }

    public function testFilterRejectsCompactAndNumericSensitiveKeyVariants(): void
    {
        $filter = new SensitiveDataFilter();
        $sensitive = [
            'password1',
            'formkey',
            'csrf',
            'session_id',
            'nonce',
            'apiTokenValue',
            'passwd',
            'captcha_solution',
            'back_url',
            'passphrase',
            'destination',
            'jwt',
        ];

        self::assertSame([], $filter->filter(
            array_fill_keys($sensitive, 'sensitive'),
            array_fill_keys($sensitive, true)
        ));
    }

    public function testFilterAllowsOnlyNumericStreetLinesFromAnExplicitWildcard(): void
    {
        $filter = new SensitiveDataFilter();

        self::assertSame([
            'street' => [
                0 => '1 Analytical Engine Way',
                1 => 'Suite 2',
            ],
        ], $filter->filter([
            'street' => [
                0 => '1 Analytical Engine Way',
                1 => 'Suite 2',
                '*' => 'not a street line',
                'password' => 'password',
                'api_token' => 'token',
            ],
        ], [
            'street' => ['*' => true],
        ]));
    }

    /**
     * @param array<string, mixed>|null $policies
     */
    private function stateManager(
        DataPersistorInterface $persistor,
        ?array $policies = null,
        ?FormFactory $formFactory = null
    ): StateManager
    {
        return new StateManager(
            new SensitiveDataFilter(),
            ['data_persistor' => $persistor],
            $policies ?? $this->contactPolicy(),
            $formFactory
        );
    }
}

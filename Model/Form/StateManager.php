<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Form;

use Magento\Catalog\Model\Session as CatalogSession;
use Magento\Customer\Api\AddressMetadataInterface;
use Magento\Customer\Api\Data\AttributeMetadataInterface;
use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Metadata\FormFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Session\Generic;
use Laminas\Stdlib\ParametersInterface;
use PrivateCaptcha\PrivateCaptcha\Model\Config;

class StateManager
{
    private const DATA_PERSISTOR = 'data_persistor';
    private const CUSTOMER_SESSION = 'customer_session';
    private const REVIEW_SESSION = 'review_session';
    private const CATALOG_SESSION = 'catalog_session';
    private const WISHLIST_SESSION = 'wishlist_session';

    /**
     * Creates form-state persistence from explicit native-store policies.
     *
     * @param SensitiveDataFilter $sensitiveDataFilter Enforces form-specific state allowlists.
     * @param array $stateStores Native persistence stores.
     * @param array $policies Form-state policies.
     * @param FormFactory|null $formFactory Native Registration metadata for visible custom attributes.
     * @phpstan-param array<string, CatalogSession|DataPersistorInterface|CustomerSession|Generic> $stateStores
     * @phpstan-param array<string, mixed> $policies
     */
    public function __construct(
        private readonly SensitiveDataFilter $sensitiveDataFilter,
        private readonly array $stateStores = [],
        private readonly array $policies = [],
        private readonly ?FormFactory $formFactory = null
    ) {
    }

    /**
     * Persists the permitted fields after a CAPTCHA failure skips controller dispatch.
     *
     * @param string $form Resolved protected form identifier.
     * @param Http $request Current frontend request.
     */
    public function persistFailure(string $form, Http $request): void
    {
        $policy = $this->getPolicy($form);
        if ($policy === null) {
            return;
        }

        $data = $request->getPostValue();
        $postData = is_array($data) ? $data : [];
        $scalarField = $this->getScalarField($policy);
        if ($scalarField !== null) {
            $safeScalar = $this->sensitiveDataFilter->filter([
                $scalarField => $this->getScalarValue($postData, $policy),
            ], [$scalarField => true]);
            $this->writeScalar($policy, $safeScalar[$scalarField] ?? null);

            return;
        }

        $this->write($policy, $this->sensitiveDataFilter->filter($postData, $policy['fields']));
    }

    /**
     * Removes unsafe request values before a verified native controller can use or persist them.
     *
     * @param string $form Resolved protected form identifier.
     * @param Http $request Current frontend request.
     */
    public function sanitizeRequest(string $form, Http $request): void
    {
        $policy = $this->getPolicy($form);
        if ($policy === null) {
            return;
        }

        $data = $request->getPostValue();
        $postData = is_array($data) ? $data : [];
        $safeData = $this->sensitiveDataFilter->filter($postData, $policy['fields']);
        $nativeValidationData = $this->filterNativeValidationFields(
            $postData,
            $policy['native_validation_fields'] ?? []
        );
        foreach ($nativeValidationData as $key => $value) {
            if ($value !== null && $value !== false && (!is_string($value) || trim($value) !== '')) {
                $safeData[$key] = $value;
            }
        }
        // Native controllers need these values during dispatch, but they never enter persisted form state.
        foreach ($this->filterNativeValidationFields($postData, $policy['transient_fields'] ?? []) as $key => $value) {
            $safeData[$key] = $value;
        }
        $this->replaceRequestData($request, $policy, $safeData);
    }

    /**
     * Removes every transient input before an ineligible native action persists the request.
     *
     * @param string $form Resolved protected form identifier.
     * @param Http $request Current frontend request.
     */
    public function sanitizeIneligibleRequest(string $form, Http $request): void
    {
        $policy = $this->getPolicy($form);
        if ($policy === null) {
            return;
        }

        $data = $request->getPostValue();
        $postData = is_array($data) ? $data : [];
        $this->replaceRequestData(
            $request,
            $policy,
            $this->sensitiveDataFilter->filter($postData, $policy['fields'])
        );
    }

    /**
     * Replaces request data while retaining only configured safe route parameters.
     *
     * @param Http $request Current frontend request.
     * @param array $policy Form-state policy.
     * @param array $safeData Sanitized native-controller input.
     */
    private function replaceRequestData(Http $request, array $policy, array $safeData): void
    {
        $routeParameters = $this->filterRouteParameters(
            $request,
            $policy['route_parameters'] ?? []
        );
        $request->clearParams();
        $query = $request->getQuery();
        if ($query instanceof ParametersInterface) {
            $query->fromArray([]);
        }
        foreach ($routeParameters as $key => $value) {
            $request->setParam($key, $value);
        }

        $request->setPostValue($safeData);
    }

    /**
     * Keeps explicitly configured native validation values out of persisted form state.
     *
     * Some native field names, such as user_forgotpassword, intentionally contain a
     * sensitive word but carry only a CAPTCHA answer for the current request.
     *
     * @param array $data Submitted request data.
     * @param array $allowed Explicit transient native validation fields.
     * @return array Allowed transient native validation data.
     */
    private function filterNativeValidationFields(array $data, array $allowed): array
    {
        $filtered = [];
        foreach ($allowed as $key => $rule) {
            if (!is_string($key) || !array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if ($rule === true && (is_scalar($value) || $value === null)) {
                $filtered[$key] = $value;

                continue;
            }

            if (is_array($rule) && is_array($value)) {
                $nested = $this->filterNativeValidationFields($value, $rule);
                if ($nested !== []) {
                    $filtered[$key] = $nested;
                }
            }
        }

        if (($allowed['*'] ?? null) === true) {
            foreach ($data as $key => $value) {
                if ((is_int($key) || (is_string($key) && ctype_digit($key))) &&
                    (is_scalar($value) || $value === null)) {
                    $filtered[$key] = $value;
                }
            }
        }

        return $filtered;
    }

    /**
     * Preserves only explicitly configured, positive native route IDs.
     *
     * @param Http $request Current frontend request.
     * @param array $allowed Explicit route-parameter rules.
     * @return array<string, int> Safe route parameters.
     */
    private function filterRouteParameters(Http $request, array $allowed): array
    {
        $filtered = [];
        foreach ($allowed as $key => $rule) {
            if (!is_string($key) || $rule !== 'positive_int') {
                continue;
            }

            $value = $request->getParam($key);
            if (!is_int($value) && (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1)) {
                continue;
            }

            $integer = (int) $value;
            if ($integer > 0 && (string) $integer === (string) $value) {
                $filtered[$key] = $integer;
            }
        }

        return $filtered;
    }

    /**
     * Removes sensitive data from state persisted by a native controller after verification succeeds.
     *
     * @param string $form Resolved protected form identifier.
     */
    public function scrub(string $form): void
    {
        $policy = $this->getPolicy($form);
        if ($policy === null) {
            return;
        }

        $data = $this->read($policy);
        $scalarField = $this->getScalarField($policy);
        if ($scalarField !== null) {
            $filteredData = is_scalar($data)
                ? $this->sensitiveDataFilter->filter([$scalarField => $data], $policy['fields'])
                : [];
            $this->writeScalar($policy, $filteredData[$scalarField] ?? null);

            return;
        }

        if (!is_array($data)) {
            if ($data !== null) {
                $this->write($policy, []);
            }

            return;
        }

        $this->write($policy, $this->sensitiveDataFilter->filter($data, $policy['fields']));
    }

    /**
     * Looks up a complete state policy for one protected form.
     *
     * @param string $form Resolved protected form identifier.
     * @return array{
     *     storage: string,
     *     key: string,
     *     fields: array<string, mixed>,
     *     native_validation_fields?: array<string, mixed>,
     *     transient_fields?: array<string, mixed>,
     *     route_parameters?: array<string, string>,
     *     scalar_field?: string,
     *     scalar_path?: array<int, string>
     * }|null
     */
    private function getPolicy(string $form): ?array
    {
        $policy = $this->policies[$form] ?? null;
        if (!is_array($policy) ||
            !is_string($policy['storage'] ?? null) ||
            !is_string($policy['key'] ?? null) ||
            !is_array($policy['fields'] ?? null) ||
            (isset($policy['native_validation_fields']) && !is_array($policy['native_validation_fields'])) ||
            (isset($policy['transient_fields']) && !is_array($policy['transient_fields'])) ||
            (isset($policy['route_parameters']) && !$this->hasValidRouteParameters($policy['route_parameters'])) ||
            (isset($policy['scalar_field']) && (!is_string($policy['scalar_field']) ||
                !array_key_exists($policy['scalar_field'], $policy['fields']))) ||
            (isset($policy['scalar_path']) && (!isset($policy['scalar_field']) ||
                !is_array($policy['scalar_path']) || $policy['scalar_path'] === [] ||
                array_filter($policy['scalar_path'], static fn (mixed $part): bool =>
                    !is_string($part) || $part === '') !== []))) {
            return null;
        }

        if ($form === Config::FORM_CUSTOMER_REGISTRATION) {
            $policy['fields'] = $this->getRegistrationFields($policy['fields']);
        }

        return $policy;
    }

    /**
     * Validates configured route-parameter rules.
     *
     * @param mixed $parameters Configured route-parameter rules.
     */
    private function hasValidRouteParameters(mixed $parameters): bool
    {
        if (!is_array($parameters)) {
            return false;
        }

        foreach ($parameters as $key => $rule) {
            if (!is_string($key) || $key === '' || $rule !== 'positive_int') {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns the configured native scalar state field, if any.
     *
     * @param array $policy Form-state policy.
     * @return string|null Native scalar state field.
     */
    private function getScalarField(array $policy): ?string
    {
        $field = $policy['scalar_field'] ?? null;

        return is_string($field) ? $field : null;
    }

    /**
     * Extracts an explicitly configured scalar from native nested request data.
     *
     * @param array $data Submitted request data.
     * @param array $policy Form-state policy.
     * @return mixed Submitted scalar candidate.
     */
    private function getScalarValue(array $data, array $policy): mixed
    {
        $field = $this->getScalarField($policy);
        if ($field === null) {
            return null;
        }

        $value = $data;
        foreach ($policy['scalar_path'] ?? [$field] as $part) {
            if (!is_string($part) || !is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }

            $value = $value[$part];
        }

        return $value;
    }

    /**
     * Adds only user-defined fields which the native Registration forms accept.
     *
     * @param array $fields Explicit Registration allowlist.
     * @return array Registration allowlist with safe custom attributes.
     */
    private function getRegistrationFields(array $fields): array
    {
        if ($this->formFactory === null) {
            return $fields;
        }

        foreach ($this->formFactory->create(
            CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
            'customer_account_create'
        )->getAllowedAttributes() as $attribute) {
            if ($attribute->isUserDefined()) {
                $fields[$attribute->getAttributeCode()] = $this->getAttributeRule($attribute);
            }
        }

        $addressFields = [];
        foreach ($this->formFactory->create(
            AddressMetadataInterface::ENTITY_TYPE_ADDRESS,
            'customer_register_address'
        )->getAllowedAttributes() as $attribute) {
            if ($attribute->isUserDefined()) {
                $addressFields[$attribute->getAttributeCode()] = $this->getAttributeRule($attribute);
            }
        }
        if ($addressFields !== []) {
            $fields['address'] = $addressFields;
        }

        return $fields;
    }

    /**
     * Allows only numeric selections for multiselect attributes.
     *
     * @param AttributeMetadataInterface $attribute Visible native form attribute.
     * @return array<string, true>|true Safe filter rule for the attribute.
     */
    private function getAttributeRule(AttributeMetadataInterface $attribute): array|bool
    {
        return $attribute->getFrontendInput() === 'multiselect' ? ['*' => true] : true;
    }

    /**
     * Reads native state for a supported policy.
     *
     * @param array $policy Form-state policy.
     * @return mixed Persisted native state.
     * @phpstan-param array{
     *     storage: string,
     *     key: string,
     *     fields: array<string, mixed>,
     *     native_validation_fields?: array<string, mixed>,
     *     transient_fields?: array<string, mixed>,
     *     route_parameters?: array<string, string>,
     *     scalar_field?: string,
     *     scalar_path?: array<int, string>
     * } $policy
     */
    private function read(array $policy): mixed
    {
        if ($policy['storage'] !== self::DATA_PERSISTOR) {
            $store = $this->getSessionStore($policy);

            // Session proxies inherit DataObject::getData(), which reads the proxy rather
            // than its subject. Explicit __call() forwards to the native session.
            return $store !== null ? $store->__call('getData', [$policy['key']]) : null;
        }

        $store = $this->stateStores[$policy['storage']] ?? null;

        return $store instanceof DataPersistorInterface ? $store->get($policy['key']) : null;
    }

    /**
     * Replaces native state with the sanitized result, or clears it when nothing is safe.
     *
     * @param array $policy Form-state policy.
     * @param array $data Sanitized state data.
     * @phpstan-param array{
     *     storage: string,
     *     key: string,
     *     fields: array<string, mixed>,
     *     native_validation_fields?: array<string, mixed>,
     *     transient_fields?: array<string, mixed>,
     *     route_parameters?: array<string, string>,
     *     scalar_field?: string,
     *     scalar_path?: array<int, string>
     * } $policy
     * @phpstan-param array<string, mixed> $data
     */
    private function write(array $policy, array $data): void
    {
        if ($policy['storage'] !== self::DATA_PERSISTOR) {
            $store = $this->getSessionStore($policy);
            if ($store === null) {
                return;
            }

            if ($data === []) {
                $store->__call('unsetData', [$policy['key']]);

                return;
            }

            $store->__call('setData', [$policy['key'], $data]);

            return;
        }

        $store = $this->stateStores[$policy['storage']] ?? null;
        if (!$store instanceof DataPersistorInterface) {
            return;
        }

        if ($data === []) {
            $store->clear($policy['key']);

            return;
        }

        $store->set($policy['key'], $data);
    }

    /**
     * Returns an explicitly configured native session store.
     *
     * @param array $policy Form-state policy.
     */
    private function getSessionStore(array $policy): CatalogSession|CustomerSession|Generic|null
    {
        if (!in_array($policy['storage'], [
            self::CATALOG_SESSION,
            self::CUSTOMER_SESSION,
            self::REVIEW_SESSION,
            self::WISHLIST_SESSION,
        ], true)) {
            return null;
        }

        $store = $this->stateStores[$policy['storage']] ?? null;

        return $store instanceof CatalogSession || $store instanceof CustomerSession || $store instanceof Generic
            ? $store
            : null;
    }

    /**
     * Replaces native scalar customer-session state, clearing empty or unsafe values.
     *
     * @param array $policy Form-state policy.
     * @param mixed $data Sanitized scalar state.
     */
    private function writeScalar(array $policy, mixed $data): void
    {
        if ($policy['storage'] !== self::CUSTOMER_SESSION) {
            return;
        }

        $store = $this->stateStores[$policy['storage']] ?? null;
        if (!$store instanceof CustomerSession) {
            return;
        }

        if (!is_scalar($data) || $data === '') {
            $store->__call('unsetData', [$policy['key']]);

            return;
        }

        $store->__call('setData', [$policy['key'], $data]);
    }
}

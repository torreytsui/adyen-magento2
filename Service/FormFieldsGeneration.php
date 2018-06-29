<?php

namespace Adyen\Payment\Service;

class FormFieldsGeneration
{
    /** @var \Adyen\Payment\Logger\AdyenLogger */
    private $_adyenLogger;
    /** @var \Adyen\Payment\Helper\Data */
    private $_adyenHelper;
    /** @var \Magento\Framework\Locale\ResolverInterface */
    private $_localeResolver;
    /** @var \Magento\Store\Model\StoreManagerInterface */
    private $_storeManager;

    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Locale\ResolverInterface $localeResolver,
        \Adyen\Payment\Helper\Data $adyenHelper,
        \Adyen\Payment\Logger\AdyenLogger $adyenLogger
    ) {
        $this->_adyenLogger = $adyenLogger;
        $this->_adyenHelper = $adyenHelper;
        $this->_localeResolver = $localeResolver;
        $this->_storeManager = $storeManager;
    }

    public function getFormFields(
        \Magento\Sales\Model\Order $order
    ) {
        $formFields = [];
        try {
            if ($order->getPayment()) {

                $realOrderId = $order->getRealOrderId();
                $orderCurrencyCode = $order->getOrderCurrencyCode();
                $skinCode = trim($this->_adyenHelper->getAdyenHppConfigData('skin_code'));
                $amount = $this->_adyenHelper->formatAmount(
                    $order->getGrandTotal(), $orderCurrencyCode
                );
                $merchantAccount = trim($this->_adyenHelper->getAdyenAbstractConfigData('merchant_account'));
                $shopperEmail = $order->getCustomerEmail();
                $customerId = $order->getCustomerId();
                $shopperIP = $order->getRemoteIp();
                $browserInfo = $_SERVER['HTTP_USER_AGENT'];
                $deliveryDays = $this->_adyenHelper->getAdyenHppConfigData('delivery_days');
                $shopperLocale = trim($this->_adyenHelper->getAdyenHppConfigData('shopper_locale'));
                $shopperLocale = (!empty($shopperLocale)) ? $shopperLocale : $this->_localeResolver->getLocale();
                $countryCode = trim($this->_adyenHelper->getAdyenHppConfigData('country_code'));
                $countryCode = (!empty($countryCode)) ? $countryCode : false;

                // if directory lookup is enabled use the billingaddress as countrycode
                if ($countryCode == false) {
                    if ($order->getBillingAddress() &&
                        $order->getBillingAddress()->getCountryId() != ""
                    ) {
                        $countryCode = $order->getBillingAddress()->getCountryId();
                    }
                }

                $formFields = [];

                $formFields['merchantAccount'] = $merchantAccount;
                $formFields['merchantReference'] = $realOrderId;
                $formFields['paymentAmount'] = (int)$amount;
                $formFields['currencyCode'] = $orderCurrencyCode;
                $formFields['shipBeforeDate'] = date(
                    "Y-m-d",
                    mktime(date("H"), date("i"), date("s"), date("m"), date("j") + $deliveryDays, date("Y"))
                );
                $formFields['skinCode'] = $skinCode;
                $formFields['shopperLocale'] = $shopperLocale;
                $formFields['countryCode'] = $countryCode;
                $formFields['shopperIP'] = $shopperIP;
                $formFields['browserInfo'] = $browserInfo;
                $formFields['sessionValidity'] = date(
                    DATE_ATOM,
                    mktime(date("H") + 1, date("i"), date("s"), date("m"), date("j"), date("Y"))
                );
                $formFields['shopperEmail'] = $shopperEmail;
                // recurring
                $recurringType = trim($this->_adyenHelper->getAdyenAbstractConfigData(
                    'recurring_type')
                );
                $brandCode = $order->getPayment()->getAdditionalInformation(
                    \Adyen\Payment\Observer\AdyenHppDataAssignObserver::BRAND_CODE
                );

                // Paypal does not allow ONECLICK,RECURRING only RECURRING
                if ($brandCode == "paypal" && $recurringType == 'ONECLICK,RECURRING') {
                    $recurringType = "RECURRING";
                }

                if ($customerId > 0) {
                    $formFields['recurringContract'] = $recurringType;
                    $formFields['shopperReference'] = $customerId;
                } else {
                    // required for openinvoice payment methods use unique id
                    $uniqueReference = "guest_" . $realOrderId . "_" . $order->getStoreId();
                    $formFields['shopperReference'] = $uniqueReference;
                }

                //blocked methods
                $formFields['blockedMethods'] = "";

                $baseUrl = $this->_storeManager->getStore($this->getStore())
                    ->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_LINK);

                $formFields['resURL'] = $baseUrl . 'adyen/process/result';
                $hmacKey = $this->_adyenHelper->getHmac();


                if ($brandCode) {
                    $formFields['brandCode'] = $brandCode;
                }

                $issuerId = $order->getPayment()->getAdditionalInformation("issuer_id");
                if ($issuerId) {
                    $formFields['issuerId'] = $issuerId;
                }

                $formFields = $this->setBillingAddressData($formFields);
                $formFields = $this->setShippingAddressData($formFields);
                $formFields = $this->setOpenInvoiceData($formFields);

                $formFields['shopper.gender'] = $this->getGenderText($order->getCustomerGender());
                $dob = $order->getCustomerDob();
                if ($dob) {
                    $formFields['shopper.dateOfBirthDayOfMonth'] = trim($this->_getDate($dob, 'd'));
                    $formFields['shopper.dateOfBirthMonth'] = trim($this->_getDate($dob, 'm'));
                    $formFields['shopper.dateOfBirthYear'] = trim($this->_getDate($dob, 'Y'));
                }

                // For klarna acceptPrivacyPolicy to skip HPP page
                if ($brandCode == "klarna") {
                    $ssn = $order->getPayment()->getAdditionalInformation('ssn');
                    if (!empty($ssn)) {
                        $formFields['shopper.socialSecurityNumber'] = $ssn;
                    }
                    //  // needed for DE and AT
                    $formFields['klarna.acceptPrivacyPolicy'] = 'true';
                }

                // OpenInvoice don't allow to edit billing and delivery items

                if ($this->_adyenHelper->isPaymentMethodOpenInvoiceMethod($brandCode)) {
                    // don't allow editable shipping/delivery address
                    $formFields['billingAddressType'] = "1";
                    $formFields['deliveryAddressType'] = "1";
                }

                if ($order->getPayment()->getAdditionalInformation("df_value") != "") {
                    $formFields['dfValue'] = $order->getPayment()->getAdditionalInformation("df_value");
                }

                // Sort the array by key using SORT_STRING order
                ksort($formFields, SORT_STRING);

                // Generate the signing data string
                $signData = implode(":", array_map([$this, 'escapeString'],
                    array_merge(array_keys($formFields), array_values($formFields))));

                $merchantSig = base64_encode(hash_hmac('sha256', $signData, pack("H*", $hmacKey), true));

                $formFields['merchantSig'] = $merchantSig;

                $this->_adyenLogger->addAdyenDebug(print_r($formFields, true));
            }

        } catch (\Symfony\Component\Config\Definition\Exception\Exception $e) {
            // do nothing for now
        }

        return $formFields;
    }
}

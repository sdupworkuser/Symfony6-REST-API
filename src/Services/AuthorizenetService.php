<?php

namespace AppBundle\Services;

use AppBundle\Entity\Authorizenet\AuthorizenetTokens;
use AppBundle\Entity\Authorizenet\AuthorizenetAuthAccess;
use AppBundle\Entity\Authorizenet\AuthorizenetTransaction;
use AppBundle\Entity\Authorizenet\AuthorizenetCustomerProfile;
use AppBundle\Entity\Authorizenet\AuthorizenetCustomersPaymentProfile;
use AppBundle\Entity\Contact;
use AppBundle\Entity\User;
use AppBundle\Validator\Payment\CreditCardTransactionValidator;
use Doctrine\ORM\EntityManager;
use Doctrine\ODM\MongoDB\DocumentManager;
use GuzzleHttp\Client as Guzzler;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class AuthorizenetService
{
    /** @var ContainerInterface $container */
    private $container;

    /** @var EntityManager $em */
    private $em;

    /** @var DocumentManager $dm */
    private $dm;

    /** @var string $auth_url */
    private $auth_url;

    /** @var string $auth_version */
    private $auth_version;

    /** @var string $client_id */
    private $client_id;

    /** @var string $client_secret */
    private $client_secret;

    public function __construct(
        ContainerInterface $container,
        EntityManager $em,
        DocumentManager $dm
    ) {
        $this->container = $container;
        $this->em = $em;
        $this->dm = $dm;
        $this->auth_url = "https://access.authorize.net";
        $this->auth_version = "v1";
        $this->client_id = "XXXXXXXXXX";
        $this->client_secret = "XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX";
    }

    /**
     * @param string $code
     * @param \AppBundle\Entity\User $user
     * @return array|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function generateAccessToken($code, $user)
    {
        $client = $this->getClient();
        try {
            $result = $client->post("oauth/{$this->auth_version}/token", [
                'form_params' => [
                    "grant_type" => "authorization_code",
                    "code" => $code,
                    "client_id" => $this->client_id,
                    "client_secret" => $this->client_secret
                ]
            ]);

            $response = json_decode($result->getBody(), true);
            if ($result->getStatusCode() !== Response::HTTP_OK) {
                return [false, $response];
            }

            $authorizenetTokenRepo = $this->em->getRepository(AuthorizenetTokens::class);
            $authorizenetToken = $authorizenetTokenRepo->findOneBy(['user' => $user]);
            if (empty($authorizenetToken)) {
                $authorizenetToken = new AuthorizenetTokens;
                $authorizenetToken->setUser( $user );
                $authorizenetToken->setCreatedAt( date('Y-m-d H:i:s') );
            }

            return [true, $this->storeAccessToken($authorizenetToken, $response)];
        } catch (\Exception $e) {
            return [false, $e->getMessage()];
        }
    }

    private function storeAccessToken($authorizenetToken, $response)
    {
        $authorizenetToken->setAccessToken( $response['access_token'] );
        $authorizenetToken->setClientStatus( $response['client_status'] );
        $authorizenetToken->setExpiresIn( $response['expires_in'] );
        $authorizenetToken->setRefreshToken( $response['refresh_token'] );
        $authorizenetToken->setRefreshTokenExpiresIn( $response['refresh_token_expires_in'] );
        $authorizenetToken->setScope( $response['scope'] );
        $authorizenetToken->setTokenType( $response['token_type'] );
        $authorizenetToken->setUpdatedAt( date('Y-m-d H:i:s') );

        $this->em->persist($authorizenetToken);
        $this->em->flush($authorizenetToken);

        return $authorizenetToken;
    }

    /**
     * @param User $user
     * @return array|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function refreshAccessToken($user)
    {
        $authTokenRepo = $this->em->getRepository(AuthorizenetTokens::class);
        $authorizenetToken = $authTokenRepo->findOneBy(['user' => $user]);

        $client = $this->getClient();
        try {
            $result = $client->post("oauth/{$this->auth_version}/token", [
                'form_params' => [
                    "grant_type" => "refresh_token",
                    "refresh_token" => $authorizenetToken->getRefreshToken(),
                    "client_id" => $this->client_id,
                    "client_secret" => $this->client_secret
                ]
            ]);

            $response = json_decode($result->getBody(), true);
            if ($result->getStatusCode() !== Response::HTTP_OK) {
                return [false, $response];
            }

            return [true, $this->storeAccessToken($authorizenetToken, $response)];
        } catch (\Exception $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * @return Guzzler
     */
    private function getClient(): Guzzler
    {
        return new Guzzler([
            "base_uri" => $this->auth_url
        ]);
    }

    /**
     * @param array $params
     * @return AuthorizenetAuthAccess
     */
    public function getAuthAccess($params)
    {
        $authAccessRepo = $this->em->getRepository(AuthorizenetAuthAccess::class);

        if ($params['type'] == 'branch') {
            $authAccess = $authAccessRepo->findOneBy(['company' => $params['id']]);
            return ($authAccess) ? $authAccess : [];
        }

        if ($params['type'] == 'company') {
            $authAccess = $authAccessRepo->findOneBy(['company' => $params['id']]);
            return ($authAccess) ? $authAccess : [];
        }

        $userRepo = $this->em->getRepository(User::class);
        $user = $userRepo->find($params['id']);

        if (!in_array('ROLE_COMPANY_ADMIN', $user->getRoles())) {
            $user = $userRepo->getCompanyAdministrator($user->getBranch()->getCompany());
        }

        $authAccess = $authAccessRepo->findOneBy(['user' => $user->getId()]);
        return ($authAccess) ? $authAccess : [];
    }

    /**
     * @param \AppBundle\Entity\User $user
     * @return AuthorizenetAuthAccess
     */
    private function getMerchantAuthAccess($user = null)
    {
        // For sub-User
        if ($user->getParent()) {
            $user = $user->getParent();
        }

        $authAccessRepo = $this->em->getRepository(AuthorizenetAuthAccess::class);
        return $authAccessRepo->findOneBy(['user' => $user]);
    }


    /**
     * @param \AppBundle\Entity\Contact $contact
     * @return AuthorizenetAuthAccess
     */
    public function getCustomerProfileAccess($contact)
    {
        $authCustomerProfile = $this->em->getRepository(AuthorizenetCustomerProfile::class);
        return $authCustomerProfile->findOneBy(['contact' => $contact->getId()]);
    }

    /**
     * @param int $invoiceId
     * @return \AppBundle\Entity\Invoice
     */
    private function getInvoiceDetails($invoiceId)
    {
        $invoiceRepo = $this->em->getRepository(\AppBundle\Entity\Invoice::class);
        return $invoiceRepo->findOneBy(['id' => $invoiceId]);
    }

    /**
     * @param int $transactionId
     * @return \AppBundle\Entity\Authorizenet\AuthorizenetTransaction
     */
    private function getTransactionDetails($transactionId)
    {
        $authTransactionRepo = $this->em->getRepository(\AppBundle\Entity\Authorizenet\AuthorizenetTransaction::class);
        return $authTransactionRepo->findOneBy(['id' => $transactionId]);
    }

    /** 
     * @param array $params
     * @param \AppBundle\Entity\User $user
     * @return AuthorizenetAuthAccess
     */
    public function storeAuthAccess(array $param, $user)
    {
        $authAccessRepo = $this->em->getRepository(AuthorizenetAuthAccess::class);
        $authAccess = $authAccessRepo->findOneBy(['user' => $user]);

        if (empty($authAccess)) {
            $authAccess = new AuthorizenetAuthAccess();
            $authAccess->setCompany( $user->getBranch()->getCompany() );
            $authAccess->setUser( $user );
            $authAccess->setCreatedAt( date('Y-m-d H:i:s') );
        }

        $authAccess->setCompany( $user->getBranch()->getCompany() );
        $secureAuth = $this->setSecureAuthAccess($param);
        $authAccess->setLoginId($secureAuth['loginId']);
        $authAccess->setTransactionKey($secureAuth['transactionKey']);
        $authAccess->setClientKey($secureAuth['clientKey']);
        $authAccess->setUpdatedAt( date('Y-m-d H:i:s') );

        $this->em->persist($authAccess);
        $this->em->flush($authAccess);

        return $authAccess;
    }

    /** 
     * @param array $param
     * @return array
     */
    private function setSecureAuthAccess($param)
    {
        $base1 = (isset($param['auth_login_id'])) ? base64_encode($param['auth_login_id']) : '';
        $base2 = (isset($param['auth_transaction_key'])) ? base64_encode($param['auth_transaction_key']) : '';
        $base3 = (isset($param['auth_client_key'])) ? base64_encode($param['auth_client_key']) : '';

        $changed1 = substr_replace($base1, '&eEnDorMeNT&', 3, 0);
        $changed2 = substr_replace($base2, '&eEnDorMeNT&', 3, 0);
        $changed3 = substr_replace($base3, '&eEnDorMeNT&', 3, 0);

        return [
            'loginId' => base64_encode($changed1),
            'transactionKey' => base64_encode($changed2),
            'clientKey' => base64_encode($changed3),
        ];
    }

    /** 
     * @param array $param
     * @return array
     */
    public function getConvertAuthAccess($param, $authChecker)
    {
        if (!$authChecker->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedHttpException("You do not have access to this method.");
        }

        return $this->getSecureAuthAccess($param);
    }

    /** 
     * @param array $param
     * @return array
     */
    private function getSecureAuthAccess($param)
    {
        $changed11 = (isset($param['login_id'])) ? base64_decode($param['login_id']) : '';
        $changed22 = (isset($param['transaction_key'])) ? base64_decode($param['transaction_key']) : '';
        $changed33 = (isset($param['client_key'])) ? base64_decode($param['client_key']) : '';
        
        $replaceCode1 = str_replace('&eEnDorMeNT&', '', $changed11);
        $replaceCode2 = str_replace('&eEnDorMeNT&', '', $changed22);
        $replaceCode3 = str_replace('&eEnDorMeNT&', '', $changed33);

        return [
            'loginId' => base64_decode($replaceCode1),
            'transactionKey' => base64_decode($replaceCode2),
            'clientKey' => base64_decode($replaceCode3),
        ];
    }

    /**
     * @param array $auth
     * @return array|mixed
     */
    private function merchantAuthenticationType($auth)
    {
        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName( $auth['loginId'] );
        $merchantAuthentication->setTransactionKey( $auth['transactionKey'] );
        
        return $merchantAuthentication;
    }

    /**
     * @param array $params
     * @return array|mixed
     */
    private function creditCardType($params)
    {
        $creditCard = new AnetAPI\CreditCardType();
        $creditCard->setCardNumber($params['cardNumber']);
        $creditCard->setExpirationDate($params['expirationDate']);
        if (isset($params['cardCode'])) {
            $creditCard->setCardCode($params['cardCode']);
        }

        return $creditCard;
    }

    /**
     * @param array $params
     * @return array|mixed
     */
    private function paymentNonceType($params)
    {
        \AppBundle\Utilities\HelperUtilities::Log('In paymentNonceType Response', [$params['dataValue']]);
        // Create the payment object for a payment nonce
        $opaqueData = new AnetAPI\OpaqueDataType();
        $opaqueData->setDataDescriptor($params['dataDescriptor']);
        $opaqueData->setDataValue($params['dataValue']);

        return $opaqueData;
    }

    /**
     * @param array|mixed $controller
     * @param array $responses
     * @return array|mixed
     */
    private function executeWithApiResponse($controller, &$responses = [])
    {
        $response = '';
        if (strtolower($this->container->getParameter('auth_net_mode')) == "production") {
            $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::PRODUCTION);
        } else {
            $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);
        }
        
        $responses[] = $response;
        return $response;
    }

    /**
     * @param CreditCardTransactionValidator $payload
     * @return array|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function creditCardCharge(CreditCardTransactionValidator $payload)
    {
        $invoice = $this->getInvoiceDetails($payload->getInvoiceId());
        if (empty($invoice)) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Invoice not found'];
        }

        if ($invoice->getStatus() == 'paid') {
            return ['code' => Response::HTTP_UNPROCESSABLE_ENTITY, 'message' => 'Invoice already paid'];
        }
        
        $contact = $this->em->getRepository(Contact::class)->find($invoice->getContactId());
        if (empty($contact)) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Invoice contact not found'];
        }

        $salesPerson = $this->em->getRepository(User::class)->find($invoice->getSalespersonId());
        if (empty($salesPerson)) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Invoice sales person not found'];
        }

        $params = [
            "amount" => $invoice->getTotalInvoiceAmount(),
            "cardNumber" => $payload->getCardNumber(),
            "expirationDate" => $payload->getExpirationYear().'-'.$payload->getExpirationMonth(),
            "cardCode" => $payload->getCvv(),
            "firstName" => $payload->getFirstName(),
            "lastName" => $payload->getLastName(),
            "invoiceNumber" => $invoice->getInvoiceNumber(),
            "id" => $contact->getId(),
            "transactionType" => "authCaptureTransaction",
            "currencyCode" => 'USD',
            "type" => "individual",
            "paymentMethod" => $payload->getPaymentMethod(), // "CreditCard || AcceptJs",
            "dataValue" => $payload->getDataValue(),
            "dataDescriptor" => $payload->getDataDescriptor()
        ];

        if (!empty($invoice->getCustomerNote())) {
            $params['description'] = $invoice->getCustomerNote();
        }

        if (!empty($contact->getEmail())) {
            $params['email'] = $contact->getEmail();
        }

        $authAccess = $this->getMerchantAuthAccess($salesPerson);

        if (empty($authAccess) || (empty($authAccess->getLoginId()) || empty($authAccess->getTransactionKey()))) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Merchant details not found'];
        }

        $auth = $this->getSecureAuthAccess([
            "login_id" => $authAccess->getLoginId(),
            "transaction_key" => $authAccess->getTransactionKey()
        ]);

        \AppBundle\Utilities\HelperUtilities::Log('In auth', [$auth]);

        /* Create a merchantAuthenticationType object with authentication details
        retrieved from the constants file */
        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName( $auth['loginId'] );
        $merchantAuthentication->setTransactionKey( $auth['transactionKey'] );

        // $merchantAuthentication = $this->merchantAuthenticationType($auth);
        
        // Set the transaction's refId
        $refId = 'ref' . time();

        if ($params['paymentMethod'] === 'creditcard') {
            \AppBundle\Utilities\HelperUtilities::Log('In creditcard', ['dataValue' => $params['dataValue']]);
            // Create the payment data for a credit card
            $creditCard = $this->creditCardType($params);
        } elseif ($params['paymentMethod'] === 'AcceptJs') {
            \AppBundle\Utilities\HelperUtilities::Log('In AcceptJs', ['dataValue' => $params['dataValue']]);
            // Create the payment data for a Accept Payment Transaction
            $opaqueData = $this->paymentNonceType($params);
        } else {
            return ['code' => Response::HTTP_UNPROCESSABLE_ENTITY, 'message' => 'Please provide payment method type.', 'response' => []];
        }

        // Add the payment data to a paymentType object
        $paymentOne = new AnetAPI\PaymentType();
        if ($params['paymentMethod'] === 'creditcard') {
            $paymentOne->setCreditCard($creditCard);
        } elseif ($params['paymentMethod'] === 'AcceptJs') {
            $paymentOne->setOpaqueData($opaqueData);
        }

        // Create order information
        $order = new AnetAPI\OrderType();
        $order->setInvoiceNumber($params['invoiceNumber']);
        if (!empty($params['description'])) {
            $order->setDescription($params['description']);
        }

        // Set the customer's Bill To address
        $customerAddress = new AnetAPI\CustomerAddressType();
        $customerAddress->setFirstName($params['firstName']);
        $customerAddress->setLastName($params['lastName']);

        // Set the customer's identifying information
        $customerData = new AnetAPI\CustomerDataType();
        $customerData->setType($params['type']);
        $customerData->setId($params['id']);
        if (!empty($params['email'])) {
            $customerData->setEmail($params['email']);
        }

        // Create a TransactionRequestType object and add the previous objects to it
        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType($params['transactionType']);
        $transactionRequestType->setAmount($params['amount']);
        $transactionRequestType->setOrder($order);
        $transactionRequestType->setPayment($paymentOne);
        $transactionRequestType->setBillTo($customerAddress);
        $transactionRequestType->setCustomer($customerData);

        // Assemble the complete transaction request
        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId($refId);
        $request->setTransactionRequest($transactionRequestType);

        // Create the controller and get the response
        $controller = new AnetController\CreateTransactionController($request);

        // execute With Auth.net Api Response
        $response = $this->executeWithApiResponse($controller);

        if (empty($response)) {
            return ['code' => Response::HTTP_UNPROCESSABLE_ENTITY, 'message' => 'Unable to create transaction', 'response' => $response];
        }

        \AppBundle\Utilities\HelperUtilities::Log('In execute With Auth.net Api Response', [$response]);

        // Storing transaction details
        $authorizenetTransaction = new AuthorizenetTransaction();
        $authorizenetTransaction->setInvoice($invoice);
        $authorizenetTransaction->setUser($salesPerson);
        $authorizenetTransaction->setContact($contact);
        $authorizenetTransaction->setCardNumber(preg_replace( "#(.*?)(\d{4})$#", "$2", $params["cardNumber"]));
        $authorizenetTransaction->setTransactionId($response->getTransactionResponse()->getTransId());
        $authorizenetTransaction->setRefId( $refId );
        $authorizenetTransaction->setTransactionBy('normal');
        $authorizenetTransaction->setTransactionAmount($invoice->getTotalInvoiceAmount());
        $authorizenetTransaction->setCreatedAt( date('Y-m-d H:i:s') );
        $authorizenetTransaction->setTransactionPayload($params);
        $authorizenetTransaction->setTransactionResponse($response->getTransactionResponse());

        $tresponse = $response->getTransactionResponse();
        if ($response->getMessages()->getResultCode() == "Ok") {
            $authorizenetTransaction->setTransactionCode($tresponse->getMessages()[0]->getCode());
            $authorizenetTransaction->setTransactionMessage($tresponse->getMessages()[0]->getDescription());
            $this->em->persist($authorizenetTransaction);
            $this->em->flush($authorizenetTransaction);
            
            $invoice->setStatus('paid');
            $invoice->setCardNumber(preg_replace( "#(.*?)(\d{4})$#", "$2", $tresponse->getAccountNumber()));
            $invoice->setAuthnetTransactionId($tresponse->getTransId());
            $invoice->setUpdatedAt( date('Y-m-d H:i:s') );
            $this->em->persist($invoice);
            $this->em->flush($invoice);

            if ($invoice->getAppointmentId() != null) {
                $appointment = $this->em->getRepository(\AppBundle\Entity\Appointment::class)->find($invoice->getAppointmentId());   

                $exitDateTime = new \DateTime($appointment->getAppointmentEndDatetime());
                $currentDateTime = new \DateTime();
        
                // Current time is grater than the appointment end datetime
                if ($currentDateTime > $exitDateTime) {
                    $appointment->setStatus("completed");
                    $this->em->persist($appointment);
                    $this->em->flush($appointment);
                }
            }

            return $response;
        }

        if ($tresponse && $tresponse->getErrors()) {
            return [
               'code' => $tresponse->getErrors()[0]->getErrorCode(),
               'message' => $tresponse->getErrors()[0]->getErrorText()
            ];
        }

        if ($response && $response->getMessages()) {
            return [
               'code' => $response->getMessages()->getMessage()[0]->getCode(),
               'message' => $response->getMessages()->getMessage()[0]->getText()
            ];
        }

        return ['code' => Response::HTTP_UNPROCESSABLE_ENTITY, 'message' => 'Unable to create transaction'];
    }

    /**
     * @param CreditCardTransactionValidator $payload
     * @return array|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function createCustomerProfile(CreditCardTransactionValidator $payload)
    {
        $invoice = $this->getInvoiceDetails($payload->getInvoiceId());
        if (empty($invoice)) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Invoice not found'];
        }

        if ($invoice->getStatus() == 'paid') {
            return ['code' => Response::HTTP_UNPROCESSABLE_ENTITY, 'message' => 'Invoice already paid'];
        }
        
        $contact = $this->em->getRepository(Contact::class)->find($invoice->getContactId());
        if (empty($contact)) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Invoice contact not found'];
        }

        $salesPerson = $this->em->getRepository(User::class)->find($invoice->getSalespersonId());
        if (empty($salesPerson)) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Invoice sales person not found'];
        }

        $responses = [];
        $params = [
            "cardNumber" => $payload->getCardNumber(),
            "expirationDate" => $payload->getExpirationYear().'-'.$payload->getExpirationMonth(),
            "cardCode" => $payload->getCvv(),
            "firstName" => $payload->getFirstName(),
            "lastName" => $payload->getLastName(),
            "id" => $contact->getId(),
            "email" => '',
            "phone" => '',
            // "transactionType" => "authCaptureTransaction",
            "currencyCode" => 'USD',
            "type" => "individual",
            "paymentMethod" => $payload->getPaymentMethod(), // "CreditCard || AcceptJs",
            "dataValue" => $payload->getDataValue(),
            "dataDescriptor" => $payload->getDataDescriptor()
        ];

        \AppBundle\Utilities\HelperUtilities::Log('In Service', [$params]);

        if (!empty($invoice->getCustomerNote())) {
            $params['description'] = $invoice->getCustomerNote();
        }

        if (!empty($contact->getEmail())) {
            $params['email'] = $contact->getEmail();
        }

        if (!empty($contact->getEmail())) {
            $params['phone'] = $contact->getPhone();
        }

        $authAccess = $this->getMerchantAuthAccess($salesPerson);

        if (empty($authAccess) || (empty($authAccess->getLoginId()) || empty($authAccess->getTransactionKey()))) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Merchant details not found'];
        }

        $auth = $this->getSecureAuthAccess([
            "login_id" => $authAccess->getLoginId(),
            "transaction_key" => $authAccess->getTransactionKey()
        ]);

        /* Create a merchantAuthenticationType object with authentication details
        retrieved from the constants file */
        $merchantAuthentication = $this->merchantAuthenticationType($auth);

        \AppBundle\Utilities\HelperUtilities::Log('In merchantAuthentication', [$merchantAuthentication]);
        
        // Set the transaction's refId
        $refId = 'ref' . time();

        if ($params['paymentMethod'] === 'creditcard') {
            // Create the payment data for a credit card
            $creditCard = $this->creditCardType($params);
        } elseif ($params['paymentMethod'] === 'AcceptJs') {
            // Create the payment data for a Accept Payment Transaction
            $opaqueData = $this->paymentNonceType($params);
        }

        // \AppBundle\Utilities\HelperUtilities::Log('In creditCard', [$creditCard]);

        $paymentCreditCard = new AnetAPI\PaymentType();
        if (strtolower($params['paymentMethod']) === 'creditcard') {
            $paymentCreditCard->setCreditCard($creditCard);
        } elseif (strtolower($params['paymentMethod']) === 'acceptjs') {
            $paymentCreditCard->setOpaqueData($opaqueData);
        }

        // Create the Bill To info for new payment type
        $billTo = new AnetAPI\CustomerAddressType();
        $billTo->setFirstName($params['firstName']);
        $billTo->setLastName($params['lastName']);
        $billTo->setPhoneNumber($params['phone']);

        // Create a customer shipping address
        $customerShippingAddress = new AnetAPI\CustomerAddressType();
        $customerShippingAddress->setFirstName($params['firstName']);
        $customerShippingAddress->setLastName($params['lastName']);
        $customerShippingAddress->setPhoneNumber($params['phone']);

        // Create an array of any shipping addresses
        $shippingProfiles[] = $customerShippingAddress;

        $customerProfileDetails = $this->em->getRepository(AuthorizenetCustomerProfile::class)->findOneBy(['contact' => $contact->getId()]);

        try {
            \AppBundle\Utilities\HelperUtilities::Log('In customerProfile', [$customerProfileDetails]);
            $customerProfileId = null;
            if (empty($customerProfileDetails)) {
                // Create a new CustomerProfileType and add the payment profile object
                $customerProfile = new AnetAPI\CustomerProfileType();
                // $customerProfile->setDescription("Customer 2 Test PHP");
                $customerProfile->setMerchantCustomerId($params['id'] . "M_" . time());
                $customerProfile->setEmail($params['email']);
                $customerProfile->setpaymentProfiles([]);
                $customerProfile->setShipToList($shippingProfiles);

                // Assemble the complete transaction request
                $request = new AnetAPI\CreateCustomerProfileRequest();
                $request->setMerchantAuthentication($merchantAuthentication);
                $request->setRefId($refId);
                $request->setProfile($customerProfile);
        
                // Create the controller and get the response
                $controller = new AnetController\CreateCustomerProfileController($request);

                $response = $this->executeWithApiResponse($controller, $responses);
                if (empty($response) || $response->getMessages()->getResultCode() != "Ok") {
                    $errorMessages = $response->getMessages()->getMessage();
                    throw new BadRequestHttpException("Unable to create customer profile [".$errorMessages[0]->getCode()."]: " . $errorMessages[0]->getText());
                }

                $customerProfileId = $response->getCustomerProfileId();
            } else {
                $customerProfileId = $customerProfileDetails->getCustomerProfileId();
            }

            // Create a new Customer Payment Profile object
            $paymentProfile = new AnetAPI\CustomerPaymentProfileType();
            $paymentProfile->setCustomerType($params['type']);
            $paymentProfile->setBillTo($billTo);
            $paymentProfile->setPayment($paymentCreditCard);

            // Assemble the complete transaction request
            $paymentProfileRequest = new AnetAPI\CreateCustomerPaymentProfileRequest();
            $paymentProfileRequest->setMerchantAuthentication($merchantAuthentication);

            // Add an existing profile id to the request
            $paymentProfileRequest->setCustomerProfileId($customerProfileId);
            $paymentProfileRequest->setPaymentProfile($paymentProfile);
            // $paymentProfileRequest->setValidationMode("liveMode");

            // Create the controller and get the response
            $controller = new AnetController\CreateCustomerPaymentProfileController($paymentProfileRequest);

            $response = $this->executeWithApiResponse($controller, $responses);
            if (empty($response) || $response->getMessages()->getResultCode() != "Ok") {
                $errorMessages = $response->getMessages()->getMessage();
                if ($errorMessages[0]->getCode() != "E00039") {
                    throw new BadRequestHttpException("Unable to create customer payment profile [".$errorMessages[0]->getCode()."]: " . $errorMessages[0]->getText());
                }
            }

            $customerPaymentProfileId = $response->getCustomerPaymentProfileId();

            \AppBundle\Utilities\HelperUtilities::Log('In response', [$response]);

            // Storing Customer Profile details
            $authCustomerProfile = new AuthorizenetCustomerProfile;
            if ($customerProfileDetails) {
                $authCustomerProfile = $customerProfileDetails;
                $authCustomerProfile->setUpdatedAt( date('Y-m-d H:i:s') );
            } else {
                $authCustomerProfile->setCreatedAt( date('Y-m-d H:i:s') );
            }
            
            $creaditCardNumber = preg_replace( "#(.*?)(\d{4})$#", "$2", $params["cardNumber"]);
            $authCustomerProfile->setContact($contact);
            $authCustomerProfile->setRefId( $refId );
            $authCustomerProfile->setPayload($params);
            $authCustomerProfile->setResponse($responses);
            $authCustomerProfile->setCustomerProfileId($customerProfileId);

            // Storing Customer Payment Profile details
            $authCustomerPaymentProfile = $this->em->getRepository(AuthorizenetCustomersPaymentProfile::class)->findOneBy([
                'contact' => $contact,
                'paymentProfileId' => $customerPaymentProfileId
            ]);

            if (empty($authCustomerPaymentProfile)) {
                $authCustomerPaymentProfile = new AuthorizenetCustomersPaymentProfile;
                $authCustomerPaymentProfile->setCreatedAt(date('Y-m-d H:i:s'));
            }

            /** @var AuthorizenetCustomersPaymentProfile $authCustomerPaymentProfile */
            $authCustomerPaymentProfile->setContact($contact);
            $authCustomerPaymentProfile->setCardNumber($creaditCardNumber);
            $authCustomerPaymentProfile->setPaymentProfileId($customerPaymentProfileId);

            $this->em->persist($authCustomerProfile);
            $this->em->flush();

            $authCustomerPaymentProfile->setAuthorizenetCustomerProfile($authCustomerProfile);
            $this->em->persist($authCustomerPaymentProfile);
            $this->em->flush();

            $invoice->setCardNumber($creaditCardNumber);
            $invoice->setAuthorizenetCustomersPaymentProfileId($authCustomerPaymentProfile->getId());
            $this->em->persist($invoice);
            $this->em->flush();
        } catch (\Exception $e) {
            return [
                'code' => Response::HTTP_NOT_ACCEPTABLE,
                'message' => $e->getMessage()
            ];
        }

        return $responses;
    }


    /**
     * @param array $payload
     * @return array|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    function chargeCustomerProfile($payload)
    {
        $invoice = $this->getInvoiceDetails($payload['invoice']);
        if (empty($invoice)) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Invoice not found'];
        }

        if ($invoice->getStatus() == 'paid') {
            return ['code' => Response::HTTP_UNPROCESSABLE_ENTITY, 'message' => 'Invoice already paid'];
        }
        
        $contact = $this->em->getRepository(Contact::class)->find($invoice->getContactId());
        if (empty($contact)) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Invoice contact not found'];
        }

        $salesPerson = $this->em->getRepository(User::class)->find($invoice->getSalespersonId());
        if (empty($salesPerson)) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Invoice sales person not found'];
        }

        $paymentProfile = $this->em->getRepository(AuthorizenetCustomersPaymentProfile::class)->find($invoice->getAuthorizenetCustomersPaymentProfileId());
        if (empty($paymentProfile)) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Payment profile details not found'];
        }

        $params = [
            "amount" => $invoice->getTotalInvoiceAmount(),
            "profileId" => $paymentProfile->getAuthorizenetCustomerProfile()->getCustomerProfileId(),
            "paymentProfileId" => $paymentProfile->getPaymentProfileId(),
            "invoiceNumber" => $invoice->getInvoiceNumber(),
            "id" => $contact->getId(),
            "email" => $contact->getEmail(),
            "phone" => $contact->getPhone(),
            "transactionType" => "authCaptureTransaction"
        ];

        $authAccess = $this->getMerchantAuthAccess($salesPerson);

        if (empty($authAccess) || (empty($authAccess->getLoginId()) || empty($authAccess->getTransactionKey()))) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Merchant details not found'];
        }

        $auth = $this->getSecureAuthAccess([
            "login_id" => $authAccess->getLoginId(),
            "transaction_key" => $authAccess->getTransactionKey()
        ]);

        /* Create a merchantAuthenticationType object with authentication details
        retrieved from the constants file */
        $merchantAuthentication = $this->merchantAuthenticationType($auth);

        // Set the transaction's refId
        $refId = 'ref' . time();

        $profileToCharge = new AnetAPI\CustomerProfilePaymentType();
        $profileToCharge->setCustomerProfileId($params['profileId']);
        $paymentProfile = new AnetAPI\PaymentProfileType();
        $paymentProfile->setPaymentProfileId($params['paymentProfileId']);
        $profileToCharge->setPaymentProfile($paymentProfile);

        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType($params['transactionType']); 
        $transactionRequestType->setAmount($params['amount']);
        $transactionRequestType->setProfile($profileToCharge);

        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId( $refId);
        $request->setTransactionRequest( $transactionRequestType);
        $controller = new AnetController\CreateTransactionController($request);
        
        // execute With Auth.net Api Response
        $response = $this->executeWithApiResponse($controller);

        if (empty($response)) {
            return ['code' => Response::HTTP_UNPROCESSABLE_ENTITY, 'message' => 'Unable to create transaction', 'response' => $response];
        }

        // Storing transaction details
        $authorizenetTransaction = new AuthorizenetTransaction();
        $authorizenetTransaction->setInvoice($invoice);
        $authorizenetTransaction->setUser($salesPerson);
        $authorizenetTransaction->setContact($contact);
        $authorizenetTransaction->setRefId( $refId );
        $authorizenetTransaction->setTransactionBy('normal');
        $authorizenetTransaction->setTransactionAmount($invoice->getTotalInvoiceAmount());
        $authorizenetTransaction->setCreatedAt( date('Y-m-d H:i:s') );
        $authorizenetTransaction->setTransactionPayload($params);
        $authorizenetTransaction->setTransactionResponse($response->getTransactionResponse());

        $tresponse = $response->getTransactionResponse();
        if ($response->getMessages()->getResultCode() == "Ok") {
            $authorizenetTransaction->setTransactionId($tresponse->getTransId());
            $authorizenetTransaction->setCardNumber(preg_replace( "#(.*?)(\d{4})$#", "$2", $tresponse->getAccountNumber()));
            $authorizenetTransaction->setTransactionCode($tresponse->getMessages()[0]->getCode());
            $authorizenetTransaction->setTransactionMessage($tresponse->getMessages()[0]->getDescription());
            $this->em->persist($authorizenetTransaction);
            $this->em->flush($authorizenetTransaction);
            
            $invoice->setStatus('paid');
            $invoice->setCardNumber(preg_replace( "#(.*?)(\d{4})$#", "$2", $tresponse->getAccountNumber()));
            $invoice->setAuthnetTransactionId($tresponse->getTransId());
            $invoice->setUpdatedAt( date('Y-m-d H:i:s') );
            $this->em->persist($invoice);
            $this->em->flush($invoice);
            return $response;
        }

        if ($tresponse && $tresponse->getErrors()) {
            return [
               'code' => $tresponse->getErrors()[0]->getErrorCode(),
               'message' => $tresponse->getErrors()[0]->getErrorText()
            ];
        }

        if ($response && $response->getMessages()) {
            return [
               'code' => $response->getMessages()->getMessage()[0]->getCode(),
               'message' => $response->getMessages()->getMessage()[0]->getText()
            ];
        }
        return ['code' => Response::HTTP_UNPROCESSABLE_ENTITY, 'message' => 'Unable to create transaction'];
    }

    /**
     * @param CreditCardTransactionValidator $payload
     * @return array|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    function createAnApplePayTransaction(CreditCardTransactionValidator $payload)
    {
        $invoice = $this->getInvoiceDetails($payload->getInvoiceId());
        if (empty($invoice)) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Invoice not found'];
        }

        if ($invoice->getStatus() == 'paid') {
            return ['code' => Response::HTTP_UNPROCESSABLE_ENTITY, 'message' => 'Invoice already paid'];
        }
        
        $contact = $this->em->getRepository(Contact::class)->find($invoice->getContactId());
        if (empty($contact)) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Invoice contact not found'];
        }

        $salesPerson = $this->em->getRepository(User::class)->find($invoice->getSalespersonId());
        if (empty($salesPerson)) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Invoice sales person not found'];
        }

        $params = [
            "amount" => $invoice->getTotalInvoiceAmount(),
            "cardNumber" => $payload->getCardNumber(),
            "expirationDate" => $payload->getExpirationYear().'-'.$payload->getExpirationMonth(),
            "cardCode" => $payload->getCvv(),
            "firstName" => $payload->getFirstName(),
            "lastName" => $payload->getLastName(),
            "invoiceNumber" => $invoice->getInvoiceNumber(),
            "id" => $contact->getId(),
            "transactionType" => "authCaptureTransaction",
            "currencyCode" => 'USD',
            "type" => "individual",
            "dataDiscriptor" => "COMMON.APPLE.INAPP.PAYMENT",
            "dataValue" => "=="
        ];

        $authAccess = $this->getMerchantAuthAccess($salesPerson);

        if (empty($authAccess) || (empty($authAccess->getLoginId()) || empty($authAccess->getTransactionKey()))) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Merchant details not found'];
        }

        $auth = $this->getSecureAuthAccess([
            "login_id" => $authAccess->getLoginId(),
            "transaction_key" => $authAccess->getTransactionKey()
        ]);

        /* Create a merchantAuthenticationType object with authentication details
        retrieved from the constants file */
        $merchantAuthentication = $this->merchantAuthenticationType($auth);
        
        // Set the transaction's refId
        $refId = 'ref' . time();

        $op = new AnetAPI\OpaqueDataType();
        $op->setDataDescriptor($params["dataDiscriptor"]);
        $op->setDataValue( base64_encode($params['dataValue']) );
        $paymentOne = new AnetAPI\PaymentType();
        $paymentOne->setOpaqueData($op);

        //create a transaction
        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType($params["transactionType"]);
        $transactionRequestType->setAmount($params["amount"]);
        $transactionRequestType->setPayment($paymentOne);

        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId( $refId);
        $request->setTransactionRequest( $transactionRequestType);

        $controller = new AnetController\CreateTransactionController($request);
        
        // execute With Auth.net Api Response
        $response = $this->executeWithApiResponse($controller);

        $tresponse = $response->getTransactionResponse();
        if ($response->getMessages()->getResultCode() == "Ok") {
            return $response;
        }

        if ($tresponse && $tresponse->getErrors()) {
            return [
               'code' => $tresponse->getErrors()[0]->getErrorCode(),
               'message' => $tresponse->getErrors()[0]->getErrorText()
            ];
        }

        if ($response && $response->getMessages()) {
            return [
               'code' => $response->getMessages()->getMessage()[0]->getCode(),
               'message' => $response->getMessages()->getMessage()[0]->getText()
            ];
        }

        return ['code' => Response::HTTP_UNPROCESSABLE_ENTITY, 'message' => 'Unable to create transaction'];
    }

    /** 
     * @param string $amount
     * @param string $dataValue
     * @param \AppBundle\Entity\User $user
     * @return AuthorizenetAuthAccess
     */
    function createGooglePayTransaction($amount, $dataValue, $user)
    {
        $params = [
            "itemId" => "1",
            "name" => "vase",
            "description" => "Cannes logo",
            "quantity" => 18,
            "unitPrice" => 45.00,
            "amount" => 5.00,
            "taxName" => "level2 tax name",
            "taxDiscription" => "level2 tax",
            "transactionType" => "authCaptureTransaction",
            "userFieldName1" => "UserDefinedFieldName1",
            "userFieldValue1" => "UserDefinedFieldValue1",
            "userFieldName2" => "UserDefinedFieldName2",
            "userFieldValue2" => "UserDefinedFieldValue2",
        ];

        $authAccess = $this->getMerchantAuthAccess($user);

        /* Create a merchantAuthenticationType object with authentication details
        retrieved from the constants file */
        $merchantAuthentication = $this->merchantAuthenticationType($auth);
        
        $refId = 'ref' . time();

        $opaqueData = new AnetAPI\OpaqueDataType();
        $opaqueData->setDataDescriptor("COMMON.GOOGLE.INAPP.PAYMENT");
        $opaqueData->setDataValue($dataValue);
        $paymentType = new AnetAPI\PaymentType();
        $paymentType->setOpaqueData($opaqueData);

        $lineItem = new AnetAPI\LineItemType();
        $lineItem->setItemId($params['itemId']);
        $lineItem->setName($params['name']);
        $lineItem->setDescription($params['description']);
        $lineItem->setQuantity($params['quantity']);
        $lineItem->setUnitPrice($params['unitPrice']);

        $lineItemsArray = array();
        $lineItemsArray[0] = $lineItem;

        $tax = new AnetAPI\ExtendedAmountType();
        $tax->setAmount($params['amount']);
        $tax->setName($params['taxName']);
        $tax->setDescription($params['taxDescription']);

        $userField = new AnetAPI\UserFieldType();
        $userFields = array();

        $userField->setName($params['userFieldName1']);
        $userField->setValue($params['userFieldValue1']);
        $userFields[0] = $userField;

        $userField->setName($params['userFieldName2']);
        $userField->setValue($params['userFieldValue2']);
        $userFields[1] = $userField;

        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType($params['transactionType']);
        $transactionRequestType->setAmount($amount);
        $transactionRequestType->setPayment($paymentType);

        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId($refId);
        $request->setTransactionRequest( $transactionRequestType);

        $controller = new AnetController\CreateTransactionController($request);
        
        // execute With Auth.net Api Response
        $response = $this->executeWithApiResponse($controller);

        $tresponse = $response->getTransactionResponse();
        if ($response->getMessages()->getResultCode() == "Ok") {
            return $response;
        }

        if ($tresponse && $tresponse->getErrors()) {
            return [
               'code' => $tresponse->getErrors()[0]->getErrorCode(),
               'message' => $tresponse->getErrors()[0]->getErrorText()
            ];
        }

        if ($response && $response->getMessages()) {
            return [
               'code' => $response->getMessages()->getMessage()[0]->getCode(),
               'message' => $response->getMessages()->getMessage()[0]->getText()
            ];
        }

        return null;
    }

    /** 
     * @param array $payload
     * @return AuthorizenetAuthAccess
     */
    public function refundTransaction($payload) 
    {
        $authTransaction = $this->getTransactionDetails($payload['transaction_id']);
        if (empty($authTransaction)) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Transaction not found'];
        }

        if ($payload['amount'] > $authTransaction->getTransactionAmount()) {
            return ['code' => Response::HTTP_UNPROCESSABLE_ENTITY, 'message' => 'Amount not matching with transaction'];
        }

        $params = [
            "refTransId" => $authTransaction->getTransactionId(),
            "cardNumber" => $authTransaction->getCardNumber(),
            "amount" => $authTransaction->getTransactionAmount(),
            "expirationDate" => "XXXX",
            "transactionType" => "refundTransaction",
            "currencyCode" => 'USD',
            "type" => "individual",
            "paymentMethod" => "CreditCard" // $payload->getPaymentMethod() // "CreditCard || AcceptJs",
            // "paymentMethod" => $payload->getPaymentMethod(), // "CreditCard || AcceptJs",
            // "dataValue" => $payload->getDataValue()
        ];

        $salesPerson = $this->em->getRepository(User::class)->find($authTransaction->getInvoice()->getSalespersonId());
        if (empty($salesPerson)) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Invoice sales person not found'];
        }
        $authAccess = $this->getMerchantAuthAccess($salesPerson);

        if (empty($authAccess) || (empty($authAccess->getLoginId()) || empty($authAccess->getTransactionKey()))) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Merchant details not found'];
        }

        $auth = $this->getSecureAuthAccess([
            "login_id" => $authAccess->getLoginId(),
            "transaction_key" => $authAccess->getTransactionKey()
        ]);

        /* Create a merchantAuthenticationType object with authentication details
        retrieved from the constants file */
        $merchantAuthentication = $this->merchantAuthenticationType($auth);
        
        // Set the transaction's refId
        $refId = 'ref' . time();
    
        if ($params['paymentMethod'] === 'creditcard') {
            // Create the payment data for a credit card
            $creditCard = $this->creditCardType($params);
        } elseif ($params['paymentMethod'] === 'AcceptJs') {
            // Create the payment data for a Accept Payment Transaction
            $opaqueData = $this->paymentNonceType($params);
        }

        $paymentOne = new AnetAPI\PaymentType();
        if ($params['paymentMethod'] === 'creditcard') {
            $paymentOne->setCreditCard($creditCard);
        } elseif ($params['paymentMethod'] === 'AcceptJs') {
            $paymentOne->setOpaqueData($opaqueData);
        }

        //create a transaction
        $transactionRequest = new AnetAPI\TransactionRequestType();
        $transactionRequest->setTransactionType($params["transactionType"]); 
        $transactionRequest->setAmount($params["amount"]);
        $transactionRequest->setPayment($paymentOne);
        $transactionRequest->setRefTransId($params["refTransId"]);
     
        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId($refId);
        $request->setTransactionRequest( $transactionRequest);
        $controller = new AnetController\CreateTransactionController($request);
        
        // execute With Auth.net Api Response
        $response = $this->executeWithApiResponse($controller);

        $tresponse = $response->getTransactionResponse();
        if ($response->getMessages()->getResultCode() == "Ok") {
            return $response;
        }

        if ($tresponse && $tresponse->getErrors()) {
            return [
               'code' => $tresponse->getErrors()[0]->getErrorCode(),
               'message' => $tresponse->getErrors()[0]->getErrorText()
            ];
        }

        if ($response && $response->getMessages()) {
            return [
               'code' => $response->getMessages()->getMessage()[0]->getCode(),
               'message' => $response->getMessages()->getMessage()[0]->getText()
            ];
        }

        return ['code' => Response::HTTP_UNPROCESSABLE_ENTITY, 'message' => 'Unable to create transaction'];
    }


    /** 
     * @param User $user
     * @param string $customerProfileId
     * @param string $customerPaymentProfileId
     * @return AuthorizenetAuthAccess
     */
    function getCustomerPaymentProfile($user, $customerProfileId = "", $customerPaymentProfileId = "")
    {

        $authAccess = $this->getMerchantAuthAccess($user);

        if (empty($authAccess) || (empty($authAccess->getLoginId()) || empty($authAccess->getTransactionKey()))) {
            return ['code' => Response::HTTP_NOT_FOUND, 'message' => 'Merchant details not found'];
        }

        $auth = $this->getSecureAuthAccess([
            "login_id" => $authAccess->getLoginId(),
            "transaction_key" => $authAccess->getTransactionKey()
        ]);

        /* Create a merchantAuthenticationType object with authentication details
        retrieved from the constants file */
        $merchantAuthentication = $this->merchantAuthenticationType($auth);
        
        // Set the transaction's refId
        $refId = 'ref' . time();

        //request requires customerProfileId and customerPaymentProfileId
        $request = new AnetAPI\GetCustomerPaymentProfileRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId( $refId);
        $request->setCustomerProfileId($customerProfileId);
        $request->setCustomerPaymentProfileId($customerPaymentProfileId);

        $controller = new AnetController\GetCustomerPaymentProfileController($request);
        
        // execute With Auth.net Api Response
        $response = $this->executeWithApiResponse($controller);

        \AppBundle\Utilities\HelperUtilities::Log('In getCustomerPaymentProfile Result', [$response]);

        if ($response->getMessages()->getResultCode() == "Ok") {
            return $response;
        }

        if ($response && $response->getMessages()) {
            return [
               'code' => $response->getMessages()->getMessage()[0]->getCode(),
               'message' => $response->getMessages()->getMessage()[0]->getText()
            ];
        }

        return ['code' => Response::HTTP_NO_CONTENT, 'message' => 'NULL Response Error'];
    }
}

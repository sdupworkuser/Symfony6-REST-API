<?php

namespace AppBundle\Services;

use AppBundle\Entity\Branch;
use AppBundle\Entity\Calendar;
use AppBundle\Entity\Company;
use AppBundle\Entity\Invoice;
use AppBundle\Entity\User;
use AppBundle\Entity\Appointment;
use AppBundle\Document\AppointmentRequest;
use Doctrine\ORM\EntityManager;
use GuzzleHttp\Client;
use Google_Service_Calendar_FreeBusyRequest;
use Google_Service_Calendar_FreeBusyRequestItem;
use Google_Service_Calendar_Event;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\DateTime;
use AppBundle\Entity\Jupiter\JupiterMerchant;
use AppBundle\Entity\ReviewAggregationToken\GoogleToken;

/**
 * Class GoogleCalendarService
 * @package AppBundle\Services
 */
class GoogleCalendarService 
{
    const GOOGLE_CALENDAR_API_SCOPE = 'https://www.googleapis.com/auth/calendar.readonly     https://www.googleapis.com/auth/calendar.events';

    /** @var EntityManager */
    private $em;

    /** @var string $clientId */
    private $clientId;

    /** @var string $clientSecret */
    private $clientSecret;

    /** @var string $redirectUri */
    private $redirectUri;

    /** @var object $appointmentService */
    private $appointmentService;

    /** @var object $appointmentRequestService */
    private $appointmentRequestService;

    /** @var object $appointmentReminderService */
    private $appointmentReminderService;
    
    /** @var ContainerInterface $container */
    private $container;

    /**
     * GoogleMyBusinessService constructor.
     * @param EntityManager $em
     * @param string $clientId
     * @param string $clientSecret
     * @param string $redirectUri
     * @param ContainerInterface $container
     */
    public function __construct(
        EntityManager $em,
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        ContainerInterface $container
    ) {
        $this->em = $em;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        $this->container = $container;
        $this->appointmentService = $container->get('appointment_service');
        $this->appointmentRequestService = $container->get('appointment_request_service');
        $this->appointmentReminderService = $container->get('appointment_reminder_service');
    }

    /**
     * @return string
     */
    public function getRedirectUrl($stateParams=array())
    {
        $client = $this->createAuthorizationRequest($stateParams);
        return $client->createAuthUrl();
    }

    /**
     * @return \Google_Client
     */
    private function createAuthorizationRequest($stateParams)
    {
        $client = new \Google_Client;
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->addScope([$this::GOOGLE_CALENDAR_API_SCOPE]);
        $client->setRedirectUri($this->redirectUri);
        $client->setAccessType('offline');
        $client->setIncludeGrantedScopes(true);
        $client->setApprovalPrompt('force');
        if(!empty($stateParams)) {
            $client->setState($stateParams);
        }
        return $client;
    }

    /**
     * @param string $authorizationCode
     * @return array
     */
    public function exchangeAuthorizationCode(string $authorizationCode)
    {
        $client = new \Google_Client;
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);
        $client->fetchAccessTokenWithAuthCode($authorizationCode);

        return $client->getAccessToken();
    }

    /**
     * @param array $token
     * @param Calendar $calendar
     * @param String $profileType
     * @param User $user
     * @return Calendar $calendar
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function persistToken(array $token, Calendar $calendar, String $profileType, User $user)
    {
        if($profileType == "USER") {
            $calendar->setUser($user);
        }
        else if($profileType == "BRANCH") {
            $calendar->setBranch($user->getBranch());
        }
        else if($profileType == "COMPANY") {
            $calendar->setCompany($user->getBranch()->getCompany());
        }

        $calendar->setCalendarType("google");
        $calendar->setTokenType($token["token_type"]);
        $calendar->setAccessToken($token["access_token"]);
        $calendar->setExpiresIn($token["expires_in"]);
        $calendar->setCreated($token["created"]);
        $calendar->setRefreshToken($token["refresh_token"]);
        $calendar->setActive(true);

        // If the calendar already exists, update the existing calendar
        $calendarExists = $this->em->getRepository(Calendar::class)->findOneBy([
            'calendarType' => $calendar->getCalendarType(),
            'user' => $calendar->getUser(),
            'branch' => $calendar->getBranch(),
            'company' => $calendar->getCompany()
        ]);

        if ($calendarExists) {
            $this->deleteToken($calendarExists);
        }

        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }

    /**
     * @param GoogleToken $token
     * @param GoogleToken $data
     * @return GoogleToken
     */
    public function putToken(GoogleToken $token, GoogleToken $data)
    {
        if (null !== $data->getAccountName()) {
            $token->setAccountName($data->getAccountName());
        }

        if (null !== $data->getLocationName()) {
            $token->setLocationName($data->getLocationName());
        }

        $this->em->persist($token);
        $this->em->flush();

        return $token;
    }

    /**
     * @param Calendar $calendar
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function deleteToken(Calendar $calendar)
    {
        $client = new \Google_Client;
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);

        // Ensure token is valid with Google
        $validToken = $this->getToken($calendar);

        // If ok, revoke the let Google know that we want to revoke the token
        if ($validToken) {
            $client->setAccessToken($validToken);
            $client->revokeToken();
        }

        $this->em->remove($calendar);
        $this->em->flush();
    }

    /**
     * @param Calendar $calendar
     * @param $timeMin , $timeMax, $calendarId
     * @return array
     */
    public function checkCalendarFreeBusy(Calendar $calendar, $timeMin, $timeMax, $calendarId){

        $client = new \Google_Client;
        $client->setAccessToken($this->getToken($calendar));
        $service = new \Google_Service_Calendar($client);

        $freebusy_req = new Google_Service_Calendar_FreeBusyRequest();
        $freebusy_req->setTimeMin($timeMin); 
        $freebusy_req->setTimeMax($timeMax);

        $item = new Google_Service_Calendar_FreeBusyRequestItem();
        $item->setId($calendarId);

        $freebusy_req->setItems(array($item));
        $query = $service->freebusy->query($freebusy_req);
        
        $query = json_encode($query);

        return $query;
    }

    /**
     * @param Calendar|null $calendar
     * @param Array $appointment
     * @param String $calendarId
     * @param string $eventId
     * @param bool|null $online
     * @return array
     */
    public function cancelEvent($calendar, $calendarId, $eventId) 
    {
        if ($calendarId != NULL && $calendar != NULL) {
            $client = new \Google_Client;
            $client->setAccessToken($this->getToken($calendar));

            $service = new \Google_Service_Calendar($client);
            $service->events->delete($calendarId, $eventId);
        }
    }

    /**
     * @param Calendar|null $calendar
     * @param Array $event
     * @param Array $appointment
     * @param String $calendarId
     * @param User|null $user
     * @param Branch|null $branch
     * @param Company|null $company
     * @param string $profileType
     * @param bool|null $online
     * @return array
     */
    public function addEvent($calendar, $event, $appointment, $calendarId, $user, $branch, $company, $profileType, $invoiceId = null, $currentTime = null, $online = false) 
    {
        $processStatus = false;
        $return = [];
        \AppBundle\Utilities\HelperUtilities::Log('In addEvent 1', [$appointment['location']]);
        if ($appointment['cost'] == 0 && $appointment['cost'] <= 0) {

            /*--------Check & Create contact if not exists--------*/
            $contactCheck = [];
            $contactCheck['first_name'] = $appointment['contact_name'];

            if (strpos($appointment['contact_name'], ' ') !== false) {
                $fullName = explode(' ', preg_replace('/\s+/', ' ', $appointment['contact_name']));
                $contactCheck['first_name'] = $fullName[0];
                $contactCheck['last_name'] = $fullName[1];
            }
            
            $contactCheck['email'] = $appointment['contact_email'];
            $contactCheck['secondary_phone'] = $appointment['contact_phone'];
            $contactCheck['do_not_text'] = ($appointment['do_not_text'] == true) ? "false" : "true";

            $contact = $this->container->get('contact_service')->createContactFromArrayIfNotExists($contactCheck, $user);
            if (!isset($contact['contact'])) {
                return $contact;
            } else {
                $return['contact'] = $contact['contact'];
            }

            /* Manage Appointment Process */
            $appointmentData = $this->manageAppointmentProcess($appointment, $user, $branch, $company, null, $return['contact'], null);
            $return = [ 'appointmentData' => $appointmentData ];
            \AppBundle\Utilities\HelperUtilities::Log('In addEvent 2', [$appointmentData['location']]);
            $online = false;
            $processStatus = true;
        }

        if ($online) {
            try {
                $return = $invoiceDetails = $this->createContactInvoiceAndTransaction($appointment, $user, $branch, $company, $profileType, $invoiceId, $currentTime);
                if (!isset($invoiceDetails['contact'])) {
                    return $invoiceDetails;
                }

                if ((isset($invoiceDetails['transaction']) && !empty($invoiceDetails['transaction']) && $invoiceDetails['transaction']->getStatus() == 'approved' ) && !empty($invoiceDetails['transaction']->getTransactionId())) {
                    $processStatus = true;
                } else { // For now Jupiter payment are not supported that why its true in else
                    $processStatus = true;
                }

                if ((!array_key_exists('token', $appointment) || $appointment['token'] == NULL) && (!empty($invoiceDetails['invoice']['id']) && $invoiceDetails['invoice']['total_invoice_amount'] > 0)) {
                    /* Manage Appointment Process */
                    $appointmentData = $this->manageAppointmentProcess($appointment, $user, $branch, $company, $invoiceDetails['invoice'], $invoiceDetails['contact'], null);

                    $return['appointmentData'] = $appointmentData;
                }

            } catch (\Exception $e) {
                return $e->getMessage();
            }
        }

        if ($processStatus) {
            $eventId = "";
            $location = $appointment['location'];
            \AppBundle\Utilities\HelperUtilities::Log('In addEvent 3', [$location]);
            \AppBundle\Utilities\HelperUtilities::Log('In createContactInvoiceAndTransaction', []);
            if ($event != NULL && $calendarId != NULL && $calendar != NULL) {
                $client = new \Google_Client;
                $client->setAccessToken($this->getToken($calendar));
                $service = new \Google_Service_Calendar($client);

                $event = new Google_Service_Calendar_Event($event);
                if ($location == 'google_meet') {
                    // Add Google Meet link to the event
                    $solution_key = new \Google_Service_Calendar_ConferenceSolutionKey();
                    $solution_key->setType("hangoutsMeet");

                    $conferenceRequest = new \Google_Service_Calendar_CreateConferenceRequest();
                    $conferenceRequest->setRequestId($appointment['location'] . time());
                    $conferenceRequest->setConferenceSolutionKey($solution_key);

                    $conferenceData = new \Google_Service_Calendar_ConferenceData();
                    $conferenceData->setCreateRequest($conferenceRequest);
                    $event->setConferenceData($conferenceData);
                    $event->setLocation(null);
                }

                $event = $service->events->insert($calendarId, $event, array('conferenceDataVersion' => 1));
                $eventId = $event['id'];
                if ($location == 'google_meet') {
                    $location = $event->getHangoutLink();
                }
                \AppBundle\Utilities\HelperUtilities::Log('In addEvent 4', [$location]);
            }

            $appointment['location'] = $location;
            \AppBundle\Utilities\HelperUtilities::Log('In addEvent 5', [$location]);
            if (empty($return['appointmentData'])) {
                /* Manage Appointment Process */
                $appointmentData = $this->manageAppointmentProcess($appointment, $user, $branch, $company, $invoiceDetails['invoice'], $invoiceDetails['contact'], $eventId);

                $return['appointmentData'] = $appointmentData;
            } else {
                $return['appointmentData']->setEventId($eventId);
                $return['appointmentData']->setStatus("open");
                $return['appointmentData']->setLocation($location);
                \AppBundle\Utilities\HelperUtilities::Log('In addEvent 6', [$location]);
        
                $this->em->persist($return['appointmentData']);
                $this->em->flush();
            }

            return $return;
        }

        return $return;
    }

    /**
     * @return array
     */
    public function manageAppointmentProcess($appointment, $user, $branch, $company, $invoice, $contact, $eventId)
    {
        $appointmentData = $this->appointmentService->createAppointment($appointment, $eventId);
        $before24Hours = true;

        /*--------Add Default Scheduled Appointment Email Confirmation profile--------*/
        $appointmentRequestUser = $this->appointmentRequestService->createDefaultAppointmentRequest($appointmentData, $user, $branch, $company);
        if($appointmentRequestUser) {
            $this->appointmentRequestService->createAppointmentRequest($appointmentRequestUser);
        }

        /*--------Add Scheduled Appointment Email Confirmation Guest profile--------*/ 
        $appointmentRequestGuest = $this->appointmentRequestService->createAppointmentRequestFromObject($appointmentData);
        $this->appointmentRequestService->createAppointmentRequest($appointmentRequestGuest);
        

        /*--------Add Scheduled Appointment Reminder profile--------*/
        if((isset($appointment["is_reminder"]) && $appointment["is_reminder"] == true) || $before24Hours) {
            $appointmentReminder = $this->appointmentReminderService->createAppointmentReminderFromObject($appointmentData, $appointment["reminder_time"], $user, $branch, $company);
            if($appointmentReminder){
                $this->appointmentReminderService->createAppointmentReminder($appointmentReminder);
            }
        }

        /*--------Add Scheduled Appointment Reminder Guest profile--------*/  
        if((isset($appointment["is_guest_reminder"]) && $appointment["is_guest_reminder"] == true) || $before24Hours) {
            $appointmentReminderGuest = $this->appointmentReminderService->createAppointmentReminderGuestFromObject($appointmentData, $appointment["guest_reminder_time"]);

            $this->appointmentReminderService->createAppointmentReminder($appointmentReminderGuest);
        }

        /*--------Update Invoice Details--------*/  
        if (!empty($invoice)) {
            $invoice = $this->em->getRepository(Invoice::class)->find($invoice['id']);
            $appointmentData->setInvoice($invoice);


            $invoice->setAppointmentId( $appointmentData->getId() );
            $invoice->setCustomerNote( 'Payment against Appointment #'.$appointmentData->getId() );
            $this->em->persist($invoice);
            $this->em->flush();
        }
    
        $appointmentData->setContactId($contact->getId());
        $this->em->persist($appointmentData);

        return $appointmentData;
    }

    public function createContactInvoiceAndTransaction($appointment, $user, $branch, $company, $profileType, $invoiceId = null, $currentTime = null, $jupiterPay = false) 
    {
        \AppBundle\Utilities\HelperUtilities::Log('In createContactInvoiceAndTransaction', []);
        $res = [
            'oldSlot' => false,
            'invoice' => [],
            'transaction' => [],
            'appointmentData' => []
        ];

        /*--------Check & Create contact if not exists--------*/
        $contactCheck = [];
        $contactCheck['first_name'] = $appointment['contact_name'];

        if (strpos($appointment['contact_name'], ' ') !== false) {
            $fullName = explode(' ', preg_replace('/\s+/', ' ', $appointment['contact_name']));
            $contactCheck['first_name'] = $fullName[0];
            $contactCheck['last_name'] = $fullName[1];
        }
        
        $contactCheck['email'] = $appointment['contact_email'];
        $contactCheck['secondary_phone'] = $appointment['contact_phone'];
        $contactCheck['do_not_text'] = ($appointment['do_not_text'] == true) ? "false" : "true";

        \AppBundle\Utilities\HelperUtilities::Log('In before contact', []);
        $contact = $this->container->get('contact_service')->createContactFromArrayIfNotExists($contactCheck, $user);

        if (!isset($contact['contact'])) {
            \AppBundle\Utilities\HelperUtilities::Log('In if ', ['contact' => $contact]);
            return $contact;
        } else {
            \AppBundle\Utilities\HelperUtilities::Log('In else ', ['contact' => $contact]);
            $res['contact'] = $contact['contact'];
        }
        \AppBundle\Utilities\HelperUtilities::Log('In after contact', ['contact' => $contact]);

        /* Check & Create Invoice*/
        if ($invoiceId != NULL && !empty($invoiceId)) {
            $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['id' => $invoiceId]);
        } else {
            $invoice = $this->container->get('invoice_service')->createInvoiceFromArray($contact['contact'], $appointment['cost']);
        }

        // $invoice->setStatus( 'pending' );
        $res['invoice'] = [
            'id' => $invoice->getId(),
            'total_invoice_amount' => $invoice->getTotalInvoiceAmount()
        ];

        if ($currentTime >= $appointment['appointment_start_datetime'] ) {
            $res['oldSlot'] = true;
            return $res;
        }
        
        if ($jupiterPay) {

            $jupiterMerchant = null;

            if (array_key_exists('parent_id', $appointment) && $appointment['parent_id'] != NULL) {
                $jupiterMerchant = $this->em->getRepository(JupiterMerchant::class)->findOneBy(['userId' => $appointment['parent_id']]);
    
                if ($jupiterMerchant === null || empty($jupiterMerchant)) {
                    $parent = $this->em->getRepository(User::class)->find($appointment['parent_id']);
    
                    $jupiterMerchant = $this->em->getRepository(JupiterMerchant::class)->findOneBy(['companyId' => $parent->getBranch()->getCompany()->getId()]);
                }
            } else {
                switch ($profileType) {
                    case 'USER':
                        $jupiterMerchant = $this->em->getRepository(JupiterMerchant::class)->findOneBy(['userId' => $user->getId()]);
                        break;
                    
                    case 'BRANCH':
                        $jupiterMerchant = $this->em->getRepository(JupiterMerchant::class)->findOneBy(['companyId' => $branch->getCompany()->getId()]);
                        break;
                    case 'COMPANY':
                        $jupiterMerchant = $this->em->getRepository(JupiterMerchant::class)->findOneBy(['companyId' => $company->getId()]);
                        break;
                }
            }
    
            if ($jupiterMerchant == null || !array_key_exists('token', $appointment) || $appointment['token'] == NULL) {
                $invoice->setSalespersonDetail($user);
                return $res;
            }
    
            $saleRes = $this->container->get('jupiter_service')->createSale($appointment['token'], $jupiterMerchant, $invoice);
    
            $transaction = $this->container->get('jupiter_service')->createTransactionFromArray($saleRes, $invoice);
            $res['transaction'] = $transaction;

            if ($transaction->getStatus() != "faild") {
                $invoice->setStatus( 'paid' );
            }
            $invoice->setJupiterTransactionId( $transaction->getId() );
        }

        $invoice->setUpdatedAt( date('Y-m-d H:i:s') );
        $invoice->setSalespersonDetail($user);
        $this->em->persist($invoice);
        $this->em->flush();

        \AppBundle\Utilities\HelperUtilities::Log('Out createContactInvoiceAndTransaction', ['$res' => $res]);
        return $res;
    }

    /**
     * @param $token
     * @return bool|\GuzzleHttp\ClientInterface
     */
    private function getAuthorizedHttpClient($token)
    {
        $client = new \Google_Client;
        if ($token) {
            $client->setAccessToken($token);

            /** @var Client $httpClient */
            return $client->authorize();
        }

        return false;
    }

    /**
     * @param Calendar $calendar
     * @return bool|string
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    private function getToken(Calendar $calendar)
    {
        if ($this->isTokenExpired($calendar)) {
            $token = $this->refreshToken($calendar);
            return $token ? $token->getAccessToken() : false;
        }

        return $calendar->getAccessToken();
    }

    /**
     * @param Calendar $calendar
     * @return bool
     */
    private function isTokenExpired(Calendar $calendar)
    {
        return $calendar->getCreated() + $calendar->getExpiresIn() < time();
    }

    /**
     * @param Calendar $calendar
     * @return Calendar|bool
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    private function refreshToken(Calendar $calendar)
    {
        $client = new \Google_Client;
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->fetchAccessTokenWithRefreshToken($calendar->getRefreshToken());

        $newToken = $client->getAccessToken();

        if (null == $newToken) {
            return false;
        }

        $calendar->setAccessToken($newToken['access_token']);
        $calendar->setTokenType($newToken['token_type']);
        $calendar->setExpiresIn($newToken['expires_in']);
        $calendar->setCreated($newToken['created']);
        $calendar->setRefreshToken($newToken['refresh_token']);
        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }
}

<?php

namespace AppBundle\Services;

use AppBundle\Entity\Branch;
use AppBundle\Entity\Calendar;
use AppBundle\Entity\Company;
use AppBundle\Entity\User;
use AppBundle\Entity\Appointment;
use AppBundle\Entity\ProfileAppointmentSetting;
use AppBundle\Entity\Jupiter\JupiterTransactions;
use AppBundle\Document\AppointmentRequest;
use Doctrine\ORM\EntityManager;
use GuzzleHttp\Client;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model;
use Microsoft\Graph\Model\Event;
use Microsoft\Graph\Http\GraphRequest;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\DateTime;

/**
 * Class OutlookCalendarService
 * @package AppBundle\Services
 */
class OutlookCalendarService
{

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
     * OutlookMyBusinessService constructor.
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
    public function getRedirectUrl($profileType = null)
    {
        $provider = $this->createAuthorizationRequest($profileType);
        return $provider->getAuthorizationUrl();
    }

    /**
     * @return \provider
     */
    private function createAuthorizationRequest($profileType = null)
    {
        $provider = new \League\OAuth2\Client\Provider\GenericProvider([
            'clientId'                => $this->clientId,
            'clientSecret'            => $this->clientSecret,
            'redirectUri'             => $this->redirectUri . ( ($profileType) ? ("/".$profileType) : "" ),
            'urlAuthorize'            => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'urlAccessToken'          => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'urlResourceOwnerDetails' => '',
            'scopes'                  => 'offline_access openid profile user.read mail.send Calendars.Read, Calendars.ReadWrite'
        ]);
        return $provider;
    }

    /**
     * @param String $authorizationCode
     * @param String $profileType
     * @return bool|string
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function exchangeAuthorizationCode(String $authorizationCode, String $profileType)
    {
        $provider = $this->createAuthorizationRequest($profileType);
        
        $accessToken = $provider->getAccessToken('authorization_code', [
            'code'     => $authorizationCode
        ]);

        $values = $accessToken->getValues();

        $token = [
            'access_token' => $accessToken->getToken(),
            'token_type' => $values['token_type'],
            'expires_in' => $values['ext_expires_in'],
            'created' => ($accessToken->getExpires() - $values['ext_expires_in']),
            'refresh_token' => $accessToken->getRefreshToken()
        ];

        return $token;
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

        $calendar->setCalendarType("outlook");
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
            $this->deleteToken($calendarExists, $profileType);
        }

        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }

    /**
     * @param Calendar $calendar
     * @param String $profileType
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function deleteToken(Calendar $calendar, String $profileType)
    {
        // Ensure token is valid with Microsoft
        $validToken = $this->getToken($calendar, $profileType);
        $this->em->remove($calendar);
        $this->em->flush();
    }

    /**
     * @param Calendar $calendar
     * @param String $profileType
     * @return bool|string
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    private function getToken(Calendar $calendar, String $profileType)
    {
        if ($this->isTokenExpired($calendar)) {
            $token = $this->refreshToken($calendar, $profileType);
            return $token ? $token->getAccessToken() : false;
        }

        return $calendar->getAccessToken();
    }

    /**
     * @param Calendar $calendar
     * @param String $profileType
     * @return Calendar|bool
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    private function refreshToken(Calendar $calendar, String $profileType)
    {
        $oauthClient = $this->createAuthorizationRequest($profileType);
        try {
            $newToken = $oauthClient->getAccessToken('refresh_token', [
              'refresh_token' => $calendar->getRefreshToken()]);

            if (null == $newToken) {
                return false;
            }

            $values = $newToken->getValues();

            $calendar->setAccessToken($newToken->getToken());
            $calendar->setTokenType($values['token_type']);
            $calendar->setExpiresIn($values['ext_expires_in']);
            $calendar->setCreated($newToken->getExpires() - $values['ext_expires_in']);
            $calendar->setRefreshToken($newToken->getRefreshToken());
            $this->em->persist($calendar);
            $this->em->flush();

            return $calendar;
        }
        catch (League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
            return '';
        }
    }

    /**
     * @param Calendar|null $calendar
     * @param $request
     * @return array
    */
    public function addEvent($calendar, $event, $appointment, $calendarId, $user, $branch, $company, $profileType, $invoiceId = null, $currentTime = null, $online = false)
    {
        \AppBundle\Utilities\HelperUtilities::Log('In Outlook addEvent', [
            'calendar' => $calendar,
            'event' => $event,
            'appointment' => $appointment,
            'calendarId' => $calendarId,
            'profileType' => $profileType,
            'invoiceId' => $invoiceId,
            'currentTime' => $currentTime,
            'online' => $online,
            'userbranchcompany' => [
                'user' => ($user) ? $user->getId() : 'NA',
                'branch' => ($branch) ? $branch->getId() : 'NA',
                'company' => ($company) ? $company->getId() : 'NA'
            ],
        ]);
        $processStatus = false;
        $return = [];

        if ($appointment['cost'] == 0 && $appointment['cost'] <= 0) {
            \AppBundle\Utilities\HelperUtilities::Log('In Outlook zero cost', []);
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
            $appointmentData = $this->container->get('google_calendar_service')
            ->manageAppointmentProcess($appointment, $user, $branch, $company, null, $return['contact'], null);
            $return = [ 'appointmentData' => $appointmentData ];

            $online = false;
            $processStatus = true;
        } 

        if ($online) {

            \AppBundle\Utilities\HelperUtilities::Log('In Outlook online with paid', []);
            try {
                $return = $invoiceDetails = $this->container->get('google_calendar_service')
                        ->createContactInvoiceAndTransaction($appointment, $user, $branch, $company, $profileType, $invoiceId, $currentTime);

                if (!isset($invoiceDetails['contact'])) {
                    return $invoiceDetails;
                }
                \AppBundle\Utilities\HelperUtilities::Log('In Outlook return', ['retunr' => $return]);

                if ((!empty($invoiceDetails['transaction']) && $invoiceDetails['transaction']->getStatus() == 'approved' ) && !empty($invoiceDetails['transaction']->getTransactionId())) {
                    $processStatus = true;
                } else { // For now Jupiter payment are not supported that why its true in else
                    $processStatus = true;
                }

                if ((!array_key_exists('token', $appointment) || $appointment['token'] == NULL) && (!empty($invoiceDetails['invoice']['id']) && $invoiceDetails['invoice']['total_invoice_amount'] > 0)) {
                    /* Manage Appointment Process */
                    $appointmentData = $this->container->get('google_calendar_service')
                    ->manageAppointmentProcess($appointment, $user, $branch, $company, $invoiceDetails['invoice'], $invoiceDetails['contact'], null);
                    \AppBundle\Utilities\HelperUtilities::Log('In Outlook appointmentData', ['appointmentData' => $appointmentData]);
                    $return['appointmentData'] = $appointmentData;
                }

            } catch (\Exception $e) {
                return $e->getMessage();
            }
        }

        if ($processStatus) {
            \AppBundle\Utilities\HelperUtilities::Log('In Outlook processStatus', [
                'event' => $event,
                'calendarId' => $calendarId,
                'calendar' => $calendar,
                'profileType' => $profileType
            ]);
            $eventId = "";
            if ($event != NULL && $calendarId != NULL && $calendar != NULL) {
                $graph = new Graph();
                $graph->setAccessToken($this->getToken($calendar, $profileType));
    
                $data = [
                    'Subject' => $event['summary'],
                    'Body' => [
                        'ContentType' => 'HTML',
                        'Content' => $event['description'],
                    ],
                    'Start' => [
                        'DateTime' => $event['start']['dateTime'],
                        'TimeZone' => 'Pacific Standard Time',
                    ],
                    'End' => [
                        'DateTime' => $event['end']['dateTime'],
                        'TimeZone' => 'Pacific Standard Time',
                    ],
                    'Location' => [
                        'DisplayName' => $event['location']
                    ],
                    "Attendees" => [
                        (object)[
                            "EmailAddress" => (object)[
                               "Address" => $event['attendees'][0]['email'],
                               "Name" => $event['attendees'][0]['name']
                            ],
                            "Type" => "required"
                        ]
                    ]
                ];
    
                $url = "/me/events";
                $event = $graph->createRequest("POST", $url)
                    ->attachBody($data)
                    ->setReturnType(Model\Event::class)
                    ->execute();
                $eventId = $event->getId();
                \AppBundle\Utilities\HelperUtilities::Log('Out Outlook processStatus', ['eventId' => $eventId]);
            }

            if (empty($return['appointmentData'])) {
                \AppBundle\Utilities\HelperUtilities::Log('In if Outlook appointmentData', []);
                /* Manage Appointment Process */
                $appointmentData = $this->container->get('google_calendar_service')
                ->manageAppointmentProcess($appointment, $user, $branch, $company, $invoiceDetails['invoice'], $invoiceDetails['contact'], $eventId);

                $return['appointmentData'] = $appointmentData;
            } else {
                \AppBundle\Utilities\HelperUtilities::Log('In else Outlook appointmentData', []);
                $return['appointmentData']->setEventId($eventId);
                $return['appointmentData']->setStatus("open");
        
                $this->em->persist($return['appointmentData']);
                $this->em->flush();
            }

            return $return;
        }

        return $return;
    }

    /**
     * @param Calendar|null $calendar
     * @param string $profileType
     * @param string $eventId
     * @param bool|null $online
     * @return array
     */
    public function cancelEvent($calendar, $profileType, $eventId) 
    {
        if ($calendar != NULL && $eventId != NULL) {
            $graph = new Graph();
            $graph->setAccessToken($this->getToken($calendar, $profileType));

            $data = [];
            $url = "/me/events/" . $eventId;
            $graph->createRequest('Delete', $url)
                ->attachBody($data)
                ->setReturnType(Model\Event::class)
                ->execute();
        }
    }

    /**
    * @param Calendar $calendar
    * @param $timeMin , $timeMax, $calendarId
    * @return array
    */
    public function checkCalendarFreeBusy(Calendar $calendar, $timeMin, $timeMax, $calendarId){ 

        if($calendar->getUser()) {
            $profileType = "USER";
        } else if($calendar->getBranch()) {
            $profileType = "BRANCH";
        } else if($calendar->getCompany()) {
            $profileType = "COMPANY";
        }

        $graph = new Graph();
        $graph->setAccessToken($this->getToken($calendar, $profileType));

        //Get User Details
        $user = $graph->createRequest('GET', '/me')
                ->setReturnType(Model\User::class)
                ->execute();
        $userPrincipalName = $user->getUserPrincipalName();

        $data = [
            'Schedules' => [$userPrincipalName],
            "StartTime" => (object)[
                "dateTime" => $timeMin,
                "timeZone" => "GMT",
            ],
            "EndTime" => (object)[
                "dateTime" => $timeMax,
                "timeZone" => "GMT",
            ]
        ];

        $url = "/me/calendar/getschedule";
        $response = $graph->createRequest("POST", $url)
            ->attachBody($data)
            ->execute();

        $query = json_encode($response->getBody()['value']);
        return $query;
    }

    /**
     * @param Calendar $calendar
     * @return bool
     */
    private function isTokenExpired(Calendar $calendar)
    {
        return $calendar->getCreated() + $calendar->getExpiresIn() < time();
    }
}
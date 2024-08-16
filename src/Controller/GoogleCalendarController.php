<?php

namespace AppBundle\Controller\Calendar;

use AppBundle\Entity\Calendar;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\Controller\FOSRestController;
use Nelmio\ApiDocBundle\Annotation as Doc;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\HttpFoundation\Request;

/** @Route("/google-calendar") */
class GoogleCalendarController extends FOSRestController
{
    /**
     * @Rest\Get(path="/redirect-url/{profileType}", name="google_calendar_redirect_url_get")
     *
     * @Doc\ApiDoc(
     *      section="Google Calendar",
     *      description="Get redirect URL",
     *      https="true",
     *      statusCodes={
     *         200="Success",
     *         401="Unauthorized",
     *         404="Resource not found"
     *     }
     * )
     */
    public function getRedirectUrlAction($profileType)
    {
        $stateParams = array("profileType" => $profileType);
        $stateParams = json_encode($stateParams);
        return $this->view([$this->get('google_calendar_service')->getRedirectUrl($stateParams)], Response::HTTP_OK);
    }

    /**
     * @Rest\Post(path="/exchange-auth-code/{profileType}", name="google_calendar_exchange_auth_code_post")
     *
     * @ParamConverter(
     *      "calendar",
     *      converter="fos_rest.request_body",
     *      options={
     *          "validator"={
     *              "groups"="auth_exchange"
     *          }
     *      }
     * )
     *
     * @Doc\ApiDoc(
     *      section="Google Calendar",
     *      description="Exchange an auth code for a calendar",
     *      https="true",
     *      statusCodes={
     *         201="Success",
     *         401="Unauthorized",
     *         404="Resource not found"
     *     }
     * )
     */

    public function postExchangeAuthCodeAction(Calendar $calendar, String $profileType, ConstraintViolationListInterface $violations)
    {
        $gcService = $this->get('google_calendar_service');
        $exchange = $gcService->exchangeAuthorizationCode($calendar->getAuthorizationCode());
        $user = $this->getUser();
        $calendarToken = $gcService->persistToken(
            $exchange,
            $calendar,
            $profileType,
            $user
        );
        return $this->view(["id" => $calendarToken->getId()], Response::HTTP_CREATED);
    }

    /**
     * @Rest\Post(path="/calendar-free-busy", name="google_calendar_free_busy_post")
     * @Rest\View(serializerGroups={"default", "public", "profile"})
     * @Doc\ApiDoc(
     *      section="Google Calendar",
     *      description="POST Calendar FreeBusy URL",
     *      https="true",
     *      statusCodes={
     *         200="Success",
     *         401="Unauthorized",
     *         404="Resource not found"
     *     }
     * )
     */
    public function postCalendarFreeBusy(Request $request){

        $postRequest    = $request->request->all();

        $slug           = $postRequest['slug'];
        $timeMin        = $postRequest['timeMin'];
        $timeMax        = $postRequest['timeMax'];
        $calendarId     = $postRequest['id'];
        $profileType    = $postRequest['profileType'];

        if($profileType == "USER") {
            $userService = $this->get('user_service');
            $user = $userService->getUserBySlug($slug);
            $calendars =  $user->getCalendars();
        } else if($profileType == "BRANCH") {
            $branchService = $this->get('branch_service');
            $branch = $branchService->getBranchBySlug($slug);
            $calendars =  $branch->getCalendars();
        } else if($profileType == "COMPANY") {
            $companyService = $this->get('company_service');
            $company = $companyService->getCompanyBySlug($slug);
            $calendars =  $company->getCalendars();
        } 
        $googleCalendar = $this->getGoogleCalendar($calendars);

        $gcService = $this->get('google_calendar_service');
        return $this->view([$gcService->checkCalendarFreeBusy($googleCalendar, $timeMin, $timeMax, $calendarId)], Response::HTTP_OK);
    }

    /**
     * @Rest\Post(path="/calendar-add-event", name="google_calendar_add_event_post")
     *
     * @Doc\ApiDoc(
     *      section="Google Calendar",
     *      description="POST Calendar AddEvent URL",
     *      https="true",
     *      statusCodes={
     *         200="Success",
     *         401="Unauthorized",
     *         404="Resource not found"
     *     }
     * )
     */
    public function postCalendarAddEvent(Request $request){

        $postRequest    = $request->request->all();

        $event          = $postRequest['event'];
        $appointment    = $postRequest['appointment'];
        $calendarId     = $postRequest['id'];
        $slug           = $postRequest['slug'];
        $profileType    = $postRequest['profileType'];
        $invoiceId      = ($postRequest['invoice_id']) ? $postRequest['invoice_id'] : NULL;
        $currentTime    = ($postRequest['current_time']) ? $postRequest['current_time'] : NULL;
        $user = $branch = $company = NULL;
        $appointment['account'] = "google";

        if($profileType == "USER") {
            $userService = $this->get('user_service');
            $user = $userService->getUserBySlug($slug);
            $calendars =  $user->getCalendars();
        } else if($profileType == "BRANCH") {
            $branchService = $this->get('branch_service');
            $branch = $branchService->getBranchBySlug($slug);
            $calendars =  $branch->getCalendars();
        } else if($profileType == "COMPANY") {
            $companyService = $this->get('company_service');
            $company = $companyService->getCompanyBySlug($slug);
            $calendars =  $company->getCalendars();
        } 
        $googleCalendar = $this->getGoogleCalendar($calendars);

        $gcService = $this->get('google_calendar_service');
        return $this->view([$gcService->addEvent($googleCalendar, $event, $appointment, $calendarId, $user, $branch, $company, $profileType, $invoiceId, $currentTime)], Response::HTTP_OK);
    }

    public function getGoogleCalendar($calendars) {
        foreach($calendars as $calendar){
            $calendarType = $calendar->getCalendarType();
            if($calendarType === "google"){
               return $calendar;
            }
        }
    }

    /**
     * @Rest\Post(path="/calendar-add-event-google", name="google_calendar_add_online_event_post")
     *
     * @Doc\ApiDoc(
     *      section="Google Calendar",
     *      description="POST Calendar AddEvent URL",
     *      https="true",
     *      statusCodes={
     *         200="Success",
     *         401="Unauthorized",
     *         404="Resource not found"
     *     }
     * )
     */
    public function postCalendarAddEventGoogle(Request $request) {

        $postRequest    = $request->request->all();

        $event          = (array_key_exists('event', $postRequest)) ? $postRequest['event'] : NULL;
        $appointment    = $postRequest['appointment'];
        $calendarId     = (array_key_exists('id', $postRequest)) ? $postRequest['id'] : NULL;
        $slug           = $postRequest['slug'];
        $profileType    = $postRequest['profileType'];
        $user = $branch = $company = NULL;
        $appointment['account'] = "google";
        $userSlug       = isset($postRequest['user_slug']) ? $postRequest['user_slug'] : NULL;
        $invoiceId      = isset($postRequest['invoice_id']) ? $postRequest['invoice_id'] : NULL;
        $currentTime    = isset($postRequest['current_time']) ? $postRequest['current_time'] : NULL;

        $userService = $this->get('user_service');
        
        if($profileType == "USER") {
            $user = $userService->getUserBySlug($slug);
            $calendars =  $user->getCalendars();
        } else if($profileType == "BRANCH") {
            $branchService = $this->get('branch_service');
            $branch = $branchService->getBranchBySlug($slug);
            $calendars =  $branch->getCalendars();
        } else if($profileType == "COMPANY") {
            $companyService = $this->get('company_service');
            $company = $companyService->getCompanyBySlug($slug);
            $calendars =  $company->getCalendars();
        } 
        $googleCalendar = $this->getGoogleCalendar($calendars);

        if ($userSlug !== NULL && $profileType != "USER") {
            $user = $userService->getUserBySlug($userSlug);
        }

        $gcService = $this->get('google_calendar_service');
        return $gcService->addEvent($googleCalendar, $event, $appointment, $calendarId, $user, $branch, $company, $profileType, $invoiceId, $currentTime, true);
    }
}
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

/** @Route("/outlook-calendar") */
class OutlookCalendarController extends FOSRestController
{
    /**
     * @Rest\Get(path="/redirect-url/{profileType}", name="outlook_calendar_redirect_url_get")
     *
     * @Doc\ApiDoc(
     *      section="Calendar",
     *      description="Get redirect URL",
     *      https="true",
     *      statusCodes={
     *         200="Success",
     *         401="Unauthorized",
     *         404="Resource not found"
     *     }
     * )
     */
    public function getRedirectUrlAction(String $profileType)
    { 
        return $this->view([$this->get('outlook_calendar_service')->getRedirectUrl($profileType)], Response::HTTP_OK);
    }

    /**
     * @Rest\Post(path="/exchange-auth-code/{profileType}", name="outlook_calendar_exchange_auth_code_post")
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
     *      section="Outlook Calendar",
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
        $ocService = $this->get('outlook_calendar_service');

        $exchange = $ocService->exchangeAuthorizationCode($calendar->getAuthorizationCode(), $profileType);

        $user = $this->getUser();

        $outlookToken = $ocService->persistToken(
            $exchange,
            $calendar,
            $profileType,
            $this->getUser()
        );

        return $this->view(["id" => $outlookToken->getId()], Response::HTTP_CREATED);
    }

     /**
     * @Rest\Post(path="/calendar-free-busy", name="outlook_calendar_free_busy_post")
     * @Rest\View(serializerGroups={"default", "public", "profile"})
     * @Doc\ApiDoc(
     *      section="Outlook Calendar",
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

        $postRequest = $request->request->all();

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
        $outlookCalendar = $this->getOutlookCalendar($calendars);

        $ocService = $this->get('outlook_calendar_service');
        return $this->view([$ocService->checkCalendarFreeBusy($outlookCalendar, $timeMin, $timeMax, $calendarId)], Response::HTTP_OK);
    }

    /**
     * @Rest\Post(path="/calendar-add-event", name="outlook_calendar_add_event_post")
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
        $appointment['account'] = "outlook";

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
        $outlookCalendar = $this->getOutlookCalendar($calendars);

        $ocService = $this->get('outlook_calendar_service');
        return $this->view([$ocService->addEvent($outlookCalendar, $event, $appointment, $calendarId, $user, $branch, $company, $profileType, $invoiceId, $currentTime)], Response::HTTP_OK);
    }

    public function getOutlookCalendar($calendars) {
        foreach($calendars as $calendar){
            $calendarType = $calendar->getCalendarType();
            if($calendarType === "outlook"){ 
               return $calendar;
            }
        }
    }

    /**
     * @Rest\Post(path="/calendar-add-event-outlook", name="outlook_calendar_add_event_post_outlook")
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
    public function postCalendarAddEventOutlook(Request $request) {

        $postRequest    = $request->request->all();

        $event          = (array_key_exists('event', $postRequest)) ? $postRequest['event'] : NULL;
        $appointment    = $postRequest['appointment'];
        $calendarId     = (array_key_exists('id', $postRequest)) ? $postRequest['id'] : NULL;
        $slug           = $postRequest['slug'];
        $profileType    = $postRequest['profileType'];
        $user = $branch = $company = NULL;
        $appointment['account'] = "outlook";
        $userSlug       = isset($postRequest['user_slug']) ? $postRequest['user_slug'] : NULL;
        $invoiceId      = isset($postRequest['invoice_id']) ? $postRequest['invoice_id'] : NULL;
        $currentTime    = isset($postRequest['current_time']) ? $postRequest['current_time'] : NULL;
        $userService = $this->get('user_service');

        if($profileType == "USER") {
            $user = $userService->getUserBySlug($slug);
            $calendars =  $user->getCalendars();

            \AppBundle\Utilities\HelperUtilities::Log('In User', [$user]);
        } else if($profileType == "BRANCH") {
            $branchService = $this->get('branch_service');
            $branch = $branchService->getBranchBySlug($slug);
            $calendars =  $branch->getCalendars();
            \AppBundle\Utilities\HelperUtilities::Log('In Branch', [$branch]);
        } else if($profileType == "COMPANY") {
            $companyService = $this->get('company_service');
            $company = $companyService->getCompanyBySlug($slug);
            $calendars =  $company->getCalendars();
            \AppBundle\Utilities\HelperUtilities::Log('In Company', [$company]);
        }
        $outlookCalendar = $this->getOutlookCalendar($calendars);

        if ($userSlug !== NULL && $profileType != "USER") {
            $user = $userService->getUserBySlug($userSlug);
        }

        $ocService = $this->get('outlook_calendar_service');
        return $ocService->addEvent($outlookCalendar, $event, $appointment, $calendarId, $user, $branch, $company, $profileType, $invoiceId, $currentTime, true);
    }
}
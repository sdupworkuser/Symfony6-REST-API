<?php

namespace AppBundle\Controller\Consumer;

use AppBundle\Entity\User;
use AppBundle\Exception\ApiProblemException;
use AppBundle\Model\ApiProblem;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\Controller\FOSRestController;
use JMS\Serializer\SerializationContext;
use Nelmio\ApiDocBundle\Annotation as Doc;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** @Route("/consumer") */
class ConsumerController extends FOSRestController
{
    /**
     * @Rest\Post(path="/signup", name="consumer_post")
     *
     * @Rest\View(serializerGroups={"private"})
     *
     * @ParamConverter(
     *      "user",
     *      converter="fos_rest.request_body",
     *      options={
     *          "validator"={
     *              "groups"="consumer_post"
     *          }
     *      }
     * )
     *
     * @Doc\ApiDoc(
     *      section="Consumer",
     *      description="Create a consumer",
     *      https="true",
     *      statusCodes={
     *         201="Created",
     *         400="Invalid data"
     *     }
     * )
     *
     * @param User                             $user
     * @param ConstraintViolationListInterface $violations
     * @throws ApiProblemException
     * @return \FOS\RestBundle\View\View
     */
    public function postAction(User $user, ConstraintViolationListInterface $violations)
    {
        if (count($violations)) {
            $apiProblem = new ApiProblem(Response::HTTP_BAD_REQUEST, $violations, ApiProblem::TYPE_VALIDATION_ERROR);
            throw new ApiProblemException($apiProblem);
        }

        $consumerService = $this->get('consumer_service');
        $u = $consumerService->createConsumer($user);

        $consumerProfileService = $this->get('consumer_profile_service');
        $consumerProfileService->createConsumerProfile($u, $user);

        return $this->view($u, Response::HTTP_CREATED);
    }

    /**
     * @Rest\Post(path="/{id}/role", name="consumer_role_set")
     *
     * @Doc\ApiDoc(
     *      section="Consumer",
     *      description="Add consumer role",
     *      https="true",
     *      statusCodes={
     *         200="Success",
     *         400="Invalid data",
     *         404="User not found"
     *     }
     * )
     *
     * @param User                             $id
     * @return \FOS\RestBundle\View\View
     */
    public function postConsumerRoleAction(
        User $id
    ) {
        $this->denyAccessUnlessGranted('edit', $id);

        $c = $this->get('consumer_service')->addConsumerRole($id);

        /** Manually handle serialization when groups are used (JMS Bug?) */
        $serializer = $this->get('serializer');
        $c = $serializer->serialize($c, 'json', SerializationContext::create()->setGroups(['private']));
        $view = $this->view(json_decode($c, true), Response::HTTP_OK);

        return $this->handleView($view);
    }

    /**
     * @Rest\Post(path="/verify/otp", name="consumer_verify")
     *
     * @Doc\ApiDoc(
     *      section="Consumer",
     *      description="Verify consumer one time passcode",
     *      https="true",
     *      statusCodes={
     *         200="Success",
     *         400="Invalid data",
     *         404="User not found"
     *     }
     * )
     *
     * @param Request  $equest
     * @return \FOS\RestBundle\View\View
     */
    public function postVerifyEmailOtpAction(Request $request) 
    {
        if($request->get('otp') === null || $request->get('email') === null ) {
           throw new AccessDeniedHttpException("OTP and Email is required to access this resource");
        }

        $result = $this->get('consumer_service')->verifyEmailOtp($request->get('otp'), $request->get('email'));

        return $this->view($result, Response::HTTP_OK);
    }

    /**
     * @Rest\Post(path="/resend/otp", name="consumer_resend_otp")
     *
     * @Doc\ApiDoc(
     *      section="Consumer",
     *      description="Resend consumer one time passcode",
     *      https="true",
     *      statusCodes={
     *         200="Success",
     *         400="Invalid data",
     *         404="User not found"
     *     }
     * )
     * @param Request $request
     * @return \FOS\RestBundle\View\View
     */
    public function postResendEmailOtpAction(Request $request) 
    {
        if($request->get('email') === null ) {
            throw new AccessDeniedHttpException("Email is required to access this resource");
        }

        $result = $this->get('consumer_service')->resendEmailOtp($request->get('email'));

        return $this->view($result, Response::HTTP_OK);
    }
}
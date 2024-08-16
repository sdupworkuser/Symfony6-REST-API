<?php

namespace AppBundle\Controller\Consumer;

use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\Controller\FOSRestController;
use Nelmio\ApiDocBundle\Annotation as Doc;
use AppBundle\Entity\ConsumerProfile;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;

/** @Route("/consumer_profile") */
class ConsumerProfileController extends FOSRestController
{
    /**
     * @Rest\Put(path="", name="consumer_profile_put")
     * 
     * @Rest\View(serializerGroups={"private"})
     *
     * @Rest\QueryParam(
     *      name="city",
     *      nullable=true,
     *      description="city"
     * )
     * 
     * @Rest\QueryParam(
     *      name="state",
     *      nullable=true,
     *      description="state"
     * )
     * 
     * @Rest\QueryParam(
     *      name="profile_picture",
     *      nullable=true,
     *      description="base 64 encode image"
     * )
     *
     * @Doc\ApiDoc(
     *      section="consumer",
     *      description="Create consumer profile",
     *      https="true",
     *      statusCodes={
     *         200="Success",
     *         401="Unauthorized"
     *     }
     * )
     */
    public function putAction(Request $request)
    {
        if(!$this->getUser()->getConsumerProfile()) {
            throw new AccessDeniedHttpException("You are not permitted to access this resource. You don't have consumer profile.");
        }

        $this->denyAccessUnlessGranted('edit', $this->getUser()->getConsumerProfile());

        $consumerProfileService = $this->get('consumer_profile_service');
        return $consumerProfileService->postConsumerProfile($this->getUser(), $request, Response::HTTP_CREATED);
    }

    /**
     * @Rest\get("/get_business_token", name="get_business_token")
     * 
     * @Rest\View(serializerGroups={"private"})
     * 
     * @Doc\ApiDoc(
     *      section="consumer",
     *      description="get business token",
     *      https="true",
     *      statusCodes={
     *         200="Success",
     *         401="Unauthorized"
     *     }
     * )
     */
    public function getBusinessToken()
    {
        if(!$this->getUser()->getConsumerProfile()) {
            throw new AccessDeniedHttpException("You are not permitted to access this resource. You don't have consumer profile.");
        }

        $this->denyAccessUnlessGranted('view', $this->getUser()->getConsumerProfile());

        return $this->view($this->get('consumer_profile_service')->getBusinessToken($this->getUser()), Response::HTTP_OK);
    }

    /**
     * @Rest\Post("/consumer_auth_token", name="consumer_auth_token")
     * 
     * @Rest\View(serializerGroups={"private"})
     *
     * @Rest\QueryParam(
     *      name="token",
     *      nullable=true,
     *      description="token"
     * )
     * 
     * @Doc\ApiDoc(
     *      section="consumer",
     *      description="auth business token",
     *      https="true",
     *      statusCodes={
     *         200="Success",
     *         401="Unauthorized"
     *     }
     * )
     */
    public function authBusinessToken(Request $request)
    {
        $result = $this->get('consumer_profile_service')->validateToken($request);

        if(!$result['status']){
            return $this->view($result, Response::HTTP_OK);
        }

        $data = $this->autoLoginUser($request);
        return $this->view($data, Response::HTTP_OK);
    }

    public function autoLoginUser(Request $request) 
    {
        $data = array();

        $consumerProfile = $this->get('consumer_profile_service')->businessToken($request->get('token'));

        $this->get('user_service')->addBusinessRoles($consumerProfile->getUser());

        $userNamePasswordToken = new UsernamePasswordToken($consumerProfile->getUser(), null, 'main', $consumerProfile->getUser()->getRoles());
        $this->get('security.token_storage')->setToken($userNamePasswordToken);

        // Fire the login event manually
        $event = new InteractiveLoginEvent($request, $userNamePasswordToken);
        $this->get("event_dispatcher")->dispatch("security.interactive_login", $event);

        $user = $this->get('security.token_storage')->getToken()->getUser();
        $jwtManager = $this->get('lexik_jwt_authentication.jwt_manager');
        $jwtToken = $jwtManager->create($user);

        $data['status'] = true;
        $data['user']  = $this->getUser();
        $data['token'] = 'Bearer '.$jwtToken;

        return $data;
    }

    /**
     * @Rest\Delete("/profile_picture/{id}", name="delete_consumer_profile_picture")
     * 
     * @Rest\View(serializerGroups={"private"})
     * 
     * @Doc\ApiDoc(
     *      section="consumer",
     *      description="Delete a Consumer Profile Picture",
     *      https="true",
     *      statusCodes={
     *         200="Profile Picture deleted",
     *         404="Not found"
     *     }
     * )
     */
    public function deleteProfilePicture(ConsumerProfile $consumerProfile)
    {
        if(!$this->getUser()->getConsumerProfile()) {
            throw new AccessDeniedHttpException("You are not permitted to access this resource. You don't have consumer profile.");
        }

        $this->denyAccessUnlessGranted('edit', $consumerProfile);

        /** @var ConsumerProfile $service */
        $data = $this->get('consumer_profile_service')->deleteConsumerProfilePicture($consumerProfile);

        return $this->view($data, Response::HTTP_OK);
    }
}

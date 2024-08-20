<?php

namespace AppBundle\Controller\Authorizenet;

use AppBundle\Validator\Payment\CreditCardTransactionValidator;
use AppBundle\Entity\Authorizenet\AuthorizenetCustomerProfile;
use AppBundle\Entity\Contact;
use AppBundle\Entity\User;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\Controller\FOSRestController;
use Nelmio\ApiDocBundle\Annotation as Doc;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use AppBundle\Model\ApiProblem;
use AppBundle\Exception\ApiProblemException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/** @Route("/authorizenet") */
class AuthorizenetController extends FOSRestController
{
    /**
     * @Rest\Post("/get-access-token", name="authorizenet_get_access_token")
     *
     * @Doc\ApiDoc(
     *      section="Authorizenet",
     *      description="Authorizenet Get Token Access",
     *      https="true",
     *      statusCodes={
     *         200 = "Returned when successful",
     *         404 = "Returned when error occurs"
     *     }
     * )
     */
    public function getAccessToken(Request $request)
    {
        $code = $request->get('authorization_code', null);
        if(empty($code)) {
            throw new BadRequestHttpException("Code is required to access this resource");
        }

        $user = $this->getUser();
        list($status, $response) = $this->get('authorizenet_service')->generateAccessToken($code, $user);

        return $this->view($response, $status ? Response::HTTP_CREATED : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @Rest\Get("/get-auth-access", name="authorizenet_get_auth_access")
     * @Rest\View(serializerGroups={"private"})
     *
     * @Doc\ApiDoc(
     *      section="Authorizenet",
     *      description="Authorizenet get Merchant Auth Access",
     *      https="true",
     *      statusCodes={
     *         200 = "Returned when successful",
     *         404 = "Returned when error occurs"
     *     }
     * )
     */
    public function getAuthAccess(Request $request)
    {
        $user = $this->getUser();
        $response = $this->get('authorizenet_service')->getAuthAccess($user);
        return $this->view($response, Response::HTTP_OK);
    }

    /**
     * @Rest\Get("/check-auth-access", name="authorizenet_get_auth_access")
     * @Rest\View(serializerGroups={"public"})
     *
     * @Doc\ApiDoc(
     *      section="Authorizenet",
     *      description="Authorizenet get Merchant Auth Access",
     *      https="true",
     *      statusCodes={
     *         200 = "Returned when successful",
     *         404 = "Returned when error occurs"
     *     }
     * )
     */
    public function getPublicAuthAccess(Request $request)
    {
        $params = $request->query->all();
        $response = $this->get('authorizenet_service')->getAuthAccess($params);
        return $this->view($response ? $response : [], Response::HTTP_OK);
    }

    /**
     * @Rest\Post("/store-auth-access", name="authorizenet_store_auth_access")
     *
     * @Doc\ApiDoc(
     *      section="Authorizenet",
     *      description="Authorizenet Store Merchant Auth Access",
     *      https="true",
     *      statusCodes={
     *         200 = "Returned when successful",
     *         404 = "Returned when error occurs"
     *     }
     * )
     */
    public function setAuthAccess(Request $request)
    {

        /* Only Super Admin will be able to add Auth Access for the provided User*/
        if (!in_array("ROLE_ADMIN", $this->getUser()->getRoles()))
        {
            throw new BadRequestHttpException('You are not permitted to access this resource');
        }
    
        $apiLoginId = $request->get('auth_login_id', null);
        $apiTransactionkey = $request->get('auth_transaction_key', null);
        $userId = $request->get('user_id', null);
        if(empty($apiLoginId)) {
            throw new BadRequestHttpException("Login ID is required to perform this method");
        }
        if(empty($apiTransactionkey)) {
            throw new BadRequestHttpException("Transaction Key is required to perform this method");
        }
        if(empty($userId)) {
            throw new BadRequestHttpException("User Id is required to perform this method");
        }

        $user = $this->getDoctrine()->getRepository('AppBundle:User')->find($userId);

        if($user->getId()){
            $data = json_decode($request->getContent(), true);
            $response = $this->get('authorizenet_service')->storeAuthAccess($data, $user);

            return $this->view($response ? ['code' => Response::HTTP_CREATED, 'message' => 'Merchant auth details successfully store.'] : ['code' => Response::HTTP_UNPROCESSABLE_ENTITY, 'message' => 'Error.']);
        } else {
            throw new BadRequestHttpException("User not found.");
        }
    }

    /**
     * @Rest\Post("/charge-credit-card", name="authorizenet_charge_credit_card")
     * @ParamConverter(
     *      "creditCardTransaction",
     *      converter="fos_rest.request_body",
     *      options={
     *          "validator"={
     *              "groups"="transaction_post"
     *          }
     *      }
     * )
     * 
     * @Doc\ApiDoc(
     *      section="Authorizenet",
     *      description="Authorizenet Charge Credit Card",
     *      https="true",
     *      statusCodes={
     *         200 = "Returned when successful",
     *         404 = "Returned when error occurs"
     *     }
     * )
     */
    public function chargeCreditCard(CreditCardTransactionValidator $creditCardTransaction, ConstraintViolationListInterface $violations)
    {
        if (count($violations)) {
            throw new ApiProblemException(
                new ApiProblem(Response::HTTP_BAD_REQUEST, $violations, ApiProblem::TYPE_VALIDATION_ERROR)
            );
        }

        $response = $this->get('authorizenet_service')->creditCardCharge($creditCardTransaction);

        return $this->view($response, Response::HTTP_CREATED);
    }

    /**
     * @Rest\Post("/charge-customer-profile", name="authorizenet_charge_customer_profile")
     * 
     * @Doc\ApiDoc(
     *      section="Authorizenet",
     *      description="Authorizenet Charge Customer Profile",
     *      https="true",
     *      statusCodes={
     *         200 = "Returned when successful",
     *         404 = "Returned when error occurs"
     *     }
     * )
     */
    public function chargeCustomerProfile(Request $request)
    {
        $response = $this->get('authorizenet_service')->chargeCustomerProfile(json_decode($request->getContent(), true));

        return $this->view($response, Response::HTTP_CREATED);
    }

    /**
     * @Rest\Post("/create-customer-profile", name="authorizenet_create_customer_profile")
     * @ParamConverter(
     *      "creditCardTransaction",
     *      converter="fos_rest.request_body",
     *      options={
     *          "validator"={
     *              "groups"="transaction_post"
     *          }
     *      }
     * )
     * 
     * @Doc\ApiDoc(
     *      section="Authorizenet",
     *      description="Authorizenet Create Customer Profile",
     *      https="true",
     *      statusCodes={
     *         200 = "Returned when successful",
     *         404 = "Returned when error occurs"
     *     }
     * )
     */
    public function createCustomerProfile(CreditCardTransactionValidator $creditCardTransaction, ConstraintViolationListInterface $violations)
    {
        if (count($violations)) {
            throw new ApiProblemException(
                new ApiProblem(Response::HTTP_BAD_REQUEST, $violations, ApiProblem::TYPE_VALIDATION_ERROR)
            );
        }

        $response = $this->get('authorizenet_service')->createCustomerProfile($creditCardTransaction);

        return $this->view($response, Response::HTTP_CREATED);
    }

    /**
     * @Rest\GET("/customer-profile/{contact}", name="authorizenet_get_customer_profile_access")
    
     * @Doc\ApiDoc(
     *      section="Authorizenet",
     *      description="Authorizenet get Customer Profile Access",
     *      https="true",
     *      statusCodes={
     *         200 = "Returned when successful",
     *         404 = "Returned when error occurs"
     *     }
     * )
     */
    public function getCustomerProfile(Contact $contact)
    {
        $user = $this->getUser();
        if ($contact->getUser()->getId() !== $user->getId() && ($contact->getUser()->getParent() && $contact->getUser()->getParent()->getId() !== $user->getId()))
        {
            throw new AccessDeniedHttpException("You are not permitted to access this resource");
        }

        $authCustomerProfile = $this->getDoctrine()->getRepository(AuthorizenetCustomerProfile::class);
        $response = $authCustomerProfile->findOneBy(['contact' => $contact->getId()]);
        return $response ? $response : [];
    }

    /**
     * @Rest\Post("/apple-pay-transaction", name="authorizenet_apple_pay_transaction")
     * @ParamConverter(
     *      "creditCardTransaction",
     *      converter="fos_rest.request_body",
     *      options={
     *          "validator"={
     *              "groups"="transaction_post"
     *          }
     *      }
     * )
     *
     * @Doc\ApiDoc(
     *      section="Authorizenet",
     *      description="Authorizenet Apple Pay Transaction",
     *      https="true",
     *      statusCodes={
     *         200 = "Returned when successful",
     *         404 = "Returned when error occurs"
     *     }
     * )
     */
    public function applePayTransaction(CreditCardTransactionValidator $creditCardTransaction, ConstraintViolationListInterface $violations)
    {
        if (count($violations)) {
            throw new ApiProblemException(
                new ApiProblem(Response::HTTP_BAD_REQUEST, $violations, ApiProblem::TYPE_VALIDATION_ERROR)
            );
        }

        $response = $this->get('authorizenet_service')->createAnApplePayTransaction($creditCardTransaction);

        return $this->view($response, Response::HTTP_CREATED);
    }

    /**
     * @Rest\Post("/google-pay-transaction", name="authorizenet_google_pay_transaction")
     *
     * @Doc\ApiDoc(
     *      section="Authorizenet",
     *      description="Authorizenet Google Pay Transaction",
     *      https="true",
     *      statusCodes={
     *         200 = "Returned when successful",
     *         404 = "Returned when error occurs"
     *     }
     * )
     */
    public function googlePayTransaction(Request $request)
    {
        $response = $this->get('authorizenet_service')
            ->createAnApplePayTransaction(json_decode($request->getContent(), true));

        return $this->view($response, Response::HTTP_CREATED);
    }

    /**
     * @Rest\Post("/refund-transaction", name="authorizenet_refurnd_transaction")
     *
     * @Doc\ApiDoc(
     *      section="Authorizenet",
     *      description="Authorizenet Refund Transaction",
     *      https="true",
     *      statusCodes={
     *         200 = "Returned when successful",
     *         404 = "Returned when error occurs"
     *     }
     * )
     */
    public function refundTransaction(Request $request)
    {
        $response = $this->get('authorizenet_service')
            ->refundTransaction(json_decode($request->getContent(), true));

        return $this->view($response, Response::HTTP_CREATED);
    }

}

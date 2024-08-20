<?php

namespace AppBundle\Controller\Authorizenet;

use AppBundle\Entity\Authorizenet\AuthorizenetAuthAccess;
use AppBundle\Representation\AuthorizenetList;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Request\ParamFetcherInterface;
use JMS\Serializer\SerializationContext;
use Nelmio\ApiDocBundle\Annotation as Doc;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** @Route("/authorizenets") */
class AuthorizenetListController extends FOSRestController
{
    /**
     * @Rest\GET("/get-auth-merchant-list", name="authorizenet_get_auth_merchant_list")
     *
     * @Rest\QueryParam(
     *      name="filter",
     *      nullable=true,
     *      description="Filter"
     * )
     * @Rest\QueryParam(
     *      name="order_by",
     *      description="Order by"
     * )
     * @Rest\QueryParam(
     *      name="order_direction",
     *      default="ASC",
     *      description="Order direction (ascending or descending)"
     * )
     * @Rest\QueryParam(
     *      name="limit",
     *      requirements="\d+",
     *      default="25",
     *      description="Max number of results"
     * )
     * @Rest\QueryParam(
     *      name="page",
     *      requirements="\d+",
     *      default="1",
     *      description="The page"
     * )
     *
     * @Doc\ApiDoc(
     *      section="Authorizenet",
     *      description="Authorizenet get Merchant List",
     *      https="true",
     *      statusCodes={
     *         200 = "Returned when successful",
     *         404 = "Returned when error occurs"
     *     }
     * )
     */
    public function getAction(ParamFetcherInterface $paramFetcher)
    {
        // Filtering, pagination and order controls
        $filter = $paramFetcher->get('filter');
        $orderBy = $paramFetcher->get('order_by');
        $orderDirection = $paramFetcher->get('order_direction');
        if (empty($orderDirection) || strtoupper($orderDirection) != 'DESC') {
            $orderDirection = 'ASC';
        }
        $limit = (empty($paramFetcher->get('limit'))) ? 25 : $paramFetcher->get('limit');
        $page = (empty($paramFetcher->get('page'))) ? 1 : $paramFetcher->get('page');

        // Utils
        $authChecker = $this->get('security.authorization_checker');
        $em = $this->getDoctrine()->getManager();

        // Only Admin can access this API
        if (!$authChecker->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedHttpException("You do not have access to this method.");
        }

        $repo = $this->getDoctrine()->getRepository(AuthorizenetAuthAccess::class);

        $query = $repo->filter(
            $filter,
            $orderBy,
            $orderDirection
        );

        $auths = new AuthorizenetList(
            $query,
            $repo->getCount($filter),
            $limit,
            $page
        );

        $response = [];
        $response['meta'] = $auths->meta;

        $data = [];
        foreach ($auths->data as $k => $item) {
            $context = new SerializationContext();
            $context->setGroups("list_auth");
            $data[] = json_decode($this->get('jms_serializer')->serialize($item, 'json', $context), true);

            $auth = $this->get('authorizenet_service')->getConvertAuthAccess($data[$k], $authChecker);
    
            if (!empty($auth)) {
                $data[$k]['login_id'] = $auth['loginId'];
                $data[$k]['transaction_key'] = $auth['transactionKey'];
                $data[$k]['client_key'] = $auth['clientKey'];
            }
        }

        $response['auth'] = $data;


        return $this->view($response, Response::HTTP_OK);
    }
}

<?php

namespace AppBundle\EventListener;

use AppBundle\Entity\User;
use AppBundle\Entity\Wix\WixUser;
use Doctrine\ORM\EntityManager;
use Symfony\Component\Security\Core\User\UserInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpFoundation\RequestStack;


class JwtCreatedListener
{
    const CONSUMER_LOGIN_TYPE = 'consumer';
    const BUSINESS_LOGIN_TYPE = 'user';

    /** @var EntityManager $em */
    private $em;

    /** @var RequestStack $requestStack */
    private $requestStack;

    public function __construct(EntityManager $em, RequestStack $requestStack)
    {
        $this->em = $em;
        $this->requestStack = $requestStack;
    }

    /**
     * @param JWTCreatedEvent $event
     *
     * @return void
     */
    public function onJwtCreated(JWTCreatedEvent $event)
    {
        $request = $this->requestStack->getCurrentRequest();
        $login_type = ($request->get('_login_type')) ? $request->get('_login_type') : null;

        $user = $event->getUser();
        if (!$user instanceof UserInterface) {
            return;
        }

        if($login_type === JwtCreatedListener::CONSUMER_LOGIN_TYPE && 
            in_array('ROLE_CONSUMER', $user->getRoles()) && 
            $user->getEmailVerifiedAt() === null
        ) {
            throw new AccessDeniedHttpException("Your email address is not verified!");
        }

        $payload = $event->getData();

        if ($user instanceof User) {
            $u = $this->em->getRepository('AppBundle:User')->findOneBy(['username' => $user->getUsername()]);
        }

        if ($user instanceof WixUser) {
            $u = $this->em->getRepository('AppBundle:Wix\WixUser')->findOneBy(['username' => $user->getUsername()]);
        }

        if (isset($u)) {
            $payload['id'] = $u->getId();
            $event->setData($payload);
        }
    }
}

<?php

namespace AppBundle\EventListener;

use AppBundle\Entity\User;
use AppBundle\Enumeration\FeatureAccessLevel;
use AppBundle\Enumeration\PayeeLevel;
use Doctrine\Common\EventSubscriber;
use Doctrine\Common\Persistence\Event\LifecycleEventArgs;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Doctrine\ORM\EntityManager;

class UserSubscriber implements EventSubscriber
{
    /** @var EntityManager */
    private $em;

    /** @var PlanService $planService */
    private $planService;

    /** @var StripeService $stripeService */
    private $stripeService;

    /**
     * UserSubscriber constructor.
     * @param EntityManager $em
     */
    public function __construct(EntityManager $em, ContainerInterface $container)
    {
        $this->em = $em;
        $this->planService = $container->get('plan_service');
        $this->stripeService = $container->get('stripe_service');
    }

    /**
     * @return array
     */
    public function getSubscribedEvents()
    {
        return [
            'postPersist',
            'postUpdate'
        ];
    }

    /**
     * @param LifecycleEventArgs $args
     */
    public function postPersist(LifecycleEventArgs $args)
    {
        /** @var User $user */
        if (($user = $args->getObject()) instanceof User) {
            $this->handlePlan($user);
        }
    }

    /**
     * @param LifecycleEventArgs $args
     */
    public function postUpdate(LifecycleEventArgs $args)
    {
        /** @var User $user */
        if (($user = $args->getObject()) instanceof User) {
            $this->handlePlan($user);

            $changes = $this->em->getUnitOfWork()->getEntityChangeSet($user);
            if (isset($changes['active'])) {
                // OLD value: $changes['active'][0]
                // NEW value: $changes['active'][1]
                $this->handleSubscription($user);
            }
        }
    }

    /**
     * @param User $user
     * @return void
     */
    private function handlePlan(User $user)
    {
        \AppBundle\Utilities\HelperUtilities::Log('Calling the handlePlan method', [
            'user_id' => $user ? $user->getId() : null,
            'plan_id' => $user->getPlan() ? $user->getPlan()->getId() : null
        ]);

        if ($user && $user->getBranch()) {

            $company = $user->getBranch()->getCompany();
            if ($user->getPlan() && ($user->getPlan()->getAcl() == FeatureAccessLevel::ENTERPRISE || ($user->getParent() && $user->getParent()->getPlan()->getAcl() == FeatureAccessLevel::ENTERPRISE))) {
                $company->setPayeeLevel(PayeeLevel::COMPANY);
            } else {
                $company->setPayeeLevel(PayeeLevel::USER); 
            }

            $this->em->persist($company);
            $this->em->flush();
        }
    }

    private function handleSubscription(User $user) 
    {
        switch ($user->getBranch()->getCompany()->getPayeeLevel()) {
            case (PayeeLevel::BRANCH):
                $payee = $this->em->getRepository('AppBundle:User')->getBranchAdministrator($user->getBranch());
                if (null !== $payee) {
                    $this->planService->updateBranchSubscriptionQuantity($user->getBranch(), $payee);
                }
                return;
            case (PayeeLevel::COMPANY):
                $payee = $this->em->getRepository('AppBundle:User')
                    ->getCompanyAdministrator($user->getBranch()->getCompany());

                \AppBundle\Utilities\HelperUtilities::Log('In PayeeLevel condition', [
                    'payee' => $payee ? $payee->getId() : null
                ]);
                if (null !== $payee) {

                    \AppBundle\Utilities\HelperUtilities::Log('In payee not null', []);
                    $this->planService->updateCompanySubscriptionQuantity($user->getBranch()->getCompany(), $payee);
                }
                return;
            case (PayeeLevel::USER):
                $this->stripeService->cancelUserSubscription($user);
                break;
            default:
                return;
        }
    }
}

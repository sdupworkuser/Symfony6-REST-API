<?php

namespace AppBundle\EventListener;

use AppBundle\Document\Answer\StarRatingAnswer;
use AppBundle\Entity\SurveyQuestion;
use AppBundle\Entity\User;
use Doctrine\Common\EventSubscriber;
use Doctrine\Common\Persistence\Event\LifecycleEventArgs;
use Doctrine\ORM\EntityManager;

class StarRatingSubscriber implements EventSubscriber
{
    /**
     * @var EntityManager
     */
    private $em;

    /**
     * StarRatingSubscriber constructor.
     * @param EntityManager $em
     */
    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    /**
     * @return array
     */
    public function getSubscribedEvents()
    {
        return [
            'prePersist',
        ];
    }

    /**
     * @param LifecycleEventArgs $args
     */
    public function prePersist(LifecycleEventArgs $args)
    {
        /** @var StarRatingAnswer $starRatingAnswer */
        if (($starRatingAnswer = $args->getObject()) instanceof StarRatingAnswer &&
            null === $starRatingAnswer->getUserId()
        ) {
            /** @var User $user */
            $user = $this->em->getRepository(SurveyQuestion::class)
                ->find($starRatingAnswer->getQuestionId())
                ->getSurvey()
                ->getUser();

            $starRatingAnswer->setUserId($user->getId());
        }
    }
}

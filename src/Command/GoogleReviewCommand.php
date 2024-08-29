<?php

namespace AppBundle\Command;

use AppBundle\Document\SocialMediaReviews;
use AppBundle\Entity\ReviewAggregationToken\GoogleToken;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GoogleReviewCommand extends ContainerAwareCommand
{

    protected function configure()
    {
        $this
            ->setName('restapi:googleReview')
            ->setDescription('Command script to Store Google Reviews  of all the profiles (user/branch/company) of given user');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        ini_set('max_execution_time', 0);

        $container = $this->getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $dm = $container->get('doctrine_mongodb')->getManager();

        if ($container->getParameter('crons_enabled') == "true") {

            // In Future, if google_token records get increased, we can also increase this limit.
            // If google_token records = 1000, set records per cron to 10 (every 5 mins)
            $googleTokenDetails = $em->getRepository(GoogleToken::class)->findBy(['cronStatus' => 0], [], 5);
 
            if (sizeof($googleTokenDetails) > 0) {
                foreach ($googleTokenDetails as $key => $googleToken) {
                    try {
                        $googleReviewsQueryBuilder = $dm->createQueryBuilder(SocialMediaReviews::class);
                        $lastReviewedAt = false;
                        if ($googleToken->getUser()){
                            $id = $googleToken->getUser()->getId();
                            $type = 'user';
                            $googleReviewsQueryBuilder->field('userId')->equals($id);

                        } else {
                            continue;
                        }

                        $googleReviewsQueryBuilder->field('reviewPlatform')->equals(SocialMediaReviews::REVIEW_PLATFORM_GOOGLE);
                        $googleReviewsQueryBuilder->sort('reviewUpdatedTime', 'desc');
                        $lastReview = $googleReviewsQueryBuilder->getQuery()->execute()->getSingleResult();

                        if ($lastReview) {
                            $lastReviewedAt = $lastReview->getReviewUpdatedTime()->format('Y-m-d H:i:s');
                        }
                        $response = $this->getContainer()->get('social_media_reviews_service')->getReviews($googleToken, [], null, $lastReviewedAt);
                        if ($response) {
                            // Sort review by ASC order
                            krsort($response);
                            $this->getContainer()->get('social_media_reviews_service')->storeSocialMediaReview($response, $id, $type, true, $googleToken->getId());

                            $googleToken->setCronStatus(1);
                            $em->persist($googleToken);
                            $em->flush();
                        }
                        
                        $googleToken->setCronStatus(1);
                        $em->persist($googleToken);
                        $em->flush();

                    } catch (\Exception $e) {
                        continue;
                    }
                }
            } else {
                // Reset Cron Status
                $googleTokenDetails = $em->getRepository(GoogleToken::class)->findAll();
                foreach ($googleTokenDetails as $googleToken) {
                    $googleToken->setCronStatus(0);
                        $em->persist($googleToken);
                }
                $em->flush();
            }
        }
    }
}

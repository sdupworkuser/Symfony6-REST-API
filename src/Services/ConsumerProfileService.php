<?php

namespace AppBundle\Services;

use AppBundle\Entity\ConsumerProfile;
use AppBundle\Entity\User;
use AppBundle\Services\MediaService;
use Doctrine\ORM\EntityManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class ConsumerProfileService
{
    /** @var ContainerInterface */
    private $container;

    /** @var EntityManager */
    private $em;

    /** @var MediaService */
    private $mediaService;

    /** @var string */
    private $awsPublicProfileImageDirectory;

    /**
     * ConsumerProfileService constructor.
     *
     * @param $awsPublicProfileImageDirectory
     */
    public function __construct(
        ContainerInterface $container,
        EntityManager $em,
        MediaService $mediaService,
        $awsPublicProfileImageDirectory
    ) {
        $this->container = $container;
        $this->em = $em;
        $this->mediaService = $mediaService;
        $this->awsPublicProfileImageDirectory = $awsPublicProfileImageDirectory;
    }

    /**
     * @return ConsumerProfile
     */
    public function postConsumerProfile(User $user, Request $request)
    {
        $consumerProfileObject = new ConsumerProfile();

        if ($user->getConsumerProfile()) {
            $consumerProfileObject = $user->getConsumerProfile();
        }

        if ($request->get('city') != null && $request->get('state') != null) {
            $consumerProfileObject->setCity($request->get('city'));
            $consumerProfileObject->setState($request->get('state'));
        }

        if ($request->get('profile_picture') != null) {
            $consumerProfileObject->setProfilePicture($this->mediaService->putBase64EncodedImage($request->get('profile_picture'), $this->awsPublicProfileImageDirectory));
        }

        $this->em->persist($consumerProfileObject);
        $this->em->flush();

        return $consumerProfileObject;
    }

    /**
     * @return ConsumerProfile
     */
    public function createConsumerProfile(User $u, User $user)
    {
        $consumerProfile = new ConsumerProfile();
        $consumerProfile->setUser($u);

        if ($user->getCity()) {
            $consumerProfile->setCity($user->getCity());
        }
        if ($user->getState()) {
            $consumerProfile->setState($user->getState());
        }

        $this->em->persist($consumerProfile);
        $this->em->flush();
    }

    /**
     * @return ConsumerProfile
     */
    public function validateToken(Request $request)
    {
        $data = [];
        $data['status'] = true;
        if (!$request->get('token')) {
            $data['status'] = false;
            $data['msessage'] = 'Token is required';

            return $data;
        }

        $consumerProfile = $this->businessToken($request->get('token'));

        if ($consumerProfile == null) {
            $data['status'] = false;
            $data['msessage'] = 'Invalid token';

            return $data;
        }

        $consumerTokenExpiryTime = $consumerProfile->getConsumerTokenExpiryTime()->format('Y-m-d h:i:s');

        $date = new \DateTime();
        $currentTime = $date->format('Y-m-d h:i:s');

        if (strtotime($currentTime) > strtotime($consumerTokenExpiryTime)) {
            $data['status'] = false;
            $data['msessage'] = 'Token is expired!';

            return $data;
        }

        return $data;
    }

    /**
     * @return ConsumerProfile
     */
    public function getBusinessToken(User $user)
    {
        $token = md5(random_bytes(20));
        $tokenExpiryTime = new \DateTime();
        $tokenExpiryTime->modify('+10 minutes');

        $consumerProfile = $user->getConsumerProfile();
        $consumerProfile->setConsumerToken($token);
        $consumerProfile->setConsumerTokenExpiryTime($tokenExpiryTime);
        $this->em->persist($consumerProfile);
        $this->em->flush();

        return $consumerProfile;
    }

    /**
     * @return array
     */
    public function businessToken(string $token)
    {
        $consumerProfileRepo = $this->em->getRepository(ConsumerProfile::class);

        return  $consumerProfileRepo->findOneBy(['consumerToken' => $token]);
    }

    /**
     * @return ConsumerProfile
     */
    public function deleteConsumerProfilePicture(ConsumerProfile $consumerProfile)
    {
        if ($consumerProfile->getProfilePicture() != null) {
            $this->mediaService->deleteImage($consumerProfile->getProfilePicture(), $this->awsPublicProfileImageDirectory);
            $consumerProfile->setProfilePicture(null);
        }

        $this->em->persist($consumerProfile);
        $this->em->flush();

        return $consumerProfile;
    }
}

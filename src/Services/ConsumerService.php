<?php

namespace AppBundle\Services;

use AppBundle\Entity\User;
use AppBundle\Repository\UserRepository;
use AppBundle\Utilities\SlugUtilities;
use Doctrine\ORM\EntityManager;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ConsumerService
{
    const DEFAULT_MINIMUM_REVIEW_VALUE = .8;

    /** @var ContainerInterface $container */
    private $container;

    /** @var EntityManager $em */
    private $em;

    /** @var UserPasswordEncoder $encoder */
    private $encoder;

    /**
     * UserService constructor.
     * @param EntityManager $em
     * @param UserPasswordEncoder $encoder
     */
    public function __construct(
        ContainerInterface $container,
        EntityManager $em,
        UserPasswordEncoder $encoder
    ) {
        $this->container = $container;
        $this->em = $em;
        $this->encoder = $encoder;
    }

    /**
     * @param User   $user
     * @return User
     */
    public function createConsumer(User $user)
    {
        $u = new User;
        $u->setUsername($user->getUsername());
        $u->setPassword($this->encoder->encodePassword($user, $user->getPlainPassword()));
        $u->setFirstName($user->getFirstName());
        $u->setLastName($user->getLastName());
        $u->setRoles(['ROLE_CONSUMER']);
        $u->setActive(true);
        $u->setCreated(new \DateTime);
        $u->setWelcomeSent(null);
        $u->setSlug($this->getUserSlug($user));
        $u->setApiKey($this->generateUserApiKey());
        $u->setEmailVerificationOtp($this->generateUserOtp());
        $u->setEmailVerificationOtpExpiredAt($this->getOtpExpiredAt());
        $u->setIsRepReviewsClient(false);

        $this->em->persist($u);
        $this->em->flush();

        //Send Email Verification Code
        $this->sendEmailVerifyOtp($u);

        return $u;
    }

    /**
     * @param User $user
     * @return string
     */
    public function getUserSlug(User $user)
    {
        $slug = SlugUtilities::slugify($user->getFirstName() . ' ' . $user->getLastName());
        $slugIsUnique = false;
        $i = 1;
        $repo = $this->em->getRepository(User::class);

        while (!$slugIsUnique) {
            $query = $repo->isSlugUnique($slug);

            if (!$query) {
                $slug .= $i;
                $i++;
            } else {
                $slugIsUnique = true;
            }
        }

        return $slug;
    }

    /**
     * @return string
     */
    public function generateUserApiKey()
    {
        return md5(uniqid());
    }
    
    /**
     * @param User        $u
     * @return User
     */
    public function addConsumerRole(User $u)
    {
        if(!in_array('ROLE_CONSUMER', $u->getRoles())) {
            $roles = $u->getRoles();
            $roles[] = "ROLE_CONSUMER";
            $u->setRoles($roles);
            $this->em->flush();
        }
        return $u;
    }

    /**
     * @param Int $otp
     * @param String $email
     * @return boolean
     */
    public function verifyEmailOtp(Int $otp, String $email)
    {
        $userRepo = $this->em->getRepository(User::class);
        $user = $userRepo->findOneBy(['username' => $email]);

        if(!$user){
            throw new AccessDeniedHttpException("Account with this email does not exist!");
        }

        if($user->getEmailVerifiedAt() != null) {
            throw new AccessDeniedHttpException("Your account has already been verified!");
        }

        if($user->getEmailVerificationOtp() != $otp){
            throw new AccessDeniedHttpException("Authentication Failed! Invalid OTP.");
        }

        $emailVerificationOtpExpire = $user->getEmailVerificationOtpExpiredAt()->format('Y-m-d h:i:s');

        $date = new \DateTime();
        $currentTime = $date->format('Y-m-d h:i:s');

        if (strtotime($currentTime) > strtotime($emailVerificationOtpExpire)) {
            throw new AccessDeniedHttpException("Your email verification OTP has been expired!");
        }

        $user->setEmailVerificationOtp(null);
        $user->setEmailVerificationOtpExpiredAt(null);
        $user->setEmailVerifiedAt($date);

        $this->em->persist($user);
        $this->em->flush();

        return array('success' => true, 'msessage' => "Your email account has been verified successfully!");
    }

    /**
     * @param String $email
     * @return User
     */
    public function resendEmailOtp(String $email)
    {
        $data = array();
        $userRepo = $this->em->getRepository(User::class);
        $user = $userRepo->findOneBy(['username' => $email]);

        if(!$user){
            throw new AccessDeniedHttpException("Account with this email does not exist!");
        }

        if($user->getEmailVerifiedAt() != null) {
            throw new AccessDeniedHttpException("Your account has already been verified!");
        }

        $user->setEmailVerificationOtp($this->generateUserOtp());
        $user->setEmailVerificationOtpExpiredAt($this->getOtpExpiredAt());

        $this->em->persist($user);
        $this->em->flush();

        //Send Email Verification Code
        $this->sendEmailVerifyOtp($user);

        return array('success' => true, 'msessage' => "The email confirmation OTP has been sent to your email address.");
    }

    /**
     * @return numbers
     */
    private function generateUserOtp()
    {
        return  rand(100000,999999);
    }

    /**
     * @return DateTime
     */
    private function getOtpExpiredAt()
    {
        $tokenExpiryTime = new \DateTime();
        return $tokenExpiryTime->modify('+30 minutes');
    }

    /**
     * @param User $user
     * @return mixed
     */
    private function sendEmailVerifyOtp(User $user) 
    {
        //trigger exception in a "try" block
        try {
            $this->container->get('mailer')->sendEmailVerify($user);
        }
        //catch exception
        catch(\Exception $e) {
            //echo 'Message: ' .$e->getMessage();
        }
    }
}
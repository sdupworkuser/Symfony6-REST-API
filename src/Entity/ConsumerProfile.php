<?php

namespace AppBundle\Entity;

use AppBundle\Validator\Constraints as CustomConstraint;
use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation as Serializer;

/**
 * ConsumerProfile.
 *
 * @ORM\Table(name="consumer_profile")
 * @ORM\Entity(repositoryClass="AppBundle\Repository\ConsumerProfileRepository")
 */
class ConsumerProfile
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @Serializer\Expose
     * @Serializer\Groups({"private"})
     */
    private $id;

    /**
     * @ORM\OneToOne(targetEntity="AppBundle\Entity\User")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id")
     * @Serializer\Groups({"private"})
     */
    private $user;

    /**
     * @var string
     *
     * @ORM\Column(name="city", type="string", length=50, nullable=true)
     * @Serializer\Groups({"consumer_profile_put", "private"})
     */
    private $city;

    /**
     * @var string
     *
     * @ORM\Column(name="state", type="string", length=2, nullable=true)
     * @Serializer\Groups({"consumer_profile_put", "private"})
     */
    private $state;

    /**
     * @var string
     *
     * @ORM\Column(name="profile_picture", type="string", length=100, nullable=true)
     * @Serializer\Groups({"consumer_profile_put", "private"})
     */
    private $profilePicture;

    /**
     * @var string
     *
     * @ORM\Column(name="consumer_token", type="string", length=100, nullable=true)
     * @Serializer\Groups({"consumer_profile_put", "private"})
     */
    private $consumerToken;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="consumer_token_expiry_time", type="datetime", nullable=true)
     * @Serializer\Groups({"consumer_profile_put", "private"})
     */
    private $consumerTokenExpiryTime;

    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set user.
     *
     * @return ConsumerProfile
     */
    public function setUser(\AppBundle\Entity\User $user)
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get user.
     *
     * @return int
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set city.
     *
     * @param string $city
     *
     * @return ConsumerProfile
     */
    public function setCity($city)
    {
        $this->city = $city;

        return $this;
    }

    /**
     * Get city.
     *
     * @return string
     */
    public function getCity()
    {
        return $this->city;
    }

    /**
     * Set state.
     *
     * @param string $state
     *
     * @return ConsumerProfile
     */
    public function setState($state)
    {
        $this->state = $state;

        return $this;
    }

    /**
     * Get state.
     *
     * @return string
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Set profilePicture.
     *
     * @param string $profilePicture
     *
     * @return ConsumerProfile
     */
    public function setProfilePicture($profilePicture)
    {
        $this->profilePicture = $profilePicture;

        return $this;
    }

    /**
     * Get profilePicture.
     *
     * @return string
     */
    public function getProfilePicture()
    {
        return $this->profilePicture;
    }

    /**
     * Set consumerToken.
     *
     * @param string $consumerToken
     *
     * @return ConsumerProfile
     */
    public function setConsumerToken($consumerToken)
    {
        $this->consumerToken = $consumerToken;

        return $this;
    }

    /**
     * Get consumerToken.
     *
     * @return string
     */
    public function getConsumerToken()
    {
        return $this->consumerToken;
    }

    /**
     * Set consumerTokenExpiryTime.
     *
     * @param \DateTime $consumerTokenExpiryTime
     *
     * @return ConsumerProfile
     */
    public function setConsumerTokenExpiryTime($consumerTokenExpiryTime)
    {
        $this->consumerTokenExpiryTime = $consumerTokenExpiryTime;

        return $this;
    }

    /**
     * Get consumerTokenExpiryTime.
     *
     * @return \DateTime
     */
    public function getConsumerTokenExpiryTime()
    {
        return $this->consumerTokenExpiryTime;
    }
}

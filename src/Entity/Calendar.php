<?php

namespace AppBundle\Entity;

use AppBundle\Validator\Constraints as CustomConstraint;
use AppBundle\Utilities\ConstructorArgs;
use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * *     href="expr('/calendar/' ~ object.getId())"
 * @ORM\Entity(repositoryClass="AppBundle\Repository\CalendarRepository")
 * @ORM\Table("calendar")
 * @Serializer\ExclusionPolicy("all")
 * @CustomConstraint\AuthorizationCodeExchangeConstraint(
 *     groups={"auth_exchange"}
 * )
 */
class Calendar
{
    use ConstructorArgs;

    /**
     * @ORM\Column(type="integer")
     * @ORM\Id()
     * @ORM\GeneratedValue(strategy="AUTO")
     * @Serializer\Expose
     * @var int
     */
    public $id;

    /**
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\Company")
     * @ORM\JoinColumn(name="company_id", referencedColumnName="id")
     * @Serializer\Groups({"public"})
     * @Assert\Valid()
     */
    private $company;

    /**
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\Branch")
     * @ORM\JoinColumn(name="branch_id", referencedColumnName="id")
     * @Serializer\Groups({"public"})
     * @Assert\Valid()
     */
    private $branch;

    /**
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\User")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id")
     * @Serializer\Groups({"public"})
     * @Assert\Valid()
     */
    private $user;

    /**
     * @ORM\Column(name="calendar_type", type="string", length=50)
     * @Serializer\Expose
     * @Serializer\Groups({"public"})
     * @Assert\Length(
     *     groups={"calendar_post", "calendar_put"},
     *     max="50",
     *     maxMessage="Calendar Type can not be longer than 50 characters."
     * )
     */
    private $calendarType;

    /**
     * @ORM\Column(name="token_type", type="string", length=50)
     * @Serializer\Expose
     * @Assert\Length(
     *     groups={"calendar_post", "calendar_put"},
     *     max="50",
     *     maxMessage="Token Type can not be longer than 50 characters."
     * )
     */
    private $tokenType;

    /**
     * @ORM\Column(name="access_token", type="string")
     * @Serializer\Expose
     */
    private $accessToken;

    /**
     * @ORM\Column(name="expires_in", type="integer")
     * @Serializer\Expose
     */
    private $expiresIn;

    /**
     * @ORM\Column(name="created", type="integer")
     * @Serializer\Expose
     */
    private $created;

    /**
     * @ORM\Column(name="refresh_token", type="string")
     * @Serializer\Expose
     */
    private $refreshToken;

    /**
     * @ORM\Column(name="active", type="boolean", options={"default": true})
     * @Serializer\Expose
     * @Serializer\Groups({"public"})
     * @var bool
     * @Assert\NotNull(
     *     groups={"calendar_put"}
     * )
     * @Assert\Type(
     *     groups={"calendar_post", "calendar_put"},
     *     type="boolean"
     * )
     */
    private $active;

    /**
     * @Serializer\Expose
     * @var string
     *
     * @Serializer\Type("string")
     * @Assert\NotBlank(
     *     groups={"auth_exchange"}
     * )
     */
    private $authorizationCode;

    public function __construct(array $args = [])
    {
        $this->handleArgs($args);
    }

    private function getRoute()
    {
        return '/calendar/';
    }

    /**
     * Get id
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set company
     *
     * @param \AppBundle\Entity\Company $company
     *
     * @return Calendar
     */
    public function setCompany(\AppBundle\Entity\Company $company = null)
    {
        $this->company = $company;

        return $this;
    }

    /**
     * Get company
     *
     * @return \AppBundle\Entity\Company
     */
    public function getCompany()
    {
        return $this->company;
    }

    /**
     * Set branch
     *
     * @param \AppBundle\Entity\Branch $branch
     *
     * @return Calendar
     */
    public function setBranch(\AppBundle\Entity\Branch $branch = null)
    {
        $this->branch = $branch;

        return $this;
    }

    /**
     * Get branch
     *
     * @return \AppBundle\Entity\Branch
     */
    public function getBranch()
    {
        return $this->branch;
    }

    /**
     * Set user
     *
     * @param \AppBundle\Entity\User $user
     *
     * @return Calendar
     */
    public function setUser(\AppBundle\Entity\User $user = null)
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get user
     *
     * @return \AppBundle\Entity\User
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set calendarType
     *
     * @param string $calendarType
     *
     * @return Calendar
     */
    public function setCalendarType($calendarType)
    {
        $this->calendarType = $calendarType;

        return $this;
    }

    /**
     * Get calendarType
     *
     * @return string
     */
    public function getCalendarType()
    {
        return $this->calendarType;
    }

    /**
     * Set tokenType
     *
     * @param string $tokenType
     *
     * @return Calendar
     */
    public function setTokenType($tokenType)
    {
        $this->tokenType = $tokenType;

        return $this;
    }

    /**
     * Get tokenType
     *
     * @return string
     */
    public function getTokenType()
    {
        return $this->tokenType;
    }

    /**
     * Set accessToken
     *
     * @param string $accessToken
     *
     * @return Calendar
     */
    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    /**
     * Get accessToken
     *
     * @return string
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * Set refreshToken
     *
     * @param string $refreshToken
     *
     * @return Calendar
     */
    public function setRefreshToken($refreshToken)
    {
        $this->refreshToken = $refreshToken;

        return $this;
    }

    /**
     * Get refreshToken
     *
     * @return string
     */
    public function getRefreshToken()
    {
        return $this->refreshToken;
    }

    /**
     * Set expiresIn
     *
     * @param integer $expiresIn
     *
     * @return Calendar
     */
    public function setExpiresIn($expiresIn)
    {
        $this->expiresIn = $expiresIn;

        return $this;
    }

    /**
     * Get expiresIn
     *
     * @return integer
     */
    public function getExpiresIn()
    {
        return $this->expiresIn;
    }

    /**
     * Set created
     *
     * @param integer $created
     *
     * @return Calendar
     */
    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get created
     *
     * @return integer
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Set active
     *
     * @param bool $active
     *
     * @return Contact
     */
    public function setActive($active)
    {
        $this->active = $active;

        return $this;
    }

    /**
     * Get active
     *
     * @return bool
     */
    public function getActive()
    {
        return $this->active;
    }

    /**
     * @return bool
     */
    public function isActive()
    {
        return !!$this->active;
    }

    /**
     * @return string
     */
    public function getAuthorizationCode()
    {
        return $this->authorizationCode;
    }
}

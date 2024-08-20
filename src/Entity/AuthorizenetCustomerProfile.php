<?php

namespace AppBundle\Entity;

use AppBundle\Utilities\ConstructorArgs;
use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation as Serializer;

/**
 * AuthorizenetCustomerProfile
 *
 * @ORM\Table(name="authorizenet_customer_profile")
 * @ORM\Entity(repositoryClass="AppBundle\Repository\AuthorizenetCustomerProfileRepository")
 */
class AuthorizenetCustomerProfile
{ 
    use ConstructorArgs;
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @Serializer\Expose
     */
    private $id;

    /**
     * @ORM\OneToOne(targetEntity="AppBundle\Entity\Contact")
     * @ORM\JoinColumn(name="contact_id", referencedColumnName="id")
     */
    private $contact;

    /**
     * @var string
     * @ORM\Column(name="card_number", type="string", nullable=true, options={"default": null})
     * @Serializer\Expose
     */
    private $cardNumber;

    /**
     * @var string
     * @ORM\Column(name="customer_profile_id", type="string", nullable=true, options={"default": null})
     */
    private $customerProfileId;

    /**
     * @var string
     * @ORM\Column(name="payment_profile_id", type="string", nullable=true, options={"default": null})
     */
    private $paymentProfileId;

    /**
     * @var string
     * @ORM\Column(name="ref_id", type="string", nullable=true, options={"default": null})
     */
    private $refId;

    /**
     * @var json_array
     * @ORM\Column(name="payload", type="json_array", nullable=true, options={"default": null})
     * @Serializer\Expose
     */
    private $payload;

    /**
     * @var json_array
     * @ORM\Column(name="response", type="json_array", nullable=true, options={"default": null})
     * @Serializer\Expose
     */
    private $response;

    /**
     * @var date_time
     * @ORM\Column(name="created_at", nullable=true, options={"default": null})
     * @Serializer\Expose
     */
    private $createdAt;

    /**
     * @var date_time
     * @ORM\Column(name="updated_at", nullable=true, options={"default": null})
     * @Serializer\Expose
     */
    private $updatedAt;


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
     * Set contact.
     *
     * @param \AppBundle\Entity\Contact|null $contact
     *
     * @return AuthorizenetCustomerProfile
     */
    public function setContact(\AppBundle\Entity\Contact $contact = null)
    {
        $this->contact = $contact;

        return $this;
    }

    /**
     * Get contact.
     *
     * @return \AppBundle\Entity\Contact|null
     */
    public function getContact()
    {
        return $this->contact;
    }

    /**
     * Set cardNumber
     *
     * @param string $cardNumber
     *
     * @return AuthorizenetCustomerProfile
     */
    public function setCardNumber($cardNumber)
    {
        $this->cardNumber = $cardNumber;

        return $this;
    }

    /**
     * Get cardNumber
     *
     * @return string
     */
    public function getCardNumber()
    {
        return $this->cardNumber;
    }

    /**
     * Set customerProfileId
     *
     * @param string $customerProfileId
     *
     * @return AuthorizenetCustomerProfile
     */
    public function setCustomerProfileId($customerProfileId)
    {
        $this->customerProfileId = $customerProfileId;

        return $this;
    }

    /**
     * Get customerProfileId
     *
     * @return string
     */
    public function getCustomerProfileId()
    {
        return $this->customerProfileId;
    }

    /**
     * Set paymentProfileId
     *
     * @param string $paymentProfileId
     *
     * @return AuthorizenetCustomerProfile
     */
    public function setPaymentProfileId($paymentProfileId)
    {
        $this->paymentProfileId = $paymentProfileId;

        return $this;
    }

    /**
     * Get paymentProfileId
     *
     * @return string
     */
    public function getPaymentProfileId()
    {
        return $this->paymentProfileId;
    }

    /**
     * Set refId
     *
     * @param string $refId
     *
     * @return AuthorizenetCustomerProfile
     */
    public function setRefId($refId)
    {
        $this->refId = $refId;  

        return $this;
    }

    /**
     * Get refId
     *
     * @return string
     */
    public function getRefId()
    {
        return $this->refId;
    }

    /**
     * Set payload
     *
     * @param json_array $payload
     *
     * @return AuthorizenetCustomerProfile
     */
    public function setPayload($payload)
    {
        $this->payload = $payload;

        return $this;
    }

    /**
     * Get payload
     *
     * @return json_array
     */
    public function getPayload()
    {
        return $this->payload;
    }

    /**
     * Set response
     *
     * @param json_array $response
     *
     * @return AuthorizenetCustomerProfile
     */
    public function setResponse($response)
    {
        $this->response = $response;

        return $this;
    }

    /**
     * Get response
     *
     * @return json_array
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Set createdAt
     *
     * @param \DateTime $createdAt
     *
     * @return AuthorizenetCustomerProfile
     */
    public function setCreatedAt($createdAt)
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * Get createdAt
     *
     * @return \DateTime
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * Set updatedAt
     *
     * @param \DateTime $updatedAt
     *
     * @return AuthorizenetCustomerProfile
     */
    public function setUpdatedAt($updatedAt)
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * Get updatedAt
     *
     * @return \DateTime
     */
    public function getUpdatedAt()
    {
        return $this->updatedAt;
    }
}
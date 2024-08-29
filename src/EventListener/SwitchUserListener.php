<?php

namespace AppBundle\EventListener;

use AppBundle\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\SecurityEvents;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\Security\Core\Role\SwitchUserRole;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Firewall\ListenerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;

class SwitchUserListener implements ListenerInterface
{
    private $tokenStorage;
    private $provider;
    private $userChecker;
    private $providerKey;
    private $accessDecisionManager;
    private $usernameParameter;
    private $role;
    private $logger;
    private $dispatcher;

    public function __construct(
        TokenStorageInterface $tokenStorage,
        UserProviderInterface $provider,
        UserCheckerInterface $userChecker,
        $providerKey,
        AccessDecisionManagerInterface $accessDecisionManager,
        LoggerInterface $logger = null,
        $usernameParameter = '_switch_user',
        $role = 'ROLE_ALLOWED_TO_SWITCH',
        EventDispatcherInterface $dispatcher = null
    ) {
        if (empty($providerKey)) {
            throw new \InvalidArgumentException('$providerKey must not be empty.');
        }

        $this->tokenStorage = $tokenStorage;
        $this->provider = $provider;
        $this->userChecker = $userChecker;
        $this->providerKey = $providerKey;
        $this->accessDecisionManager = $accessDecisionManager;
        $this->usernameParameter = $usernameParameter;
        $this->role = $role;
        $this->logger = $logger;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Handles the switch to another user.
     *
     * @param GetResponseEvent $event A GetResponseEvent instance
     *
     * @throws \LogicException if switching to a user failed
     */
    public function handle(GetResponseEvent $event)
    {
        $request = $event->getRequest();

        if (!$request->get($this->usernameParameter)) {
            return;
        }

        if ('_exit' === $request->get($this->usernameParameter)) {
            $this->tokenStorage->setToken($this->attemptExitUser($request));
        } else {
            try {
                $this->tokenStorage->setToken($this->attemptSwitchUser($request));
            } catch (AuthenticationException $e) {
                throw new \LogicException(sprintf('Switch User failed: "%s"', $e->getMessage()));
            }
        }
    }

    /**
     * Attempts to switch to another user.
     *
     * @param Request $request A Request instance
     *
     * @return TokenInterface|null The new TokenInterface if successfully switched, null otherwise
     *
     * @throws \LogicException
     * @throws AccessDeniedException
     */
    private function attemptSwitchUser(Request $request)
    {
        $token = $this->tokenStorage->getToken();
        $originalToken = $this->getOriginalToken($token);

        if (false !== $originalToken) {
            if ($token->getUsername() === $request->get($this->usernameParameter)) {
                return $token;
            }

            throw new \LogicException(sprintf('You are already switched to "%s" user.', $token->getUsername()));
        }

        $username = $request->get($this->usernameParameter);
        $user = $this->provider->loadUserByUsername($username);

        if (false === $this->isGranted($token, $user)) {
            $exception = new AccessDeniedException();
            $exception->setAttributes($this->role);

            throw $exception;
        }

        if (null !== $this->logger) {
            $this->logger->info('Attempting to switch to user.', ['username' => $username]);
        }

        $this->userChecker->checkPostAuth($user);

        $roles = $user->getRoles();
        $roles[] = new SwitchUserRole('ROLE_PREVIOUS_ADMIN', $this->tokenStorage->getToken());

        $token = new UsernamePasswordToken($user, $user->getPassword(), $this->providerKey, $roles);

        if (null !== $this->dispatcher) {
            $switchEvent = new SwitchUserEvent($request, $token->getUser());
            $this->dispatcher->dispatch(SecurityEvents::SWITCH_USER, $switchEvent);
        }

        return $token;
    }

    /**
     * Checks if allowed user switching.
     *
     * @param TokenInterface $token
     * @param User           $user
     *
     * @return bool
     */
    private function isGranted(TokenInterface $token, User $user)
    {
        $role = $this->role;

        if ($token->getUser()->isAdmin()) {
            return true;
        }

        if ($this->accessDecisionManager->decide($token, [$role])) {
            return true;
        }

        // Company admins can log into accounts from their own company
        if ($this->accessDecisionManager->decide($token, ['ROLE_COMPANY_ADMIN'])) {
            if ($token->getUser()->getBranch()->getCompany() === $user->getBranch()->getCompany()) {
                return true;
            }
        }

        // Branch admins can log into accounts from their own branch
        if ($this->accessDecisionManager->decide($token, ['ROLE_BRANCH_ADMIN'])) {
            if ($token->getUser()->getBranch() === $user->getBranch()) {
                return true;
            }
        }

        // Resellers can log into accounts they own
        if ($this->accessDecisionManager->decide($token, ['ROLE_RESELLER'])) {
            if ($token->getUser()->getReseller()->getId() === $user->getReseller()->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Attempts to exit from an already switched user.
     *
     * @param Request $request A Request instance
     *
     * @return TokenInterface The original TokenInterface instance
     *
     * @throws AuthenticationCredentialsNotFoundException
     */
    private function attemptExitUser(Request $request)
    {
        if (false === $original = $this->getOriginalToken($this->tokenStorage->getToken())) {
            throw new AuthenticationCredentialsNotFoundException('Could not find original Token object.');
        }

        if (null !== $this->dispatcher && $original->getUser() instanceof UserInterface) {
            $user = $this->provider->refreshUser($original->getUser());
            $switchEvent = new SwitchUserEvent($request, $user);
            $this->dispatcher->dispatch(SecurityEvents::SWITCH_USER, $switchEvent);
        }

        return $original;
    }

    /**
     * Gets the original Token from a switched one.
     *
     * @param TokenInterface $token A switched TokenInterface instance
     *
     * @return TokenInterface|false The original TokenInterface instance, false if the current TokenInterface is not switched
     */
    private function getOriginalToken(TokenInterface $token)
    {
        foreach ($token->getRoles() as $role) {
            if ($role instanceof SwitchUserRole) {
                return $role->getSource();
            }
        }

        return false;
    }
}

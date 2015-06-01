<?php

namespace Ojs\Common\Services;

use Doctrine\ORM\EntityManager;
use Ojs\JournalBundle\Entity\Journal;
use Ojs\UserBundle\Entity\Role;
use Ojs\UserBundle\Entity\User;
use Ojs\UserBundle\Entity\UserJournalRole;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Encoder\EncoderFactory;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Security\Core\User\User as SecurityUser;

class UserListener
{
    /** @var Session  */
    protected $session;
    /** @var Router  */
    protected $router;
    /** @var Request  */
    protected $request;
    /** @var EntityManager  */
    protected $em;
    /** @var  string */
    protected $rootDir;
    /** @var JournalService  */
    protected $journalService;
    /** @var EncoderFactory  */
    protected $encoderFactory;
    /** @var AuthorizationCheckerInterface  */
    protected $authorizationChecker;
    /** @var TokenStorage  */
    protected $tokenStorage;

    /**
     * @param Router $router
     * @param EntityManager $em
     * @param $rootDir
     * @param JournalService $journalService
     * @param EncoderFactory $encoderFactory
     * @param AuthorizationCheckerInterface $authorizationChecker
     * @param TokenStorage $tokenStorage
     */
    public function __construct(
        Router $router,
        EntityManager $em, $rootDir,
        JournalService $journalService,
        EncoderFactory $encoderFactory,
        AuthorizationCheckerInterface $authorizationChecker,
        TokenStorage $tokenStorage)
    {
        $this->router = $router;

        $this->em = $em;
        $this->rootDir = $rootDir;
        $this->journalService = $journalService;
        $this->encoderFactory = $encoderFactory;
        $this->authorizationChecker = $authorizationChecker;
        $this->tokenStorage = $tokenStorage;
    }

    public function onKernelRequest(GetResponseEvent $event)
    {
        $this->request = $event->getRequest();
        $this->session = $event->getRequest()->getSession();

        if ($event->isMasterRequest()) {
            $this->loadJournals();
            $this->loadJournalRoles();
            $this->loadClientUsers();
        }
        // fill journal roles
    }

    /**
     *
     * @param  string  $username
     * @return boolean
     */
    public function checkUsernameAvailability($username)
    {
        $usernameLower = trim(strtolower($username));
        if (strlen($usernameLower) < 4) {
            return false;
        }
        $yamlParser = new Parser();
        $reservedUserNames = $yamlParser->parse(file_get_contents(
                        $this->rootDir.
                        '/../src/Ojs/UserBundle/Resources/data/reservedUsernames.yml'
        ));
        $user = $this->em->getRepository('OjsUserBundle:User')->findOneBy(array('username' => $usernameLower));

        return (!$user && !in_array($usernameLower, $reservedUserNames));
    }

    /**
     * get user's roles for selected journal and save to userJournalRoles session key
     * @return void
     */
    public function loadJournalRoles()
    {
        $userJournalRoles = $this->getJournalRoles();
        $this->session->set('userJournalRoles', $userJournalRoles);
    }

    /**
     * Get user's journal roles
     * @param  Journal|bool      $journal
     * @return UserJournalRole[]
     */
    public function getJournalRoles($journal = false)
    {
        $journalObject = $journal ? $journal : $this->journalService->getSelectedJournal(false);
        $user = $this->checkUser();
        $userJournalRoles = [];
        if (!$user || !$journalObject) {
            return [];
        }
        //for API_KEY based connection
        if ($user instanceof SecurityUser) {
            $user = $this->em->getRepository('OjsUserBundle:User')
                    ->findOneBy(['username' => $user->getUsername()]);
        }
        $repo = $this->em->getRepository('OjsUserBundle:UserJournalRole');
        /** @var UserJournalRole[] $entities */
        $entities = $repo->findBy(array('userId' => $user->getId(), 'journalId' => $journalObject->getId()));
        if ($entities) {
            foreach ($entities as $entity) {
                $userJournalRoles[] = $entity->getRole();
            }
        }

        return $userJournalRoles;
    }

    /**
     * load users to session that I can login as them
     * @return void
     */
    public function loadClientUsers()
    {
        $user = $this->checkUser();
        if (!$user) {
            return;
        }

        //for API_KEY based connection
        if ($user instanceof SecurityUser) {
            $user = $this->em->getRepository('OjsUserBundle:User')->findOneBy(['username' => $user->getUsername()]);
        }

        $clients = $this->em->getRepository('OjsUserBundle:Proxy')->findBy(
                array('proxyUserId' => $user->getId())
        );
        $this->session->set('userClients', $clients);
    }

    /**
     * @return void
     */
    public function loadJournals()
    {
        if ($this->session->get("selectedJournalId", false)) {
            return;
        }
        /** @var User $user */
        $user = $this->checkUser();
        if (!$user) {
            return;
        }

        //for API_KEY based connection
        if ($user instanceof SecurityUser) {
            $user = $this->em->getRepository('OjsUserBundle:User')->findOneBy(['username' => $user->getUsername()]);
        }

        /** @var UserJournalRole[] $userJournals */
        $userJournals = $this->em->getRepository('OjsUserBundle:UserJournalRole')->findBy(array('user' => $user));
        if (is_array($userJournals)) {
            foreach ($userJournals as $item) {
                $this->journalService->setSelectedJournal($item->getJournalId());
                break;
            }
        }
    }


    /**
     * @return bool|User
     */
    public function checkUser()
    {
        $token = $this->tokenStorage->getToken();
        if (empty($token)) {
            return false;
        }
        if ($this->authorizationChecker->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $token->getUser();
        }

        return false;
    }

    /**
     * @param $checkRoles
     * @param  bool|Journal $journal
     * @return bool
     */
    public function hasAnyRole($checkRoles, $journal = false)
    {
        /** @var Role[] $checkRoles */
        foreach ($checkRoles as $checkRole) {
            if ($this->hasJournalRole($checkRole->getRole(), $journal)) {
                return true;
            }
        }

        return false;
    }

    /**
     *
     * @param  string       $roleCode
     * @param  bool|Journal $journal
     * @return boolean
     */
    public function hasJournalRole($roleCode, $journal = false)
    {
        $userJournalRoles = $this->getJournalRoles($journal);
        $user = $this->checkUser();
        if ($user && is_array($userJournalRoles)) {
            foreach ($userJournalRoles as $role) {
                /** @var UserJournalRole $role */
                if ($roleCode == $role->getRole()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  User $user
     * @param $password
     * @param  bool $old_password
     * @return bool
     */
    public function changePassword(User &$user, $password, $old_password = false)
    {
        if (empty($password)) {
            return false;
        }
        $encoder = $this->encoderFactory->getEncoder($user);

        if ($old_password) {
            if (!$encoder->isPasswordValid($user->getPassword(), $old_password, $user->getSalt())) {
                return false;
            }
        }

        $user->setPassword($password);

        $password = $encoder->encodePassword($user->getPassword(), $user->getSalt());
        $user->setPassword($password);

        return true;
    }
}

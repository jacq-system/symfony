<?php declare(strict_types = 1);

namespace App\EventSubscriber;

use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\FirewallMapInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class AuthorizationCodeSubscriber implements EventSubscriberInterface
{
    use TargetPathTrait;

    private Security $security;
    private UrlGeneratorInterface $urlGenerator;
    private RequestStack $requestStack;
    private $firewallName;

    public function __construct(Security $security, UrlGeneratorInterface $urlGenerator, RequestStack $requestStack, FirewallMapInterface $firewallMap)
    {
        $this->security = $security;
        $this->urlGenerator = $urlGenerator;
        $this->requestStack = $requestStack;
        $this->firewallName = $firewallMap->getFirewallConfig($requestStack->getCurrentRequest())->getName();
    }

    public function onLeagueOauth2ServerEventAuthorizationRequestResolve(AuthorizationRequestResolveEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $user = $this->security->getUser();
        $this->saveTargetPath($request->getSession(), $this->firewallName, $request->getUri());
        $response = new RedirectResponse($this->urlGenerator->generate('app_login'), 307);
        if ($user instanceof UserInterface) {
            if ($request->getSession()->get('consent_granted') !== null) {
                $event->resolveAuthorization($request->getSession()->get('consent_granted'));
                $request->getSession()->remove('consent_granted');
                return;
            }
            $response = new RedirectResponse($this->urlGenerator->generate('app_consent', $request->query->all()), 307);
        }
        $event->setResponse($response);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE => 'onLeagueOauth2ServerEventAuthorizationRequestResolve',
        ];
    }
}

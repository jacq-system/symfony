<?php declare(strict_types = 1);

namespace App\Controller\OAuth;

use App\Entity\OAuth2UserConsent;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class LoginController extends AbstractController
{

    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
    }

    #[Route('/login', name: 'app_login')]
    public function index(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_index');
        }
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();
        return $this->render('login/index.html.twig', [
            'controller_name' => 'LoginController',
            'error' => $error,
            'last_username' => $lastUsername,
        ]);
    }

    /**
     * @throws \Exception
     */
    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout()
    {
        // controller can be blank: it will never be called!
        throw new \Exception('Don\'t forget to activate logout in security.yaml');
    }

    /**
     * I know this controller is fat, bloated and ugly. But it's just a demo and I feel
     * keeping all the logic in one place makes it easier to follow as a tutorial.
     * And of course I don't get paid to write these blog posts.
     * In the real world, we would factor this out in to some pretty services...
     */
    #[Route('/consent', name: 'app_consent', methods: ['GET', 'POST'])]
    public function consent(Request $request): Response
    {
        $clientId = $request->query->get('client_id');
        if (!$clientId || !ctype_alnum($clientId) || !$this->getUser()) {
            return $this->redirectToRoute('app_index');
        }
        $appClient = $this->managerRegistry->getRepository(Client::class)->findOneBy(['identifier' => $clientId]);
        if (!$appClient) {
            return $this->redirectToRoute('app_index');
        }
        $appName = $appClient->getName();

        // Get the client scopes
        $requestedScopes = explode(' ', $request->query->get('scope'));
        // Get the client scopes in the database
        $clientScopes = $appClient->getScopes();

        // Check all requested scopes are in the client scopes
        if (count(array_diff($requestedScopes, $clientScopes)) > 0) {
            return $this->redirectToRoute('app_index');
        }

        // Check if the user has already consented to the scopes
        /** @var User $user */
        $user = $this->getUser();
        $userConsents = $user->getOAuth2UserConsents()->filter(
            fn (OAuth2UserConsent $consent) => $consent->getClient() === $appClient
        )->first() ?: null;
        $userScopes = $userConsents?->getScopes() ?? [];
        $hasExistingScopes = count($userScopes) > 0;

        // If user has already consented to the scopes, give consent
        if (count(array_diff($requestedScopes, $userScopes)) === 0) {
            $request->getSession()->set('consent_granted', true);
            return $this->redirectToRoute('oauth2_authorize', $request->query->all());
        }

        // Remove the scopes to which the user has already consented
        $requestedScopes = array_diff($requestedScopes, $userScopes);

        // Map the requested scopes to scope names
        $scopeNames = [
            'profile' => 'Your profile',
            'specimen_read' => 'Your herbarium specimens (read)',
            'specimen_write' => 'Your herbarium specimens (write)',
        ];

        // Get all the scope names in the requested scopes.
        $requestedScopeNames = array_map(fn($scope) => $scopeNames[$scope], $requestedScopes);
        $existingScopes = array_map(fn($scope) => $scopeNames[$scope], $userScopes);

        if ($request->isMethod('POST')) {
            if ($request->request->get('consent') === 'yes') {
                $request->getSession()->set('consent_granted', true);
                // Add the requested scopes to the user's scopes
                $consents = $userConsents ?? new OAuth2UserConsent();;
                $consents->setScopes(array_merge($requestedScopes, $userScopes));
                $consents->setClient($appClient);
                $consents->setCreated(new \DateTimeImmutable());
                $consents->setExpires(new \DateTimeImmutable('+30 days'));
                $consents->setIpAddress($request->getClientIp());
                $user->addOAuth2UserConsent($consents);
                $this->managerRegistry->getManager()->persist($consents);
                $this->managerRegistry->getManager()->flush();
            }
            if ($request->request->get('consent') === 'no') {
                $request->getSession()->set('consent_granted', false);
            }
            return $this->redirectToRoute('oauth2_authorize', $request->query->all());
        }
        return $this->render('login/consent.html.twig', [
            'app_name' => $appName,
            'scopes' => $requestedScopeNames,
            'has_existing_scopes' => $hasExistingScopes,
            'existing_scopes' => $existingScopes,
        ]);
    }
}

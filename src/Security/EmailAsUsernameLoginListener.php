<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Security;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\FrontendUser;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EmailAsUsernamePolicy;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * A4: lets a member log in with a differently-cased email even though
 * tl_member.username is a case-sensitive (BINARY) column.
 *
 * Runs BEFORE Symfony's LoginThrottlingListener (priority 2080) and
 * UserCheckerListener (priority 256) on CheckPassportEvent, so both see the
 * already-normalized identifier. Passport::addBadge() replaces a badge keyed
 * by the same FQCN (verified against both installations this bundle targets:
 * Contao 5.3.46 / Symfony 6.4 and Contao 5.7.6 / Symfony 7.4 – identical
 * Passport::addBadge()/UserBadge in both), and UserBadge::getUserLoader() is
 * public – so the identifier can be swapped while re-using Contao's own loader
 * (ContaoLoginAuthenticator::authenticate() wires the passport's UserBadge to
 * $userProvider->loadUserByIdentifier(...)), giving the exact same lookup
 * the real login would perform.
 *
 * Only acts on the frontend firewall (Contao's own ScopeMatcher, the same
 * service the core authenticator itself uses to tell front from back end –
 * CheckPassportEvent carries no firewall name), only for identifiers
 * containing "@", and only when there is no member with that EXACT username
 * (checked the same way Contao's own frontend user provider does:
 * FrontendUser::loadUserByIdentifier(), which is an exact, case-sensitive
 * findBy('username', …) – see contao/library/Contao/User.php). A stored,
 * differently-cased username is therefore never shadowed.
 */
final class EmailAsUsernameLoginListener
{
    public function __construct(
        private readonly EmailAsUsernamePolicy $policy,
        private readonly ScopeMatcher $scopeMatcher,
        private readonly RequestStack $requestStack,
        private readonly ContaoFramework $framework,
    ) {
    }

    #[AsEventListener(event: CheckPassportEvent::class, priority: 3000)]
    public function __invoke(CheckPassportEvent $event): void
    {
        if (!$this->policy->isEnabled()) {
            return;
        }

        $request = $this->requestStack->getMainRequest();

        if (null === $request || !$this->scopeMatcher->isFrontendRequest($request)) {
            return;
        }

        $passport = $event->getPassport();

        if (!$passport->hasBadge(UserBadge::class)) {
            return;
        }

        $badge = $passport->getBadge(UserBadge::class);
        $identifier = $badge->getUserIdentifier();

        if (!str_contains($identifier, '@')) {
            return;
        }

        $lower = mb_strtolower($identifier);

        if ($lower === $identifier) {
            return; // Already lowercase – the exact search below would find the same thing.
        }

        $this->framework->initialize();

        if (null !== $this->framework->getAdapter(FrontendUser::class)->loadUserByIdentifier($identifier)) {
            return; // A member carries the exact, differently-cased identifier – do not shadow it.
        }

        $passport->addBadge(new UserBadge($lower, $badge->getUserLoader()));
    }
}

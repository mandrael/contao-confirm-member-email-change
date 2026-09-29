<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Security;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\CanonicalUsername;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\FrontendUser;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * A4: lets a member log in with a differently-cased email even though
 * tl_member.username is a case-sensitive (BINARY) column.
 *
 * Independent of the memberEmailAsUsername switch (A1)
 * - a member's login name IS an email address the moment ANY route wrote a lower-cased
 * one (the switch, terminal42/contao-mailusername, or a manually assigned address-shaped
 * name), and the switch being off afterwards must not un-break the case-insensitive login
 * that address-shaped names imply. The "@"-only scope and the exact-match precedence below
 * are what keeps this from touching an unrelated non-email username.
 *
 * Runs AFTER Symfony's LoginThrottlingListener (priority 2080, its limiter lowercases the
 * identifier itself) and CsrfProtectionListener (512), so a throttled or forged login never
 * reaches the lookups below, and BEFORE UserCheckerListener (256), which then sees the
 * normalized identifier. Passport::addBadge() replaces a badge keyed
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
 *
 * Without an exact match, a single member whose stored name equals the input apart from
 * case wins: terminal42/contao-mailusername stores the address with its original case,
 * so "john.doe@…" has to find "John.Doe@…" too. Otherwise the canonical form is tried.
 */
final class EmailAsUsernameLoginListener
{
    public function __construct(
        private readonly ScopeMatcher $scopeMatcher,
        private readonly RequestStack $requestStack,
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
    }

    #[AsEventListener(event: CheckPassportEvent::class, priority: 384)]
    public function __invoke(CheckPassportEvent $event): void
    {
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

        $this->framework->initialize();

        $userAdapter = $this->framework->getAdapter(FrontendUser::class);

        if (null !== $userAdapter->loadUserByIdentifier($identifier)) {
            return; // A member carries the exact identifier – do not shadow it.
        }

        // Same rule the stored login name follows: lowercased, IDN domain as punycode.
        $lower = CanonicalUsername::normalize($identifier);

        // The indexed lookup first; the scan below only for names stored in mixed case.
        if ($lower !== $identifier && null !== $userAdapter->loadUserByIdentifier($lower)) {
            $passport->addBadge(new UserBadge($lower, $badge->getUserLoader()));

            return;
        }

        // username is utf8mb4_bin, a character collation, so LOWER() does apply. No
        // index can serve it; it runs only for a login with "@" that matched nothing above.
        $stored = $this->connection->fetchFirstColumn(
            'SELECT username FROM tl_member WHERE LOWER(username) IN (?) LIMIT 2',
            [array_values(array_unique([$lower, mb_strtolower($identifier)]))],
            [ArrayParameterType::STRING],
        );

        $target = 1 === \count($stored) ? (string) $stored[0] : $lower;

        if ($target !== $identifier) {
            $passport->addBadge(new UserBadge($target, $badge->getUserLoader()));
        }
    }
}

<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Controller;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FrontendUser;
use Doctrine\DBAL\Connection;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernameChangeSync;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor\EmailChangeAnchorPolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\OptIn\UnconfirmedTokenPurger;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A8: consumes the "revoke via the old address" security anchor that
 * ConfirmEmailChangeController creates (or keeps, per the chain rule) on a confirmed
 * email change.
 *
 * GET renders ONLY a confirmation form and never touches the database – so a mail
 * scanner or link-preview fetch that follows the link stays completely inert, and the
 * page itself never reveals whether a token is valid, expired or already used. POST
 * re-checks everything from scratch under a row lock and a transaction (the anchor may
 * have expired, been consumed by a parallel request, or the address it restores may
 * meanwhile belong to someone else) and applies the change atomically. Every failure
 * shows the exact same generic message and HTTP status.
 */
#[AsController]
class RevokeEmailChangeController
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly UsernameChangeSync $usernameChangeSync,
        private readonly UnconfirmedTokenPurger $tokenPurger,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    #[Route(
        '/confirm-email-change/revoke/{token}',
        name: 'mandrael_revoke_member_email_change',
        defaults: ['_scope' => ContaoCoreBundle::SCOPE_FRONTEND],
        requirements: ['token' => '[a-f0-9]{64}'],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $token): Response
    {
        $this->framework->initialize();

        $response = $request->isMethod('POST') ? $this->revoke($token) : $this->confirmationForm($token);

        // A page that can restore account access must never be cached, shared or
        // indexed – regardless of which branch above produced it.
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    /**
     * GET: a static form with no database lookup at all. Validating the token here
     * too would (a) let a mail scanner's GET request reveal, via timing or content,
     * whether a link is still valid, and (b) buys nothing – POST re-validates
     * everything anyway. So the same form renders unconditionally.
     */
    private function confirmationForm(string $token): Response
    {
        $enc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $action = $enc($this->requestStack->getCurrentRequest()?->getUri() ?? '');
        $tokenField = $enc($token);
        $requestToken = $enc($this->csrfTokenManager->getDefaultTokenValue());
        $button = $enc($this->trans('revokeButton'));

        $body = <<<HTML
            <form method="post" action="{$action}">
                <input type="hidden" name="token" value="{$tokenField}">
                <input type="hidden" name="REQUEST_TOKEN" value="{$requestToken}">
                <button type="submit">{$button}</button>
            </form>
            HTML;

        return $this->page('revokeFormTitle', 'revokeFormText', $body, Response::HTTP_OK);
    }

    /**
     * POST: everything happens under a SELECT ... FOR UPDATE row lock inside one
     * transaction, using raw DBAL rather than the Model layer, so a concurrent
     * request (e.g. the same link opened twice) can never interleave with this one.
     */
    private function revoke(string $token): Response
    {
        $hash = EmailChangeAnchorPolicy::hashToken($token);

        $this->connection->beginTransaction();

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT id, email, username, emailChangeAnchorHash, emailChangeAnchorEmail, emailChangeAnchorExpires FROM tl_member WHERE emailChangeAnchorHash = ? FOR UPDATE',
                [$hash],
            );

            if (
                false === $row
                || !EmailChangeAnchorPolicy::matchesToken((string) $row['emailChangeAnchorHash'], $token)
                || !EmailChangeAnchorPolicy::hasValidAnchor((string) $row['emailChangeAnchorHash'], (int) $row['emailChangeAnchorExpires'], time())
            ) {
                $this->connection->rollBack();

                return $this->invalidPage();
            }

            $memberId = (int) $row['id'];
            $restoredEmail = (string) $row['emailChangeAnchorEmail'];

            $conflict = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM tl_member WHERE email = ? AND id != ?',
                [$restoredEmail, $memberId],
            );

            if ($conflict > 0) {
                $this->connection->rollBack();
                $this->logger?->warning(\sprintf('Email-change revoke for member ID %d aborted: the restored address is meanwhile taken by another member.', $memberId));

                return $this->invalidPage();
            }

            // Same sync as ConfirmEmailChangeController, just backwards: the
            // "current" email is the (possibly compromised) address being replaced,
            // the "new" one is the address being restored.
            $newUsername = $this->usernameChangeSync->resolve(
                (string) ($row['username'] ?? ''),
                (string) $row['email'],
                $restoredEmail,
                $memberId,
            ) ?? (string) $row['username'];

            $this->connection->executeStatement(
                'UPDATE tl_member SET email = ?, username = ?, password = ?, emailChangeAnchorHash = ?, emailChangeAnchorEmail = ?, emailChangeAnchorExpires = 0, tstamp = ? WHERE id = ?',
                [$restoredEmail, $newUsername, $this->invalidatedPasswordHash(), '', '', time(), $memberId],
            );

            // The account just changed hands (back) – kill whatever password-reset /
            // email-change / access-setup token happens to be pending too, not just
            // this anchor.
            $this->tokenPurger->purgeAll($memberId);

            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        $response = $this->page('revokeSuccessTitle', 'revokeSuccess', '', Response::HTTP_OK);

        // Only log out if the browser submitting THIS request happens to be
        // authenticated as the very member being restored – never anyone else's
        // session (unlike the confirm controller, the person clicking a mailed link
        // is not necessarily the one currently browsing in this browser).
        $user = $this->security->getUser();

        if ($user instanceof FrontendUser && (int) $user->id === $memberId) {
            $this->carryOverLogoutCookies($this->security->logout(false), $response);
        }

        return $response;
    }

    /**
     * Not a hash format any of Contao's chained password hashers (native → sodium →
     * pbkdf2/message-digest, see Symfony's MigratingPasswordHasher behind the "auto"
     * password_hashers config) recognizes, so every ->verify() call in the chain
     * safely returns false instead of throwing (confirmed against
     * NativePasswordHasher::verify() and SodiumPasswordHasher::verify(), both fall
     * back to a plain password_verify() for a non-"$argon"/"$2" string, which PHP
     * itself defines as returning false for an unrecognized hash). Login by password
     * becomes impossible without touching tl_member.login, which stays operator-owned.
     */
    private function invalidatedPasswordHash(): string
    {
        return 'invalidated$'.bin2hex(random_bytes(32));
    }

    private function invalidPage(): Response
    {
        return $this->page('revokeErrorTitle', 'revokeInvalid', '', Response::HTTP_BAD_REQUEST);
    }

    private function page(string $titleKey, string $textKey, string $bodyExtraHtml, int $status): Response
    {
        $enc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $lang = $enc($this->locale());
        $title = $enc($this->trans($titleKey));
        $text = $enc($this->trans($textKey));
        $back = $enc($this->trans('backToSite'));
        $home = $enc(($this->requestStack->getCurrentRequest()?->getBasePath() ?? '').'/');

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="{$lang}">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="robots" content="noindex">
                <title>{$title}</title>
                <style>
                    body { font-family: system-ui, sans-serif; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f4f4f5; color: #18181b; }
                    main { max-width: 32rem; padding: 2.5rem; margin: 1rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); text-align: center; }
                    h1 { font-size: 1.4rem; margin: 0 0 .75rem; }
                    p { line-height: 1.5; margin: 0 0 1.25rem; }
                    a { color: #2563eb; text-decoration: none; font-weight: 600; }
                    a:hover { text-decoration: underline; }
                    button { font: inherit; padding: .6rem 1.4rem; border: 0; border-radius: .4rem; background: #2563eb; color: #fff; cursor: pointer; }
                </style>
            </head>
            <body>
                <main>
                    <h1>{$title}</h1>
                    <p>{$text}</p>
                    {$bodyExtraHtml}
                    <p><a href="{$home}">{$back}</a></p>
                </main>
            </body>
            </html>
            HTML;

        return new Response($html, $status);
    }

    private function locale(): string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale() ?: 'en';
    }

    private function trans(string $key): string
    {
        return $this->translator->trans('MSC.confirmEmailChange.'.$key, [], 'contao_default');
    }

    /**
     * logout() builds its own response carrying the cookie-clearing headers
     * (remember-me deletion, cleared session cookie). We render our own page
     * instead, so move those cookies onto it – otherwise they are lost.
     */
    private function carryOverLogoutCookies(?Response $logoutResponse, Response $response): void
    {
        foreach ($logoutResponse?->headers->getCookies() ?? [] as $cookie) {
            $response->headers->setCookie($cookie);
        }
    }
}

<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Controller;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\OptIn\OptIn;
use Contao\CoreBundle\OptIn\OptInTokenAlreadyConfirmedException;
use Contao\CoreBundle\OptIn\OptInTokenInterface;
use Contao\CoreBundle\OptIn\OptInTokenNoLongerValidException;
use Contao\Email;
use Contao\FrontendUser;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernameChangeSync;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor\AnchorNotice;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor\EmailChangeAnchorPolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\OptIn\UnconfirmedTokenPurger;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Consumes the double-opt-in token: confirms it, writes the new email and – when
 * an email-as-username extension is active – keeps tl_member.username in sync.
 *
 * It responds with a small self-contained page rather than redirecting into the
 * site: the identifier just changed, so the member's current session is stale, and
 * rendering a themed Contao page (whose modules may resolve the front-end user)
 * could fail. A standalone response is always safe and shows clear feedback.
 */
#[AsController]
class ConfirmEmailChangeController
{
    private const PREFIX = 'email';

    /**
     * Core password-reset opt-in prefix (ModuleLostPassword) – revoked per A6 once an
     * email change is confirmed, so the old address can no longer complete a reset.
     */
    private const REVOKE_ON_CONFIRM_PREFIXES = ['pw', 'mdacc'];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly OptIn $optIn,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly UsernameChangeSync $usernameChangeSync,
        private readonly UnconfirmedTokenPurger $tokenPurger,
        private readonly Connection $connection,
        private readonly AnchorNotice $anchorNotice,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    #[Route(
        '/confirm-email-change/{token}',
        name: 'mandrael_confirm_member_email_change',
        defaults: ['_scope' => ContaoCoreBundle::SCOPE_FRONTEND, '_token_check' => false],
        requirements: ['token' => '[a-zA-Z0-9\-]+'],
    )]
    public function __invoke(string $token): Response
    {
        $this->framework->initialize();

        $optInToken = $this->optIn->find($token);

        // Only ever consume tokens that THIS bundle issued (prefix "email-").
        if (null === $optInToken || !str_starts_with($optInToken->getIdentifier(), self::PREFIX.'-')) {
            return $this->page('invalid', true);
        }

        // Cheap pre-checks so an obviously dead link never even opens a transaction.
        // Everything that decides the outcome is read AGAIN under the row lock below.
        if ($optInToken->isConfirmed()) {
            return $this->page('alreadyConfirmed', false);
        }

        if (!$optInToken->isValid()) {
            return $this->page('expired', true);
        }

        $memberId = (int) ($optInToken->getRelatedRecords()['tl_member'][0] ?? 0);

        if ($memberId < 1) {
            return $this->page('invalid', true);
        }

        try {
            $outcome = $this->confirmUnderLock($optInToken, $memberId);
        } catch (\Throwable $e) {
            // Roll back only while the transaction is still open, and never leave a
            // technical failure looking like a half-applied change.
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $this->logger?->error(\sprintf('Email change confirmation for member ID %d failed with %s.', $memberId, $e::class));

            return $this->page('invalid', true);
        }

        if (null !== $outcome['page']) {
            return $this->page($outcome['page'], $outcome['error']);
        }

        return $this->afterCommit($memberId, $outcome);
    }

    /**
     * The protocol shared with RevokeEmailChangeController: open the transaction, take
     * the member row lock FIRST, then read everything the decision rests on again. A
     * MemberModel sitting in Contao's registry would still carry the state from before
     * the lock, so all reads go through DBAL – and a locking read is by definition a
     * fresh read of the committed row, which makes it lock and re-read in one step.
     * Contao's own Model layer uses this very connection (core Database.php:64-66), so
     * the token purge below joins the same transaction.
     *
     * Deliberately NO named GET_LOCK here: the directory bundle takes one BEFORE this
     * row lock, so taking one here too would create the opposite lock order.
     *
     * The member/token relation itself is re-verified from the freshly locked tl_opt_in
     * row too (Runde 2, Hinweis) – $memberId is still the one read before the lock, only
     * used to acquire it, never trusted for the write below without this re-check.
     *
     * @return array{page: string|null, error: bool, oldEmail: string, revokeToken: string|null, usernameChanged: bool}
     */
    private function confirmUnderLock(OptInTokenInterface $optInToken, int $memberId): array
    {
        $this->connection->beginTransaction();

        $fail = function (string $page, bool $error): array {
            $this->connection->rollBack();

            return ['page' => $page, 'error' => $error, 'oldEmail' => '', 'revokeToken' => null, 'usernameChanged' => false];
        };

        $member = $this->connection->fetchAssociative(
            'SELECT id, email, username, emailChangeAnchorHash, emailChangeAnchorExpires FROM tl_member WHERE id = ? FOR UPDATE',
            [$memberId],
        );

        if (false === $member) {
            return $fail('invalid', true);
        }

        // The token object was loaded BEFORE the lock. Re-read its row, locking, so two
        // parallel confirmations of the same link cannot both get past this point.
        $tokenRow = $this->connection->fetchAssociative(
            'SELECT confirmedOn, invalidatedThrough, createdOn, email, relatedRecords FROM tl_opt_in WHERE token = ? FOR UPDATE',
            [$optInToken->getIdentifier()],
        );

        if (false === $tokenRow) {
            return $fail('invalid', true);
        }

        if ((int) $tokenRow['confirmedOn'] > 0) {
            return $fail('alreadyConfirmed', false);
        }

        // Same rule as OptInToken::isValid() (core 5.3 OptInToken.php:37-40).
        if ('' !== (string) $tokenRow['invalidatedThrough'] || (int) $tokenRow['createdOn'] <= strtotime('-24 hours')) {
            return $fail('expired', true);
        }

        // Runde 2, Hinweis (Codex): $memberId above came from $optInToken->getRelatedRecords(),
        // read BEFORE the lock. Re-derive it from the SAME locked row the checks above just
        // read, so the relation this confirmation acts on is verified fresh, not trusted from
        // before the lock was even acquired.
        $relatedRecords = $this->framework->getAdapter(StringUtil::class)->deserialize($tokenRow['relatedRecords'] ?? null, true);

        if ($memberId !== (int) ($relatedRecords['tl_member'][0] ?? 0)) {
            return $fail('invalid', true);
        }

        $newEmail = (string) $tokenRow['email'];

        if ('' === $newEmail) {
            return $fail('invalid', true);
        }

        // tl_member.email is DCA-unique but not DB-unique (the core allows duplicates),
        // so the address can have been taken while this token was pending.
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_member WHERE email = ? AND id != ?', [$newEmail, $memberId]) > 0) {
            return $fail('taken', true);
        }

        // A2, "the login name IS the email": if the address can no longer become the
        // login name, the address is not written either. The link stays UNUSED on
        // purpose – the usual cause is a collision an operator can still clear, and
        // burning the member's only link would leave them without a way to finish the
        // change at all. It expires by itself after 24 hours.
        if ($this->usernameChangeSync->rejects($newEmail, $memberId)) {
            return $fail('usernameRejected', true);
        }

        try {
            $optInToken->confirm();
        } catch (OptInTokenNoLongerValidException) {
            return $fail('expired', true);
        } catch (OptInTokenAlreadyConfirmedException) {
            return $fail('alreadyConfirmed', false);
        }

        $oldEmail = (string) $member['email'];
        $oldUsername = (string) ($member['username'] ?? '');
        $newUsername = $this->usernameChangeSync->resolve($oldUsername, $oldEmail, $newEmail, $memberId);
        $usernameChanged = null !== $newUsername && '' !== $newUsername && $newUsername !== $oldUsername;

        // tl_member.username carries a UNIQUE index and is nullable, so it is only ever
        // written with a real value – never blanked back to an empty string.
        if ($usernameChanged) {
            $this->connection->executeStatement(
                'UPDATE tl_member SET email = ?, username = ?, tstamp = ? WHERE id = ?',
                [$newEmail, $newUsername, time(), $memberId],
            );
        } else {
            $this->connection->executeStatement(
                'UPDATE tl_member SET email = ?, tstamp = ? WHERE id = ?',
                [$newEmail, time(), $memberId],
            );
        }

        // A8: a security anchor lets the OLD address undo the change on its own. An
        // existing, still-valid anchor stays UNTOUCHED (chain rule) – only the oldest
        // valid anchor may ever point back further than one hop, otherwise an attacker
        // who just took the account over could overwrite it with a second change and
        // erase the real owner's way back in. Under the row lock this holds for two
        // parallel confirmations as well: the second one reads the anchor the first one
        // committed.
        $revokeToken = null;

        if (!EmailChangeAnchorPolicy::hasValidAnchor((string) $member['emailChangeAnchorHash'], (int) $member['emailChangeAnchorExpires'], time())) {
            $revokeToken = bin2hex(random_bytes(32));

            // emailChangeAnchorNotified stays 0 until the link really went out, see
            // AnchorNotice and ResendEmailChangeAnchorNoticeCron. emailChangeAnchorPending
            // carries the plaintext for exactly that pending window (Runde 2, Befund 2) -
            // writing it here also naturally replaces whatever an earlier, since-expired
            // anchor may have left behind.
            $this->connection->executeStatement(
                'UPDATE tl_member SET emailChangeAnchorHash = ?, emailChangeAnchorEmail = ?, emailChangeAnchorExpires = ?, emailChangeAnchorNotified = 0, emailChangeAnchorPending = ? WHERE id = ?',
                [EmailChangeAnchorPolicy::hashToken($revokeToken), $oldEmail, time() + EmailChangeAnchorPolicy::TTL_SECONDS, $revokeToken, $memberId],
            );
        }

        // A6: the recovery channel just moved to the new address – a still-open core
        // password-reset link for the OLD address must not be able to complete a reset.
        foreach (self::REVOKE_ON_CONFIRM_PREFIXES as $prefix) {
            $this->tokenPurger->purge($memberId, $prefix);
        }

        $this->connection->commit();

        return ['page' => null, 'error' => false, 'oldEmail' => $oldEmail, 'revokeToken' => $revokeToken, 'usernameChanged' => $usernameChanged];
    }

    /**
     * Everything after the commit. The change IS applied at this point, so no failure
     * in here may present it as a failed confirmation.
     *
     * @param array{page: string|null, error: bool, oldEmail: string, revokeToken: string|null, usernameChanged: bool} $outcome
     */
    private function afterCommit(int $memberId, array $outcome): Response
    {
        $response = $this->page('success', false);

        try {
            if (null !== $outcome['revokeToken']) {
                // AnchorNotice keeps emailChangeAnchorNotified at 0 if the mail fails.
                $this->anchorNotice->send($memberId, $outcome['oldEmail'], $outcome['revokeToken']);
            } else {
                $this->notifyChainedAnchor($outcome['oldEmail']);
            }

            // When the login identifier changed, the member's current session points at
            // a username that no longer exists → log them out.
            if ($outcome['usernameChanged']) {
                $this->carryOverLogoutCookies($this->logoutFrontendUser(), $response);
            }
        } catch (\Throwable $e) {
            $this->logger?->error(\sprintf('Follow-up work after a confirmed email change for member ID %d failed with %s; the change itself is applied.', $memberId, $e::class));
        }

        return $response;
    }

    /**
     * A8, chain case: an older, still valid anchor was kept, so this old address gets
     * the same wording WITHOUT a link – it might belong to the attacker.
     */
    private function notifyChainedAnchor(string $oldEmail): void
    {
        $email = $this->framework->createInstance(Email::class);
        $email->from = $GLOBALS['TL_ADMIN_EMAIL'] ?? null;
        $email->fromName = $GLOBALS['TL_ADMIN_NAME'] ?? null;
        $email->subject = $this->trans('confirmEmailChange.revokeNoticeSubject');
        $email->text = $this->trans('confirmEmailChange.revokeNoticeChainedText');
        $email->sendTo($oldEmail);
    }

    private function logoutFrontendUser(): ?Response
    {
        // The identifier changed → log the current member out via the security
        // helper so they re-authenticate with the new address. A stale session token
        // would otherwise reference a username that no longer exists.
        if ($this->security->getUser() instanceof FrontendUser) {
            return $this->security->logout(false);
        }

        return null;
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

    private function page(string $key, bool $isError): Response
    {
        $enc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $lang = $enc($this->locale());
        $title = $enc($this->trans($isError ? 'confirmEmailChange.errorTitle' : 'confirmEmailChange.successTitle'));
        $text = $enc($this->trans('confirmEmailChange.'.$key));
        $back = $enc($this->trans('confirmEmailChange.backToSite'));
        // Base path of the app, so the back link stays inside a Contao install
        // hosted under a sub-path (empty for a domain-root install → "/").
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
                </style>
            </head>
            <body>
                <main>
                    <h1>{$title}</h1>
                    <p>{$text}</p>
                    <p><a href="{$home}">{$back}</a></p>
                </main>
            </body>
            </html>
            HTML;

        $response = new Response($html, $isError ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK);

        // The URL carries the token – never cache, share or index this page.
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    private function locale(): string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale() ?: 'en';
    }

    private function trans(string $key): string
    {
        return $this->translator->trans('MSC.'.$key, [], 'contao_default');
    }
}

<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Controller;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\OptIn\OptIn;
use Contao\CoreBundle\OptIn\OptInTokenAlreadyConfirmedException;
use Contao\CoreBundle\OptIn\OptInTokenNoLongerValidException;
use Contao\Email;
use Contao\FrontendUser;
use Contao\MemberModel;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernameChangeSync;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor\EmailChangeAnchorPolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\OptIn\UnconfirmedTokenPurger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
        private readonly UrlGeneratorInterface $urlGenerator,
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

        // Non-mutating pre-checks so a token is never marked confirmed before we
        // know the change can actually be applied (member gone / address taken).
        if ($optInToken->isConfirmed()) {
            return $this->page('alreadyConfirmed', false);
        }

        if (!$optInToken->isValid()) {
            return $this->page('expired', true);
        }

        $related = $optInToken->getRelatedRecords();
        $memberId = (int) ($related['tl_member'][0] ?? 0);
        $newEmail = $optInToken->getEmail();

        $memberAdapter = $this->framework->getAdapter(MemberModel::class);
        $member = $memberAdapter->findByPk($memberId);

        if (null === $member) {
            return $this->page('invalid', true);
        }

        // Re-validate uniqueness at confirm time: two members could have requested
        // the same new address while both tokens were pending. This is a best-effort
        // check, matching Contao's own guarantee – tl_member.email is DCA-unique but
        // not DB-unique (core allows duplicate emails), so a rare parallel double
        // confirm of the same address can still slip through. A DB UNIQUE constraint
        // is deliberately not added: it would break real installs with duplicates.
        if (null !== $memberAdapter->findOneBy(['email=?', 'id!=?'], [$newEmail, $member->id])) {
            return $this->page('taken', true);
        }

        // Everything validated → now consume the token and persist. The exceptions
        // still guard against a race between the pre-checks above and confirm().
        try {
            $optInToken->confirm();
        } catch (OptInTokenNoLongerValidException) {
            return $this->page('expired', true);
        } catch (OptInTokenAlreadyConfirmedException) {
            return $this->page('alreadyConfirmed', false);
        }

        $usernameChanged = $this->syncUsername($member, $newEmail);
        $oldEmail = (string) $member->email;

        // A8: a security anchor lets the OLD address undo the change on its own. Keep
        // an existing, still-valid anchor UNTOUCHED (chain rule) – only the oldest
        // valid anchor may ever point back further than one hop, otherwise an
        // attacker who just took over the account could overwrite it with a second
        // change and erase the real owner's own way back in.
        $revokeToken = null;

        if (!EmailChangeAnchorPolicy::hasValidAnchor((string) $member->emailChangeAnchorHash, (int) $member->emailChangeAnchorExpires, time())) {
            $revokeToken = bin2hex(random_bytes(32));
            $member->emailChangeAnchorHash = EmailChangeAnchorPolicy::hashToken($revokeToken);
            $member->emailChangeAnchorEmail = $oldEmail;
            $member->emailChangeAnchorExpires = time() + EmailChangeAnchorPolicy::TTL_SECONDS;
        }

        $member->email = $newEmail;
        // A programmatic save persists the row but does not bump tstamp on its own,
        // so set it explicitly – matching how the core personal-data save behaves.
        $member->tstamp = time();
        $member->save();

        // A6: the recovery channel just moved to the new address – a still-open core
        // password-reset link for the OLD address must not be able to complete a reset.
        foreach (self::REVOKE_ON_CONFIRM_PREFIXES as $prefix) {
            $this->tokenPurger->purge((int) $member->id, $prefix);
        }

        $this->notifyOldAddressAfterConfirm($oldEmail, $revokeToken);

        $response = $this->page('success', false);

        // When the login identifier (username) changed, the member's current session
        // now points at a username that no longer exists → log them out so they
        // re-authenticate with the new address.
        if ($usernameChanged) {
            $this->carryOverLogoutCookies($this->logoutFrontendUser(), $response);
        }

        return $response;
    }

    /**
     * The programmatic write above does not fire any DCA save_callback, so neither the
     * bundle's own opt-in sync (A2/A3) nor an active email-as-username extension would
     * update the username on its own – both are re-applied here via the shared
     * UsernameChangeSync (also used, in the opposite direction, by
     * RevokeEmailChangeController).
     *
     * @return bool whether the username was changed
     */
    private function syncUsername(MemberModel $member, string $newEmail): bool
    {
        return $this->usernameChangeSync->apply($member, $newEmail);
    }

    /**
     * A8: the second notice to the old address, sent only AFTER confirmation (the
     * request-time notice from EmailChangeListener::notifyOldAddress() stays as-is,
     * without a link). Carries the revoke link when a fresh anchor was just created;
     * when the chain rule (see __invoke()) kept an earlier anchor instead, this old
     * address gets the same wording WITHOUT a link – it might belong to the attacker.
     */
    private function notifyOldAddressAfterConfirm(string $oldEmail, ?string $revokeToken): void
    {
        $email = $this->framework->createInstance(Email::class);
        $email->from = $GLOBALS['TL_ADMIN_EMAIL'] ?? null;
        $email->fromName = $GLOBALS['TL_ADMIN_NAME'] ?? null;
        $email->subject = $this->trans('confirmEmailChange.revokeNoticeSubject');

        if (null !== $revokeToken) {
            $url = $this->urlGenerator->generate(
                'mandrael_revoke_member_email_change',
                ['token' => $revokeToken],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );

            $email->text = \sprintf($this->trans('confirmEmailChange.revokeNoticeText'), EmailChangeAnchorPolicy::ttlDays(), $url);
        } else {
            $email->text = $this->trans('confirmEmailChange.revokeNoticeChainedText');
        }

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

        return new Response($html, $isError ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK);
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

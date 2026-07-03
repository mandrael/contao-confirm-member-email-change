<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Controller;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\OptIn\OptIn;
use Contao\CoreBundle\OptIn\OptInTokenAlreadyConfirmedException;
use Contao\CoreBundle\OptIn\OptInTokenNoLongerValidException;
use Contao\FrontendUser;
use Contao\MemberModel;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Consumes the double-opt-in token: confirms it, writes the new email and — when
 * an email-as-username extension is active — keeps tl_member.username in sync.
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

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly OptIn $optIn,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
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

        try {
            $optInToken->confirm();
        } catch (OptInTokenNoLongerValidException) {
            return $this->page('expired', true);
        } catch (OptInTokenAlreadyConfirmedException) {
            return $this->page('alreadyConfirmed', false);
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
        // the same new address while both tokens were pending.
        if (null !== $memberAdapter->findOneBy(['email=?', 'id!=?'], [$newEmail, $member->id])) {
            return $this->page('taken', true);
        }

        $usernameChanged = $this->syncUsername($member, $newEmail);
        $member->email = $newEmail;
        $member->save();

        // When the login identifier (username) changed, the member's current session
        // now points at a username that no longer exists → log them out so they
        // re-authenticate with the new address.
        if ($usernameChanged) {
            $this->logoutFrontendUser();
        }

        return $this->page('success', false);
    }

    /**
     * The programmatic write above does not fire any DCA save_callback, so an
     * active email-as-username extension would not update the username itself.
     *
     * @return bool whether the username was changed
     */
    private function syncUsername(MemberModel $member, string $newEmail): bool
    {
        // terminal42/contao-mailusername: pure sync, NO login decorator → the
        // username MUST follow (verbatim), otherwise login with the new address breaks.
        if (class_exists(\Terminal42\MailusernameBundle\Terminal42MailusernameBundle::class)) {
            $member->username = $newEmail;

            return true;
        }

        // heimrichhannot/contao-email2username-bundle: login keeps working via its
        // user-provider decorator, so this is cosmetic. It leaves username at
        // varchar(64), so guard the length.
        if (class_exists(\HeimrichHannot\Email2UsernameBundle\HeimrichHannotEmail2UsernameBundle::class)) {
            $lower = mb_strtolower($newEmail);

            if (mb_strlen($lower) <= 64) {
                $member->username = $lower;

                return true;
            }
        }

        return false;
    }

    private function logoutFrontendUser(): void
    {
        // The identifier changed → log the current member out via the security
        // helper so they re-authenticate with the new address. A stale session token
        // would otherwise reference a username that no longer exists.
        if ($this->security->getUser() instanceof FrontendUser) {
            $this->security->logout(false);
        }
    }

    private function page(string $key, bool $isError): Response
    {
        $enc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $lang = $enc($this->locale());
        $title = $enc($this->trans($isError ? 'confirmEmailChange.errorTitle' : 'confirmEmailChange.successTitle'));
        $text = $enc($this->trans('confirmEmailChange.'.$key));
        $back = $enc($this->trans('confirmEmailChange.backToSite'));

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
                    <p><a href="/">{$back}</a></p>
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

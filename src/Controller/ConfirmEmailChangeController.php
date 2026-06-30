<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Controller;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\OptIn\OptIn;
use Contao\CoreBundle\OptIn\OptInTokenAlreadyConfirmedException;
use Contao\CoreBundle\OptIn\OptInTokenNoLongerValidException;
use Contao\MemberModel;
use Contao\Message;
use Contao\PageModel;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Consumes the double-opt-in token: confirms it, writes the new email and — when
 * an email-as-username extension is active — keeps tl_member.username in sync.
 */
#[AsController]
class ConfirmEmailChangeController
{
    private const PREFIX = 'email';

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly OptIn $optIn,
        private readonly TranslatorInterface $translator,
        private readonly int|null $jumpTo = null,
    ) {
    }

    #[Route(
        '/confirm-email-change/{token}',
        name: 'mandrael_confirm_member_email_change',
        defaults: ['_scope' => ContaoCoreBundle::SCOPE_FRONTEND, '_token_check' => false],
        requirements: ['token' => '[a-zA-Z0-9\-]+'],
    )]
    public function __invoke(string $token): RedirectResponse
    {
        $this->framework->initialize();

        $message = $this->framework->getAdapter(Message::class);
        $optInToken = $this->optIn->find($token);

        // Only ever consume tokens that THIS bundle issued (prefix "email-").
        if (null === $optInToken || !str_starts_with($optInToken->getIdentifier(), self::PREFIX.'-')) {
            $message->addError($this->trans('confirmEmailChange.invalid'));

            return $this->redirect();
        }

        try {
            $optInToken->confirm();
        } catch (OptInTokenNoLongerValidException) {
            $message->addError($this->trans('confirmEmailChange.expired'));

            return $this->redirect();
        } catch (OptInTokenAlreadyConfirmedException) {
            $message->addInfo($this->trans('confirmEmailChange.alreadyConfirmed'));

            return $this->redirect();
        }

        $related = $optInToken->getRelatedRecords();
        $memberId = (int) ($related['tl_member'][0] ?? 0);
        $newEmail = $optInToken->getEmail();

        $memberAdapter = $this->framework->getAdapter(MemberModel::class);
        $member = $memberAdapter->findByPk($memberId);

        if (null === $member) {
            $message->addError($this->trans('confirmEmailChange.invalid'));

            return $this->redirect();
        }

        // Re-validate uniqueness at confirm time: two members could have requested
        // the same new address while both tokens were pending.
        if (null !== $memberAdapter->findOneBy(['email=?', 'id!=?'], [$newEmail, $member->id])) {
            $message->addError($this->trans('confirmEmailChange.taken'));

            return $this->redirect();
        }

        $this->syncUsername($member, $newEmail);
        $member->email = $newEmail;
        $member->save();

        $message->addConfirmation($this->trans('confirmEmailChange.success'));

        return $this->redirect();
    }

    /**
     * The programmatic write above does not fire any DCA save_callback, so an
     * active email-as-username extension would not update the username itself.
     */
    private function syncUsername(MemberModel $member, string $newEmail): void
    {
        // terminal42/contao-mailusername: pure sync, NO login decorator → the
        // username MUST follow (verbatim), otherwise login with the new address breaks.
        if (class_exists(\Terminal42\MailusernameBundle\Terminal42MailusernameBundle::class)) {
            $member->username = $newEmail;

            return;
        }

        // heimrichhannot/contao-email2username-bundle: login keeps working via its
        // user-provider decorator, so this is cosmetic. It leaves username at
        // varchar(64), so guard the length.
        if (class_exists(\HeimrichHannot\Email2UsernameBundle\HeimrichHannotEmail2UsernameBundle::class)) {
            $lower = mb_strtolower($newEmail);

            if (mb_strlen($lower) <= 64) {
                $member->username = $lower;
            }
        }
    }

    private function redirect(): RedirectResponse
    {
        $url = '/';

        if (null !== $this->jumpTo) {
            $page = $this->framework->getAdapter(PageModel::class)->findByPk($this->jumpTo);

            if (null !== $page) {
                $url = $page->getAbsoluteUrl();
            }
        }

        return new RedirectResponse($url);
    }

    private function trans(string $key): string
    {
        return $this->translator->trans('MSC.'.$key, [], 'contao_default');
    }
}

<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\EmailAsUsername;

use Contao\Template;
use Contao\TestCase\ContaoTestCase;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EmailAsUsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\LoginLabelListener;
use Symfony\Contracts\Translation\TranslatorInterface;

class LoginLabelListenerTest extends ContaoTestCase
{
    public function testSetsTheLoginLabelWhenTheSwitchIsOnAndTheTemplateIsALoginTemplate(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->with('MSC.confirmEmailChange.loginUsernameLabel', [], 'contao_default')->willReturn('E-Mail-Adresse');

        $template = $this->createMock(Template::class);
        $template->method('getName')->willReturn('mod_login');
        $template->expects(self::once())->method('__set')->with('username', 'E-Mail-Adresse');

        $this->listener(true, $translator)->onParseTemplate($template);
    }

    /**
     * "starts with mod_login", not "equals mod_login" - a themed/overridden template
     * variant must be caught too.
     */
    public function testAlsoMatchesATemplateNameThatOnlyStartsWithModLogin(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('E-Mail-Adresse');

        $template = $this->createMock(Template::class);
        $template->method('getName')->willReturn('mod_login_2fa');
        $template->expects(self::once())->method('__set')->with('username', 'E-Mail-Adresse');

        $this->listener(true, $translator)->onParseTemplate($template);
    }

    public function testLeavesUnrelatedTemplatesUntouched(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::never())->method('trans');

        $template = $this->createMock(Template::class);
        $template->method('getName')->willReturn('mod_article');
        $template->expects(self::never())->method('__set');

        $this->listener(true, $translator)->onParseTemplate($template);
    }

    /**
     * A1: proves the switch off leaves the global core behaviour untouched.
     */
    public function testDoesNothingWhenTheSwitchIsOff(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::never())->method('trans');

        $template = $this->createMock(Template::class);
        $template->method('getName')->willReturn('mod_login');
        $template->expects(self::never())->method('__set');

        $this->listener(false, $translator)->onParseTemplate($template);
    }

    private function listener(bool $enabled, TranslatorInterface $translator): LoginLabelListener
    {
        $policy = $this->createMock(EmailAsUsernamePolicy::class);
        $policy->method('isEnabled')->willReturn($enabled);

        return new LoginLabelListener($policy, $translator);
    }
}

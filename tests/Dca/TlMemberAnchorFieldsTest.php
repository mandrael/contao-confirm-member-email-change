<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\Dca;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Codex 1 (Runde 2, blockierend): the core's DC_Table::copy() copies every field that
 * lacks eval.doNotCopy verbatim - including a still-valid revoke anchor. Two members
 * would then answer to the same anchor hash until one of them is edited. All five
 * anchor fields (see AnchorNotice/EmailChangeAnchorPolicy) must therefore reset to
 * their SQL default on copy.
 */
class TlMemberAnchorFieldsTest extends TestCase
{
    /**
     * @return list<string>
     */
    public static function anchorFieldsProvider(): array
    {
        return [
            ['emailChangeAnchorHash'],
            ['emailChangeAnchorEmail'],
            ['emailChangeAnchorExpires'],
            ['emailChangeAnchorNotified'],
            ['emailChangeAnchorPending'],
        ];
    }

    #[DataProvider('anchorFieldsProvider')]
    public function testAnchorFieldIsMarkedDoNotCopy(string $field): void
    {
        $GLOBALS['TL_DCA'] = [];
        require __DIR__.'/../../contao/dca/tl_member.php';

        self::assertTrue(
            $GLOBALS['TL_DCA']['tl_member']['fields'][$field]['eval']['doNotCopy'] ?? false,
            \sprintf('tl_member.%s must carry eval.doNotCopy so DC_Table::copy() resets it to the SQL default', $field),
        );
    }

    /**
     * The same five fields carry a plaintext token while a send is pending, so they
     * must neither be versioned (Versions::create() would serialize the plaintext into
     * tl_version) nor shown (DC_Table::show()'s detail view).
     */
    #[DataProvider('anchorFieldsProvider')]
    public function testAnchorFieldIsNeitherVersionizedNorShown(string $field): void
    {
        $GLOBALS['TL_DCA'] = [];
        require __DIR__.'/../../contao/dca/tl_member.php';

        $eval = $GLOBALS['TL_DCA']['tl_member']['fields'][$field]['eval'] ?? [];

        self::assertSame(false, $eval['versionize'] ?? null, \sprintf('tl_member.%s must carry eval.versionize === false', $field));
        self::assertSame(true, $eval['doNotShow'] ?? null, \sprintf('tl_member.%s must carry eval.doNotShow === true', $field));
    }
}

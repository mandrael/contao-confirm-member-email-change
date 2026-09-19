<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests;

use PHPUnit\Framework\TestCase;

class LanguageFilesTest extends TestCase
{
    public function testGermanAndEnglishKeysMatch(): void
    {
        $de = $this->loadKeys(__DIR__.'/../contao/languages/de/default.php', 'MSC', 'confirmEmailChange');
        $en = $this->loadKeys(__DIR__.'/../contao/languages/en/default.php', 'MSC', 'confirmEmailChange');

        self::assertNotEmpty($de);
        self::assertSame($de, $en, 'German and English language keys must be identical');
    }

    public function testGermanAndEnglishSettingsKeysMatch(): void
    {
        $de = $this->loadKeys(__DIR__.'/../contao/languages/de/tl_settings.php', 'tl_settings');
        $en = $this->loadKeys(__DIR__.'/../contao/languages/en/tl_settings.php', 'tl_settings');

        self::assertNotEmpty($de);
        self::assertSame($de, $en, 'German and English tl_settings language keys must be identical');
    }

    /**
     * @return array<int, string>
     */
    private function loadKeys(string $file, string ...$path): array
    {
        $GLOBALS['TL_LANG'] = [];
        include $file;

        $node = $GLOBALS['TL_LANG'];

        foreach ($path as $segment) {
            $node = $node[$segment] ?? [];
        }

        $keys = array_keys($node);
        sort($keys);

        return $keys;
    }
}

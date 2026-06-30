<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests;

use PHPUnit\Framework\TestCase;

class LanguageFilesTest extends TestCase
{
    public function testGermanAndEnglishKeysMatch(): void
    {
        $de = $this->loadKeys(__DIR__.'/../contao/languages/de/default.php');
        $en = $this->loadKeys(__DIR__.'/../contao/languages/en/default.php');

        self::assertNotEmpty($de);
        self::assertSame($de, $en, 'German and English language keys must be identical');
    }

    /**
     * @return array<int, string>
     */
    private function loadKeys(string $file): array
    {
        $GLOBALS['TL_LANG'] = [];
        include $file;

        $keys = array_keys($GLOBALS['TL_LANG']['MSC']['confirmEmailChange'] ?? []);
        sort($keys);

        return $keys;
    }
}

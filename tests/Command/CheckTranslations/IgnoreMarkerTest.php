<?php

namespace Efabrica\TranslationsAutomatization\Tests\Command\CheckTranslations;

use Efabrica\TranslationsAutomatization\Command\CheckTranslations\CheckTranslationsDeepCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class IgnoreMarkerTest extends TestCase
{
    /**
     * @var string[]
     */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->temporaryFiles = [];
    }

    public function testNewIgnoreTranslationMarkerOnPreviousLine(): void
    {
        $file = $this->createTemplate("{* @Ignore translation *}\n<strong>Typ:</strong>");

        $this->assertTrue($this->isHardcodedTextIgnored($file, 2));
    }

    public function testLegacyIgnoreDeepCheckMarkerStillWorks(): void
    {
        $file = $this->createTemplate("{* @Ignore transka deep check *}\n<strong>Typ:</strong>");

        $this->assertTrue($this->isHardcodedTextIgnored($file, 2));
    }

    public function testMarkerOnSameLineAlsoWorks(): void
    {
        $file = $this->createTemplate('<strong>Typ:</strong> {* @Ignore translation *}');

        $this->assertTrue($this->isHardcodedTextIgnored($file, 1));
    }

    public function testWithoutMarkerNotIgnored(): void
    {
        $file = $this->createTemplate("<div>iny obsah</div>\n<strong>Typ:</strong>");

        $this->assertFalse($this->isHardcodedTextIgnored($file, 2));
    }

    public function testCallIgnoredByCodeAcceptsBothMarkers(): void
    {
        $file = $this->createTemplate("{* @Ignore translation *}\n{\$dynamicKey|translate}");

        $this->assertTrue($this->isIgnoredByCode($file, 2));
    }

    private function isHardcodedTextIgnored(string $file, int $line): bool
    {
        $command = new CheckTranslationsDeepCommand();
        $method = (new ReflectionClass($command))->getMethod('isHardcodedTextIgnored');
        $method->setAccessible(true);
        return (bool) $method->invoke($command, ['file' => $file, 'line' => $line, 'text' => 'Typ:']);
    }

    private function isIgnoredByCode(string $file, int $line): bool
    {
        $command = new CheckTranslationsDeepCommand();
        $method = (new ReflectionClass($command))->getMethod('isIgnoredByCode');
        $method->setAccessible(true);
        return (bool) $method->invoke($command, ['file' => $file, 'line' => $line]);
    }

    private function createTemplate(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'ignore_marker_') . '.latte';
        file_put_contents($file, $contents);
        $this->temporaryFiles[] = $file;
        return $file;
    }
}

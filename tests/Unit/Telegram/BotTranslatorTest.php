<?php

declare(strict_types=1);

namespace App\Tests\Unit\Telegram;

use App\Telegram\BotTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

final class BotTranslatorTest extends TestCase
{
    private const string TRANSLATIONS_DIR = __DIR__.'/../../../translations';

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function languageCodes(): iterable
    {
        yield 'german' => ['de', 'de'];
        yield 'russian with region' => ['ru-RU', 'ru'];
        yield 'english with region' => ['en-GB', 'en'];
        yield 'unsupported language' => ['fr', 'en'];
        yield 'missing language' => [null, 'en'];
    }

    #[DataProvider('languageCodes')]
    public function testResolvesTelegramLanguageCode(?string $languageCode, string $expected): void
    {
        self::assertSame($expected, BotTranslator::resolveLocale($languageCode));
    }

    public function testTranslatesInTheUserLanguageAndRestoresTheLocale(): void
    {
        $translator = $this->translator();
        $bot = new BotTranslator($translator);

        $text = $bot->inLanguage('de', static fn (): string => $bot->trans('dispatcher.cancelled'));

        self::assertSame('Aktion abgebrochen.', $text);
        self::assertSame('en', $translator->getLocale());
        self::assertSame('Action cancelled.', $bot->trans('dispatcher.cancelled'));
    }

    public function testRestoresTheLocaleWhenTheCallbackFails(): void
    {
        $translator = $this->translator();
        $bot = new BotTranslator($translator);

        try {
            $bot->inLanguage('ru', static fn () => throw new \RuntimeException('handler failed'));
        } catch (\RuntimeException $e) {
            self::assertSame('handler failed', $e->getMessage());
        }

        self::assertSame('en', $translator->getLocale());
    }

    public function testMonthLabelIsLocalised(): void
    {
        $bot = new BotTranslator($this->translator());
        $date = new \DateTimeImmutable('2026-03-15');

        self::assertSame('March 2026', $bot->monthLabel($date));
        self::assertSame('Март 2026', $bot->inLanguage('ru', static fn (): string => $bot->monthLabel($date)));
        self::assertSame('März 2026', $bot->inLanguage('de', static fn (): string => $bot->monthLabel($date)));
    }

    public function testAllLanguagesDefineTheSameKeys(): void
    {
        $english = $this->keys('en');

        foreach (['ru', 'de'] as $locale) {
            self::assertSame($english, $this->keys($locale), "translations/bot.$locale.yaml differs from bot.en.yaml");
        }
    }

    private function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (BotTranslator::SUPPORTED_LOCALES as $locale) {
            $translator->addResource('yaml', self::TRANSLATIONS_DIR."/bot.$locale.yaml", $locale, BotTranslator::DOMAIN);
        }

        return $translator;
    }

    /**
     * @return list<string>
     */
    private function keys(string $locale): array
    {
        $messages = Yaml::parseFile(self::TRANSLATIONS_DIR."/bot.$locale.yaml");
        self::assertIsArray($messages);
        $keys = array_keys($this->flatten($messages));
        sort($keys);

        return $keys;
    }

    /**
     * @param array<mixed> $messages
     *
     * @return array<string, mixed>
     */
    private function flatten(array $messages, string $prefix = ''): array
    {
        $flat = [];
        foreach ($messages as $key => $value) {
            if (\is_array($value)) {
                $flat += $this->flatten($value, $prefix.$key.'.');
            } else {
                $flat[$prefix.$key] = $value;
            }
        }

        return $flat;
    }
}

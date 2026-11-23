<?php

declare(strict_types=1);

namespace App\Telegram;

use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Translates bot texts (domain "bot") in the language of the Telegram user who sent the update.
 */
class BotTranslator
{
    public const string DOMAIN = 'bot';
    public const array SUPPORTED_LOCALES = ['en', 'ru', 'de'];
    public const string DEFAULT_LOCALE = 'en';

    private readonly LocaleAwareInterface $localeAware;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
        if (!$translator instanceof LocaleAwareInterface) {
            throw new \LogicException(\sprintf('The translator must implement %s.', LocaleAwareInterface::class));
        }

        $this->localeAware = $translator;
    }

    /**
     * @param array<string, string|int|float> $parameters
     */
    public function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, self::DOMAIN);
    }

    /**
     * Maps a Telegram language_code ("de", "ru-RU", "en-GB", null) to a supported locale.
     */
    public static function resolveLocale(?string $languageCode): string
    {
        $language = strtolower(substr((string) $languageCode, 0, 2));

        return \in_array($language, self::SUPPORTED_LOCALES, true) ? $language : self::DEFAULT_LOCALE;
    }

    /**
     * Runs $callback with the translator switched to the given Telegram language and restores the locale afterwards.
     *
     * @template T
     *
     * @param \Closure(): T $callback
     *
     * @return T
     */
    public function inLanguage(?string $languageCode, \Closure $callback): mixed
    {
        $previous = $this->localeAware->getLocale();
        $this->localeAware->setLocale(self::resolveLocale($languageCode));

        try {
            return $callback();
        } finally {
            $this->localeAware->setLocale($previous);
        }
    }

    /**
     * Month and year in the current locale, e.g. "November 2026" / "Ноябрь 2026".
     *
     * @phpstan-impure depends on the translator locale
     */
    public function monthLabel(\DateTimeInterface $date): string
    {
        $formatter = new \IntlDateFormatter(
            $this->localeAware->getLocale(),
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            $date->getTimezone(),
            null,
            'LLLL yyyy',
        );
        $label = $formatter->format($date);
        if (false === $label) {
            throw new \RuntimeException('Cannot format month label: '.$formatter->getErrorMessage());
        }

        return mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1);
    }
}

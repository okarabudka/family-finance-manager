<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applies the UI language chosen via the locale switcher (stored in the session) to every request.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public const string SESSION_KEY = '_locale';

    public static function getSubscribedEvents(): array
    {
        // Must run before the LocaleListener (priority 16) so the translator picks the locale up.
        return [KernelEvents::REQUEST => [['onKernelRequest', 20]]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->hasPreviousSession()) {
            return;
        }

        $locale = $request->getSession()->get(self::SESSION_KEY);
        if (\is_string($locale) && !$request->attributes->has('_locale')) {
            $request->setLocale($locale);
        }
    }
}

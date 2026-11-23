<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\EventSubscriber\LocaleSubscriber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class LocaleController extends AbstractController
{
    #[Route('/locale/{_locale}', name: 'app_locale', requirements: ['_locale' => 'en|ru|de'], methods: ['GET'])]
    public function switch(Request $request, string $_locale): RedirectResponse
    {
        $request->getSession()->set(LocaleSubscriber::SESSION_KEY, $_locale);

        $referer = $request->headers->get('referer');
        if (\is_string($referer) && parse_url($referer, \PHP_URL_HOST) === $request->getHost()) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute(null !== $this->getUser() ? 'dashboard' : 'app_login');
    }
}

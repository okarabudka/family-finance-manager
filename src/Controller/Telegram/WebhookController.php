<?php

declare(strict_types=1);

namespace App\Controller\Telegram;

use App\Telegram\Payload;
use App\Telegram\UpdateDispatcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/telegram/webhook', name: 'telegram_webhook', methods: ['POST'])]
class WebhookController extends AbstractController
{
    public function __construct(
        private readonly UpdateDispatcher $dispatcher,
        private readonly string $webhookSecret,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        // Validate the shared secret Telegram sends in the header
        $secret = $request->headers->get('X-Telegram-Bot-Api-Secret-Token');
        if ($secret !== $this->webhookSecret) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        $content = $request->getContent();
        if ('' === $content) {
            return new Response('Bad Request', Response::HTTP_BAD_REQUEST);
        }

        $decoded = json_decode($content, true);
        $update = \is_array($decoded) && !array_is_list($decoded) ? Payload::stringKeys($decoded) : null;
        if (null === $update || [] === $update) {
            return new Response('Bad Request', Response::HTTP_BAD_REQUEST);
        }

        $this->dispatcher->dispatch($update);

        return new Response('OK', Response::HTTP_OK);
    }
}

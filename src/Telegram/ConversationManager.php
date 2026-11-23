<?php

declare(strict_types=1);

namespace App\Telegram;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Manages multi-step conversation state in Redis.
 *
 * Uses CacheInterface for writing (with TTL via ItemInterface::expiresAfter)
 * and CacheItemPoolInterface for reading (to use isHit() without side effects).
 *
 * Both interfaces are implemented by the same Redis adapter instance;
 * Symfony's DI automatically injects the "app" cache pool for both.
 */
class ConversationManager
{
    private const TTL = 1800; // 30 minutes

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly CacheItemPoolInterface $cachePool,
        private readonly ConversationRegistry $registry,
    ) {}

    /**
     * Starts a new conversation for the given chat, replacing any existing one.
     *
     * @param array<string, mixed> $data Initial data to store in state
     */
    public function start(int $chatId, string $conversationClass, array $data = []): void
    {
        $state = [
            'class' => $conversationClass,
            'step'  => 0,
            'data'  => $data,
        ];

        $this->save($chatId, $state);
    }

    public function hasActiveConversation(int $chatId): bool
    {
        return $this->cachePool->getItem($this->key($chatId))->isHit();
    }

    /** @param array<string, mixed> $update */
    public function continue(int $chatId, array $update): void
    {
        $item = $this->cachePool->getItem($this->key($chatId));
        if (!$item->isHit()) {
            return;
        }

        $state = Payload::stringKeys($item->get()) ?? [];
        $class = Payload::string($state, 'class');
        if ($class === null) {
            $this->end($chatId);

            return;
        }

        $this->registry->get($class)->handle($chatId, $update, $state);
    }

    /**
     * Returns the current state or null if no conversation is active.
     *
     * @return array<string, mixed>|null
     */
    public function getState(int $chatId): ?array
    {
        $item = $this->cachePool->getItem($this->key($chatId));
        if (!$item->isHit()) {
            return null;
        }

        return Payload::stringKeys($item->get());
    }

    /**
     * Persists updated state back to Redis, resetting TTL.
     *
     * @param array<string, mixed> $state
     */
    public function updateState(int $chatId, array $state): void
    {
        $this->save($chatId, $state);
    }

    public function end(int $chatId): void
    {
        $this->cachePool->deleteItem($this->key($chatId));
    }

    /** @param array<string, mixed> $state */
    private function save(int $chatId, array $state): void
    {
        // Delete first to force the callback to re-run with a fresh TTL
        $this->cachePool->deleteItem($this->key($chatId));

        $this->cache->get($this->key($chatId), static function (ItemInterface $item) use ($state): array {
            $item->expiresAfter(self::TTL);

            return $state;
        });
    }

    private function key(int $chatId): string
    {
        return 'conversation_' . $chatId;
    }
}

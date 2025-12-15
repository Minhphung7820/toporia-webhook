<?php

declare(strict_types=1);

namespace Toporia\Webhook\Models;

use Toporia\Framework\Database\ORM\Model;

/**
 * WebhookFailure Model
 *
 * Dead Letter Queue for webhooks that failed after all retry attempts.
 * Stores failed webhooks for manual review, retry, or debugging.
 *
 * @property int $id
 * @property int|null $endpoint_id
 * @property string $endpoint_url
 * @property string $event
 * @property array $payload
 * @property array $options
 * @property int $total_attempts
 * @property string|null $last_error_message
 * @property int|null $last_status_code
 * @property string|null $last_response_body
 * @property string|null $idempotency_key
 * @property string|null $retried_at
 * @property string $created_at
 * @property string $updated_at
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/webhook
 * @since       2025-01-15
 */
class WebhookFailure extends Model
{
    protected static string $table = 'webhook_failures';

    protected static array $fillable = [
        'endpoint_id',
        'endpoint_url',
        'event',
        'payload',
        'options',
        'total_attempts',
        'last_error_message',
        'last_status_code',
        'last_response_body',
        'idempotency_key',
        'retried_at',
    ];

    protected static array $casts = [
        'payload' => 'array',
        'options' => 'array',
        'total_attempts' => 'int',
        'last_status_code' => 'int',
        'retried_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get endpoint relation.
     */
    public function endpoint()
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }

    /**
     * Check if this failure can be retried.
     *
     * @return bool
     */
    public function canRetry(): bool
    {
        return $this->retried_at === null;
    }

    /**
     * Mark as retried.
     *
     * @return bool
     */
    public function markAsRetried(): bool
    {
        $this->retried_at = now();
        return $this->save();
    }

    /**
     * Scope: Not yet retried.
     *
     * @param \Toporia\Framework\Database\ORM\Builder $query
     * @return \Toporia\Framework\Database\ORM\Builder
     */
    public function scopePending($query)
    {
        return $query->whereNull('retried_at');
    }

    /**
     * Scope: Already retried.
     *
     * @param \Toporia\Framework\Database\ORM\Builder $query
     * @return \Toporia\Framework\Database\ORM\Builder
     */
    public function scopeRetried($query)
    {
        return $query->whereNotNull('retried_at');
    }

    /**
     * Scope: By event name.
     *
     * @param \Toporia\Framework\Database\ORM\Builder $query
     * @param string $event
     * @return \Toporia\Framework\Database\ORM\Builder
     */
    public function scopeForEvent($query, string $event)
    {
        return $query->where('event', $event);
    }
}

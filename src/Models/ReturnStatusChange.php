<?php

namespace Liberu\Ecommerce\Returns\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;

/**
 * One move of the state machine, written down.
 *
 * Append-only, and there is no update path: a history you can edit answers a
 * different question from the one it was kept for.
 *
 * No factory. Rows here are written by `Actions\TransitionReturn` and by nothing
 * else, and a factory would exist only to let a test fabricate a history the
 * state machine could not have produced.
 *
 * `from_status` is null for exactly one row per return — the request itself,
 * which came from nowhere.
 *
 * @property int $id
 * @property int $return_request_id
 * @property ReturnStatus|null $from_status
 * @property ReturnStatus $to_status
 * @property int|null $actor_id
 * @property string|null $reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ReturnRequest $returnRequest
 */
class ReturnStatusChange extends Model
{
    protected $table = 'ecommerce_returns_status_changes';

    protected $fillable = ['return_request_id', 'from_status', 'to_status', 'actor_id', 'reason'];

    protected $casts = [
        'from_status' => ReturnStatus::class,
        'to_status' => ReturnStatus::class,
        'actor_id' => 'integer',
    ];

    /** @return BelongsTo<ReturnRequest, $this> */
    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class, 'return_request_id');
    }
}

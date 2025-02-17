<?php

declare(strict_types=1);

namespace Bavix\Wallet\Models;

use Bavix\Wallet\Interfaces\Wallet;
use Bavix\Wallet\Internal\Service\MathServiceInterface;
use Bavix\Wallet\Models\Wallet as WalletModel;
use Bavix\Wallet\Services\CastServiceInterface;
use function config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Kyslik\ColumnSortable\Sortable;
use Nicolaslopezj\Searchable\SearchableTrait;
use \RexlManu\LaravelTickets\Traits\HasTicketReference;

/**
 * Class Transaction.
 *
 * @property string      $payable_type
 * @property int         $payable_id
 * @property int         $wallet_id
 * @property string      $uuid
 * @property string      $type
 * @property string      $amount
 * @property int         $amountInt
 * @property string      $amountFloat
 * @property bool        $confirmed
 * @property array       $meta
 * @property Wallet      $payable
 * @property WalletModel $wallet
 */
class Transaction extends Model
{
    use Sortable;
    use SearchableTrait;
    use HasTicketReference;
    use \Spiritix\LadaCache\Database\LadaCacheTrait;

    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_WITHDRAW = 'withdraw';

    /**
     * @var string[]
     */
    protected $fillable = [
        'payable_type',
        'payable_id',
        'wallet_id',
        'uuid',
        'type',
        'amount',
        'confirmed',
        'meta',
    ];

    /**
     * @var array
     */
    protected $casts = [
        'wallet_id' => 'int',
        'confirmed' => 'bool',
        'meta' => 'json',
    ];

    public $sortable = [
        'id',
        'type',
        'amount',
        'created_at'
    ];

    protected $searchable = [
        'columns' => [
            'transactions.uuid' => 10,
            'users.email' => 10,
            'users.username' => 10,
            'orders.uuid' => 10,
        ],
        'joins' => [
            'users' => ['transactions.payable_id','users.id'],
            'orders' => ['transactions.meta->order_id', 'orders.id']
            //'sellers' => ['listings.user_id','listings.id'],
        ],
    ];

    public function getTable(): string
    {
        if (!$this->table) {
            $this->table = config('wallet.transaction.table', 'transactions');
        }

        return parent::getTable();
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(config('wallet.wallet.model', WalletModel::class));
    }

    public function getAmountIntAttribute(): int
    {
        return (int) $this->amount;
    }

    public function getDescriptionFormattedAttribute() :string
    {
        if ($this->meta['source'] == 'cancel')
            return __("Cancel of order #:order_hash :balance_type",
                ['order_hash' => $this->meta['order_hash'], 'balance_type' => _l($this->meta['balance_type'])]);

        elseif ($this->meta['source'] == 'payout')
            return __("Payout of :start_date to :end_date",
                ['start_date' => $this->meta['start_date'], 'end_date' => $this->meta['end_date']]);

        elseif ($this->meta['source'] == 'transfer')
            return __("Transfer to main balance order #:order_hash",
                ['order_hash' => $this->meta['order_hash']]);

        elseif ($this->meta['source'] == 'penalty')
            return __("Penalty for cancel of order #:order_hash",
                ['order_hash' => $this->meta['order_hash']]);

        elseif ($this->meta['source'] == 'purchase'){
            if ($this->meta['balance_type'] == 'bank')
                return __("Bank transfer for order #:order_hash",
                    ['order_hash' => $this->meta['order_hash']]);

            return __("Pay with :balance_type for order #:order_hash",
                ['order_hash' => $this->meta['order_hash'], 'balance_type' => _l($this->meta['balance_type'])]);
        }

        elseif ($this->meta['source'] == 'order')
            return __("Pending payment for order #:order_hash",
                ['order_hash' => $this->meta['order_hash']]);

        elseif ($this->meta['source'] == 'granted')
            if ($this->type == 'deposit')
                return __('Deposit balance :balance_type',
                    ['balance_type' => _l($this->meta['balance_type'])]);
            else
                return __('Withdrawn balance :balance_type',
                    ['balance_type' => _l($this->meta['balance_type'])]);
    }

    public function getAmountFloatAttribute(): string
    {
        $math = app(MathServiceInterface::class);
        $decimalPlacesValue = app(CastServiceInterface::class)
            ->getWallet($this->wallet)
            ->decimal_places;
        $decimalPlaces = $math->powTen($decimalPlacesValue);

        return $math->div($this->amount, $decimalPlaces, $decimalPlacesValue);
    }

    /**
     * @param float|int|string $amount
     */
    public function setAmountFloatAttribute($amount): void
    {
        $math = app(MathServiceInterface::class);
        $decimalPlacesValue = app(CastServiceInterface::class)
            ->getWallet($this->wallet)
            ->decimal_places;
        $decimalPlaces = $math->powTen($decimalPlacesValue);

        $this->amount = $math->round($math->mul($amount, $decimalPlaces));
    }

    public function sourceSortable($query, $direction)
    {
//        return $query->orderBy('meta->source', $direction);
        return $query
            ->orderByRaw("FIELD(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.source')) ,'purchase', 'cancel', 'granted', 'order', 'penalty', 'transfer', 'payout') $direction")
            ->orderBy('id', $direction);
    }

    public function orderidSortable($query, $direction)
    {
        return $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.order_id')) AS DECIMAL) $direction");
    }

    public function payoutidSortable($query, $direction)
    {
        return $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.payout_id')) AS DECIMAL) $direction");
    }

    function hasReferenceAccess() : bool {
        return (request()->user()->id == $this->payable_id);
    }

    public function scopeReferenceAccess($query, $user)
    {

        if ($user->can(config('laravel-tickets.permissions.all-ticket')))
            return $query;

        return $query->where('payable_id', $user->id);
    }
}

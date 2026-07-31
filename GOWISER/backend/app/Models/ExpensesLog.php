<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpensesLog extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'expenses_logs';

    /**
     * `id` is varchar(50), not an auto-increment int — same shape as inventory_logs and
     * the other legacy varchar-PK tables here. Controllers assign a UUID on create.
     */
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * The table tracks edits through `modified_date`, not Eloquent's updated_at, and it
     * has no updated_at column at all. `created_at` is written by hand on insert.
     */
    public $timestamps = false;

    /** Bucket tags only — they pick a summary card, they do not recur or accrue. */
    public const TYPE_DAILY = 'daily';
    public const TYPE_MONTHLY = 'monthly';

    public const TYPES = [self::TYPE_DAILY, self::TYPE_MONTHLY];

    protected $fillable = [
        'id',
        'organization_id',
        'date',
        'provider',
        'description',
        'amount',
        'photo',
        'processed_by',
        'modified_by',
        'modified_date',
        'user_email',
        'location',
        'payee',
        'category',
        'category_id',
        'expense_type',
        'invoice_no',
        'reference_no',
        'received_date',
        'supplier',
        'barangay',
        'city',
        'created_at',
    ];

    protected $casts = [
        'date' => 'date',
        'received_date' => 'date',
        'modified_date' => 'datetime',
        'created_at' => 'datetime',
        'deleted_at' => 'datetime',
        'amount' => 'decimal:2',
        'organization_id' => 'integer',
        'category_id' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(ExpensesCategory::class, 'category_id', 'id');
    }

    public function scopeDaily($query)
    {
        return $query->where('expense_type', self::TYPE_DAILY);
    }

    public function scopeMonthly($query)
    {
        return $query->where('expense_type', self::TYPE_MONTHLY);
    }

    /**
     * Matches the org rule the rest of the app uses: a user scoped to an organization
     * sees that organization's rows, a user with no organization sees the unscoped ones.
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $organizationId
            ? $query->where('organization_id', $organizationId)
            : $query->whereNull('organization_id');
    }
}

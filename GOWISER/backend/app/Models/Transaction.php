<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $table = 'transactions';

    protected $fillable = [
        'account_no',
        'transaction_type',
        'received_payment',
        'payment_date',
        'date_processed',
        'processed_by_user',
        'payment_method',
        'reference_no',
        'or_no',
        'remarks',
        'status',
        'image_url',
        'created_by_user',
        'updated_by_user',
        'approved_by',
        'account_balance_before',
        'organization_id',
        'updated_column',
        // Prepaid only: the plan this payment buys. Acted on at approval by
        // PrepaidPlanChangeService (queued if the period is live, applied if it has lapsed).
        'selected_plan_id',
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'date_processed' => 'datetime',
        'received_payment' => 'decimal:2',
        'updated_column' => 'array',
    ];

    public function account()
    {
        return $this->belongsTo(BillingAccount::class, 'account_no', 'account_no');
    }

    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by_user', 'email_address');
    }

    public function getProcessedByUserAttribute($value)
    {
        if ($this->relationLoaded('processor')) {
            return $this->processor ? $this->processor->full_name : $value;
        }
        return $value;
    }

    public function paymentMethodInfo()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method', 'payment_method');
    }



    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'transaction_id');
    }

    public function revert_request()
    {
        return $this->hasOne(\App\Models\TransactionRevert::class, 'transaction_id');
    }
}



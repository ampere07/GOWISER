<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobOrder extends Model
{
    protected $table = 'job_orders';

    protected $fillable = [
        'application_id',
        'account_id',
        'status',
        'timestamp',
        'date_installed',
        'installation_fee',
        'billing_day',
        'billing_status',
        'generation_type',
        // Legacy free-text VAT mode, still written alongside vat_enabled so older readers of the
        // job order (details view, exports) keep working.
        'vat_type',
        'vat_enabled',
        'withholding_enabled',
        'withholding_percentage',
        // VIP captured up front on the JO Assign Form. Copied verbatim onto the billing account
        // at approval, which is also created with the VIP billing status — same VIP mechanism as
        // before, just no longer needing a manual edit after approval.
        'vip_enabled',
        'vip_expiration',
        'modem_router_sn',
        'router_model',
        'group_name',
        'lcpnap',
        'port',
        'vlan',
        'username',
        'ip_address',
        'connection_type',
        'usage_type',
        'username_status',
        'visit_by',
        'visit_with',
        'visit_with_other',
        'onsite_status',
        'assigned_email',
        'status_remarks',
        'onsite_remarks',
        'status_remarks_id',
        'address_coordinates',
        'contract_link',
        'client_signature_url',
        'setup_image_url',
        'speedtest_image_url',
        'signed_contract_image_url',
        'box_reading_image_url',
        'router_reading_image_url',
        'port_label_image_url',
        'house_front_picture_url',
        'installation_landmark',
        'pppoe_username',
        'pppoe_password',
        'created_by_user_email',
        'updated_by_user_email',
        'start_time',
        'end_time',
        'proof_image_url',
        'client_tagging_url',
        'organization_id',
        'technicians',
        'commission_status',
    ];

    protected $dates = [
        'timestamp',
        'date_installed',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'installation_fee' => 'decimal:2',
        'billing_day' => 'integer',
        'timestamp' => 'datetime',
        'date_installed' => 'datetime',
        'organization_id' => 'integer',
        'technicians' => 'array',
        'vat_enabled' => 'boolean',
        'withholding_enabled' => 'boolean',
        'withholding_percentage' => 'decimal:2',
        'vip_enabled' => 'boolean',
        'vip_expiration' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class , 'application_id', 'id');
    }

    public function items()
    {
        return $this->hasMany(JobOrderItem::class , 'job_order_id', 'id');
    }

    public function lcpnapLocation()
    {
        return $this->belongsTo(LCPNAPLocation::class , 'lcpnap', 'lcpnap_name');
    }

    public function billingAccount()
    {
        return $this->belongsTo(BillingAccount::class, 'account_id', 'id');
    }
}



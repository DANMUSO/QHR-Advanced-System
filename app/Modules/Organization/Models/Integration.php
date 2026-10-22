<?php

namespace App\Modules\Organization\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's connection to an external service. The catalogue (fields, secrets) lives in CATALOGUE;
 * other code reads a live configuration with Integration::configFor($companyId, 'sms_africastalking').
 */
class Integration extends Model
{
    use BelongsToCompany;

    protected $table = 'org_integrations';

    protected $guarded = ['id', 'company_id'];

    protected $hidden = ['config'];

    /** key => [name, description, category, fields => [field => [label, secret?, type?]]] */
    public const CATALOGUE = [
        'sms_africastalking' => [
            'name' => "Africa's Talking SMS", 'category' => 'Messaging',
            'description' => 'Send leave, payslip and security SMS alerts to staff phones.',
            'fields' => ['username' => ['label' => 'Username'], 'api_key' => ['label' => 'API key', 'secret' => true], 'sender_id' => ['label' => 'Sender ID']],
        ],
        'smtp' => [
            'name' => 'Company SMTP mail', 'category' => 'Messaging',
            'description' => 'Send HR emails from your own mail server instead of the platform default.',
            'fields' => [
                'host' => ['label' => 'Host'], 'port' => ['label' => 'Port', 'type' => 'number'], 'username' => ['label' => 'Username'],
                'password' => ['label' => 'Password', 'secret' => true], 'encryption' => ['label' => 'Encryption (tls/ssl)'], 'from_address' => ['label' => 'From address', 'type' => 'email'],
            ],
        ],
        'biometric_zkteco' => [
            'name' => 'ZKTeco biometric devices', 'category' => 'Attendance',
            'description' => 'Pull clock-in / clock-out punches from fingerprint and face terminals.',
            'fields' => ['device_ip' => ['label' => 'Device IP'], 'port' => ['label' => 'Port', 'type' => 'number'], 'comm_key' => ['label' => 'Comm key', 'secret' => true], 'sync_interval_minutes' => ['label' => 'Sync interval (minutes)', 'type' => 'number']],
        ],
        'kra_itax' => [
            'name' => 'KRA iTax (P10)', 'category' => 'Statutory',
            'description' => 'Prepare monthly PAYE P10 returns in the iTax upload format.',
            'fields' => ['employer_pin' => ['label' => 'Employer PIN'], 'tax_obligation' => ['label' => 'Tax obligation']],
        ],
        'mpesa_b2c' => [
            'name' => 'M-Pesa B2C', 'category' => 'Payments',
            'description' => 'Disburse claim refunds and casual wages to M-Pesa wallets.',
            'fields' => ['shortcode' => ['label' => 'Shortcode'], 'consumer_key' => ['label' => 'Consumer key'], 'consumer_secret' => ['label' => 'Consumer secret', 'secret' => true], 'initiator_name' => ['label' => 'Initiator name']],
        ],
        'bank_eft' => [
            'name' => 'Bank EFT file', 'category' => 'Payments',
            'description' => 'Generate the salary payment file for your bank (CSV / fixed width).',
            'fields' => ['bank_name' => ['label' => 'Bank'], 'account_number' => ['label' => 'Debit account'], 'branch_code' => ['label' => 'Branch code'], 'file_format' => ['label' => 'File format']],
        ],
        'slack' => [
            'name' => 'Slack / Teams alerts', 'category' => 'Collaboration',
            'description' => 'Post approvals and announcements to a team channel via an incoming webhook.',
            'fields' => ['webhook_url' => ['label' => 'Incoming webhook URL', 'secret' => true, 'type' => 'url'], 'channel' => ['label' => 'Channel']],
        ],
    ];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'config' => 'encrypted:array'];
    }

    /** Configuration of an enabled integration, or null. */
    public static function configFor(int $companyId, string $key): ?array
    {
        $row = static::withoutGlobalScope('company')->where('company_id', $companyId)->where('key', $key)->where('is_enabled', true)->first();

        return $row ? ($row->config ?? []) : null;
    }
}

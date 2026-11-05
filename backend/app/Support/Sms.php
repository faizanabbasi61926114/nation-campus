<?php

namespace App\Support;

use App\Models\CommunicationLog;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Outbound SMS with an auditable trail. Two drivers:
 *  - log     (default): writes to laravel.log, records the message as sent. No external call.
 *  - gateway: HTTPS POST to the configured aggregator; requires SMS_GATEWAY_URL + SMS_GATEWAY_API_KEY
 *             in the environment. Without them every send is recorded as "skipped" — the system
 *             never falls back to a silent no-op and never contacts a partially configured gateway.
 */
class Sms
{
    public const MAX_CHARS = 480;

    public function __construct(private TenantContext $ctx) {}

    /**
     * @param array{subject?:string|null, announcement_id?:int|null, message_id?:int|null, campus_id?:int|null} $meta
     * @return array{status:string, provider:string, error:string|null}
     */
    public function send(?string $to, string $body, array $meta = []): array
    {
        $org = (int) ($this->ctx->organizationId ?: 0);
        // An explicit null campus_id means "organisation-wide": do not fall back to the
        // campus the publisher happens to be switched to, or the wrong override is read.
        $campusId = array_key_exists('campus_id', $meta) ? $meta['campus_id'] : $this->ctx->campusId();
        $msisdn = $this->normalize($to);
        $sender = (string) (Setting::getFor($org, $campusId, 'communication.sms_sender') ?? config('cms.sms.sender_id', 'CMS'));
        $subject = $meta['subject'] ?? null;

        $log = fn (string $status, ?string $provider, ?string $error) => $this->record([
            'organization_id' => $org, 'campus_id' => $campusId, 'channel' => 'sms',
            'recipient' => $msisdn ?: (string) $to, 'subject' => $subject, 'status' => $status,
            'provider' => $provider, 'error' => $error ? mb_substr($error, 0, 255) : null,
            'announcement_id' => $meta['announcement_id'] ?? null, 'message_id' => $meta['message_id'] ?? null,
        ]);

        if ($msisdn === '') return $log('skipped', null, 'Missing or invalid mobile number.');

        $text = $this->truncate($sender, mb_substr($body, 0, self::MAX_CHARS));
        $driver = $this->driver($org, $campusId);

        if ($driver === 'gateway') {
            $url = (string) config('cms.sms.gateway_url');
            $key = (string) config('cms.sms.gateway_api_key');
            if ($url === '' || $key === '') {
                return $log('skipped', 'gateway', 'Gateway driver selected but SMS_GATEWAY_URL / SMS_GATEWAY_API_KEY are not configured.');
            }
            try {
                $res = Http::asJson()->timeout((int) config('cms.sms.timeout_seconds', 5))
                    ->withHeaders(['Authorization' => 'Bearer ' . $key])
                    ->post($url, ['to' => $msisdn, 'from' => $sender, 'text' => $text]);
                if ($res->failed()) return $log('failed', 'gateway', 'HTTP ' . $res->status() . ' from gateway.');
                return $log('sent', 'gateway', null);
            } catch (\Throwable $e) {
                return $log('failed', 'gateway', $e->getMessage());
            }
        }

        logger()->info('[sms] ' . $text, ['to' => $msisdn, 'sender' => $sender]);
        return $log('sent', 'log', null);
    }

    /** Campus-level setting wins; falls back to the environment driver. */
    public function driver(?int $org = null, ?int $campusId = null): string
    {
        $org = $org ?: $this->ctx->organizationId;
        $campusId = $campusId ?? $this->ctx->campusId();
        $setting = Setting::getFor($org, $campusId, 'communication.sms_driver');
        return in_array($setting, ['log', 'gateway'], true) ? $setting : (string) config('cms.sms.driver', 'log');
    }

    /** Pakistan MSISDN in international form: 92XXXXXXXXXX. Accepts +92, 0 or bare 12 digits. */
    public function normalize(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if ($digits === '') return '';
        if (str_starts_with($digits, '00')) $digits = substr($digits, 2);
        if (str_starts_with($digits, '92')) $digits = substr($digits, 2);
        elseif (str_starts_with($digits, '0')) $digits = substr($digits, 1);
        if (strlen($digits) < 10 || strlen($digits) > 12) return '';
        return '92' . $digits;
    }

    private function truncate(string $sender, string $body): string
    {
        $prefix = $sender !== '' ? "[$sender] " : '';
        if (mb_strlen($prefix . $body) > self::MAX_CHARS) {
            $body = rtrim(mb_substr($body, 0, self::MAX_CHARS - mb_strlen($prefix) - 1)) . '…';
        }
        return $prefix . $body;
    }

    private function record(array $attrs): array
    {
        CommunicationLog::create($attrs);
        return ['status' => $attrs['status'], 'provider' => $attrs['provider'], 'error' => $attrs['error']];
    }
}

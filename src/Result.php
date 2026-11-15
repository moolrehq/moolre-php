<?php

declare(strict_types=1);

namespace Moolre;

/** What every API call returns. `raw` is the untouched response JSON (as an array). */
final class Result
{
    /** @var bool status === 1 (the API sends 1, "1", 0 or "0" depending on the endpoint) */
    public $ok;
    /** @var int|string */
    public $status;
    /** @var string */
    public $code;
    /** @var mixed */
    public $message;
    /** @var mixed */
    public $data;
    /** @var mixed */
    public $go;
    /** @var string|null */
    public $requestId;
    /** @var array[] */
    public $notices;
    /** @var bool True when an Idempotency-Key matched an earlier request and the stored response was replayed */
    public $replayed;
    /** @var int */
    public $httpStatus;
    /** @var array */
    public $raw;

    public function __construct(array $raw, int $httpStatus, ?string $headerRequestId)
    {
        $this->raw = $raw;
        $this->httpStatus = $httpStatus;
        $this->status = $raw['status'] ?? (($httpStatus >= 200 && $httpStatus < 300) ? 1 : 0);
        $this->ok = (string) $this->status === '1';
        $this->code = isset($raw['code']) ? (string) $raw['code'] : '';
        $this->message = $raw['message'] ?? null;
        $this->data = $raw['data'] ?? null;
        $this->go = $raw['go'] ?? null;
        // Request id: meta.requestid, else legacy top-level request_id, else the X-Request-Id header.
        // Notices: meta.notices, else legacy top-level notices, else [].
        $meta = isset($raw['meta']) && is_array($raw['meta']) ? $raw['meta'] : [];
        $rid = isset($meta['requestid']) && is_string($meta['requestid']) ? $meta['requestid'] : null;
        if ($rid === null && isset($raw['request_id']) && is_string($raw['request_id'])) {
            $rid = $raw['request_id'];
        }
        $this->requestId = $rid !== null ? $rid : $headerRequestId;
        if (isset($meta['notices']) && is_array($meta['notices'])) {
            $this->notices = $meta['notices'];
        } else {
            $this->notices = isset($raw['notices']) && is_array($raw['notices']) ? $raw['notices'] : [];
        }
        $this->replayed = ($meta['replayed'] ?? false) === true || ($raw['replayed'] ?? false) === true;
    }

    /** Collection answered TP14: ask the customer for the SMS code and call verifyOtp(). */
    public function otpRequired(): bool
    {
        return $this->code === 'TP14';
    }

    /** Collection answered TP17: the number is verified; call initiate() again without otpCode. */
    public function otpVerified(): bool
    {
        return $this->code === 'TP17';
    }
}

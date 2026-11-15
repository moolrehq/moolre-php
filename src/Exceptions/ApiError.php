<?php

declare(strict_types=1);

namespace Moolre\Exceptions;

use Moolre\Result;

/** The API answered status 0 (or a 4xx/5xx in strict mode). */
class ApiError extends MoolreException
{
    /** @var string Moolre application code, e.g. TP13 */
    public $apiCode;
    /** @var string */
    public $apiMessage;
    /** @var string[] Names of the request fields the API complained about */
    public $fields;
    /** @var string|null */
    public $requestId;
    /** @var int */
    public $httpStatus;
    /** @var array */
    public $notices;
    /** @var Result */
    public $result;

    public function __construct(Result $result)
    {
        $message = is_array($result->message)
            ? implode(' ', array_filter($result->message, 'is_scalar'))
            : (string) ($result->message ?? '');

        parent::__construct($result->code . ': ' . $message, $result->httpStatus);

        $this->apiCode = $result->code;
        $this->apiMessage = $message;
        $this->fields = self::fieldsOf($result->data);
        $this->requestId = $result->requestId;
        $this->httpStatus = $result->httpStatus;
        $this->notices = $result->notices;
        $this->result = $result;
    }

    /** @param mixed $data */
    private static function fieldsOf($data): array
    {
        if (is_string($data) && $data !== '' && $data !== 'all') {
            return [$data];
        }

        if (is_array($data)) {
            return array_values(array_filter($data, 'is_string'));
        }

        return [];
    }

    public static function fromResult(Result $result): self
    {
        $http = $result->httpStatus;

        if ($result->code === 'IDEM01' || $http === 409) {
            return new IdempotencyError($result);
        }
        if ($result->code === 'OTP429' || $http === 429) {
            return new RateLimitError($result);
        }
        if ($http === 401 || in_array($result->code, ['AIN01', 'AIN03', 'IN01', 'SW05'], true)) {
            return new AuthenticationError($result);
        }
        if ($http === 404) {
            return new NotFoundError($result);
        }
        if ($http === 400 || $http === 422) {
            return new ValidationError($result);
        }
        if ($http >= 500) {
            return new ServerError($result);
        }

        return new self($result);
    }
}

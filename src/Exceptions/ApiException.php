<?php

declare(strict_types=1);

namespace Moolre\Exceptions;

final class ApiException extends MoolreException
{
    /** @var mixed */
    private $responseBody;

    /**
     * @param mixed $responseBody
     */
    public function __construct(string $message, int $statusCode = 0, $responseBody = null)
    {
        parent::__construct($message, $statusCode);
        $this->responseBody = $responseBody;
    }

    /**
     * @return mixed
     */
    public function responseBody()
    {
        return $this->responseBody;
    }

    /**
     * @param mixed $message
     */
    public static function stringifyMessage($message): string
    {
        if (is_array($message)) {
            $parts = [];

            foreach ($message as $value) {
                if (is_scalar($value)) {
                    $parts[] = (string) $value;
                }
            }

            return $parts !== [] ? implode(' ', $parts) : 'Moolre API request failed.';
        }

        if (is_scalar($message)) {
            return (string) $message;
        }

        return 'Moolre API request failed.';
    }
}

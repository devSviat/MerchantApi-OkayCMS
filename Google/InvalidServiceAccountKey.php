<?php

namespace Okay\Modules\Sviat\MerchantApi\Google;

use InvalidArgumentException;

/** Причина — код для перекладу в адмінці; вміст ключа в повідомлення не потрапляє. */
final class InvalidServiceAccountKey extends InvalidArgumentException
{
    public const string NOT_JSON = 'not_json';
    public const string NOT_SERVICE_ACCOUNT = 'not_service_account';
    public const string FOREIGN_TOKEN_URI = 'foreign_token_uri';
    public const string BAD_CLIENT_EMAIL = 'bad_client_email';
    public const string BAD_PRIVATE_KEY = 'bad_private_key';
    public const string BAD_FIELD = 'bad_field';

    public function __construct(private readonly string $reason)
    {
        parent::__construct("Invalid service account key: {$reason}");
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}

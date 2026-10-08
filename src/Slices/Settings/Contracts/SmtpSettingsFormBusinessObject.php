<?php

namespace LaraSlice\Slices\Settings\Contracts;

use LaraSlice\Core\Base\BaseFormBusinessObject;

class SmtpSettingsFormBusinessObject extends BaseFormBusinessObject
{
    public string $mail_host = '';

    public int $mail_port = 587;

    public ?string $mail_username = null;

    public ?string $mail_password = null;

    public string $mail_encryption = 'tls';

    public string $mail_from_address = 'noreply@laraslice.com';

    public string $mail_from_name = 'LaraSlice';
}

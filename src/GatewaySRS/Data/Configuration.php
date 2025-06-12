<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\GatewaySRS\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * GatewaySRS configuration.
 *
 * @property-read string|null $api_hostname
 * @property-read string $username
 * @property-read string $password
 * @property-read bool|null $sandbox
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'api_hostname' => ['nullable', 'string'],
            'username' => ['required', 'string', 'min:3'],
            'password' => ['required', 'string', 'min:6'],
            'sandbox' => ['nullable', 'boolean'],
        ]);
    }
}

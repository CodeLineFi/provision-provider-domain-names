<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\GatewaySRS\Helper;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\DomainNotification;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\GatewaySRS\Data\Configuration;
use function Illuminate\Events\queueable;

/**
 * GatewaySRS Domains API client.
 */
class GatewaySRSApi
{
    /**
     * Contact Types
     */
    public const CONTACT_TYPE_REGISTRANT = 'registrant';
    public const CONTACT_TYPE_ADMIN = 'admin';
    public const CONTACT_TYPE_TECH = 'tech';
    public const CONTACT_TYPE_BILLING = 'billing';

    protected Client $client;

    protected Configuration $configuration;
    protected array $lockedStatuses = [
        'clientTransferProhibited',
        'clientUpdateProhibited',
        'clientDeleteProhibited',
    ];

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function asyncRequest(
        string $command,
        ?array $query = null,
        ?array $body = null,
        string $method = 'POST'
    ): Promise
    {
        $requestParams = [];
        if ($query) {
            $requestParams = ['query' => $query];
        }

        if ($body) {
            $requestParams['json'] = $body;
        }

        /** @var \GuzzleHttp\Promise\Promise $promise */
        $promise = $this->client->requestAsync($method, "/api/registry{$command}", $requestParams)
            ->then(function (Response $response) {
                $result = $response->getBody()->getContents();
                $response->getBody()->close();

                if ($result === '') {
                    return null;
                }

                return $this->parseResponseData($result);
            });

        return $promise;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function parseResponseData(string $result): array
    {
        $parsedResult = json_decode($result, true);

        if (!$parsedResult) {
            throw ProvisionFunctionError::create('Unknown Provider API Error')
                ->withData([
                    'response' => $result,
                ]);
        }

        if ($error = $this->getResponseErrorMessage($parsedResult)) {
            throw ProvisionFunctionError::create($error)
                ->withData([
                    'response' => $parsedResult,
                ]);
        }

        return $parsedResult;
    }

    protected function getResponseErrorMessage($responseData): ?string
    {
        if (isset($responseData['detail']) && $responseData['detail'] == 'Object does not exist') {
            return sprintf('Provider API Error: %s', $responseData['detail']);
        }

        return null;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function makeRequest(
        string            $command,
        array|string|null $query = null,
        ?array            $body = null,
        string            $method = 'POST'
    ): ?array
    {
        return $this->asyncRequest($command, $query, $body, $method)->wait();
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getDomainInfo(string $domainName): array
    {
        $response = $this->makeRequest("/domains/info/", null, ['name' => $domainName])["results"];

        $contacts = $this->parseContacts($response['contacts']);

        $statuses = [];

        if (isset($response['statuses']) && is_array($response['statuses'])) {
            foreach ($response['statuses'] as $status) {
                $statuses[] = $status['code'];
            }
        }

        return [
            'id' => $response['wid'] ?? 'N/A',
            'domain' => (string)$response['name'],
            'statuses' => $statuses,
            'locked' => boolval(array_intersect($this->lockedStatuses, $statuses)),
            'registrant' => $contacts["registrant"] ?? null,
            'billing' => $contacts["billing"] ?? null,
            'tech' => $contacts["tech"] ?? null,
            'admin' => $contacts["admin"] ?? null,
            'ns' => NameserversResult::create($this->parseNameservers($response['hosts'] ?? [])),
            'created_at' => isset($response['cdate'])
                ? Utils::formatDate((string)$response['cdate'])
                : null,
            'updated_at' => null,
            'expires_at' => isset($response['expiry'])
                ? Utils::formatDate($response['expiry'])
                : null,
        ];
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     */
    public function createContact(ContactParams $contactParams): ?string
    {
        $params = $this->setContactParams($contactParams);
        $params['id'] = uniqid();

        $response = $this->makeRequest("/contacts/", null, $params);

        return (string)$response['id'];
    }

    private function parseNameservers(array $nameservers): array
    {
        $result = [];

        if (count($nameservers) > 0) {
            foreach ($nameservers as $i => $ns) {
                $result['ns' . ($i + 1)] = ['host' => $ns['hostname']];
            }
        }

        return $result;
    }

    /**
     * @param string[] $nameServers
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(string $domainName, int $years, array $contacts, array $nameServers): void
    {
        $hosts = [];
        foreach ($nameServers as $nameServer) {
            $hosts[] = [
                "hostname" => $nameServer,
            ];
        }

        $contactParams = [];
        foreach ($contacts as $type => $contact) {
            if ($contact) {
                $contactParams[] = [
                    'type' => $type,
                    'contact' => [
                        'id' => $contact,
                    ],
                ];

            }
        }

        $params = [
            'name' => $domainName,
            'period' => $years,
            'period_unit' => 'y',
            'hosts' => $hosts,
            'contacts' => $contactParams
        ];

        $this->makeRequest("/domains/", null, $params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function poll(int $limit, ?Carbon $since): ?array
    {
        $notifications = [];

        $params = [
            'limit' => $limit,
            'status' => '1',
        ];

        if ($since != null) {
            $params = array_merge($params, ['date_time_after' => $since->format('Y-m-d\TH:i:s\Z')]);
        }

        $response = $this->makeRequest("/messages/", null, $params, "GET");

        $countRemaining = $response['count'];

        if (isset($response['results'])) {
            foreach ($response['results'] as $entity) {
                $messageId = $entity['message_id'];

                $message = $entity['content'];

                $messageDateTime = Carbon::parse($entity['date_time']);

                $notifications[] = DomainNotification::create()
                    ->setId($messageId)
                    ->setType("unknown")
                    ->setMessage($message)
                    ->setDomains([''])
                    ->setCreatedAt($messageDateTime)
                    ->setExtra(['response' => json_encode($entity)]);
            }
        }

        return [
            'count_remaining' => $countRemaining,
            'notifications' => $notifications,
        ];
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getDomainEppCode(string $domainName): ?string
    {
        $response = $this->makeRequest("/domains/info/", null, ['name' => $domainName])["results"];
        return $response['authinfo'] ?? null;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getRegistrarLockStatus(string $domainName): bool
    {
        $response = $this->makeRequest("/domains/info/", null, ['name' => $domainName])["results"];
        $statuses = [];

        if (isset($response['statuses']) && is_array($response['statuses'])) {
            foreach ($response['statuses'] as $status) {
                $statuses[] = $status['code'];
            }
        }

        return boolval(array_intersect($this->lockedStatuses, $statuses));
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setRegistrarLock(string $domainName, bool $lock): void
    {
        $response = $this->makeRequest("/domains/", null, ["name" => $domainName], 'GET')['results'][0];
        $wid = $response['wid'];

        $command = $lock ? 'lock' : 'unlock';

        $this->makeRequest("/domains/{$wid}/$command/");
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(string $domainName, array $nameServers): void
    {
        $hosts = [];

        foreach ($nameServers as $nameServer) {
            $hosts[] = [
                "hostname" => $nameServer,
            ];
        }

        $response = $this->makeRequest("/domains/", null, ["name" => $domainName], 'GET')['results'][0];
        $wid = $response['wid'];
        $response = $this->makeRequest("/domains/info/", null, ["name" => $domainName])['results'];

        $params = [
            "name" => $domainName,
            "contacts" => $response['contacts'],
            "hosts" => $hosts,
            "period" => 0,
            "period_unit" => "y",
        ];

        $this->makeRequest("/domains/{$wid}/", null, $params, 'PUT');
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(string $domainName, ContactParams $contactParams): void
    {
        $response = $this->makeRequest("/WHMCS/domains/info/", null, ['name' => $domainName])["results"];

        if ($response['rar'] != $this->configuration->username) {
            throw ProvisionFunctionError::create('Operation not available for this domain');
        }

        $contacts = [];
        foreach ($response['contacts'] as $contact) {
            if ($contact['type'] == self::CONTACT_TYPE_REGISTRANT) {
                $contacts = $this->parseContacts([$contact]);
            }
        }

        $contact = $contacts[self::CONTACT_TYPE_REGISTRANT] ?? null;
        $params = $this->setContactParams($contactParams);
        if (!$contact) {
            throw ProvisionFunctionError::create('Registrant contact does not exist');
        } else {
            $this->makeRequest("/contacts/{$contact['id']}/", null, $params, 'PUT');
        }
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     */
    private function setContactParams(ContactParams $contactParams): array
    {
        return [
            'phone' => Utils::internationalPhoneToEpp($contactParams->phone),
            'email' => $contactParams->email,
            'contact_address' => [
                [
                    'org' => $contactParams->organisation ?: '',
                    'real_name' => $contactParams->name ?? $contactParams->organisation ?: '',
                    'street' => $contactParams->address1,
                    'city' => $contactParams->city,
                    'province' => $contactParams->state ?: '',
                    'country' => Utils::normalizeCountryCode($contactParams->country_code),
                    'code' => $contactParams->postcode,
                    'type' => $contactParams->type ?? 'loc',
                ]
            ],
        ];
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(string $domainName, int $period): void
    {
        $response = $this->makeRequest("/domains/info/", null, ['name' => $domainName])["results"];
        if ($response['rar'] != $this->configuration->username) {
            throw ProvisionFunctionError::create("'Operation not available for this domain");
        }

        for ($i = 0; $i < $period; $i++) {
            $response['expiry'] = Carbon::parse($response['expiry'])->format('Y-m-d');
            $this->makeRequest("/WHMCS/domains/{$domainName}/renew/", null, ['curExpDate' => $response['expiry'], 'name' => $domainName]);
            $response['expiry'] = Carbon::parse($response['expiry'])->addYear();
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function initiateTransfer(string $domainName, string $eppCode): void
    {
        $params = [
            'name' => $domainName,
            'authinfo' => $eppCode,
        ];

        $this->makeRequest("/domains/transfer-request/", null, $params);
    }

    /**
     * @param string[] $domains
     *
     * @return DacDomain[]
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws Throwable
     */
    public function checkMultipleDomains(array $domains): array
    {
        $promises = array_map(function ($domain) {
            return $this->asyncRequest('/domains/check/', null, ['name' => $domain])
                ->then(function (array $result) use ($domain) {
                    $available = $result['results'][0]['avail'] === '1';
                    $canTransfer = !$available;

                    $premium = false;
                    if ($result['charge']['category'] == 'premium') {
                        $premium = true;
                    }

                    $description = sprintf(
                        'Domain is %s to register. %s',
                        $available ? 'available' : 'not available',
                        $result['results'][0]['reason'],
                    );


                    return DacDomain::create([
                        'domain' => $domain,
                        'description' => $description,
                        'tld' => Utils::getTld($domain),
                        'can_register' => $available,
                        'can_transfer' => $canTransfer,
                        'is_premium' => $premium,
                    ]);
                })
                ->otherwise(function (Throwable $e) use ($domain) {
                    if (!$e instanceof ClientException) {
                        throw $e;
                    }

                    $responseBody = trim($e->getResponse()->getBody()->__toString());
                    $data = json_decode($responseBody, true);

                    return DacDomain::create([
                        'domain' => $domain,
                        'description' => $data['detail'] ?? 'Unknown error',
                        'tld' => Utils::getTld($domain),
                        'can_register' => false,
                        'can_transfer' => false,
                        'is_premium' => false,
                    ]);
                });

        }, $domains);

        return PromiseUtils::all($promises)->wait();
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function parseContacts(array $contacts): array
    {
        $result = [];
        $contactsList = $this->makeRequest("/contacts/", null, null, "GET")['results'];

        foreach ($contacts as $contact) {
            foreach ($contactsList as $contactInfo) {
                if ($contact['contact']['id'] == $contactInfo['id']) {
                    $result[$contact['type']] = $this->getContactInfo((string)$contactInfo['wid']);
                }
            }
        }

        return $result;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getContactInfo(string $id): ContactData
    {
        $contact = $this->makeRequest("/contacts/$id/", null, null, "GET");
        $contactAddress = $contact['contact_address'][0];

        $name = $contactAddress['real_name'] ?: $contactAddress['org'];
        @[$firstName, $lastName] = explode(' ', $name, 2);

        return ContactData::create([
            'id' => $id,
            'organisation' => $contactAddress['org'],
            'name' => $firstName . ' ' . $lastName,
            'address1' => $contactAddress['street'],
            'city' => $contactAddress['city'],
            'state' => $contactAddress['province'],
            'postcode' => $contactAddress['code'],
            'country_code' => Utils::normalizeCountryCode($contactAddress['country']),
            'email' => $contact['email'],
            'phone' => $contact['phone'],
        ]);
    }

    /**
     * @param string $domainName
     * @param bool $autoRenew
     * @return void
     */
    public function setRenewalMode(string $domainName, bool $autoRenew): void
    {
        $response = $this->makeRequest("/domains/", null, ["name" => $domainName], 'GET')['results'][0];
        $wid = $response['wid'];

        $response = $this->makeRequest("/domains/info/", null, ["name" => $domainName])['results'];

        $params = [
            "name" => $domainName,
            "autorenew" => $autoRenew,
            "contacts" => $response['contacts'],
            "hosts" => $response['hosts'],
            "period" => 0,
            "period_unit" => "y",
        ];
        $this->makeRequest("/domains/{$wid}/", null, $params, 'PUT');
        //$this->makeRequest("/WHMCS/domains/{$domainName}/", null, ['name' => $domainName, 'autorenew' => $autoRenew], 'PUT');
    }

}

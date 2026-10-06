<?php
declare(strict_types=1);

namespace ZbxBot\Zabbix;

use IntelliTrend\Zabbix\ZabbixApi;

/** Єдина точка виклику Zabbix API. Перелогінюється лише при зміні токена. */
class ZabbixClient
{
    private ?ZabbixApi $api = null;
    private ?string $loggedAs = null;

    public function __construct(private readonly string $host, private readonly string $serviceToken)
    {
    }

    /**
     * @param array<mixed> $params
     * @param string|null $token персональний токен користувача; null - службовий
     * @throws ZabbixException
     */
    public function call(string $method, array $params = [], ?string $token = null): mixed
    {
        $token ??= $this->serviceToken;
        try {
            $this->api ??= new ZabbixApi();
            if ($this->loggedAs !== $token) {
                $this->loggedAs = null;
                $this->api->loginToken($this->host, $token);
                $this->loggedAs = $token;
            }
            return $this->api->call($method, $params);
        } catch (\Throwable $e) {
            $this->loggedAs = null;
            throw new ZabbixException($method.': '.$e->getMessage(), (int)$e->getCode(), $e);
        }
    }
}

<?php

namespace WISECP\Modules\Registrars;

use DomainNameApi\DomainNameAPI_PHPLibrary;
use Exception;

/**
 * DomainNameAPI Registrar Module for WISECP 5
 *
 * WISECP 5 replaced the 3.x registrar contract: the class extends RegistrarModule, the core
 * calls the base create()/renew() and probes the v5 method names below with method_exists(),
 * and the domain being worked on arrives in $this->options instead of a $params argument.
 * The WISECP 3.x module lives in github.com/domainreseller/wisecp-dna.
 *
 * @package    coremio/modules/Registrars/DomainNameAPI
 * @version    4.0.4
 * @license    MIT License https://opensource.org/licenses/MIT
 * @maintainer Bünyamin AKÇAY<bunyamin@bunyam.in>
 */
class DomainNameAPI extends \RegistrarModule
{
    const VERSION = '4.0.4';

    /**
     * Zone record types the gateway can store.
     *
     * Kept as a literal rather than read from DNARest::ZONE_RECORD_TYPES: the constructor tops
     * up config.php before api.php is included. The two lists must stay in sync.
     */
    const DNS_RECORD_TYPES = [
        'A', 'AAAA', 'ALIAS', 'CAA', 'CNAME', 'DS', 'MX', 'NAPTR', 'PTR', 'SRV', 'TLSA', 'TXT',
    ];

    const PENDING_STATUSES = [
        'waitingfordocument',
        'waitingforregistration',
        'confirmationemailsend',
        'preregistration',
        'pendinghold',
        'modificationpending',
    ];

    // The domain no longer lives on this registrar (transferred out or deleted). BUG-10238.
    const GONE_STATUSES = ['TransferredOut', 'Deleted', 'PendingDelete'];

    const CONTACT_TYPES = [
        'registrant'     => 'Registrant',
        'administrative' => 'Administrative',
        'technical'      => 'Technical',
        'billing'        => 'Billing',
    ];

    const SECRET_MASK     = '********';
    const CACHE_STORE     = 'domainnameapi';
    const TLD_CACHE_TTL   = 180;
    const QUERY_CACHE_TTL = 300;
    const INFO_CACHE_TTL  = 180;
    const LIST_PAGE_SIZE  = 200;
    const LIST_MAX_PAGES  = 100;

    /** @var DomainNameAPI_PHPLibrary|null */
    private $dnaClient = null;

    /**
     * The last good GetDetails answer, keyed by domain. One domain page asks for the lock, the
     * privacy flag, the auth code and the child nameservers in the same request; they share it.
     */
    private array $dnaDetails = [];

    public function __construct(...$args)
    {
        // A manual upload overwrites every file the package carries, so the live settings sit
        // in config.php, which the package never ships; it is seeded from config.sample.php.
        $configFile = __DIR__ . DS . 'config.php';
        $sampleFile = __DIR__ . DS . 'config.sample.php';
        $seeded     = !file_exists($configFile) && file_exists($sampleFile) && copy($sampleFile, $configFile);

        parent::__construct(...$args);

        // The loader read the module before config.php existed.
        if ($seeded || empty($this->config)) {
            $this->config = include $configFile;
        }

        $this->dnaUpgradeConfig($configFile);
    }

    /**
     * Stamp the running version and top up keys that older config.php files predate.
     *
     * A missing 'dns-record-types' is a fatal on the customer's DNS tab, and an update never
     * rewrites an existing config.php, so the fix lands the moment the module is built.
     */
    private function dnaUpgradeConfig(string $configFile): void
    {
        $changed = false;

        if (($this->config['meta']['version'] ?? null) !== self::VERSION) {
            $this->config['meta']['version'] = self::VERSION;
            $changed = true;
        }

        if (empty($this->config['settings']['dns-record-types'])) {
            $this->config['settings']['dns-record-types'] = self::DNS_RECORD_TYPES;
            $changed = true;
        }

        // Telemetry is opt-in: an install that never saved the setting gets it written as off.
        if (!array_key_exists('error-reporting', $this->config['settings'] ?? [])) {
            $this->config['settings']['error-reporting'] = 0;
            $changed = true;
        }

        if ($changed) {
            \FileManager::file_write($configFile, \Utility::array_export($this->config, ['pwith' => true]));
        }
    }

    /**
     * Settings form. The saved API key is encrypted, so the form only ever shows a mask.
     */
    public function config_fields($data = []): array
    {
        $data = is_array($data) && $data ? $data : ($this->config['settings'] ?? []);

        // The core settings template draws cost-currency and the default nameservers itself;
        // auto-pricing (adp) only when the module has cost_prices(), so that one is ours.
        return [
            'username'      => [
                'name'        => $this->lang['fields']['username'] ?? 'Reseller ID',
                'description' => $this->lang['desc']['username'] ?? '',
                'type'        => 'text',
                'value'       => (string) ($data['username'] ?? ''),
            ],
            'password'      => [
                'name'        => $this->lang['fields']['password'] ?? 'API Key',
                'description' => $this->lang['desc']['password'] ?? '',
                'type'        => 'password',
                'value'       => !empty($data['password']) ? self::SECRET_MASK : '',
            ],
            'test-mode'     => [
                'name'        => $this->lang['fields']['test-mode'] ?? 'Test Mode',
                'description' => $this->lang['desc']['test-mode'] ?? '',
                'type'        => 'approval',
                'checked'     => (bool) ($data['test-mode'] ?? false),
            ],
            'error-reporting' => [
                'name'        => $this->lang['fields']['error-reporting'] ?? 'Telemetry & Error Reporting',
                'description' => $this->lang['desc']['error-reporting'] ?? '',
                'type'        => 'approval',
                'checked'     => $this->dnaReportsErrors($data),
            ],
            'adp'           => [
                'name'        => $this->lang['fields']['adp'] ?? 'Automatic Cost Updates',
                'description' => $this->lang['desc']['adp'] ?? '',
                'type'        => 'approval',
                'checked'     => (bool) ($data['adp'] ?? false),
            ],
            'exclude'       => [
                'name'        => $this->lang['fields']['exclude'] ?? 'Excluded Extensions',
                'description' => $this->lang['desc']['exclude'] ?? '',
                'type'        => 'text',
                'value'       => (string) ($data['exclude'] ?? ''),
                'placeholder' => 'com.tr,org.tr',
            ],
            'balance'       => [
                'name'        => $this->lang['fields']['balance'] ?? 'Balance',
                'type'        => 'output',
                'value'       => htmlspecialchars($this->dnaBalanceText(), ENT_QUOTES, 'UTF-8'),
            ],
        ];
    }

    /**
     * Save the settings form. Overridden so the API key is stored encrypted and an untouched
     * API key field (blank or the mask) keeps the stored secret instead of overwriting it.
     */
    public function controller_settings($extraFields = []): array
    {
        $fields   = \Filter::POST("fields") ?: [];
        $config   = $this->config;
        $settings = $config['settings'] ?? [];

        $settings['username'] = trim(\Filter::html_clear((string) ($fields['username'] ?? '')));

        $password = trim((string) ($fields['password'] ?? ''));
        if ($password !== '' && !str_starts_with($password, '*')) {
            $settings['password'] = $this->encode_str($password);
        }

        // An unticked box is absent from the post, which is its "off" value.
        $settings['test-mode']     = (int) ($fields['test-mode'] ?? 0) === 1 ? 1 : 0;
        $settings['error-reporting'] = (int) ($fields['error-reporting'] ?? 0) === 1 ? 1 : 0;
        $settings['adp']           = (int) ($fields['adp'] ?? 0) === 1;
        $settings['cost-currency'] = (int) ($fields['cost-currency'] ?? 4) ?: 4;
        $settings['exclude']       = implode(',', $this->dnaParseTldList((string) ($fields['exclude'] ?? '')));

        // Default nameservers come from the core template's own field.
        $nameservers = [];
        foreach ((array) ($fields['nameservers'] ?? []) as $ns) {
            $ns = trim(\Filter::html_clear((string) $ns));
            if ($ns !== '') {
                $nameservers[] = $ns;
            }
        }
        $settings['nameservers'] = $nameservers;

        $config['settings'] = $settings;
        $file               = $this->dir . 'config.php';

        if (\FileManager::file_write($file, \Utility::array_export($config, ['pwith' => true])) === false) {
            return ['status' => "error", 'message' => \Modules::unwritable_message($file)];
        }

        $this->config    = $config;
        $this->dnaClient = null;

        $admin = \UserManager::LoginData("admin");
        \User::addAction($admin["id"] ?? 0, "alteration", "changed-registrars-module-settings", [
            'module' => $config['meta']['name'] ?? $this->_name,
            'name'   => $this->lang['name'] ?? $this->_name,
        ]);

        \Hook::run('action:domain.registrar_settings_saved', $this->_name, $config);

        return [
            'status'  => "successful",
            'message' => \Language::gc("admin/ac-settings/successful1"),
        ];
    }

    /**
     * Test button. The base passes the settings merged with what the form posted.
     */
    public function testConnection(#[\SensitiveParameter] $config = []): bool
    {
        $settings = $config['settings'] ?? $config;
        $stored   = (string) ($this->config['settings']['password'] ?? '');
        $username = trim((string) ($settings['username'] ?? ''));
        $password = trim((string) ($settings['password'] ?? ''));

        // An untouched API key field posts back the mask (or the stored ciphertext): test
        // with the stored secret then.
        if ($password === '' || str_starts_with($password, '*') || $password === $stored) {
            $password = $stored !== '' ? $this->decode_str($stored) : '';
        }

        if ($username === '' || $password === '') {
            $this->error = $this->lang['error8'] ?? 'Please enter the API information';
            return false;
        }

        try {
            $response = $this->dnaConnect($username, $password, !empty($settings['test-mode']))->getResellerDetails();
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }

        if (!$this->dnaOk($response)) {
            $this->dnaFail($response);
            return false;
        }

        return true;
    }

    /**
     * Availability, keyed by the TLDs asked for: available|unavailable, or unknown when the
     * gateway could not answer.
     */
    public function check($sld = null, $tlds = []): array
    {
        if (!is_array($tlds)) {
            $tlds = [$tlds];
        }

        if ($sld == '' || empty($tlds)) {
            $this->error = $this->lang['error2'] ?? 'Domain and extension information are not present';
            return [];
        }

        $sld = $this->dnaAscii((string) $sld);

        $result = [];
        $lookup = [];
        foreach ($tlds as $tld) {
            $result[$tld] = ['status' => 'unknown', 'premium' => false];
            $lookup[strtolower(ltrim((string) $tld, '.'))] = $tld;
        }

        $response = $this->dnaRemember('check_' . md5(json_encode([$sld, $tlds])), self::QUERY_CACHE_TTL,
            fn () => $this->dnaClient()->checkAvailability([$sld], array_values($tlds), 1, "create"));

        if (!$this->dnaOk($response)) {
            $this->dnaFail($response);
            return $result;
        }

        foreach ($response as $row) {
            $tld = is_array($row) ? ($lookup[strtolower(ltrim((string) ($row['TLD'] ?? ''), '.'))] ?? null) : null;
            if ($tld === null) {
                continue;
            }

            $result[$tld]['status'] = ($row['Status'] ?? '') === 'available' ? 'available' : 'unavailable';

            if ($this->dnaFlag($row['IsFee'] ?? false)) {
                $result[$tld]['premium']       = true;
                $result[$tld]['premium_price'] = [
                    'amount'   => (float) ($row['Price'] ?? 0),
                    'currency' => ($row['Currency'] ?? '') ?: 'USD',
                ];
            }
        }

        return $result;
    }

    public function register(): array|bool
    {
        $domain = $this->dnaDomain();
        $year   = $this->dnaYears();
        $api    = $this->dnaClient();

        // A retried order must not register (and charge) the same domain twice: when it is
        // already in the account, report what is there instead.
        $existing = $api->getDetails($domain);
        if ($this->dnaOk($existing)) {
            $status = (string) ($existing['data']['Status'] ?? '');
            $config = ['ID' => $existing['data']['ID'] ?? 0];

            if ($status === 'Active') {
                return ['config' => $config];
            }

            if ($this->dnaIsPending($status)) {
                return ['status' => "inprocess", 'config' => $config];
            }

            throw new Exception($domain . ': ' . $status);
        }

        $whois      = $this->dnaContactsIn((array) ($this->options['whois'] ?? []));
        $this->dnaRequireContacts($whois);
        $dns        = array_values($this->dnaNameservers((array) ($this->options['dns'] ?? [])));
        $privacy    = $this->dnaWantsPrivacy();
        $additional = str_ends_with($domain, '.tr') ? $this->dnaTrabisAttributes($whois) : [];

        $this->save_log("PreRegister", [
            'domain'   => $domain,
            'year'     => $year,
            'start_at' => date('Y-m-d H:i:s'),
        ], []);

        $response = $api->registerWithContactInfo($domain, $year, $whois, $dns, false, $privacy, $additional);

        $this->save_log("Register", [
            'domain'    => $domain,
            'year'      => $year,
            'whois'     => $whois,
            'finish_at' => date('Y-m-d H:i:s'),
        ], $response);

        if (!$this->dnaOk($response)) {
            if (stripos($this->dnaFail($response), 'ERR_INVALID_ORGANIZATION') !== false) {
                $this->error = $this->lang['error11'];
            }

            throw new Exception($this->error);
        }

        $config = ['ID' => $response['data']['ID'] ?? 0];

        // BUG-10456: "Success" does not mean active; a domain waiting for documents or approval
        // is a queued registration. The base keeps the service in process and asks
        // registration_sync() for the outcome.
        if ($this->dnaIsPending($response['data']['Status'] ?? '')) {
            $this->dnaLogPending(__FUNCTION__, $domain, (string) $response['data']['Status']);

            return ['status' => "inprocess", 'config' => $config];
        }

        // No top-level status on success: the core reads it as the service status.
        return ['config' => $config];
    }

    /**
     * Outcome of a registration register() reported as queued: active once the gateway shows
     * the domain Active, failed when it is gone, pending while it waits for documents or approval.
     */
    public function registration_sync(): array|false
    {
        $domain  = $this->dnaDomain();
        $details = $this->dnaDetailsFor($domain, true);

        if (!$this->dnaOk($details)) {
            // false is "could not ask", which keeps the registration waiting.
            $this->dnaFail($details);
            return false;
        }

        $status = (string) ($details['data']['Status'] ?? '');

        if ($status === 'Active') {
            return ['status' => 'active', 'message' => ''] + array_intersect_key($this->dnaDates($details), ['endtime' => true]);
        }

        if (in_array($status, self::GONE_STATUSES, true)) {
            return ['status' => 'failed', 'message' => $domain . ': ' . $status];
        }

        return ['status' => 'pending', 'message' => $this->dnaIsPending($status) ? ($this->lang['error12'] ?? '') : $status];
    }

    public function transfer(): array|bool
    {
        $domain = $this->dnaDomain();
        $year   = $this->dnaYears();
        $api    = $this->dnaClient();

        $response = $api->transfer($domain, (string) ($this->options['tcode'] ?? ''), $year);

        $this->save_log("Transfer", [
            'domain' => $domain,
            'year'   => $year,
        ], $response);

        if (!$this->dnaOk($response)) {
            throw new Exception($this->dnaFail($response));
        }

        // Nameservers and contacts can only be written once the domain shows up in the account;
        // until then the transfer itself is what was asked for, so a refusal here is ignored.
        if ($this->dnaOk($api->getDetails($domain))) {
            $nameservers = $this->dnaNameservers((array) ($this->options['dns'] ?? []));
            if ($nameservers) {
                $api->modifyNameServer($domain, $nameservers);
            }

            if (!empty($this->options['whois'])) {
                $api->saveContacts($domain, $this->dnaContactsIn((array) $this->options['whois']));
            }
        }

        // The privacy flag is best effort: the transfer was accepted either way, so a refusal is
        // logged rather than failing the order.
        if ($this->dnaWantsPrivacy()) {
            $privacy = $api->modifyPrivacyProtectionStatus($domain, true);

            if (!$this->dnaOk($privacy)) {
                $this->save_log("TransferPrivacy", ['domain' => $domain], $privacy);
            }
        }

        // The base turns an accepted transfer into inprocess; transfer_sync() decides completion.
        return ['config' => ['ID' => $response['data']['ID'] ?? 0]];
    }

    /**
     * Renewal in the argument shape the base still supports: its renew() calls this with the
     * renewal term spelled out, so the year never has to be guessed from the service.
     */
    public function renewal($options = [], $domain = '', $sld = '', $tld = '', $year = 1, $oduedate = '', $nduedate = '')
    {
        $domain = $domain !== '' ? $this->dnaAscii((string) $domain) : $this->dnaDomain();
        $year   = max(1, (int) $year);
        $api    = $this->dnaClient();

        $details = $api->getDetails($domain);
        if (!$this->dnaOk($details)) {
            throw new Exception($this->dnaFail($details));
        }

        $response = $api->renew($domain, $year);

        $this->save_log("Renewal", [
            'domain' => $domain,
            'year'   => $year,
        ], $response);

        if (!$this->dnaOk($response)) {
            throw new Exception($this->dnaFail($response));
        }

        $this->dnaDetails = [];

        return true;
    }

    /**
     * Scheduled status sync: ['creationtime', 'endtime', 'status'], dates as Y-m-d.
     */
    public function sync(): array|false
    {
        $domain  = $this->dnaDomain();
        $details = $this->dnaDetailsFor($domain, true);

        if (!$this->dnaOk($details)) {
            // An API error (e.g. ERR_DOMAIN_NOT_FOUND while the gateway is unstable) is NOT a
            // transfer-out signal: false tells the core it could not ask, so the service stays
            // as it is. A real transfer-out is only the TransferredOut status below.
            $this->dnaFail($details);
            return false;
        }

        $status = (string) ($details['data']['Status'] ?? '');
        $result = $this->dnaDates($details) + ['status' => 'unknown'];

        if ($status === 'Active') {
            $result['status'] = 'active';
        } elseif ($status === 'Expired') {
            $result['status'] = 'expired';
        } elseif (in_array($status, self::GONE_STATUSES, true)) {
            $result['status'] = 'transferred';
        } elseif ($this->dnaIsPending($status)) {
            $this->dnaLogPending(__FUNCTION__, $domain, $status);
        }

        return $result;
    }

    /**
     * Transfer completion: status is active once the domain is in the account, pending before.
     */
    public function transfer_sync(): array|false
    {
        $domain  = $this->dnaDomain();
        $details = $this->dnaDetailsFor($domain, true);

        if (!$this->dnaOk($details)) {
            $this->dnaFail($details);
            return false;
        }

        $status = (string) ($details['data']['Status'] ?? '');

        // BUG-10456: after a transfer the domain may still wait for documents or approval.
        if ($this->dnaIsPending($status)) {
            $this->dnaLogPending(__FUNCTION__, $domain, $status);
        }

        return $this->dnaDates($details) + ['status' => $status === 'Active' ? 'active' : 'pending'];
    }

    /**
     * Everything the import needs to build a local service for the domain.
     */
    public function get_info(): array|false
    {
        $domain  = $this->dnaDomain();
        $details = $this->dnaDetailsFor($domain);

        if (!$this->dnaOk($details)) {
            $this->dnaFail($details);
            return false;
        }

        $data    = $details['data'];
        $privacy = $this->dnaFlag($data['PrivacyProtectionStatus'] ?? false);
        $result  = [];

        if (!empty($data['Dates']['Start'])) {
            $result['creation_time'] = \DateManager::format("Y-m-d H:i:s", $data['Dates']['Start']);
        }
        if (!empty($data['Dates']['Expiration'])) {
            $result['end_time'] = \DateManager::format("Y-m-d H:i:s", $data['Dates']['Expiration']);
        }

        foreach (array_slice(array_values((array) ($data['NameServers'] ?? [])), 0, 4) as $i => $ns) {
            if ($ns) {
                $result['ns' . ($i + 1)] = $ns;
            }
        }

        $whois = $this->dnaContactsOut($domain);
        if ($whois) {
            $result['whois'] = $whois;
        }

        $result['cns']           = $this->dnaChildNameservers($data);
        $result['whois_privacy'] = ['status' => $privacy ? "enable" : "disable"];
        $result['privacy']       = $privacy;
        $result['transferlock']  = $this->dnaFlag($data['LockStatus'] ?? false);

        return $result;
    }

    public function is_inactive(): bool
    {
        $details = $this->dnaDetailsFor($this->dnaDomain());

        if (!$this->dnaOk($details)) {
            $this->dnaFail($details);
            return false;
        }

        return ($details['data']['Status'] ?? '') !== 'Active';
    }

    public function save_nameservers(array $dns): true
    {
        $response = $this->dnaClient()->modifyNameServer($this->dnaDomain(), $this->dnaNameservers($dns));

        $this->dnaDetails = [];

        if (!$this->dnaOk($response)) {
            throw new Exception($this->dnaFail($response));
        }

        return true;
    }

    public function get_child_nameservers(): array|false
    {
        $details = $this->dnaDetailsFor($this->dnaDomain());

        if (!$this->dnaOk($details)) {
            $this->dnaFail($details);
            return false;
        }

        return $this->dnaChildNameservers($details['data']);
    }

    public function add_child_nameserver(string $ns, string $ip): array
    {
        $response = $this->dnaClient()->addChildNameServer($this->dnaDomain(), $ns, $ip);

        $this->dnaDetails = [];

        if (!$this->dnaOk($response)) {
            throw new Exception($this->dnaFail($response));
        }

        return ['ns' => $ns, 'ip' => $ip];
    }

    public function save_child_nameserver(array $old, string $new_ns, string $new_ip): array
    {
        $domain = $this->dnaDomain();
        $api    = $this->dnaClient();
        $oldNs  = (string) ($old['ns'] ?? '');

        // The registry keys a host by its name, so a rename is a new host plus dropping the old.
        if ($oldNs === '' || strcasecmp($oldNs, $new_ns) !== 0) {
            $response = $api->addChildNameServer($domain, $new_ns, $new_ip);
            if ($this->dnaOk($response) && $oldNs !== '') {
                $response = $api->deleteChildNameServer($domain, $oldNs);
            }
        } elseif ((string) ($old['ip'] ?? '') !== $new_ip) {
            $response = $api->modifyChildNameServer($domain, $new_ns, $new_ip);
        } else {
            return ['ns' => $new_ns, 'ip' => $new_ip];
        }

        $this->dnaDetails = [];

        if (!$this->dnaOk($response)) {
            throw new Exception($this->dnaFail($response));
        }

        return ['ns' => $new_ns, 'ip' => $new_ip];
    }

    public function delete_child_nameserver(string $ns, string $ip): bool
    {
        $response = $this->dnaClient()->deleteChildNameServer($this->dnaDomain(), $ns);

        $this->dnaDetails = [];

        if (!$this->dnaOk($response)) {
            throw new Exception($this->dnaFail($response));
        }

        return true;
    }

    /**
     * Zone records of the domain, SOA and NS left out (nameservers have their own screen).
     */
    public function get_dns_records(): array|false
    {
        $domain = $this->dnaDomain();
        $result = $this->dnaClient()->getResourceRecords($domain);

        if (!$this->dnaOk($result)) {
            $this->dnaFail($result);
            return false;
        }

        $records = [];
        foreach ($result['data']['records'] ?? [] as $rec) {
            $type = strtoupper((string) ($rec['Type'] ?? ''));
            if (in_array($type, ['SOA', 'NS'], true)) {
                continue;
            }

            $host     = $this->dnaRelativeHost($rec['Name'] ?? '', $domain);
            $value    = $this->dnaDisplayValue($type, rtrim((string) ($rec['Content'] ?? ''), '.'));
            $priority = '';
            if ($type === 'MX') {
                $parts = preg_split('/\s+/', trim((string) ($rec['Content'] ?? '')), 2);
                if (count($parts) === 2) {
                    $priority = $parts[0];
                    $value    = rtrim($parts[1], '.');
                }
            }

            $records[] = [
                'identity' => $this->dnaIdentity($host, $type, $rec['Content'] ?? ''),
                'type'     => $type,
                'name'     => $host,
                'value'    => $value,
                'ttl'      => (int) ($rec['TTL'] ?? 0),
                'priority' => $priority,
            ];
        }

        return $records;
    }

    /**
     * The gateway stores a name+type as one set and replaces it wholesale on every write, so a
     * second MX (or a second A for the same host) has to merge with what is already there:
     * AddResourceRecordValue does that, a plain add would drop the sibling records.
     */
    public function add_dns_record($type, $name, $value, $ttl, $priority)
    {
        $domain  = $this->dnaDomain();
        $content = $this->dnaContent($type, $value, $priority);

        $result = $this->dnaClient()->addResourceRecordValue($domain, $this->dnaApiHost($name, $domain), strtoupper((string) $type), $content, (int) $ttl ?: null);

        if (!$this->dnaOk($result)) {
            throw new Exception($this->dnaFail($result));
        }

        return true;
    }

    /**
     * The identity says which value of the set is being edited; only that one is swapped, so the
     * other values under the same name+type survive.
     */
    public function update_dns_record($type = '', $name = '', $value = '', $identity = '', $ttl = '', $priority = '')
    {
        $domain  = $this->dnaDomain();
        $api     = $this->dnaClient();
        $host    = $this->dnaApiHost($name, $domain);
        $type    = strtoupper((string) $type);
        $content = $this->dnaContent($type, $value, $priority);

        $current = $this->dnaFindRecord($domain, $identity);
        if (!$current) {
            throw new Exception($this->error ?: 'DNS record not found.');
        }

        // Renaming or retyping a record is a move: drop the old value, then add the new one, so
        // neither set loses its other values.
        if (strcasecmp($current['name'], $host) !== 0 || strcasecmp($current['type'], $type) !== 0) {
            $removed = $api->removeResourceRecordValue($domain, $current['name'], $current['type'], $current['content']);
            if (!$this->dnaOk($removed)) {
                throw new Exception($this->dnaFail($removed));
            }

            $result = $api->addResourceRecordValue($domain, $host, $type, $content, (int) $ttl ?: null);
        } else {
            $result = $api->replaceResourceRecordValue($domain, $host, $type, $current['content'], $content, (int) $ttl ?: null);
        }

        if (!$this->dnaOk($result)) {
            throw new Exception($this->dnaFail($result));
        }

        return true;
    }

    /**
     * Removes only this value; the rest of the set is written back. A bare delete would take the
     * whole name+type with it.
     */
    public function delete_dns_record($type = '', $name = '', $value = '', $identity = '')
    {
        $domain = $this->dnaDomain();
        $host   = $this->dnaApiHost($name, $domain);
        $type   = strtoupper((string) $type);

        // Prefer the stored value behind the identity: what the panel shows is normalised (TXT
        // unquoted, MX priority split out) and would not match.
        $current = $this->dnaFindRecord($domain, $identity);
        $content = $current ? $current['content'] : $this->dnaContent($type, $value, '');
        if ($current) {
            $host = $current['name'];
            $type = $current['type'];
        }

        $result = $this->dnaClient()->removeResourceRecordValue($domain, $host, $type, $content);

        if (!$this->dnaOk($result)) {
            throw new Exception($this->dnaFail($result));
        }

        return true;
    }

    public function get_contacts(): array|false
    {
        return $this->dnaContactsOut($this->dnaDomain());
    }

    public function save_contacts(array $whois): bool
    {
        $contacts = $this->dnaContactsIn($whois);
        $this->dnaRequireContacts($contacts);

        $response = $this->dnaClient()->saveContacts($this->dnaDomain(), $contacts);

        if (!$this->dnaOk($response)) {
            throw new Exception($this->dnaFail($response));
        }

        return true;
    }

    public function get_whois_privacy(): string
    {
        $details = $this->dnaDetailsFor($this->dnaDomain());

        if (!$this->dnaOk($details)) {
            throw new Exception($this->dnaFail($details));
        }

        // The gateway sends the flag as the string "true"/"false"; "false" is truthy in PHP.
        return $this->dnaFlag($details['data']['PrivacyProtectionStatus'] ?? false) ? "active" : "passive";
    }

    public function toggle_whois_privacy(string $status): bool
    {
        $response = $this->dnaClient()->modifyPrivacyProtectionStatus($this->dnaDomain(), $this->dnaTurnsOn($status));

        $this->dnaDetails = [];

        if (!$this->dnaOk($response)) {
            throw new Exception($this->dnaFail($response));
        }

        return true;
    }

    public function get_transfer_lock(): string
    {
        $details = $this->dnaDetailsFor($this->dnaDomain());

        if (!$this->dnaOk($details)) {
            throw new Exception($this->dnaFail($details));
        }

        return $this->dnaFlag($details['data']['LockStatus'] ?? false) ? "active" : "passive";
    }

    public function toggle_transfer_lock(string $status): bool
    {
        $domain   = $this->dnaDomain();
        $api      = $this->dnaClient();
        $response = $this->dnaTurnsOn($status) ? $api->enableTheftProtectionLock($domain) : $api->disableTheftProtectionLock($domain);

        $this->dnaDetails = [];

        if (!$this->dnaOk($response)) {
            throw new Exception($this->dnaFail($response));
        }

        return true;
    }

    public function get_auth_code(): string|true
    {
        $details = $this->dnaDetailsFor($this->dnaDomain());

        if (!$this->dnaOk($details)) {
            throw new Exception($this->dnaFail($details));
        }

        $code = (string) ($details['data']['AuthCode'] ?? '');
        if ($code === '') {
            throw new Exception($this->lang['error10'] ?? 'An error has occurred');
        }

        return $code;
    }

    /**
     * The account's active domains, for the import screen.
     */
    public function domains()
    {
        $api  = $this->dnaClient();
        $rows = [];

        for ($page = 0; $page < self::LIST_MAX_PAGES; $page++) {
            $response = $api->getList(['PageNumber' => $page, 'PageSize' => self::LIST_PAGE_SIZE]);

            if (!$this->dnaOk($response)) {
                $this->dnaFail($response);
                return false;
            }

            $batch = $response['data']['Domains'] ?? [];

            foreach ($batch as $item) {
                $domain = (string) ($item['DomainName'] ?? '');
                if ($domain === '' || ($item['Status'] ?? '') !== 'Active') {
                    continue;
                }

                $order_id  = 0;
                $user_data = [];

                $imported = \WDB::select("id,owner_id AS user_id")->from("users_products");
                $imported->where("type", '=', "domain", "&&");
                $imported->where("name", '=', $domain);
                $imported = $imported->build() ? $imported->getAssoc() : false;

                if ($imported) {
                    $order_id  = (int) $imported['id'];
                    $user_data = \User::getData($imported['user_id'], "id,full_name,company_name", "array");
                }

                $rows[] = [
                    'domain'        => $domain,
                    'creation_date' => !empty($item['Dates']['Start']) ? \DateManager::format("Y-m-d", $item['Dates']['Start']) : '',
                    'end_date'      => !empty($item['Dates']['Expiration']) ? \DateManager::format("Y-m-d", $item['Dates']['Expiration']) : '',
                    'order_id'      => $order_id,
                    'user_data'     => $user_data ?: [],
                ];
            }

            if (count($batch) < self::LIST_PAGE_SIZE) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Extension catalogue for the import and the daily cost update, in the per-year shape:
     * every term the gateway prices, each in the currency it bills it in (the .tr family in
     * TRY, the rest in USD), so the core converts from the real source currency.
     */
    public function tlds()
    {
        $response = $this->dnaRemember('tlds', self::TLD_CACHE_TTL, fn () => $this->dnaClient()->getTldList(999));

        if (!$this->dnaOk($response)) {
            $this->dnaFail($response);
            return false;
        }

        $excluded = $this->dnaParseTldList((string) ($this->config['settings']['exclude'] ?? ''));
        $types    = ['register' => 'registration', 'renewal' => 'renew', 'transfer' => 'transfer'];
        $list     = [];

        foreach ($response['data'] ?? [] as $row) {
            $name = strtolower(trim((string) ($row['tld'] ?? '')));

            if ($name === '' || ($row['status'] ?? '') !== 'Active' || !isset($row['pricing']['registration'][1]) || in_array($name, $excluded, true)) {
                continue;
            }

            $pricing = [];
            foreach ($types as $ours => $theirs) {
                $currency = $this->dnaCurrency($row['currencies'][$theirs] ?? '');

                foreach ((array) ($row['pricing'][$theirs] ?? []) as $years => $cost) {
                    if ((int) $years > 0 && is_numeric($cost)) {
                        $pricing[$ours][(int) $years] = [$currency => ['cost' => (float) $cost]];
                    }
                }
            }

            $list[$name] = [
                'min_years'     => max(1, (int) ($row['minperiod'] ?? 1)),
                'max_years'     => (int) ($row['maxperiod'] ?? 10) ?: 10,
                'whois_privacy' => true,
                'dns_manage'    => true,
                // REST does not send these two; most extensions take an EPP code and need no paperwork.
                'epp_code'      => isset($row['IsTransferable']) ? $this->dnaFlag($row['IsTransferable']) : true,
                'paperwork'     => $this->dnaFlag($row['IsDocumentRequired'] ?? false),
                'pricing'       => $pricing,
            ];
        }

        return $list;
    }

    private function dnaIncludeLibrary(): void
    {
        if (!class_exists(DomainNameAPI_PHPLibrary::class, false)) {
            include_once __DIR__ . DS . 'api.php';
        }
    }

    /**
     * The API client, built on first use: the base binds the service after construction, and
     * the credentials are only needed once something talks to the gateway.
     */
    private function dnaClient(): DomainNameAPI_PHPLibrary
    {
        if ($this->dnaClient) {
            return $this->dnaClient;
        }

        $settings = $this->config['settings'] ?? [];
        $username = trim((string) ($settings['username'] ?? ''));
        $password = !empty($settings['password']) ? $this->decode_str((string) $settings['password']) : '';

        if ($username === '' || $password === '') {
            throw new Exception($this->lang['error1'] ?? 'API information is not available');
        }

        return $this->dnaClient = $this->dnaConnect($username, $password, !empty($settings['test-mode']));
    }

    /**
     * Build the library client. A failure is rethrown without the original exception: that
     * one's trace carries the constructor arguments, the API key among them, into whatever logs
     * the error.
     */
    private function dnaConnect(
        string $username,
        #[\SensitiveParameter]
        string $password,
        bool $testMode
    ): DomainNameAPI_PHPLibrary {
        $this->dnaIncludeLibrary();

        try {
            $client = new DomainNameAPI_PHPLibrary($username, $password, $testMode);
        } catch (\Throwable $e) {
            throw new Exception($e->getMessage());
        }

        $client->setErrorReportingEnabled($this->dnaReportsErrors($this->config['settings'] ?? []));
        // When switched on, reports carry only the error code and message, endpoint, timing,
        // versions and reseller ID: no customer data, domain, panel address, account or server IP.
        $client->setTelemetryMinimal(true);

        return $client;
    }

    /**
     * Read-through cache for an idempotent gateway read. A failed call is thrown out of the
     * producer so it is never stored, and the caller gets that failed answer back to report.
     */
    private function dnaRemember(string $key, int $ttl, callable $call)
    {
        $settings = $this->config['settings'] ?? [];
        $key      = $key . '_' . md5(($settings['username'] ?? '') . '|' . (int) !empty($settings['test-mode']));
        $failed   = null;

        try {
            return \Cache::remember(self::CACHE_STORE, $key, $ttl, function () use ($call, &$failed): array {
                $response = $call();

                if (!$this->dnaOk($response)) {
                    $failed = is_array($response) ? $response : [];
                    throw new \RuntimeException('DomainNameAPI call failed');
                }

                return $response;
            });
        } catch (\RuntimeException $e) {
            if ($failed === null) {
                throw $e;
            }

            return $failed;
        }
    }

    /**
     * GetDetails for the domain, shared by the reads of one request. Only a good answer is kept,
     * so a failed call is asked again by the next reader.
     */
    private function dnaDetailsFor(string $domain, bool $fresh = false)
    {
        if (!$fresh && isset($this->dnaDetails[$domain])) {
            return $this->dnaDetails[$domain];
        }

        $response = $this->dnaClient()->getDetails($domain);

        $this->dnaDetails = $this->dnaOk($response) ? [$domain => $response] : [];

        return $response;
    }

    /**
     * The domain the bound service is about, in punycode: every gateway call wants ASCII.
     */
    private function dnaDomain(): string
    {
        $domain = (string) ($this->options['domain'] ?? '');
        $sld    = (string) ($this->options['sld'] ?? ($this->options['name'] ?? ''));
        $tld    = (string) ($this->options['tld'] ?? '');

        if ($domain === '' && $sld !== '' && $tld !== '') {
            $domain = $sld . '.' . $tld;
        }

        if ($domain === '') {
            $domain = (string) ($this->service['name'] ?? '');
        }

        if ($domain === '') {
            throw new Exception($this->lang['error2'] ?? 'Domain and extension information are not present');
        }

        return $this->dnaAscii(trim($domain));
    }

    private function dnaAscii(string $domain): string
    {
        if (function_exists('idn_to_ascii')) {
            return idn_to_ascii($domain, 0, INTL_IDNA_VARIANT_UTS46) ?: $domain;
        }

        return $domain;
    }

    private function dnaYears(): int
    {
        return max(1, (int) ($this->options['year'] ?? ($this->service['period_time'] ?? 1)));
    }

    private function dnaWantsPrivacy(): bool
    {
        return $this->dnaFlag($this->addon_params['whois-privacy'] ?? ($this->options['wprivacy'] ?? false));
    }

    /**
     * Nameserver list as the gateway takes it: ns1..nsN, blanks dropped, order kept.
     */
    private function dnaNameservers(array $dns): array
    {
        $list = [];
        foreach (array_values(array_filter(array_map(fn ($ns) => trim((string) $ns), $dns), 'strlen')) as $i => $ns) {
            $list['ns' . ($i + 1)] = $ns;
        }

        return $list;
    }

    private function dnaChildNameservers(array $data): array
    {
        $result = [];
        foreach (array_values((array) ($data['ChildNameServers'] ?? [])) as $i => $cns) {
            $result[$i + 1] = [
                'ns' => (string) ($cns['ns'] ?? ''),
                'ip' => (string) ($cns['ip'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * Start and expiry as the sync keys want them.
     */
    private function dnaDates(array $details): array
    {
        $dates  = $details['data']['Dates'] ?? [];
        $result = [];

        if (!empty($dates['Start'])) {
            $result['creationtime'] = \DateManager::format("Y-m-d", $dates['Start']);
        }
        if (!empty($dates['Expiration'])) {
            $result['endtime'] = \DateManager::format("Y-m-d", $dates['Expiration']);
        }

        return $result;
    }

    /**
     * @param string|false $status
     */
    private function dnaIsPending($status): bool
    {
        return in_array(strtolower(trim((string) $status)), self::PENDING_STATUSES, true);
    }

    private function dnaLogPending(string $action, string $domain, string $status): void
    {
        $this->save_log(ucfirst($action), ['domain' => $domain], [
            'status'  => $status,
            'message' => $this->lang['error12'] ?? '',
        ]);
    }

    /**
     * The gateway answers with 'result' => 'OK' or an 'error' block; the availability check
     * answers with a bare list on success.
     */
    private function dnaOk($response): bool
    {
        if (!is_array($response) || isset($response['error'])) {
            return false;
        }

        return !isset($response['result']) || $response['result'] === 'OK';
    }

    private function dnaErrorText($response): string
    {
        $error = is_array($response) ? ($response['error'] ?? null) : null;
        $text  = is_array($error)
            ? trim(($error['Message'] ?? '') . ' : ' . ($error['Details'] ?? ''), ' :')
            : trim((string) $error);

        return $text !== '' ? $text : ($this->lang['error10'] ?? 'An error has occurred');
    }

    /**
     * Record the failure where both the v5 caller and the older $error readers find it.
     */
    private function dnaFail($response): string
    {
        return $this->error = $this->dnaErrorText($response);
    }

    /**
     * Booleans arrive as real booleans or as the strings "true"/"false", depending on the path.
     */
    private function dnaFlag($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function dnaTurnsOn(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['enable', 'enabled', 'active', 'on', '1', 'true'], true);
    }

    private function dnaCurrency($code)
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            return (int) ($this->config['settings']['cost-currency'] ?? 4);
        }

        return $code === 'TL' ? 'TRY' : $code;
    }

    private function dnaParseTldList(string $list): array
    {
        $tlds = array_map(fn ($tld) => strtolower(ltrim(trim($tld), '.')), explode(',', $list));

        return array_values(array_unique(array_filter($tlds, 'strlen')));
    }

    /**
     * The reseller's balances for the settings page; empty until credentials are saved, and never
     * an exception, since the page must render either way.
     */
    private function dnaBalanceText(): string
    {
        if (empty($this->config['settings']['username']) || empty($this->config['settings']['password'])) {
            return '';
        }

        try {
            $response = $this->dnaRemember('reseller', self::INFO_CACHE_TTL, fn () => $this->dnaClient()->getResellerDetails());
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        if (!$this->dnaOk($response)) {
            return $this->dnaErrorText($response);
        }

        $balances = [];
        foreach ((array) ($response['balances'] ?? []) as $balance) {
            $balances[] = trim(($balance['currency'] ?? '') . ' ' . number_format((float) ($balance['balance'] ?? 0), 2, '.', ','));
        }

        return implode(' · ', $balances);
    }

    /**
     * The four contacts in WISECP's shape, read from the gateway.
     */
    private function dnaContactsOut(string $domain)
    {
        $response = $this->dnaClient()->getContacts($domain);

        if (!$this->dnaOk($response)) {
            $this->dnaFail($response);
            return false;
        }

        $contacts = $response['data']['contacts'] ?? [];
        if (!$contacts) {
            return [];
        }

        $whois = [];
        foreach (self::CONTACT_TYPES as $key => $type) {
            $contact = $contacts[$type] ?? ($contacts['Registrant'] ?? []);
            $first   = (string) ($contact['FirstName'] ?? '');
            $last    = (string) ($contact['LastName'] ?? '');

            $whois[$key] = [
                'FirstName'        => $first,
                'LastName'         => $last,
                'Name'             => trim($first . ' ' . $last),
                'Company'          => (string) ($contact['Company'] ?? ''),
                'EMail'            => (string) ($contact['EMail'] ?? ''),
                'AddressLine1'     => (string) ($contact['Address']['Line1'] ?? ''),
                'AddressLine2'     => (string) ($contact['Address']['Line2'] ?? ''),
                'City'             => (string) ($contact['Address']['City'] ?? ''),
                'State'            => (string) (($contact['Address']['State'] ?? '') ?: 'N/A'),
                'ZipCode'          => (string) ($contact['Address']['ZipCode'] ?? ''),
                'Country'          => (string) ($contact['Address']['Country'] ?? ''),
                'PhoneCountryCode' => (string) ($contact['Phone']['Phone']['CountryCode'] ?? ''),
                'Phone'            => (string) ($contact['Phone']['Phone']['Number'] ?? ''),
                'FaxCountryCode'   => (string) ($contact['Phone']['Fax']['CountryCode'] ?? ''),
                'Fax'              => (string) ($contact['Phone']['Fax']['Number'] ?? ''),
            ];
        }

        return $whois;
    }

    /**
     * WISECP contacts (keyed registrant/administrative/technical/billing, or one bare contact
     * used for all four) in the gateway's shape. Blank required fields get the placeholders the
     * registry accepts, so an incomplete customer profile does not block the order.
     */
    private function dnaContactsIn(array $whois): array
    {
        $format = function ($v): array {
            $v = is_array($v) ? $v : [];

            $contact = [
                "FirstName"        => (string) ($v["FirstName"] ?? ''),
                "LastName"         => (string) ($v["LastName"] ?? ''),
                "Company"          => (string) ($v["Company"] ?? ''),
                "EMail"            => (string) ($v["EMail"] ?? ''),
                "AddressLine1"     => (string) ($v["AddressLine1"] ?? ''),
                "AddressLine2"     => (string) ($v["AddressLine2"] ?? ''),
                "State"            => (string) ($v["State"] ?? ''),
                "City"             => (string) ($v["City"] ?? ''),
                "Country"          => (string) ($v["Country"] ?? ''),
                "Fax"              => (string) ($v["Fax"] ?? ''),
                "FaxCountryCode"   => (string) ($v["FaxCountryCode"] ?? ''),
                "Phone"            => (string) ($v["Phone"] ?? ''),
                "PhoneCountryCode" => (string) ($v["PhoneCountryCode"] ?? ''),
                "Type"             => 'Contact',
                "ZipCode"          => (string) ($v["ZipCode"] ?? ''),
                "Status"           => '',
            ];

            // Missing details are reported by dnaRequireContacts(), never filled in: a
            // placeholder would end up in the public WHOIS record.
            if (trim($contact['LastName']) === '') {
                $contact['LastName'] = $contact['FirstName'];
            }

            return $contact;
        };

        if (isset($whois['registrant'])) {
            $contacts = [];
            foreach (self::CONTACT_TYPES as $key => $type) {
                $contacts[$type] = $format($whois[$key] ?? $whois['registrant']);
            }

            return $contacts;
        }

        $contact = $format($whois);

        return [
            'Administrative' => $contact,
            'Billing'        => $contact,
            'Technical'      => $contact,
            'Registrant'     => $contact,
        ];
    }

    /**
     * Stop when a contact lacks what the registry needs, naming the missing fields, instead of
     * sending placeholders that would be published in WHOIS.
     */
    private function dnaRequireContacts(array $contacts): void
    {
        $required = ['FirstName', 'EMail', 'AddressLine1', 'City', 'Country', 'ZipCode', 'Phone'];
        $labels   = (array) ($this->lang['contact-fields'] ?? []);
        $missing  = [];

        foreach ($contacts as $type => $contact) {
            foreach ($required as $field) {
                if (trim((string) ($contact[$field] ?? '')) === '') {
                    $missing[$field] = $labels[$field] ?? $field;
                }
            }
        }

        if ($missing) {
            $message = $this->lang['error13'] ?? 'Contact details are incomplete. Missing: %s';
            throw new Exception(sprintf($message, implode(', ', $missing)));
        }
    }

    /**
     * Telemetry runs only when the administrator switched it on (off by default).
     */
    private function dnaReportsErrors(array $settings): bool
    {
        return !empty($settings['error-reporting']);
    }

    /**
     * TRABIS attributes a .tr registration needs: an organisation with its tax details, or a
     * person with a citizen id, taken from the owner's profile. A missing one stops the order
     * instead of registering the domain with a made-up number.
     */
    private function dnaTrabisAttributes(array $whois): array
    {
        $registrant = $whois['Registrant'];

        $additional = [
            'TRABISDOMAINCATEGORY' => $registrant['Company'] ? 0 : 1,
            'TRABISCOUNTRYID'      => $registrant['Country'] == "TR" ? 215 : 888,
            'TRABISCOUNTRYNAME'    => $registrant['Country'],
            'TRABISCITYNAME'       => $registrant['City'],
            'TRABISCITIYID'        => 888,
        ];

        $identity   = '';
        $tax_number = '';
        $tax_office = '';

        $owner_id = (int) ($this->user['id'] ?? ($this->service['owner_id'] ?? 0));
        $user     = $owner_id ? \User::getInfo($owner_id, ['identity', 'company_tax_number', 'company_tax_office']) : [];

        if ($user) {
            if (!empty($user['identity'])) {
                $identity = $user['identity'];
            }
            if (!empty($user['company_tax_office'])) {
                $tax_office = $user['company_tax_office'];
            }
            if (!empty($user['company_tax_number'])) {
                $tax_number = $user['company_tax_number'];
            }
        }

        if ($registrant['Company'] && (trim($tax_number) === '' || trim($tax_office) === '')) {
            throw new Exception($this->lang['error14'] ?? 'The tax office and tax number of the company are required for a .tr domain.');
        }
        if (!$registrant['Company'] && trim($identity) === '') {
            throw new Exception($this->lang['error15'] ?? 'The Turkish ID number of the owner is required for a .tr domain.');
        }

        if ($registrant['Company']) {
            $additional['TRABISORGANIZATION'] = $registrant['Company'];
            $additional['TRABISTAXOFFICE']    = $tax_office;
            $additional['TRABISTAXNUMBER']    = $tax_number;
        } else {
            $additional['TRABISCITIZIENID']  = $identity;
            $additional['TRABISNAMESURNAME'] = $registrant['FirstName'] . ' ' . $registrant['LastName'];
        }

        return $additional;
    }

    /**
     * Turn a gateway FQDN record name ("www.example.com.") into the relative host the panel
     * shows ("www"); the apex becomes "@".
     */
    private function dnaRelativeHost($fqdn, $domain): string
    {
        $fqdn   = rtrim((string) $fqdn, '.');
        $domain = rtrim((string) $domain, '.');

        if (strcasecmp($fqdn, $domain) === 0) {
            return '@';
        }

        $suffix = '.' . $domain;
        if (strlen($fqdn) > strlen($suffix) && strcasecmp(substr($fqdn, -strlen($suffix)), $suffix) === 0) {
            return substr($fqdn, 0, -strlen($suffix));
        }

        return $fqdn;
    }

    /**
     * The record name the gateway expects: a RELATIVE label (it appends the zone itself). The
     * apex ("@", empty, or the domain) becomes an empty string.
     */
    private function dnaApiHost($name, $domain): string
    {
        $name = trim((string) $name);

        if ($name === '' || $name === '@' || strcasecmp(rtrim($name, '.'), $domain) === 0) {
            return '';
        }

        return $this->dnaRelativeHost($name, $domain);
    }

    /**
     * The gateway record content, with the MX priority folded into the value.
     */
    private function dnaContent($type, $value, $priority): string
    {
        $value = trim((string) $value);

        if (strtoupper((string) $type) === 'MX' && $priority !== '' && $priority !== null && strtoupper((string) $priority) !== 'N/A') {
            return $priority . ' ' . $value;
        }

        return $value;
    }

    /**
     * Strip the quoting the gateway adds to TXT values, so the panel shows what was typed.
     */
    private function dnaDisplayValue($type, $content): string
    {
        $content = trim((string) $content);

        if (strtoupper((string) $type) === 'TXT' && strlen($content) > 1 && $content[0] === '"' && substr($content, -1) === '"') {
            return substr($content, 1, -1);
        }

        return $content;
    }

    /**
     * A stable identity for a zone record.
     *
     * The gateway has no per-record id (a record IS its name+type+value), so the identity is
     * derived from those three. It has to be deterministic: the panel hands the identity from
     * the listing straight back to update/delete, which must find the exact stored value again.
     * A positional counter would point at the wrong record as soon as the zone changed.
     */
    private function dnaIdentity($name, $type, $content): string
    {
        return sprintf('%u', crc32(strtolower((string) $name) . '|' . strtoupper((string) $type) . '|' . trim((string) $content)));
    }

    /**
     * Resolve an identity from the panel to the record it was made from, so edit/delete know
     * which value of a multi-value set to touch.
     *
     * @return array|null ['name' => API host, 'type' => ..., 'content' => stored value]
     */
    private function dnaFindRecord(string $domain, $identity): ?array
    {
        $result = $this->dnaClient()->getResourceRecords($domain);

        if (!$this->dnaOk($result)) {
            $this->dnaFail($result);
            return null;
        }

        foreach ($result['data']['records'] ?? [] as $rec) {
            $type = strtoupper((string) ($rec['Type'] ?? ''));
            if (in_array($type, ['SOA', 'NS'], true)) {
                continue;
            }

            $host = $this->dnaRelativeHost($rec['Name'] ?? '', $domain);

            if ($this->dnaIdentity($host, $type, $rec['Content'] ?? '') === (string) $identity) {
                // The identity is built from the display host ("@" for the apex); the gateway
                // wants the API host ("") back.
                return [
                    'name'    => $this->dnaApiHost($host, $domain),
                    'type'    => $type,
                    'content' => (string) ($rec['Content'] ?? ''),
                ];
            }
        }

        return null;
    }
}

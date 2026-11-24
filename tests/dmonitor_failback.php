<?php
// Run with: php tests/dmonitor_failback.php (no database, network or credentials needed).

namespace app\lib {
    class NewDb
    {
        public static $connection;
        public static function pool() { return self::$connection; }
        public static function release($connection, $broken) {}
    }
}

namespace app\utils {
    use app\lib\NewDb;

    function fsockopen($host, $port, &$code, &$error, $timeout)
    {
        $state = NewDb::pool();
        \expect($host === $state->task['main_value'], 'probe must still target the primary on backup');
        $error = $state->healthy ? '' : 'connection refused';
        return $state->healthy ? fopen('php://memory', 'r') : false;
    }

    class MsgNotice
    {
        public static function send($action, $row, $result) { NewDb::pool()->notices[] = $action; }
    }
}

namespace app\lib\dns {
    use app\lib\NewDb;

    class statusFixture
    {
        public function setDomainRecordStatus($id, $enabled)
        {
            NewDb::pool()->requests[] = $enabled;
            return NewDb::pool()->accept;
        }
        public function getError() { return 'API unavailable'; }
    }

    // Capture the real Cloudflare adapter's PATCH at the transport boundary.
    function http_request($url, $body, $referer, $cookie, $headers, $proxy, $method)
    {
        \expect($method === 'PATCH' && str_ends_with($url, '/zones/zone/dns_records/record'), 'unexpected API request');
        $state = NewDb::pool();
        $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $state->requests[] = $request;
        if (!$state->accept) return ['body' => '{"success":false,"errors":[{"message":"API unavailable"}]}'];
        $state->record = $request;
        return ['body' => '{"success":true}'];
    }
}

namespace {
    use app\lib\NewDb;
    use app\service\TaskRunner;

    require __DIR__ . '/../app/common.php';
    require __DIR__ . '/../app/lib/DnsInterface.php';
    require __DIR__ . '/../app/lib/DnsHelper.php';
    require __DIR__ . '/../app/lib/dns/cloudflare.php';
    require __DIR__ . '/../app/utils/CheckUtils.php';
    require __DIR__ . '/../app/service/TaskRunner.php';

    function expect($ok, $message)
    {
        if (!$ok) throw new RuntimeException($message);
    }

    class Scenario
    {
        public array $task, $requests = [], $record = [], $notices = [], $logs = [];
        public bool $healthy, $accept;
        public string $provider = 'cloudflare';
        public int $remaining = 0;

        public function __construct(array $overrides = [])
        {
            $this->task = $overrides + [
                'id' => 1, 'did' => 2, 'rr' => 'www', 'recordid' => 'record', 'type' => 2,
                'main_value' => '192.0.2.10', 'backup_value' => 'backup.example.test',
                'checktype' => 1, 'checkurl' => '', 'tcpport' => 443, 'timeout' => 2,
                'cycle' => 1, 'status' => 0, 'errcount' => 0, 'switchtime' => 0,
                'cdn' => 1, 'recordinfo' => '{"Line":"0","TTL":600}'
            ];
            NewDb::$connection = $this;
        }

        public function name($table) { return new TestQuery($this, $table); }

        public function poll(bool $healthy, bool $accept = true)
        {
            $this->healthy = $healthy;
            $this->accept = $accept;
            (new TaskRunner())->execute($this->task);
        }
    }

    class TestQuery
    {
        private ?string $increment = null;
        public function __construct(private Scenario $state, private string $table) {}
        public function alias($name) { return $this; }
        public function join($table, $condition) { return $this; }
        public function field($fields) { return $this; }
        public function where($key, $value = null) { return $this; }
        public function inc($key) { $this->increment = $key; return $this; }
        public function count() { return $this->state->remaining; }
        public function find()
        {
            expect($this->table === 'domain', 'unexpected lookup');
            return ['name' => 'example.test', 'type' => $this->state->provider, 'thirdid' => 'zone', 'config' => '{"email":"","apikey":"unused"}'];
        }
        public function update($fields = [])
        {
            expect($this->table === 'dmtask', 'unexpected update');
            if ($this->increment !== null) ++$this->state->task[$this->increment];
            $this->state->task = $fields + $this->state->task;
        }
        public function insert($row) { $this->state->logs[$this->table][] = $row; }
    }

    $cases = [
        'rejected failback is retried' => function () {
            $s = new Scenario();
            $s->poll(false);
            expect($s->task['status'] === 1 && $s->record['content'] === $s->task['backup_value'], 'failover did not run');
            $s->task['switchtime'] = 123; // distinguish a new write even within one second
            $s->poll(true, false);
            expect($s->task['status'] === 1 && $s->task['switchtime'] === 123, 'failed PATCH marked the primary restored');
            expect($s->notices === [1] && array_column($s->logs['dmlog'], 'action') === [1], 'reported a failed switch as successful');
            expect($s->logs['log'][0]['data'] === 'API unavailable', 'provider error was lost');
            $s->poll(true);
            $s->poll(true);
            expect($s->task['status'] === 0 && $s->record['content'] === $s->task['main_value'], 'primary was not restored');
            expect($s->notices === [1, 2] && count($s->requests) === 3, 'missing retry or duplicate switch');
            expect(array_column($s->logs['dmlog'], 'action') === [1, 2], 'incorrect switch history');
        },
        'consecutive probes include the current one' => function () {
            $s = new Scenario(['cycle' => 3]);
            foreach ([false, false, true, false, false] as $healthy) $s->poll($healthy);
            expect(!$s->requests, 'switched on nonconsecutive failures');
            $s->poll(false);
            expect($s->task['status'] === 1, 'did not switch on third failure');
            foreach ([true, true, false, true, true] as $healthy) $s->poll($healthy);
            expect(count($s->requests) === 1, 'restored on nonconsecutive successes');
            $s->poll(true, false);
            $s->poll(true);
            expect($s->task['status'] === 0 && $s->notices === [1, 2] && count($s->requests) === 3, 'recovery threshold or retry incorrect');
        },
        'Cloudflare proxy, content, type and TTL round trip' => function () {
            foreach ([[0, '0'], [0, '1'], [1, '0'], [1, '1']] as [$cdn, $line]) {
                $ttl = $line === '1' ? 1 : 600;
                $s = new Scenario(['cdn' => $cdn, 'recordinfo' => json_encode(['Line' => $line, 'TTL' => $ttl])]);
                $s->poll(false);
                expect($s->record['proxied'] === ($cdn === 1 || $line === '1') && $s->record['type'] === 'CNAME', 'incorrect backup proxy/type');
                expect($s->record['ttl'] === ($cdn ? 1 : $ttl), 'proxied backup must use automatic TTL');
                $s->poll(true);
                expect($s->record['proxied'] === ($line === '1') && $s->record['ttl'] === $ttl, 'original proxy/TTL not restored');
                expect($s->record['type'] === 'A' && $s->record['content'] === $s->task['main_value'], 'incorrect restored record');
            }
        },
        'monitor and record status strategies' => function () {
            $s = new Scenario(['type' => 0]);
            $s->poll(false);
            $s->poll(true);
            expect(!$s->requests && $s->notices === [1, 2], 'monitor-only strategy attempted DNS changes or lost alerts');

            $s = new Scenario(['type' => 1]);
            $s->provider = 'statusFixture';
            $s->poll(false);
            $s->poll(true, false);
            $s->poll(true);
            expect($s->requests === ['0', '1', '1'] && $s->notices === [1, 2] && $s->task['status'] === 0, 'pause/enable retry incorrect');

            $s = new Scenario(['type' => 3]);
            $s->provider = 'statusFixture';
            $s->poll(true, false);
            $s->poll(true);
            expect($s->task['status'] === 1, 'conditional activation not recorded');
            $s->remaining = 2;
            $s->poll(true);
            expect($s->requests === ['1', '1', '0'] && !$s->notices && $s->task['status'] === 0, 'conditional enable/pause incorrect');
        },
    ];

    $failures = 0;
    foreach ($cases as $name => $run) {
        try {
            $run();
            echo "PASS: $name\n";
        } catch (Throwable $e) {
            ++$failures;
            fwrite(STDERR, "FAIL: $name: {$e->getMessage()}\n");
        }
    }
    exit($failures ? 1 : 0);
}

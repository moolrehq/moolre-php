<?php

declare(strict_types=1);

// Dependency-free test runner. Usage:
//   php tests/sdk/run.php                       (loads src/ with a tiny autoloader)
//   MOOLRE_BUNDLE=dist/moolre-sdk.php php tests/sdk/run.php   (tests the single-file bundle)

use Moolre\Exceptions\ApiError;
use Moolre\Exceptions\AuthenticationError;
use Moolre\Exceptions\ConfigurationError;
use Moolre\Exceptions\IdempotencyError;
use Moolre\Exceptions\InvalidResponseError;
use Moolre\Exceptions\NetworkError;
use Moolre\Exceptions\RateLimitError;
use Moolre\Exceptions\WebhookSignatureError;
use Moolre\Moolre;
use Moolre\Webhook;

$bundle = getenv('MOOLRE_BUNDLE');
if ($bundle) {
    require $bundle;
} else {
    spl_autoload_register(static function (string $c): void {
        if (strpos($c, 'Moolre\\') === 0) {
            $f = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 7)) . '.php';
            if (is_file($f)) {
                require $f;
            }
        }
    });
}

$vf = is_file(__DIR__ . '/vectors/webhook.json') ? __DIR__ . '/vectors/webhook.json' : __DIR__ . '/../../../vectors/webhook.json';
$vectors = json_decode((string) file_get_contents($vf), true);

// ---- tiny harness ------------------------------------------------------------------------------
$passed = 0;
$failed = [];
function check($cond, string $msg = 'assertion failed'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}
function same($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($msg ? $msg . ': ' : '') . 'expected ' . json_encode($expected) . ' got ' . json_encode($actual));
    }
}
function throwsA(string $class, callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $class)) {
            throw new RuntimeException("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        }

        return $e;
    }
    throw new RuntimeException("expected $class, nothing thrown");
}
function t(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "  ok   $name\n";
    } catch (Throwable $e) {
        $failed[] = $name;
        echo "  FAIL $name: " . $e->getMessage() . "\n";
    }
}

// ---- mock server -------------------------------------------------------------------------------
$port = 18000 + random_int(0, 999);
$state = tempnam(sys_get_temp_dir(), 'moolre_mock');
$cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/router.php'];
$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/moolre_php_srv.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/moolre_php_srv.log', 'a']], $pipes, null, ['MOOLRE_MOCK_STATE' => $state] + getenv());
$base = "http://127.0.0.1:$port";
for ($i = 0; $i < 50; $i++) {
    $s = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
    if ($s) {
        fclose($s);
        break;
    }
    usleep(100000);
}
register_shutdown_function(static function () use ($proc, $state): void {
    proc_terminate($proc);
    @unlink($state);
});

function http_raw(string $url, string $body = ''): string
{
    $ctx = stream_context_create(['http' => ['method' => $body === '' ? 'GET' : 'POST', 'header' => 'Content-Type: application/json', 'content' => $body, 'ignore_errors' => true]]);

    return (string) file_get_contents($url, false, $ctx);
}
function script(array $responses): void
{
    global $base;
    http_raw($base . '/__setup', json_encode($responses));
}
function calls(): array
{
    global $base;

    return json_decode(http_raw($base . '/__calls'), true);
}
function okr($data = null, string $code = 'X01'): array
{
    return [200, ['status' => 1, 'code' => $code, 'message' => 'ok', 'data' => $data, 'go' => null]];
}
function client(array $o = []): Moolre
{
    global $base;

    return new Moolre($o + [
        'baseUrl' => $base, 'user' => 'acme', 'apiKey' => 'privkey', 'pubKey' => 'pubkey', 'vasKey' => 'vaskey',
        'accountNumber' => '10000001', 'retryDelayMs' => 1, 'logger' => null,
    ]);
}

// ---- tests -------------------------------------------------------------------------------------
t('headers, path and body of a collection', function () {
    script([[200, ['status' => 1, 'code' => 'TR099', 'message' => null, 'data' => 'abc', 'go' => null, 'request_id' => 'req_1'], ['X-Request-Id' => 'req_h']]]);
    $r = client()->payments->initiate(['channel' => 'MTN', 'currency' => 'GHS', 'payer' => '0241234567', 'amount' => 1.5, 'externalRef' => 'PAY-1']);
    $c = calls()[0];
    same('POST', $c['method']);
    same('/open/transact/payment', $c['url']);
    same('acme', $c['headers']['x-api-user']);
    same('privkey', $c['headers']['x-api-key']);
    same('pubkey', $c['headers']['x-api-pubkey']);
    same('vaskey', $c['headers']['x-api-vaskey']);
    same('application/json', $c['headers']['content-type']);
    check(!isset($c['headers']['x-moolre-http-status']));
    check(!isset($c['headers']['idempotency-key']));
    same(['type' => 1, 'channel' => 'MTN', 'currency' => 'GHS', 'payer' => '0241234567', 'amount' => '1.50', 'externalref' => 'PAY-1', 'accountnumber' => '10000001'], $c['body']);
    check($r->ok);
    same('TR099', $r->code);
    same('req_1', $r->requestId);
    same(false, $r->otpRequired());
});

t('OTP flow codes and credential requirements', function () {
    script([okr('all', 'TP14'), okr('all', 'TP17')]);
    $c = client();
    $p = ['channel' => 'TELECEL', 'currency' => 'GHS', 'payer' => '0201234567', 'amount' => '2.00', 'externalRef' => 'PAY-2'];
    check($c->payments->initiate($p)->otpRequired());
    $v = $c->payments->verifyOtp($p + ['otpCode' => '123456']);
    check($v->otpVerified());
    same('123456', calls()[1]['body']['otpcode']);
    throwsA(ConfigurationError::class, function () use ($p) {
        (new Moolre(['user' => 'u', 'baseUrl' => 'http://127.0.0.1:1', 'accountNumber' => '1', 'logger' => null]))->payments->initiate($p);
    });
});

t('payout carries sendername, idempotency key, channel name', function () {
    script([okr(null, 'OBGH01')]);
    $c = client();
    $c->transfers->create(['channel' => 'BANK', 'currency' => 'GHS', 'amount' => '10.00', 'receiver' => '1234567', 'subListId' => '030100', 'externalRef' => 'T-1', 'senderName' => 'Acme'], ['idempotencyKey' => 'order-1']);
    $call = calls()[0];
    same('order-1', $call['headers']['idempotency-key']);
    same('Acme', $call['body']['sendername']);
    same('BANK', $call['body']['channel']);
    same('030100', $call['body']['sublistid']);
    throwsA(ConfigurationError::class, function () use ($c) {
        $c->transfers->create(['channel' => 'MTN', 'currency' => 'GHS', 'amount' => '1', 'receiver' => '024', 'externalRef' => 'x'], ['idempotencyKey' => "bad\nkey"]);
    });
});

t('validate-name sends the numeric channel id the endpoint reads today', function () {
    script([okr([], 'AVD01')]);
    client()->transfers->validateName(['channel' => 'TELECEL', 'currency' => 'GHS', 'receiver' => '0201234567']);
    same('/open/transact/validate', calls()[0]['url']);
    same('6', calls()[0]['body']['channel']);
});

t('error mapping: code, message, fields, requestId, typed classes', function () {
    script([
        [200, ['status' => 0, 'code' => 'TP13', 'message' => 'External Reference is required and must be unique.', 'data' => 'externalref', 'go' => null, 'request_id' => 'req_a']],
        [200, ['status' => '0', 'code' => 'AIN01', 'message' => 'Authentication Error', 'data' => null, 'go' => null]],
        [200, ['status' => 0, 'code' => 'IDEM01', 'message' => 'Idempotency-Key reused with a different request', 'data' => null, 'go' => null]],
        [200, ['status' => 0, 'code' => 'OTP429', 'message' => 'Too many OTP attempts. Try again later.', 'data' => null, 'go' => null]],
    ]);
    $c = client();
    $p = ['channel' => 'MTN', 'currency' => 'GHS', 'payer' => '024', 'amount' => '1', 'externalRef' => 'a'];
    $e = throwsA(ApiError::class, function () use ($c, $p) {
        $c->payments->initiate($p);
    });
    same('TP13', $e->apiCode);
    same(['externalref'], $e->fields);
    same('req_a', $e->requestId);
    check(strpos($e->apiMessage, 'unique') !== false);
    foreach ([AuthenticationError::class, IdempotencyError::class, RateLimitError::class] as $cls) {
        throwsA($cls, function () use ($c, $p) {
            $c->payments->initiate($p);
        });
    }
});

t('strict mode header, 4xx bodies parsed, throwOnError false returns the result', function () {
    script([[401, ['status' => 0, 'code' => 'AIN01', 'message' => 'Authentication Error', 'data' => null, 'go' => null]]]);
    $e = throwsA(AuthenticationError::class, function () {
        client(['strictHttpStatus' => true])->accounts->status();
    });
    same(401, $e->httpStatus);
    same('strict', calls()[0]['headers']['x-moolre-http-status']);
    $r = client(['throwOnError' => false])->accounts->status();
    same(false, $r->ok);
    same('AIN01', $r->code);
});

t('notices exposed and logged once per code', function () {
    $notice = ['code' => 'DEPRECATED_NUMERIC_CHANNEL', 'message' => 'Use the channel name', 'docs' => 'https://docs.moolre.com/guides/supported-channels'];
    script([[200, ['status' => 1, 'code' => 'ST08', 'message' => 'ok', 'data' => ['txcount' => '0', 'transactions' => []], 'go' => null, 'request_id' => 'req_n', 'notices' => [$notice]]]]);
    $logs = [];
    $c = client(['logger' => static function (string $m) use (&$logs): void {
        $logs[] = $m;
    }]);
    $r1 = $c->accounts->transactions();
    $c->accounts->transactions();
    same([$notice], $r1->notices);
    same('req_n', $r1->requestId);
    same(1, count($logs));
    check(strpos($logs[0], 'DEPRECATED_NUMERIC_CHANNEL') !== false);
});

t('replayed flag is surfaced', function () {
    script([[200, ['status' => 1, 'code' => 'OBGH01', 'message' => 'x', 'data' => null, 'go' => null, 'replayed' => true]]]);
    $r = client()->transfers->create(['channel' => 'MTN', 'currency' => 'GHS', 'amount' => '1', 'receiver' => '024', 'externalRef' => 'r'], ['idempotencyKey' => 'k1']);
    same(true, $r->replayed);
});

t('retry rules', function () {
    script([[503, 'bad gateway'], okr([], 'SW01')]);
    same('SW01', client()->accounts->status()->code);
    same(2, count(calls()));

    script([[502, '<html>Bad gateway</html>']]);
    throwsA(InvalidResponseError::class, function () {
        client()->transfers->create(['channel' => 'MTN', 'currency' => 'GHS', 'amount' => '1', 'receiver' => '024', 'externalRef' => 'a']);
    });
    same(1, count(calls()));

    script([[503, 'x'], okr(null, 'OBGH01')]);
    $r = client()->transfers->create(['channel' => 'MTN', 'currency' => 'GHS', 'amount' => '1', 'receiver' => '024', 'externalRef' => 'a'], ['idempotencyKey' => 'k']);
    same('OBGH01', $r->code);
    same(2, count(calls()));
    same('k', calls()[1]['headers']['idempotency-key']);

    // connection refused: NetworkError, retried only with a key / on reads (cannot count against a dead port, so just check the type)
    throwsA(NetworkError::class, function () {
        client(['baseUrl' => 'http://127.0.0.1:1', 'maxRetries' => 1])->sales->airtime(['channel' => 'MTN', 'receiver' => '233240000000', 'amount' => '1.00', 'externalRef' => 'a']);
    });
});

t('transaction iterator walks the id cursor newest first without overlap', function () {
    $mk = static function (array $ids): array {
        return [200, ['status' => 1, 'code' => $ids ? 'ST08' : 'ST09', 'message' => '', 'data' => ['txcount' => (string) count($ids), 'transactions' => array_map(static function ($i) {
            return ['transactionid' => (string) $i];
        }, $ids)], 'go' => null]];
    };
    script([$mk([107, 106, 105]), $mk([104, 103, 102]), $mk([101])]);
    $got = [];
    foreach (client()->accounts->iterateTransactions(['status' => 1], ['pageSize' => 3]) as $row) {
        $got[] = (int) $row['transactionid'];
    }
    same([107, 106, 105, 104, 103, 102, 101], $got);
    $cs = calls();
    same([null, 104, 101], array_map(static function ($c) {
        return $c['body']['endid'] ?? null;
    }, $cs));
    same(1, $cs[0]['body']['descending']);
    same(1, $cs[0]['body']['status']);
});

t('transaction iterator ascending uses startid', function () {
    $mk = static function (array $ids): array {
        return [200, ['status' => 1, 'code' => 'ST08', 'message' => '', 'data' => ['txcount' => (string) count($ids), 'transactions' => array_map(static function ($i) {
            return ['transactionid' => (string) $i];
        }, $ids)], 'go' => null]];
    };
    script([$mk([10, 11]), $mk([12, 13]), $mk([])]);
    $got = [];
    foreach (client()->accounts->iterateTransactions(['descending' => false], ['pageSize' => 2]) as $row) {
        $got[] = (int) $row['transactionid'];
    }
    same([10, 11, 12, 13], $got);
    same(0, calls()[0]['body']['descending']);
    same(12, calls()[1]['body']['startid']);
});

t('whatsapp template iterator follows next (GET with query)', function () {
    script([[200, ['status' => 1, 'code' => 'WAS200', 'message' => '', 'data' => [['name' => 'a']], 'next' => 'cur2']], [200, ['status' => 1, 'code' => 'WAS200', 'message' => '', 'data' => [['name' => 'b']], 'next' => null]]]);
    $names = [];
    foreach (client()->whatsapp->iterateTemplates() as $t) {
        $names[] = $t['name'];
    }
    same(['a', 'b'], $names);
    $cs = calls();
    same('GET', $cs[0]['method']);
    same('vaskey', $cs[0]['headers']['x-api-vaskey']);
    same('/open/whatsapp/template?next=cur2', $cs[1]['url']);
});

t('bulk payout request shape', function () {
    script([okr(['batchid' => 'b1', 'accepted' => 2, 'rejected' => [], 'status' => 'queued'], 'BLK01'), okr([], 'BLK21')]);
    $c = client();
    $r = $c->bulk->create(['currency' => 'GHS', 'batchRef' => 'payroll-nov', 'atomic' => true, 'items' => [
        ['externalRef' => 'p-1', 'receiver' => '0241234567', 'channel' => 'MTN', 'amount' => 10],
        ['externalRef' => 'p-2', 'receiver' => '0201234567', 'channel' => 'TELECEL', 'amount' => '5.50', 'senderName' => 'Acme', 'reference' => 'Nov'],
    ]], ['idempotencyKey' => 'payroll-nov']);
    $cs = calls();
    same('/open/transact/bulk', $cs[0]['url']);
    same('payroll-nov', $cs[0]['headers']['idempotency-key']);
    same(true, $cs[0]['body']['atomic']);
    same(['externalref' => 'p-2', 'receiver' => '0201234567', 'channel' => 'TELECEL', 'amount' => '5.50', 'sendername' => 'Acme', 'reference' => 'Nov'], $cs[0]['body']['items'][1]);
    same('10.00', $cs[0]['body']['items'][0]['amount']);
    same('payroll-nov', $cs[0]['body']['batchref']);
    same(2, $r->data['accepted']);
    $c->bulk->status(['batchRef' => 'payroll-nov', 'status' => 'failed']);
    same('/open/transact/bulk_status', calls()[1]['url']);
    same('payroll-nov', calls()[1]['body']['batchref']);
    same('failed', calls()[1]['body']['status']);
    check(!isset(calls()[1]['body']['type']));
});

t('bulk item and batch iterators follow next_after / next_cursor', function () {
    script([
        okr(['items' => [['index' => 0], ['index' => 1]], 'next_after' => 1], 'BLK21'),
        okr(['items' => [['index' => 2]], 'next_after' => null], 'BLK21'),
        okr(['batches' => [['batchid' => 'bat_1']], 'has_more' => true, 'next_cursor' => 'bat_1'], 'BLK31'),
        okr(['batches' => [['batchid' => 'bat_2']], 'has_more' => false, 'next_cursor' => null], 'BLK31'),
    ]);
    $c = client();
    $got = [];
    foreach ($c->bulk->iterateItems(['batchId' => 'bat_1'], ['pageSize' => 2]) as $i) {
        $got[] = $i['index'];
    }
    same([0, 1, 2], $got);
    same(1, calls()[1]['body']['after']);
    $b = [];
    foreach ($c->bulk->iterateBatches() as $x) {
        $b[] = $x['batchid'];
    }
    same(['bat_1', 'bat_2'], $b);
    same('bat_1', calls()[3]['body']['starting_after']);
});

t('invoices: create body, status, cancel, list cursor', function () {
    script([
        okr(['invoiceid' => '5-1', 'payment_link' => 'https://invoice.moolre.com/5-1'], 'INV01'),
        okr([], 'INV15'),
        okr([], 'INV17'),
        okr(['invoices' => [['invoiceid' => '5-2']], 'has_more' => true, 'next_cursor' => '5-2'], 'INV14'),
        okr(['invoices' => [['invoiceid' => '5-1']], 'has_more' => false, 'next_cursor' => null], 'INV14'),
    ]);
    $c = client();
    $r = $c->invoices->create([
        'currency' => 'GHS', 'invoiceName' => 'Tuition', 'customer' => ['name' => 'Ama', 'phone' => '0244000111'],
        'items' => [['description' => 'Fee', 'quantity' => 2, 'amount' => 50]], 'tax' => 0, 'dueDate' => '2027-01-31', 'reference' => 'I-1',
    ], ['idempotencyKey' => 'inv-1']);
    same(['accountnumber' => '10000001', 'currency' => 'GHS', 'invoice_name' => 'Tuition', 'customer' => ['name' => 'Ama', 'phone' => '0244000111'],
        'items' => [['description' => 'Fee', 'quantity' => 2, 'amount' => '50.00']], 'tax' => 0, 'due_date' => '2027-01-31', 'reference' => 'I-1'], calls()[0]['body']);
    same('inv-1', calls()[0]['headers']['idempotency-key']);
    same('https://invoice.moolre.com/5-1', $r->data['payment_link']);
    $c->invoices->status(['reference' => 'I-1']);
    same('/open/invoice/status', calls()[1]['url']);
    same('I-1', calls()[1]['body']['reference']);
    $c->invoices->cancel('5-1');
    same('/open/invoice/cancel', calls()[2]['url']);
    $ids = [];
    foreach ($c->invoices->iterate(['status' => 'pending']) as $i) {
        $ids[] = $i['invoiceid'];
    }
    same(['5-2', '5-1'], $ids);
});

t('webhook resend/test/deliveries paths and cursor', function () {
    script([
        okr([], 'WHR01'), okr([], 'WHT01'),
        okr(['items' => [['event_id' => 'evt_1'], ['event_id' => 'evt_2']], 'has_more' => true, 'next_cursor' => 'evt_2'], 'WHD01'),
        okr(['items' => [['event_id' => 'evt_3']], 'has_more' => false, 'next_cursor' => null], 'WHD01'),
    ]);
    $c = client();
    $c->webhooks->resend('evt_0123456789abcdef01234567');
    $c->webhooks->test();
    $ids = [];
    foreach ($c->webhooks->iterateDeliveries(['state' => 'dead', 'from' => '2026-11-01', 'includePayload' => true]) as $d) {
        $ids[] = $d['event_id'];
    }
    same(['/open/webhook/resend', '/open/webhook/test', '/open/webhook/deliveries', '/open/webhook/deliveries'], array_map(static function ($x) {
        return $x['url'];
    }, calls()));
    same('evt_0123456789abcdef01234567', calls()[0]['body']['event_id']);
    same('dead', calls()[2]['body']['state']);
    same('2026-11-01', calls()[2]['body']['from']);
    same(1, calls()[2]['body']['include_payload']);
    same('evt_2', calls()[3]['body']['starting_after']);
    same(['evt_1', 'evt_2', 'evt_3'], $ids);
});

t('base urls and idempotency key generator', function () {
    $s = new ReflectionProperty(\Moolre\Api\Resource::class, 't');
    $s->setAccessible(true);
    same('https://sandbox.moolre.com', $s->getValue((new Moolre(['user' => 'u', 'environment' => 'sandbox']))->payments)->baseUrl);
    same('https://api.moolre.com', $s->getValue((new Moolre(['user' => 'u']))->payments)->baseUrl);
    check(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Moolre::newIdempotencyKey()) === 1);
});

foreach ($vectors['cases'] as $v) {
    t('webhook vector: ' . $v['name'], function () use ($v) {
        same($v['valid'], Webhook::verify($v['body'], $v['header'], $v['secret'], $v['tolerance'], $v['now']));
        if ($v['valid']) {
            Webhook::construct($v['body'], $v['header'], $v['secret'], $v['tolerance'], $v['now']);
        } else {
            $e = throwsA(WebhookSignatureError::class, function () use ($v) {
                Webhook::construct($v['body'], $v['header'], $v['secret'], $v['tolerance'], $v['now']);
            });
            same($v['reason'], $e->reason);
        }
    });
}

t('webhook parse vectors and sign round trip', function () use ($vectors) {
    foreach ($vectors['parse'] as $p) {
        $e = Webhook::parse($p['raw']);
        same($p['eventId'], $e->eventId);
        same($p['event'], $e->event);
        same($p['created'], $e->created);
    }
    $body = '{"event":"webhook.test","event_id":"evt_x","created":"2026-11-15T00:00:00Z","data":{}}';
    $h = Webhook::sign($body, 's3cret');
    check(Webhook::verify($body, $h, 's3cret'));
    check(!Webhook::verify($body, $h, 'other'));
    same('webhook.test', Webhook::construct($body, $h, 's3cret')->event);
});

echo "\n$passed passed, " . count($failed) . " failed\n";
exit($failed ? 1 : 0);

<?php

use App\Models\Permission;
use App\Models\User;
use App\Services\StockLedger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing'])) {
    throw new RuntimeException('Stock verification is restricted to local/testing environments.');
}
$artifactDirectory = dirname(__DIR__, 2).'/.cache/mysql-stock-tests';
if (! is_dir($artifactDirectory)) {
    mkdir($artifactDirectory, 0777, true);
}

$job = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($argv[2].'.ready', 'ready');
$deadline = microtime(true) + 20;
while (! is_file($job['start'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Verification start barrier timed out.');
    }
    usleep(10000);
}
try {
    $actor = User::findOrFail($job['actor']);
    if (in_array($job['method'], ['disable_fixture_actor', 'revoke_fixture_permission'], true)) {
        if (! str_starts_with($actor->email, '__phase3_snapshot_') || ! str_ends_with($actor->email, '@example.test')
            || $actor->role->code !== explode('@', $actor->email)[0]) {
            throw new RuntimeException('Only the tagged snapshot-test identity may be mutated.');
        }
        if ($job['method'] === 'disable_fixture_actor') {
            $actor->forceFill(['is_active' => false, 'auth_version' => $actor->auth_version + 1])->save();
        } else {
            $permissionId = Permission::where('code', 'inventory.post')->firstOrFail()->id;
            $actor->role->permissions()->detach($permissionId);
        }
        $result = ['result' => 'mutated'];
    } else {
        $operation = app(StockLedger::class)->{$job['method']}($actor, $job['key'], $job['reference'], $job['lines']);
        $result = ['result' => 'posted', 'id' => $operation->id];
    }
} catch (ValidationException) {
    $result = ['result' => 'rejected'];
} catch (Throwable $exception) {
    $result = ['result' => 'unexpected', 'class' => get_class($exception), 'code' => $exception->getCode()];
}
file_put_contents($argv[2], json_encode($result, JSON_THROW_ON_ERROR));

<?php

use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing']) || DB::getDriverName() !== 'mysql') {
    throw new RuntimeException('Purchase verification requires local/testing MySQL.');
}
$job = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$actor = User::findOrFail($job['actor']);
if (! preg_match('/\A__phase4_verify_[a-f0-9]{20}@example\.test\z/', $actor->email)
    || $actor->role->code !== explode('@', $actor->email)[0]) {
    throw new RuntimeException('Only owned purchase-test identities are allowed.');
}
$order = PurchaseOrder::findOrFail($job['order']);
if ($order->user_id !== $actor->id || $order->supplier->name !== $actor->name) {
    throw new RuntimeException('Order does not belong to the verification fixture.');
}
file_put_contents($argv[2].'.ready', 'ready');
$deadline = microtime(true) + 20;
while (! is_file($job['start'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Purchase verification barrier timed out.');
    }
    usleep(10000);
}
try {
    $service = app(PurchaseOrders::class);
    switch ($job['method']) {
        case 'create':
            $order = $service->save($actor, $job['data']);
            break;
        case 'receive':
            $order = $service->receive($actor, $order, $job['data']);
            break;
        case 'save':
            $order = $service->save($actor, $job['data'], $order);
            break;
        case 'cancel':
            $order = $service->cancel($actor, $order, $job['data']['version']);
            break;
        case 'revoke':
            if (! in_array($job['permission'], ['purchases.receive', 'inventory.post'], true)) {
                throw new RuntimeException('Unexpected fixture permission.');
            }
            $actor->role->permissions()->detach(Permission::where('code', $job['permission'])->firstOrFail()->id);
            break;
        case 'disable':
            $actor->forceFill(['is_active' => false, 'auth_version' => $actor->auth_version + 1])->save();
            break;
        default:
            throw new RuntimeException('Unexpected purchase verification job.');
    }
    $result = ['result' => 'completed', 'id' => $order->id, 'status' => $order->status, 'operation' => $order->stock_operation_id];
} catch (ValidationException|AuthorizationException $exception) {
    $result = ['result' => 'rejected', 'class' => get_class($exception)];
} catch (Throwable $exception) {
    $result = ['result' => 'unexpected', 'class' => get_class($exception), 'code' => $exception->getCode()];
}
file_put_contents($argv[2], json_encode($result, JSON_THROW_ON_ERROR));

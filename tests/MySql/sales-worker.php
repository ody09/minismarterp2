<?php

use App\Models\Customer;
use App\Models\CustomerProductPrice;
use App\Models\Permission;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\CustomerPricing;
use App\Services\SalesOrders;
use App\Services\StockMetadata;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing']) || DB::getDriverName() !== 'mysql') {
    throw new RuntimeException('Sales verification requires local/testing MySQL.');
}
$job = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$actor = User::findOrFail($job['actor']);
$tag = $actor->name;
if (! preg_match('/\A__phase5_verify_[a-f0-9]{20}\z/', $tag) || $actor->email !== $tag.'@example.test' || $actor->role->code !== $tag) {
    throw new RuntimeException('Only owned sales-test identities may be used.');
}
$customer = Customer::whereKey($job['customer'])->where('name', $tag)->firstOrFail();
$product = Product::whereKey($job['product'])->where('name', $tag)->firstOrFail();
$order = ! empty($job['order']) ? SalesOrder::findOrFail($job['order']) : null;
if ($order && ($order->user_id !== $actor->id || $order->customer_id !== $customer->id)) {
    throw new RuntimeException('Order does not belong to the test identity.');
}
if (in_array($job['method'], ['create', 'save', 'price'], true)) {
    if ((int) $job['data']['customer_id'] !== $customer->id) {
        throw new RuntimeException('Foreign customer in verification input.');
    }
    $ids = $job['method'] === 'price' ? [$job['data']['product_id']] : array_column($job['data']['items'], 'product_id');
    if (array_diff($ids, [$product->id]) !== []) {
        throw new RuntimeException('Foreign product in verification input.');
    }
}
file_put_contents($argv[2].'.ready', 'ready');
$deadline = microtime(true) + 20;
while (! is_file($job['start'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Sales verification barrier timed out.');
    }
    usleep(10000);
}
try {
    $service = app(SalesOrders::class);
    switch ($job['method']) {
        case 'create': $order = $service->save($actor, $job['data']);
            break;
        case 'save': $order = $service->save($actor, $job['data'], $order);
            break;
        case 'confirm': $order = $service->confirm($actor, $order, $job['data']);
            break;
        case 'cancel': $order = $service->cancel($actor, $order, $job['data']['version']);
            break;
        case 'price':
            $rule = ! empty($job['price_id']) ? CustomerProductPrice::whereKey($job['price_id'])->where('customer_id', $customer->id)->where('product_id', $product->id)->firstOrFail() : null;
            $rule = app(CustomerPricing::class)->save($actor, $job['data'], $rule);
            break;
        case 'catalog': app(StockMetadata::class)->updateProduct($product, ['selling_price' => '3.500001']);
            break;
        case 'disable': $actor->forceFill(['is_active' => false, 'auth_version' => $actor->auth_version + 1])->save();
            break;
        case 'revoke':
            if (! in_array($job['permission'], ['sales.post', 'inventory.post'], true)) {
                throw new RuntimeException('Unexpected verification permission.');
            }
            $actor->role->permissions()->detach(Permission::where('code', $job['permission'])->firstOrFail()->id);
            break;
        default: throw new RuntimeException('Unexpected sales verification job.');
    }
    $result = ['result' => 'completed', 'id' => $order?->id, 'status' => $order?->status,
        'operation' => $order?->stock_operation_id, 'price_id' => isset($rule) ? $rule->id : null];
} catch (ValidationException|AuthorizationException $exception) {
    $result = ['result' => 'rejected', 'class' => get_class($exception)];
} catch (Throwable $exception) {
    $result = ['result' => 'unexpected', 'class' => get_class($exception), 'code' => $exception->getCode()];
}
file_put_contents($argv[2], json_encode($result, JSON_THROW_ON_ERROR));

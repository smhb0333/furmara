<?php
/**
 * JSON API for the static FURMARA storefront.
 * Handles /api/* — checkout (JazzCash / bank / COD), order tracking,
 * newsletter, and harmless no-ops for Next-only endpoints.
 */
declare(strict_types=1);

$appBase = is_file(__DIR__ . '/app/bootstrap.php') ? __DIR__ . '/app' : dirname(__DIR__);
require_once $appBase . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function api_json(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function api_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/');

try {
    // ---- session stub (next-auth client pings this) ----
    if ($path === '/api/auth/session') {
        echo '{}';
        exit;
    }

    // ---- checkout ----
    if ($path === '/api/checkout' && $method === 'POST') {
        $b = api_body();

        $fullName = trim((string) ($b['fullName'] ?? ''));
        $email = trim((string) ($b['email'] ?? ''));
        $phone = trim((string) ($b['phone'] ?? ''));
        $province = trim((string) ($b['province'] ?? ''));
        $city = trim((string) ($b['city'] ?? ''));
        $address = trim((string) ($b['address'] ?? ''));
        $landmark = trim((string) ($b['landmark'] ?? '')) ?: null;
        $postalCode = trim((string) ($b['postalCode'] ?? ''));
        $notes = trim((string) ($b['notes'] ?? '')) ?: null;
        $paymentMethod = (string) ($b['paymentMethod'] ?? '');
        $proofDataUrl = (string) ($b['paymentProofDataUrl'] ?? '');
        $items = is_array($b['items'] ?? null) ? $b['items'] : [];

        if ($fullName === '' || $phone === '' || $city === '' || $address === '') {
            api_json(['error' => 'Please fill in your name, phone, city, and address'], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            api_json(['error' => 'Please enter a valid email address'], 400);
        }
        if (!in_array($paymentMethod, ['JAZZCASH', 'BANK_TRANSFER', 'COD'], true)) {
            api_json(['error' => 'Invalid payment method'], 400);
        }
        $isCod = $paymentMethod === 'COD';
        if (!$isCod && $proofDataUrl === '') {
            api_json(['error' => 'Please upload your payment screenshot'], 400);
        }
        if (!$items) {
            api_json(['error' => 'Your bag is empty'], 400);
        }
        if ($postalCode !== '') {
            $address .= ' (Postal code: ' . $postalCode . ')';
        }

        // decode + validate proof BEFORE creating the order
        $proofBinary = null;
        $proofExt = '';
        if (!$isCod) {
            if (preg_match('#^data:image/(jpeg|jpg|png|webp);base64,(.+)$#s', $proofDataUrl, $m) !== 1) {
                api_json(['error' => 'Screenshot must be JPG, PNG, or WebP'], 400);
            }
            $proofExt = $m[1] === 'jpeg' ? 'jpg' : $m[1];
            $proofBinary = base64_decode($m[2], true);
            if ($proofBinary === false || strlen($proofBinary) === 0) {
                api_json(['error' => 'Could not read the payment screenshot'], 400);
            }
            if (strlen($proofBinary) > 6 * 1024 * 1024) {
                api_json(['error' => 'Screenshot must be under 6MB'], 400);
            }
        }

        // Database available? (setup may still be pending — never lose an order)
        $dbUp = true;
        try {
            Database::pdo();
        } catch (Throwable $dbErr) {
            $dbUp = false;
        }

        if (!$dbUp) {
            // ---- DB-less fallback: order goes straight to email ----
            $catalog = [
                'cmsyz43ap0005kgfg63xa4k62' => ['name' => 'SkyDrive', 'size' => '50ML', 'price' => 2399],
                'cmsyz4c2i000ckgfgfvw0ykc9' => ['name' => 'Vanillate', 'size' => '50ML', 'price' => 2449],
                'cmsyz4eay000jkgfgdvmfag4j' => ['name' => 'Aurora', 'size' => '50ML', 'price' => 2399],
                'cmsyz4g1d000qkgfg801l0hem' => ['name' => 'Heritage', 'size' => '50ML', 'price' => 2449],
                'cmsyz4i7o000xkgfgxjlnkgsy' => ['name' => 'Velocity', 'size' => '50ML', 'price' => 2499],
                'cmsyz4mhf0014kgfgx36levhy' => ['name' => 'Code 9', 'size' => '50ML', 'price' => 2799],
            ];
            $emailItems = [];
            $subtotal = 0;
            foreach ($items as $it) {
                $variantId = (string) ($it['variantId'] ?? '');
                $qty = max(1, (int) ($it['quantity'] ?? 1));
                if (!isset($catalog[$variantId])) {
                    api_json(['error' => 'One of the items in your bag is unavailable'], 400);
                }
                $prod = $catalog[$variantId];
                $line = $prod['price'] * $qty;
                $subtotal += $line;
                $emailItems[] = [
                    'productName' => $prod['name'],
                    'size' => $prod['size'],
                    'quantity' => $qty,
                    'unitPrice' => $prod['price'],
                    'subtotal' => $line,
                ];
            }
            $shipping = shipping_cost($subtotal, $city);
            $total = $subtotal + $shipping;
            $orderNumber = 'FRM-' . date('ymd') . '-' . random_int(10000, 99999);

            $relative = null;
            if (!$isCod && $proofBinary !== null) {
                $dir = PUBLIC_DIR . '/uploads/payment-proofs';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $filename = preg_replace('/[^a-zA-Z0-9-_]/', '', $orderNumber) . '-' . time() . '.' . $proofExt;
                if (file_put_contents($dir . '/' . $filename, $proofBinary) !== false) {
                    $relative = '/uploads/payment-proofs/' . $filename;
                }
            }

            $payload = [
                'orderNumber' => $orderNumber,
                'orderCreatedAt' => date('Y-m-d H:i:s'),
                'total' => $total,
                'subtotal' => $subtotal,
                'shippingCost' => $shipping,
                'discountAmount' => 0,
                'paymentMethod' => $isCod ? 'Cash on delivery' : ($paymentMethod === 'BANK_TRANSFER' ? 'Bank transfer' : 'JazzCash'),
                'items' => $emailItems,
                'customer' => [
                    'fullName' => $fullName,
                    'email' => $email,
                    'phone' => $phone,
                    'province' => $province,
                    'city' => $city,
                    'address' => $address,
                    'landmark' => $landmark,
                    'notes' => $notes,
                ],
                'paymentProofUrl' => $relative !== null ? rtrim(brand()['url'], '/') . $relative : null,
                'adminNote' => 'DHYAN: Database abhi set nahi — yeh order sirf is email mein hai, dashboard mein save NAHI hua. install.php chala kar database set karo.',
            ];
            $sent = FormSubmitMailer::sendOrder($payload);
            error_log('[api/checkout] DB-less order ' . $orderNumber . ' email=' . ($sent ? 'sent' : 'FAILED'));
            if (!$sent) {
                api_json(['error' => 'Order abhi place nahi ho saka — WhatsApp +92 322 3483031 par order kar lein'], 500);
            }
            api_json(['orderNumber' => $orderNumber]);
        }

        // resolve items against DB (source of truth for price/stock)
        $resolved = [];
        $subtotal = 0;
        foreach ($items as $it) {
            $variantId = (string) ($it['variantId'] ?? '');
            $qty = max(1, (int) ($it['quantity'] ?? 1));
            $row = Database::fetch(
                'SELECT v.id AS variant_id, v.size, v.price, v.stock, p.id AS product_id, p.name
                 FROM product_variants v JOIN products p ON p.id = v.product_id
                 WHERE v.id = ? AND p.is_published = 1',
                [$variantId],
            );
            if (!$row) {
                api_json(['error' => 'One of the items in your bag is unavailable'], 400);
            }
            if ((int) $row['stock'] < $qty) {
                api_json(['error' => 'Insufficient stock for ' . $row['name']], 400);
            }
            $line = (int) $row['price'] * $qty;
            $subtotal += $line;
            $resolved[] = [
                'product_id' => $row['product_id'],
                'variant_id' => $row['variant_id'],
                'name' => $row['name'],
                'size' => $row['size'],
                'quantity' => $qty,
                'price' => (int) $row['price'],
                'line_total' => $line,
            ];
        }

        $shipping = shipping_cost($subtotal, $city);
        $total = $subtotal + $shipping;
        $orderNumber = 'FRM-' . date('ymd') . '-' . random_int(10000, 99999);
        $orderId = bin2hex(random_bytes(12));
        $now = date('Y-m-d H:i:s');

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            Database::insert('orders', [
                'id' => $orderId,
                'order_number' => $orderNumber,
                'user_id' => null,
                'status' => 'PENDING',
                'payment_status' => 'PENDING',
                'payment_method' => $paymentMethod,
                'subtotal' => $subtotal,
                'shipping_cost' => $shipping,
                'discount_amount' => 0,
                'total' => $total,
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'province' => $province,
                'city' => $city,
                'address' => $address,
                'landmark' => $landmark,
                'notes' => $notes,
                'is_gift' => !empty($b['isGift']) ? 1 : 0,
                'emails_sent' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($resolved as $i) {
                Database::insert('order_items', [
                    'id' => bin2hex(random_bytes(12)),
                    'order_id' => $orderId,
                    'product_id' => $i['product_id'],
                    'variant_id' => $i['variant_id'],
                    'product_name' => $i['name'],
                    'size' => $i['size'],
                    'quantity' => $i['quantity'],
                    'unit_price' => $i['price'],
                    'subtotal' => $i['line_total'],
                ]);
                Database::query(
                    'UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?',
                    [$i['quantity'], $i['variant_id'], $i['quantity']],
                );
            }
            Database::insert('payments', [
                'id' => bin2hex(random_bytes(12)),
                'order_id' => $orderId,
                'method' => $paymentMethod,
                'status' => 'PENDING',
                'amount' => $total,
                'created_at' => $now,
            ]);
            Database::insert('order_status_history', [
                'id' => bin2hex(random_bytes(12)),
                'order_id' => $orderId,
                'status' => 'PENDING',
                'note' => 'Order placed',
                'created_at' => $now,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $relative = null;
        if ($isCod) {
            OrderService::confirmCod($orderId);
        } else {
            $dir = PUBLIC_DIR . '/uploads/payment-proofs';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $filename = preg_replace('/[^a-zA-Z0-9-_]/', '', $orderNumber) . '-' . time() . '.' . $proofExt;
            if (file_put_contents($dir . '/' . $filename, $proofBinary) === false) {
                api_json(['error' => 'Could not save payment screenshot'], 500);
            }
            $relative = '/uploads/payment-proofs/' . $filename;
            OrderService::confirmWithProof($orderId, $relative, $paymentMethod);
        }

        $order = OrderService::findByNumber($orderNumber);
        if ($order && in_array($order['payment_status'] ?? '', ['PAID', 'COD'], true)) {
            $payload = OrderService::toEmailPayload($order);
            if ($relative !== null) {
                $payload['paymentProofUrl'] = rtrim(brand()['url'], '/') . $relative;
            }
            try {
                if (Mailer::isConfigured()) {
                    $mail = Mailer::sendTransactional($orderId, $payload);
                    error_log('[api/checkout] email ' . json_encode($mail));
                } else {
                    // SMTP password abhi set nahi — FormSubmit fallback (owner table + client auto-response)
                    $ok = FormSubmitMailer::sendOrder($payload);
                    error_log('[api/checkout] formsubmit fallback ' . ($ok ? 'sent' : 'failed'));
                }
            } catch (Throwable $mailErr) {
                error_log('[api/checkout] email failed (order kept): ' . $mailErr->getMessage());
            }
        }

        api_json(['orderNumber' => $orderNumber]);
    }

    // ---- order tracking ----
    if ($path === '/api/orders/track' && $method === 'GET') {
        $orderNumber = trim((string) ($_GET['orderNumber'] ?? ''));
        $phone = preg_replace('/\D/', '', (string) ($_GET['phone'] ?? ''));
        if ($orderNumber === '' || $phone === '') {
            api_json(['error' => 'Order number and phone are required'], 400);
        }
        $order = OrderService::findByNumber($orderNumber);
        if (!$order || !str_contains(preg_replace('/\D/', '', (string) $order['phone']), substr($phone, -10))) {
            api_json(['error' => 'Order not found — check the order number and phone'], 404);
        }
        $history = Database::fetchAll(
            'SELECT status, note, created_at FROM order_status_history WHERE order_id = ? ORDER BY created_at ASC',
            [$order['id']],
        );
        api_json([
            'orderNumber' => $order['order_number'],
            'status' => $order['status'],
            'paymentMethod' => $order['payment_method'],
            'total' => (int) $order['total'],
            'city' => $order['city'],
            'province' => $order['province'],
            'estimatedDelivery' => null,
            'trackingNumber' => $order['tracking_number'] ?? null,
            'courierName' => $order['courier_name'] ?? null,
            'createdAt' => $order['created_at'],
            'items' => array_map(static fn ($i) => [
                'productName' => $i['product_name'],
                'size' => $i['size'],
                'quantity' => (int) $i['quantity'],
                'unitPrice' => (int) $i['unit_price'],
                'subtotal' => (int) $i['subtotal'],
            ], $order['items']),
            'history' => array_map(static fn ($h) => [
                'status' => $h['status'],
                'note' => $h['note'],
                'createdAt' => $h['created_at'],
            ], $history),
        ]);
    }

    // ---- newsletter ----
    if ($path === '/api/newsletter' && $method === 'POST') {
        $b = api_body();
        $email = trim((string) ($b['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            api_json(['error' => 'Please enter a valid email address'], 400);
        }
        Database::query(
            'CREATE TABLE IF NOT EXISTS newsletter_subscribers (
                id VARCHAR(32) PRIMARY KEY,
                email VARCHAR(190) NOT NULL UNIQUE,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        try {
            Database::insert('newsletter_subscribers', [
                'id' => bin2hex(random_bytes(12)),
                'email' => mb_strtolower($email),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            // duplicate — already subscribed, treat as success
        }
        api_json(['ok' => true]);
    }

    // ---- live product search (includes admin-added products) ----
    if ($path === '/api/search' && $method === 'GET') {
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q === '') {
            api_json(['query' => $q, 'products' => []]);
        }
        $like = '%' . $q . '%';
        $rows = Database::fetchAll(
            'SELECT * FROM products
             WHERE is_published = 1 AND (name LIKE ? OR short_description LIKE ? OR fragrance_family LIKE ?)
             ORDER BY is_best_seller DESC LIMIT 24',
            [$like, $like, $like],
        );
        $products = [];
        foreach ($rows as $r) {
            $variants = Database::fetchAll(
                'SELECT id, size, price, stock FROM product_variants WHERE product_id = ?',
                [$r['id']],
            );
            $products[] = [
                'id' => $r['id'],
                'name' => $r['name'],
                'slug' => $r['slug'],
                'shortDescription' => $r['short_description'],
                'gender' => $r['gender'],
                'fragranceFamily' => $r['fragrance_family'],
                'price' => (int) $r['price'],
                'compareAtPrice' => $r['compare_at_price'] !== null ? (int) $r['compare_at_price'] : null,
                'image' => $r['image'],
                'isBestSeller' => (bool) $r['is_best_seller'],
                'isNewArrival' => (bool) $r['is_new_arrival'],
                'isOnSale' => (bool) $r['is_on_sale'],
                'isFeatured' => (bool) $r['is_featured'],
                'longevityScore' => 4,
                'projectionScore' => 4,
                'variants' => array_map(static fn ($v) => [
                    'id' => $v['id'],
                    'size' => $v['size'],
                    'price' => (int) $v['price'],
                    'stock' => (int) $v['stock'],
                ], $variants),
            ];
        }
        api_json(['query' => $q, 'products' => $products]);
    }

    // ---- discounts: not supported on this build ----
    if ($path === '/api/discounts/validate') {
        api_json(['error' => 'Invalid or expired code'], 400);
    }

    // ---- Next-only endpoints: harmless no-ops ----
    if (str_starts_with($path, '/api/checkout/abandon') || str_starts_with($path, '/api/cart') || str_starts_with($path, '/api/wishlist')) {
        api_json(['ok' => true]);
    }

    api_json(['error' => 'Not found'], 404);
} catch (Throwable $e) {
    error_log('[api] ' . $e->getMessage());
    if (str_contains($e->getMessage(), 'DB_NOT_CONFIGURED') || $e instanceof PDOException) {
        api_json(['error' => 'Store setup incomplete — pehle install.php chala kar database set karein'], 500);
    }
    api_json(['error' => 'Something went wrong — please try again'], 500);
}

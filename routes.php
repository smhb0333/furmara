<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$router = new Router();

$router->get('/', static function (): void {
    $featured = ProductRepository::list(['featured' => true]);
    $bestsellers = ProductRepository::list(['best' => true]);
    if (!$bestsellers) {
        $bestsellers = ProductRepository::list();
    }
    view('shop/home', [
        'title' => 'FURMARA — Luxury Fragrances for Pakistan',
        'featured' => $featured,
        'bestsellers' => $bestsellers,
    ]);
});

$router->get('/shop', static function (): void {
    $filters = [
        'gender' => $_GET['gender'] ?? null,
        'family' => $_GET['family'] ?? null,
        'q' => $_GET['q'] ?? null,
        'sort' => $_GET['sort'] ?? 'featured',
    ];
    view('shop/shop', [
        'title' => 'Shop All — FURMARA',
        'products' => ProductRepository::list($filters),
        'filters' => $filters,
    ]);
});

$router->get('/products/{slug}', static function (string $slug): void {
    $product = ProductRepository::bySlug($slug);
    if (!$product) {
        http_response_code(404);
        view('shop/404', ['title' => 'Product not found']);
        return;
    }
    view('shop/product', [
        'title' => $product['name'] . ' — FURMARA',
        'product' => $product,
    ]);
});

$router->get('/men', static fn () => redirect('/shop?gender=MEN'));
$router->get('/women', static fn () => redirect('/shop?gender=WOMEN'));
$router->get('/unisex', static fn () => redirect('/shop?gender=UNISEX'));
$router->get('/best-sellers', static function (): void {
    view('shop/shop', [
        'title' => 'Best Sellers — FURMARA',
        'products' => ProductRepository::list(['best' => true]),
        'filters' => ['sort' => 'featured'],
    ]);
});

$router->get('/cart', static function (): void {
    $items = Cart::detailed();
    view('shop/cart', [
        'title' => 'Cart — FURMARA',
        'items' => $items,
        'subtotal' => Cart::subtotal(),
    ]);
});

$router->post('/cart/add', static function (): void {
    verify_csrf();
    $variantId = (string) ($_POST['variant_id'] ?? '');
    $qty = max(1, (int) ($_POST['quantity'] ?? 1));
    if ($variantId === '') {
        flash('error', 'Select a size');
        redirect($_SERVER['HTTP_REFERER'] ?? '/shop');
    }
    Cart::add($variantId, $qty);
    flash('success', 'Added to cart');
    redirect('/cart');
});

$router->post('/cart/update', static function (): void {
    verify_csrf();
    $variantId = (string) ($_POST['variant_id'] ?? '');
    $qty = (int) ($_POST['quantity'] ?? 0);
    Cart::update($variantId, $qty);
    redirect('/cart');
});

$router->post('/cart/remove', static function (): void {
    verify_csrf();
    Cart::remove((string) ($_POST['variant_id'] ?? ''));
    redirect('/cart');
});

$router->get('/checkout', static function (): void {
    $items = Cart::detailed();
    if (!$items) {
        flash('error', 'Your cart is empty');
        redirect('/shop');
    }
    $user = Auth::user();
    view('shop/checkout', [
        'title' => 'Checkout — FURMARA',
        'items' => $items,
        'subtotal' => Cart::subtotal(),
        'user' => $user,
        'provinces' => provinces(),
        'jazzcash' => jazzcash(),
        'banks' => bank_accounts(),
    ]);
});

$router->post('/checkout', static function (): void {
    verify_csrf();
    try {
        $paymentMethod = (string) ($_POST['payment_method'] ?? '');
        if (!in_array($paymentMethod, ['JAZZCASH', 'BANK_TRANSFER', 'COD'], true)) {
            throw new RuntimeException('Invalid payment method');
        }
        $isCod = $paymentMethod === 'COD';

        $mime = '';
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!$isCod) {
            if (empty($_FILES['payment_proof']['tmp_name'])) {
                throw new RuntimeException('Please upload your payment screenshot');
            }
            $file = $_FILES['payment_proof'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']) ?: '';
            if (!isset($allowed[$mime])) {
                throw new RuntimeException('Screenshot must be JPG, PNG, or WebP');
            }
        }

        $result = OrderService::create([
            'full_name' => trim((string) ($_POST['full_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'province' => (string) ($_POST['province'] ?? ''),
            'city' => trim((string) ($_POST['city'] ?? '')),
            'address' => trim((string) ($_POST['address'] ?? '')),
            'landmark' => trim((string) ($_POST['landmark'] ?? '')) ?: null,
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            'is_gift' => !empty($_POST['is_gift']),
            'payment_method' => $paymentMethod,
        ]);

        $relative = null;
        if ($isCod) {
            OrderService::confirmCod($result['order_id']);
        } else {
            $dir = PUBLIC_DIR . '/uploads/payment-proofs';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $filename = preg_replace('/[^a-zA-Z0-9-_]/', '', $result['order_number']) . '-' . time() . '.' . $allowed[$mime];
            $dest = $dir . '/' . $filename;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                throw new RuntimeException('Could not save payment screenshot');
            }
            $relative = '/uploads/payment-proofs/' . $filename;

            // Mark paid ONLY after proof file is saved — emails run only for PAID/COD orders
            OrderService::confirmWithProof($result['order_id'], $relative, $paymentMethod);
        }

        $order = OrderService::findByNumber($result['order_number']);
        if ($order && in_array($order['payment_status'] ?? '', ['PAID', 'COD'], true)) {
            $payload = OrderService::toEmailPayload($order);
            if ($relative !== null) {
                $payload['paymentProofUrl'] = rtrim(brand()['url'], '/') . $relative;
            }
            try {
                $mail = Mailer::sendTransactional($result['order_id'], $payload);
                error_log('[checkout] email ' . json_encode($mail));
            } catch (Throwable $mailErr) {
                error_log('[checkout] email failed (order kept): ' . $mailErr->getMessage());
            }
        }

        Cart::clear();
        $_SESSION['last_order'] = $result['order_number'];
        redirect('/checkout/success?order=' . urlencode($result['order_number']));
    } catch (Throwable $e) {
        // Failed checkout / failed payment proof → no email (exception before Mailer call)
        flash('error', $e->getMessage());
        redirect('/checkout');
    }
});

$router->get('/checkout/success', static function (): void {
    $orderNumber = (string) ($_GET['order'] ?? $_SESSION['last_order'] ?? '');
    $order = $orderNumber !== '' ? OrderService::findByNumber($orderNumber) : null;
    view('shop/success', [
        'title' => 'Order confirmed — FURMARA',
        'order' => $order,
    ]);
});

$router->get('/track-order', static function (): void {
    $order = null;
    $error = null;
    if (!empty($_GET['order']) && !empty($_GET['phone'])) {
        $found = OrderService::findByNumber(trim((string) $_GET['order']));
        $phone = preg_replace('/\D/', '', (string) $_GET['phone']);
        if ($found && str_ends_with(preg_replace('/\D/', '', $found['phone']) ?? '', substr($phone, -10))) {
            $order = $found;
        } else {
            $error = 'Order not found. Check order number and phone.';
        }
    }
    view('shop/track', [
        'title' => 'Track Order — FURMARA',
        'order' => $order,
        'error' => $error,
    ]);
});

$router->get('/login', static function (): void {
    view('shop/login', ['title' => 'Sign in — FURMARA']);
});

$router->post('/login', static function (): void {
    verify_csrf();
    if (Auth::login((string) $_POST['email'], (string) $_POST['password'])) {
        redirect(Auth::isAdmin() ? '/admin' : '/account');
    }
    flash('error', 'Invalid email or password');
    redirect('/login');
});

$router->get('/signup', static function (): void {
    view('shop/signup', ['title' => 'Create account — FURMARA']);
});

$router->post('/signup', static function (): void {
    verify_csrf();
    try {
        Auth::register(
            (string) $_POST['name'],
            (string) $_POST['email'],
            (string) $_POST['password'],
            (string) ($_POST['phone'] ?? ''),
        );
        redirect('/account');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/signup');
    }
});

$router->post('/logout', static function (): void {
    verify_csrf();
    Auth::logout();
    redirect('/');
});

$router->get('/account', static function (): void {
    $user = Auth::requireUser();
    $orders = Database::fetchAll(
        'SELECT * FROM orders WHERE user_id = ? OR email = ? ORDER BY created_at DESC LIMIT 20',
        [$user['id'], $user['email']],
    );
    view('shop/account', ['title' => 'My account — FURMARA', 'user' => $user, 'orders' => $orders]);
});

foreach (['about', 'contact', 'faqs', 'shipping-policy', 'returns', 'terms', 'privacy'] as $page) {
    $router->get('/' . $page, static function () use ($page): void {
        view('shop/static', [
            'title' => ucfirst(str_replace('-', ' ', $page)) . ' — FURMARA',
            'page' => $page,
        ]);
    });
}

/* —— Admin —— */
$router->get('/admin/login', static function (): void {
    view('admin/login', ['title' => 'Admin login'], 'layouts/admin');
});

$router->post('/admin/login', static function (): void {
    verify_csrf();
    if (Auth::login((string) $_POST['email'], (string) $_POST['password']) && Auth::isAdmin()) {
        redirect('/admin');
    }
    Auth::logout();
    flash('error', 'Invalid admin credentials');
    redirect('/admin/login');
});

$router->get('/admin', static function (): void {
    Auth::requireAdmin();
    $today = date('Y-m-d 00:00:00');
    $stats = [
        'todayOrders' => (int) (Database::fetch('SELECT COUNT(*) AS c FROM orders WHERE created_at >= ?', [$today])['c'] ?? 0),
        'todayRevenue' => (int) (Database::fetch("SELECT COALESCE(SUM(total),0) AS s FROM orders WHERE created_at >= ? AND status NOT IN ('CANCELLED','REFUNDED')", [$today])['s'] ?? 0),
        'pending' => (int) (Database::fetch("SELECT COUNT(*) AS c FROM orders WHERE status = 'PENDING'")['c'] ?? 0),
        'lowStock' => (int) (Database::fetch('SELECT COUNT(*) AS c FROM product_variants WHERE stock <= 5')['c'] ?? 0),
        'products' => (int) (Database::fetch('SELECT COUNT(*) AS c FROM products WHERE is_published = 1')['c'] ?? 0),
        'customers' => (int) (Database::fetch('SELECT COUNT(DISTINCT email) AS c FROM orders')['c'] ?? 0),
    ];
    $recent = Database::fetchAll('SELECT * FROM orders ORDER BY created_at DESC LIMIT 8');
    view('admin/dashboard', [
        'title' => 'Home',
        'stats' => $stats,
        'recent' => $recent,
    ], 'layouts/admin');
});

$router->get('/admin/orders', static function (): void {
    Auth::requireAdmin();
    $allowed = ['PENDING','CONFIRMED','PROCESSING','PACKED','SHIPPED','OUT_FOR_DELIVERY','DELIVERED','CANCELLED'];
    $statusFilter = in_array($_GET['status'] ?? '', $allowed, true) ? (string) $_GET['status'] : null;
    $sql = 'SELECT o.*, (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS item_count FROM orders o';
    $params = [];
    if ($statusFilter !== null) {
        $sql .= ' WHERE o.status = ?';
        $params[] = $statusFilter;
    }
    $sql .= ' ORDER BY o.created_at DESC LIMIT 100';
    $orders = Database::fetchAll($sql, $params);
    view('admin/orders', [
        'title' => 'Orders',
        'orders' => $orders,
        'statusFilter' => $statusFilter,
    ], 'layouts/admin');
});

$router->get('/admin/orders/{id}', static function (string $id): void {
    Auth::requireAdmin();
    $order = Database::fetch('SELECT * FROM orders WHERE id = ?', [$id]);
    if (!$order) {
        http_response_code(404);
        exit('Order not found');
    }
    $order['items'] = Database::fetchAll('SELECT * FROM order_items WHERE order_id = ?', [$id]);
    $history = Database::fetchAll('SELECT * FROM order_status_history WHERE order_id = ? ORDER BY created_at ASC', [$id]);
    view('admin/order-detail', [
        'title' => $order['order_number'],
        'order' => $order,
        'history' => $history,
    ], 'layouts/admin');
});

$router->post('/admin/orders/{id}/status', static function (string $id): void {
    Auth::requireAdmin();
    verify_csrf();
    $status = (string) ($_POST['status'] ?? '');
    $allowed = ['PENDING','CONFIRMED','PROCESSING','PACKED','SHIPPED','OUT_FOR_DELIVERY','DELIVERED','CANCELLED'];
    if (!in_array($status, $allowed, true)) {
        flash('error', 'Invalid status');
        redirect('/admin/orders/' . $id);
    }
    $update = [
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    $tracking = trim((string) ($_POST['tracking_number'] ?? ''));
    if ($tracking !== '') {
        $update['tracking_number'] = $tracking;
    }
    Database::update('orders', $update, 'id = :oid', ['oid' => $id]);
    Database::insert('order_status_history', [
        'id' => bin2hex(random_bytes(12)),
        'order_id' => $id,
        'status' => $status,
        'note' => trim((string) ($_POST['note'] ?? '')) ?: null,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    flash('success', 'Status updated');
    redirect('/admin/orders/' . $id);
});

$router->post('/admin/orders/{id}/notes', static function (string $id): void {
    Auth::requireAdmin();
    verify_csrf();
    Database::update('orders', [
        'internal_notes' => trim((string) ($_POST['internal_notes'] ?? '')) ?: null,
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = :oid', ['oid' => $id]);
    flash('success', 'Notes saved');
    redirect('/admin/orders/' . $id);
});

$router->post('/admin/orders/{id}/cod-collected', static function (string $id): void {
    Auth::requireAdmin();
    verify_csrf();
    $now = date('Y-m-d H:i:s');
    Database::update('orders', [
        'payment_status' => 'PAID',
        'updated_at' => $now,
    ], 'id = :oid', ['oid' => $id]);
    Database::query('UPDATE payments SET status = ?, paid_at = ? WHERE order_id = ?', ['PAID', $now, $id]);
    Database::insert('order_status_history', [
        'id' => bin2hex(random_bytes(12)),
        'order_id' => $id,
        'status' => 'PAID',
        'note' => 'COD cash collected',
        'created_at' => $now,
    ]);
    flash('success', 'COD marked as collected');
    redirect('/admin/orders/' . $id);
});

$router->post('/admin/orders/{id}/resend-email', static function (string $id): void {
    Auth::requireAdmin();
    verify_csrf();
    $order = Database::fetch('SELECT * FROM orders WHERE id = ?', [$id]);
    if ($order) {
        $order['items'] = Database::fetchAll('SELECT * FROM order_items WHERE order_id = ?', [$id]);
        Mailer::sendTransactional($id, OrderService::toEmailPayload($order), true);
        flash('success', 'Emails resent');
    }
    redirect('/admin/orders/' . $id);
});

$router->get('/admin/products', static function (): void {
    Auth::requireAdmin();
    $products = Database::fetchAll(
        'SELECT p.*, (SELECT COALESCE(SUM(v.stock),0) FROM product_variants v WHERE v.product_id = p.id) AS stock
         FROM products p ORDER BY p.created_at DESC',
    );
    view('admin/products', ['title' => 'Products', 'products' => $products], 'layouts/admin');
});

$router->get('/admin/payments', static function (): void {
    Auth::requireAdmin();
    $payments = Database::fetchAll(
        'SELECT p.*, o.order_number FROM payments p JOIN orders o ON o.id = p.order_id ORDER BY p.created_at DESC LIMIT 200',
    );
    view('admin/payments', ['title' => 'Payments', 'payments' => $payments], 'layouts/admin');
});

$router->get('/admin/customers', static function (): void {
    Auth::requireAdmin();
    $customers = Database::fetchAll(
        'SELECT full_name, email, phone, COUNT(*) AS order_count, SUM(total) AS total_spent, MAX(created_at) AS last_order
         FROM orders GROUP BY email, full_name, phone ORDER BY MAX(created_at) DESC LIMIT 200',
    );
    view('admin/customers', ['title' => 'Customers', 'customers' => $customers], 'layouts/admin');
});

$router->get('/admin/newsletter', static function (): void {
    Auth::requireAdmin();
    Database::query(
        'CREATE TABLE IF NOT EXISTS newsletter_subscribers (
            id VARCHAR(32) PRIMARY KEY,
            email VARCHAR(190) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    );
    $subscribers = Database::fetchAll('SELECT * FROM newsletter_subscribers ORDER BY created_at DESC LIMIT 500');
    view('admin/newsletter', ['title' => 'Newsletter', 'subscribers' => $subscribers], 'layouts/admin');
});

$router->get('/admin/help', static function (): void {
    Auth::requireAdmin();
    view('admin/help', ['title' => 'How to use'], 'layouts/admin');
});

$router->get('/admin/products/new', static function (): void {
    Auth::requireAdmin();
    view('admin/product-form', ['title' => 'Add product'], 'layouts/admin');
});

$router->post('/admin/products/new', static function (): void {
    Auth::requireAdmin();
    verify_csrf();
    try {
        $name = trim((string) ($_POST['name'] ?? ''));
        $price = (int) ($_POST['price'] ?? 0);
        if ($name === '' || $price < 1) {
            throw new RuntimeException('Name and price are required');
        }
        $slug = slugify($name);
        if (Database::fetch('SELECT id FROM products WHERE slug = ?', [$slug])) {
            $slug .= '-' . random_int(10, 99);
        }
        $compare = (int) ($_POST['compare_at_price'] ?? 0);
        $image = save_product_image($_FILES['image'] ?? [], $slug) ?? '/images/hero-bottle.svg';
        $now = date('Y-m-d H:i:s');
        $productId = 'p_' . bin2hex(random_bytes(8));

        Database::insert('products', [
            'id' => $productId,
            'name' => $name,
            'slug' => $slug,
            'sku' => 'FRM-' . strtoupper(substr(preg_replace('/[^a-z0-9]/', '', $slug) ?: 'PRD', 0, 8)),
            'short_description' => trim((string) ($_POST['short_description'] ?? '')) ?: $name,
            'description' => trim((string) ($_POST['description'] ?? '')) ?: (trim((string) ($_POST['short_description'] ?? '')) ?: $name),
            'gender' => in_array($_POST['gender'] ?? '', ['MEN', 'WOMEN', 'UNISEX'], true) ? (string) $_POST['gender'] : 'UNISEX',
            'fragrance_family' => trim((string) ($_POST['fragrance_family'] ?? 'Fresh')) ?: 'Fresh',
            'price' => $price,
            'compare_at_price' => $compare > $price ? $compare : null,
            'image' => $image,
            'is_published' => !empty($_POST['is_published']) ? 1 : 0,
            'is_featured' => !empty($_POST['is_featured']) ? 1 : 0,
            'is_best_seller' => !empty($_POST['is_best_seller']) ? 1 : 0,
            'is_new_arrival' => !empty($_POST['is_new_arrival']) ? 1 : 0,
            'is_on_sale' => !empty($_POST['is_on_sale']) ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Database::insert('product_variants', [
            'id' => 'v_' . bin2hex(random_bytes(8)),
            'product_id' => $productId,
            'size' => trim((string) ($_POST['size'] ?? '50ML')) ?: '50ML',
            'price' => $price,
            'compare_at_price' => $compare > $price ? $compare : null,
            'stock' => max(0, (int) ($_POST['stock'] ?? 0)),
        ]);
        flash('success', $name . ' added — website pe dikhane ke liye build refresh karwa lena');
        redirect('/admin/products');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/admin/products/new');
    }
});

$router->get('/admin/products/{id}/edit', static function (string $id): void {
    Auth::requireAdmin();
    $product = Database::fetch('SELECT * FROM products WHERE id = ?', [$id]);
    if (!$product) {
        http_response_code(404);
        exit('Product not found');
    }
    $variant = Database::fetch('SELECT * FROM product_variants WHERE product_id = ? LIMIT 1', [$id]);
    view('admin/product-form', [
        'title' => 'Edit — ' . $product['name'],
        'product' => $product,
        'variant' => $variant,
    ], 'layouts/admin');
});

$router->post('/admin/products/{id}/edit', static function (string $id): void {
    Auth::requireAdmin();
    verify_csrf();
    $product = Database::fetch('SELECT * FROM products WHERE id = ?', [$id]);
    if (!$product) {
        http_response_code(404);
        exit('Product not found');
    }
    try {
        $name = trim((string) ($_POST['name'] ?? ''));
        $price = (int) ($_POST['price'] ?? 0);
        if ($name === '' || $price < 1) {
            throw new RuntimeException('Name and price are required');
        }
        $compare = (int) ($_POST['compare_at_price'] ?? 0);
        $update = [
            'name' => $name,
            'short_description' => trim((string) ($_POST['short_description'] ?? '')) ?: $name,
            'description' => trim((string) ($_POST['description'] ?? '')) ?: $name,
            'gender' => in_array($_POST['gender'] ?? '', ['MEN', 'WOMEN', 'UNISEX'], true) ? (string) $_POST['gender'] : (string) $product['gender'],
            'fragrance_family' => trim((string) ($_POST['fragrance_family'] ?? '')) ?: (string) $product['fragrance_family'],
            'price' => $price,
            'compare_at_price' => $compare > $price ? $compare : null,
            'is_published' => !empty($_POST['is_published']) ? 1 : 0,
            'is_featured' => !empty($_POST['is_featured']) ? 1 : 0,
            'is_best_seller' => !empty($_POST['is_best_seller']) ? 1 : 0,
            'is_new_arrival' => !empty($_POST['is_new_arrival']) ? 1 : 0,
            'is_on_sale' => !empty($_POST['is_on_sale']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $newImage = save_product_image($_FILES['image'] ?? [], (string) $product['slug']);
        if ($newImage !== null) {
            $update['image'] = $newImage;
        }
        Database::update('products', $update, 'id = :pid', ['pid' => $id]);

        $variant = Database::fetch('SELECT id FROM product_variants WHERE product_id = ? LIMIT 1', [$id]);
        $variantData = [
            'size' => trim((string) ($_POST['size'] ?? '50ML')) ?: '50ML',
            'price' => $price,
            'compare_at_price' => $compare > $price ? $compare : null,
            'stock' => max(0, (int) ($_POST['stock'] ?? 0)),
        ];
        if ($variant) {
            Database::update('product_variants', $variantData, 'id = :vid', ['vid' => $variant['id']]);
        } else {
            Database::insert('product_variants', $variantData + [
                'id' => 'v_' . bin2hex(random_bytes(8)),
                'product_id' => $id,
            ]);
        }
        flash('success', 'Product updated');
        redirect('/admin/products');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/admin/products/' . $id . '/edit');
    }
});

$router->post('/admin/products/{id}/toggle', static function (string $id): void {
    Auth::requireAdmin();
    verify_csrf();
    $p = Database::fetch('SELECT is_published FROM products WHERE id = ?', [$id]);
    if ($p) {
        Database::update('products', [
            'is_published' => (int) $p['is_published'] ? 0 : 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);
    }
    redirect('/admin/products');
});

$router->get('/admin/email-setup', static function (): void {
    Auth::requireAdmin();
    view('admin/email-setup', [
        'title' => 'Email setup',
    ], 'layouts/admin');
});

$router->post('/admin/email-setup/save', static function (): void {
    Auth::requireAdmin();
    verify_csrf();
    $smtpUser = trim((string) ($_POST['smtp_user'] ?? ''));
    $smtpPass = str_replace('"', '', (string) ($_POST['smtp_pass'] ?? ''));
    $testMode = !empty($_POST['test_mode']) ? 'true' : 'false';

    $envPath = __DIR__ . '/.env';
    $env = is_file($envPath) ? (string) file_get_contents($envPath) : '';
    if ($env === '' && is_file(__DIR__ . '/.env.example')) {
        $env = (string) file_get_contents(__DIR__ . '/.env.example');
    }

    $set = static function (string $env, string $key, string $value): string {
        $line = $key . '=' . $value;
        if (preg_match('/^' . preg_quote($key, '/') . '=.*$/m', $env) === 1) {
            return (string) preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $line, $env);
        }
        return rtrim($env) . "\n" . $line . "\n";
    };

    if ($smtpUser !== '' && filter_var($smtpUser, FILTER_VALIDATE_EMAIL)) {
        $env = $set($env, 'SMTP_USER', $smtpUser);
        $env = $set($env, 'SMTP_FROM', '"FURMARA <' . $smtpUser . '>"');
        $env = $set($env, 'ORDER_NOTIFY_EMAIL', '"Sonuhussyn09@gmail.com,' . $smtpUser . '"');
    }
    if ($smtpPass !== '') {
        $env = $set($env, 'SMTP_PASSWORD', '"' . $smtpPass . '"');
    }
    $env = $set($env, 'MAIL_TEST_MODE', $testMode);

    file_put_contents($envPath, $env);
    flash('success', $smtpPass !== '' ? 'SMTP settings saved — ab "Send SMTP test" dabao' : 'Settings saved (password khali tha, purana hi raha)');
    redirect('/admin/email-setup');
});

$router->post('/admin/email-setup/test', static function (): void {
    Auth::requireAdmin();
    verify_csrf();
    $to = trim((string) ($_POST['test_to'] ?? ''));
    if (Mailer::sendTest($to)) {
        flash('success', 'Test email sent to ' . $to);
    } else {
        flash('error', 'Test email failed — check SMTP credentials and PHPMailer install');
    }
    redirect('/admin/email-setup');
});

return $router;

<?php

use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Auth\CustomerAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API DutCh’M — préfixe /api/v1
|--------------------------------------------------------------------------
| Portée : public (défaut), 'customer' (client connecté), 'customer:optional',
| 'admin' / 'admin:perm1,perm2' (JWT administrateur + permissions RBAC).
*/

// ---- Authentification administrateur -------------------------------------------
Route::prefix('admin/auth')->controller(AdminAuthController::class)->group(function () {
    Route::post('login', 'login')->middleware('limit:10,1');
    Route::post('2fa/setup', 'setup')->middleware('limit:10,1');
    Route::post('2fa/verify', 'verify')->middleware('limit:10,1');
    Route::post('refresh', 'refresh')->middleware('limit:30,1');
    Route::post('logout', 'logout');
    Route::middleware('admin')->group(function () {
        Route::get('me', 'me');
        Route::post('me/2fa/setup', 'setupMine');
        Route::post('me/2fa/enable', 'enableMine');
        Route::post('me/password', 'changePassword');
    });
});

// ---- Authentification client ------------------------------------------------------
Route::prefix('auth/customer')->controller(CustomerAuthController::class)->group(function () {
    Route::post('register', 'register')->middleware('limit:5,1');
    Route::post('login', 'login')->middleware('limit:10,1');
    Route::post('refresh', 'refresh');
    Route::post('logout', 'logout');
    Route::post('forgot-password', 'forgot')->middleware('limit:5,15');
    Route::post('reset-password', 'reset')->middleware('limit:10,15');
    Route::middleware('customer')->group(function () {
        Route::post('verify-phone/request', 'requestVerification')->middleware('limit:3,10');
        Route::post('verify-phone/confirm', 'confirmVerification')->middleware('limit:10,10');
    });
});

// ---- Paramètres -----------------------------------------------------------------------
Route::get('settings/public', [SystemController::class, 'publicSettings']);

// ---- Back-office : système ----------------------------------------------------------
Route::prefix('admin')->middleware('admin')->group(function () {
    Route::get('settings', [SystemController::class, 'settings']);
    Route::patch('settings', [SystemController::class, 'updateSettings'])->middleware('admin:settings.write');
    Route::get('audit-logs', [SystemController::class, 'auditLogs'])->middleware('admin:audit.read');
    Route::post('uploads', [SystemController::class, 'upload']);

    Route::controller(UsersController::class)->group(function () {
        Route::get('users/drivers', 'drivers');
        Route::get('users/me/sessions', 'mySessions');
        Route::delete('users/{id}/sessions/{tokenId}', 'revokeSession')->whereNumber(['id', 'tokenId']);
        Route::middleware('admin:users.write')->group(function () {
            Route::get('users', 'list');
            Route::post('users', 'create');
            Route::patch('users/{id}', 'update')->whereNumber('id');
            Route::post('users/{id}/reset-password', 'resetPassword')->whereNumber('id');
            Route::post('users/{id}/reset-2fa', 'reset2fa')->whereNumber('id');
            Route::post('users/{id}/unlock', 'unlock')->whereNumber('id');
            Route::delete('users/{id}', 'remove')->whereNumber('id');
            Route::get('users/{id}/sessions', 'sessions')->whereNumber('id');
            Route::get('roles', 'roles');
            Route::get('permissions', 'permissions');
        });
    });
});

// ---- Catalogue public ----------------------------------------------------------------
Route::prefix('catalog')->controller(App\Http\Controllers\CatalogController::class)->group(function () {
    Route::get('categories', 'categories');
    Route::get('categories/{slug}', 'category');
    Route::get('collections', 'collections');
    Route::get('collections/{slug}', 'collection');
    Route::get('products', 'products');
    Route::get('facets', 'facets');
    Route::get('products/{slug}', 'product');
    Route::get('products/{slug}/related', 'related');
    Route::get('products/{slug}/reviews', 'reviews');
    Route::get('reviews/highlights', 'highlights');
    Route::get('search', 'search')->middleware('limit:60,1');
    Route::post('variants/{id}/back-in-stock', 'backInStock')->whereNumber('id')->middleware('limit:10,1');
});

// ---- Back-office : catalogue -----------------------------------------------------
Route::prefix('admin')->middleware('admin')->group(function () {
    Route::prefix('products')->controller(App\Http\Controllers\Admin\ProductsController::class)->group(function () {
        Route::get('/', 'list')->middleware('admin:catalog.read');
        Route::get('export.csv', 'exportCsv')->middleware('admin:catalog.read,exports');
        Route::get('{id}', 'get')->whereNumber('id')->middleware('admin:catalog.read');
        Route::middleware('admin:catalog.write')->group(function () {
            Route::post('import', 'importCsv');
            Route::post('bulk', 'bulk');
            Route::post('/', 'create');
            Route::patch('variants/{variantId}', 'updateVariant')->whereNumber('variantId');
            Route::delete('variants/{variantId}', 'removeVariant')->whereNumber('variantId');
            Route::patch('images/{imageId}', 'updateImage')->whereNumber('imageId');
            Route::delete('images/{imageId}', 'removeImage')->whereNumber('imageId');
            Route::patch('{id}', 'update')->whereNumber('id');
            Route::delete('{id}', 'remove')->whereNumber('id');
            Route::post('{id}/variants', 'createVariant')->whereNumber('id');
            Route::post('{id}/variants/generate', 'generate')->whereNumber('id');
            Route::post('{id}/images', 'addImage')->whereNumber('id');
            Route::put('{id}/images/order', 'reorder')->whereNumber('id');
        });
    });

    Route::controller(App\Http\Controllers\Admin\TaxonomyController::class)->group(function () {
        Route::middleware('admin:catalog.read')->group(function () {
            Route::get('categories', 'categories');
            Route::get('collections', 'collections');
            Route::get('collections/{id}', 'collection')->whereNumber('id');
            Route::get('attributes', 'attributes');
            Route::get('suppliers', 'suppliers');
            Route::get('suppliers/{id}', 'supplier')->whereNumber('id');
        });
        Route::middleware('admin:catalog.write')->group(function () {
            Route::post('categories', 'createCategory');
            Route::put('categories/tree', 'saveTree');
            Route::patch('categories/{id}', 'updateCategory')->whereNumber('id');
            Route::delete('categories/{id}', 'removeCategory')->whereNumber('id');
            Route::post('collections', 'createCollection');
            Route::patch('collections/{id}', 'updateCollection')->whereNumber('id');
            Route::delete('collections/{id}', 'removeCollection')->whereNumber('id');
            Route::post('attributes', 'createAttribute');
            Route::patch('attributes/{id}', 'updateAttribute')->whereNumber('id');
            Route::delete('attributes/{id}', 'removeAttribute')->whereNumber('id');
            Route::post('attributes/{id}/values', 'addValue')->whereNumber('id');
            Route::patch('attribute-values/{id}', 'updateValue')->whereNumber('id');
            Route::delete('attribute-values/{id}', 'removeValue')->whereNumber('id');
        });
        Route::middleware('admin:suppliers.write')->group(function () {
            Route::post('suppliers', 'createSupplier');
            Route::patch('suppliers/{id}', 'updateSupplier')->whereNumber('id');
            Route::delete('suppliers/{id}', 'removeSupplier')->whereNumber('id');
        });
    });

    Route::prefix('stock')->controller(App\Http\Controllers\Admin\StockController::class)->group(function () {
        Route::middleware('admin:stock.read')->group(function () {
            Route::get('/', 'overview');
            Route::get('movements', 'movements');
            Route::get('alerts', 'alerts');
        });
        Route::middleware('admin:stock.write')->group(function () {
            Route::post('alerts/{variantId}/send', 'sendAlerts')->whereNumber('variantId');
            Route::post('{variantId}/adjust', 'adjust')->whereNumber('variantId');
        });
    });
});

// ---- Panier, commandes et suivi (public) ---------------------------------------
Route::controller(App\Http\Controllers\OrdersController::class)->group(function () {
    Route::post('cart/validate', 'validateCart')->middleware(['customer:optional', 'limit:60,1']);
    Route::post('orders', 'create')->middleware(['customer:optional', 'limit:10,10']);
    Route::get('orders/track', 'track')->middleware('limit:5,15');
    Route::get('orders/track/{token}', 'trackByToken')->middleware('limit:60,1');
});

// ---- Back-office : commandes, expéditions, livraisons ------------------------------
Route::prefix('admin')->middleware('admin')->controller(App\Http\Controllers\Admin\OrdersController::class)->group(function () {
    Route::get('orders', 'list')->middleware('admin:orders.read');
    Route::post('orders', 'create')->middleware('admin:orders.write');
    Route::get('orders/{id}', 'detail')->whereNumber('id')->middleware('admin:orders.read');
    Route::get('orders/{id}/print', 'print')->whereNumber('id')->middleware('admin:orders.read');
    Route::post('orders/{id}/confirm', 'confirm')->whereNumber('id')->middleware('admin:orders.status');
    Route::post('orders/{id}/cancel', 'cancel')->whereNumber('id')->middleware('admin:orders.write');
    Route::post('orders/{id}/notes', 'note')->whereNumber('id')->middleware('admin:orders.read');
    Route::post('orders/{id}/resend-notification', 'resend')->whereNumber('id')->middleware('admin:orders.status');
    Route::post('orders/{id}/cash', 'cash')->whereNumber('id')->middleware('admin:orders.write');
    Route::post('shipments/{id}/steps', 'step')->whereNumber('id')->middleware('admin:shipments.write');
    Route::post('shipments/{id}/delay', 'delay')->whereNumber('id')->middleware('admin:shipments.write');
    Route::post('shipments/{id}/driver', 'assign')->whereNumber('id')->middleware('admin:delivery.write');
    Route::post('shipments/{id}/regenerate-code', 'regenerate')->whereNumber('id')->middleware('admin:delivery.write');
    Route::get('deliveries/planning', 'planning')->middleware('admin:delivery.write');
    Route::get('driver/deliveries', 'driverDeliveries')->middleware('admin:driver.deliveries');
    Route::post('driver/deliveries/{shipmentId}/complete', 'completeDelivery')->whereNumber('shipmentId')->middleware('admin:driver.deliveries');
});

// ---- Paiements mobile money (public) et webhooks opérateurs -----------------------
Route::prefix('payments')->controller(App\Http\Controllers\PaymentsController::class)->group(function () {
    Route::post('/', 'initiate')->middleware('limit:10,10');
    Route::get('{id}/status', 'status')->whereNumber('id')->middleware('limit:60,1');
    Route::post('{id}/check', 'check')->whereNumber('id')->middleware('limit:10,1');
    Route::post('{id}/simulate', 'simulate')->whereNumber('id');
    Route::post('webhooks/{provider}', 'webhook')->middleware('limit:300,1');
});

// ---- Zones de livraison (public) ----------------------------------------------------
Route::get('delivery/zones', [App\Http\Controllers\Admin\PaymentsArrivalsController::class, 'publicZones']);

// ---- Back-office : paiements, précommandes, arrivages, zones ----------------------
Route::prefix('admin')->middleware('admin')->controller(App\Http\Controllers\Admin\PaymentsArrivalsController::class)->group(function () {
    Route::get('payments', 'payments')->middleware('admin:payments.read');
    Route::get('payments/reconciliation', 'reconciliation')->middleware('admin:payments.read');
    Route::post('orders/{id}/manual-payment', 'manual')->whereNumber('id')->middleware('admin:payments.manual');
    Route::post('orders/{id}/refund', 'refund')->whereNumber('id')->middleware('admin:orders.refund');

    Route::get('preorders/pending', 'pendingPreorders')->middleware('admin:orders.read');
    Route::get('arrivals', 'arrivalsList')->middleware('admin:orders.read');
    Route::get('arrivals/{id}', 'arrival')->whereNumber('id')->middleware('admin:orders.read');
    Route::middleware('admin:arrivals.write')->group(function () {
        Route::post('arrivals', 'createArrival');
        Route::patch('arrivals/{id}', 'updateArrival')->whereNumber('id');
        Route::post('arrivals/{id}/items', 'addItems')->whereNumber('id');
        Route::post('arrivals/{id}/items/remove', 'removeItems')->whereNumber('id');
        Route::post('arrivals/{id}/steps', 'arrivalStep')->whereNumber('id');
        Route::post('arrivals/{id}/reception', 'reception')->whereNumber('id');
        Route::post('arrivals/{id}/documents', 'addDocument')->whereNumber('id');
        Route::delete('arrival-documents/{docId}', 'removeDocument')->whereNumber('docId');
    });

    Route::get('delivery/zones', 'zones');
    Route::middleware('admin:delivery.write')->group(function () {
        Route::post('delivery/zones', 'createZone');
        Route::patch('delivery/zones/{id}', 'updateZone')->whereNumber('id');
        Route::delete('delivery/zones/{id}', 'removeZone')->whereNumber('id');
    });
});

// ---- Boutique : messagerie, contact, newsletter, contenus, avis -------------------
Route::controller(App\Http\Controllers\StorefrontController::class)->group(function () {
    Route::post('conversations', 'startConversation')->middleware(['customer:optional', 'limit:5,10']);
    Route::get('conversations/{token}', 'conversation')->middleware('limit:120,1');
    Route::post('conversations/{token}/messages', 'postMessage')->middleware('limit:20,1');
    Route::post('contact', 'contact')->middleware(['customer:optional', 'limit:3,10']);
    Route::post('newsletter', 'subscribe')->middleware('limit:5,1');
    Route::get('content/pages/{slug}', 'page');
    Route::get('content/faq', 'faq');
    Route::get('content/banners', 'banners');
    Route::post('reviews', 'review')->middleware('customer');
});

// ---- Espace client « Mon compte » ----------------------------------------------------
Route::prefix('me')->middleware('customer')->controller(App\Http\Controllers\MeController::class)->group(function () {
    Route::get('/', 'me');
    Route::patch('/', 'update');
    Route::delete('/', 'remove');
    Route::get('orders', 'orders');
    Route::get('orders/{number}', 'order');
    Route::get('addresses', 'addresses');
    Route::post('addresses', 'addAddress');
    Route::patch('addresses/{id}', 'updateAddress')->whereNumber('id');
    Route::delete('addresses/{id}', 'removeAddress')->whereNumber('id');
    Route::get('wishlist', 'wishlist');
    Route::post('wishlist', 'addWishlist');
    Route::delete('wishlist/{productId}', 'removeWishlist')->whereNumber('productId');
    Route::get('cart', 'cart');
    Route::put('cart', 'putCart');
});

// ---- Back-office : messagerie, clients, marketing, notifications ----------------------
Route::prefix('admin')->middleware('admin')->controller(App\Http\Controllers\Admin\EngagementController::class)->group(function () {
    Route::middleware('admin:messages.read')->group(function () {
        Route::get('conversations', 'conversations');
        Route::get('conversations/unread-count', 'unreadCount');
        Route::get('conversations/{id}', 'conversation')->whereNumber('id');
        Route::get('quick-replies', 'quickReplies');
    });
    Route::middleware('admin:messages.write')->group(function () {
        Route::post('conversations/{id}/messages', 'reply')->whereNumber('id');
        Route::post('conversations/{id}/assign', 'assign')->whereNumber('id');
        Route::post('conversations/{id}/status', 'status')->whereNumber('id');
        Route::put('conversations/{id}/note', 'note')->whereNumber('id');
        Route::post('quick-replies', 'createQuickReply');
        Route::patch('quick-replies/{id}', 'updateQuickReply')->whereNumber('id');
        Route::delete('quick-replies/{id}', 'removeQuickReply')->whereNumber('id');
    });

    Route::get('customers', 'customers')->middleware('admin:customers.read');
    Route::get('customers/{id}', 'customer')->whereNumber('id')->middleware('admin:customers.read');
    Route::patch('customers/{id}', 'updateCustomer')->whereNumber('id')->middleware('admin:customers.write');
    Route::post('customers/{id}/anonymize', 'anonymize')->whereNumber('id')->middleware('admin:customers.write');

    Route::middleware('admin:marketing.write')->group(function () {
        Route::get('newsletter', 'subscribers');
        Route::delete('newsletter/{id}', 'unsubscribe')->whereNumber('id');
        Route::get('coupons', 'coupons');
        Route::get('coupons/{id}', 'coupon')->whereNumber('id');
        Route::post('coupons', 'createCoupon');
        Route::patch('coupons/{id}', 'updateCoupon')->whereNumber('id');
        Route::delete('coupons/{id}', 'removeCoupon')->whereNumber('id');
    });

    Route::get('notifications', 'notifications');
    Route::post('notifications/read-all', 'readAll');
    Route::post('notifications/{id}/read', 'read')->whereNumber('id');
    Route::middleware('admin:content.write')->group(function () {
        Route::get('notification-templates', 'templates');
        Route::put('notification-templates/{code}/{channel}', 'saveTemplate');
        Route::post('notification-templates/{code}/{channel}/preview', 'previewTemplate');
    });
});

// ---- Back-office : contenus, avis, tableau de bord, rapports, exports --------------------
Route::prefix('admin')->middleware('admin')->controller(App\Http\Controllers\Admin\ContentController::class)->group(function () {
    Route::middleware('admin:content.write')->group(function () {
        Route::get('pages', 'pages');
        Route::get('pages/{id}', 'page')->whereNumber('id');
        Route::post('pages', 'createPage');
        Route::patch('pages/{id}', 'updatePage')->whereNumber('id');
        Route::delete('pages/{id}', 'removePage')->whereNumber('id');
        Route::get('faq', 'faq');
        Route::post('faq-categories', 'saveFaqCategory');
        Route::put('faq-categories/{id}', 'saveFaqCategory')->whereNumber('id');
        Route::delete('faq-categories/{id}', 'removeFaqCategory')->whereNumber('id');
        Route::post('faqs', 'createFaq');
        Route::patch('faqs/{id}', 'updateFaq')->whereNumber('id');
        Route::delete('faqs/{id}', 'removeFaq')->whereNumber('id');
    });
    Route::middleware('admin:marketing.write')->group(function () {
        Route::get('banners', 'banners');
        Route::post('banners', 'createBanner');
        Route::patch('banners/{id}', 'updateBanner')->whereNumber('id');
        Route::delete('banners/{id}', 'removeBanner')->whereNumber('id');
    });
    Route::get('reviews', 'reviews')->middleware('admin:reviews.write');
    Route::post('reviews/{id}/moderate', 'moderate')->whereNumber('id')->middleware('admin:reviews.write');

    Route::get('dashboard', 'dashboard')->middleware('admin:dashboard.read');
    Route::get('reports/sales', 'sales')->middleware('admin:reports.read');
    Route::get('reports/preorders', 'preorders')->middleware('admin:reports.read');
    Route::get('reports/stock-valuation', 'valuation')->middleware('admin:reports.read');
    Route::get('exports/{entity}', 'export')->middleware('admin:exports');
});

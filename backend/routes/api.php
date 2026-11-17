<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Admin\BackOfficeController as BO;
use App\Http\Controllers\Api\V1\Admin\DeliveryController;
use App\Http\Controllers\Api\V1\Admin\FinanceController;
use App\Http\Controllers\Api\V1\Admin\InvoiceController;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use App\Http\Controllers\Api\V1\Admin\PreparationController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ReferenceDataController as Ref;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\Courier\MissionController;
use App\Http\Controllers\Api\V1\OrderTrackingController;
use App\Http\Controllers\Api\V1\PaymentSimulatorController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
| API REST v1 — préfixe /api/v1 (bootstrap/app.php).
| Deny-by-default : chaque route privée exige authentification + permission explicite.
*/

Route::middleware('throttle:api')->group(function () {
    // ---- Public : vitrine et catalogue ---------------------------------------------------------
    Route::get('config', [ContentController::class, 'config']);
    Route::get('content/home', [ContentController::class, 'home']);
    Route::get('content/pages/{slug}', [ContentController::class, 'page']);
    Route::get('delivery/zones', [ContentController::class, 'zones']);
    Route::get('promotions', [ContentController::class, 'promotions']);
    Route::get('catalog/categories', [CatalogController::class, 'categories']);
    Route::get('catalog/products', [CatalogController::class, 'products']);
    Route::get('catalog/products/{slug}', [CatalogController::class, 'show'])->name('catalog.products.show');

    // ---- Panier et checkout (invité ou client) -------------------------------------------------
    Route::middleware('throttle:cart')->group(function () {
        Route::post('cart', [CartController::class, 'store']);
        Route::get('cart', [CartController::class, 'show']);
        Route::post('cart/items', [CartController::class, 'addItem']);
        Route::patch('cart/items/{id}', [CartController::class, 'updateItem']);
        Route::delete('cart/items/{id}', [CartController::class, 'removeItem']);
        Route::put('cart/promotion', [CartController::class, 'promotion']);
        Route::post('checkout/quote', [CheckoutController::class, 'quote']);
    });
    Route::middleware('throttle:checkout')->group(function () {
        Route::post('checkout/orders', [CheckoutController::class, 'placeOrder']);
        Route::post('checkout/whatsapp-draft', [CheckoutController::class, 'whatsappDraft']);
        Route::post('orders/{ref}/payments', [OrderTrackingController::class, 'pay']);
    });

    // ---- Suivi propriétaire (jeton secret ou compte client) ------------------------------------
    Route::middleware('throttle:tracking')->group(function () {
        Route::get('orders/{ref}/status', [OrderTrackingController::class, 'status']);
        Route::post('orders/{ref}/payments/refresh', [OrderTrackingController::class, 'refreshPayment']);
        Route::post('orders/{ref}/cancel', [OrderTrackingController::class, 'cancel']);
        Route::post('orders/{ref}/whatsapp-draft', [OrderTrackingController::class, 'whatsapp']);
        Route::post('orders/{ref}/reviews', [OrderTrackingController::class, 'review']);
        Route::get('orders/{ref}/invoices/{number}', [OrderTrackingController::class, 'invoice']);
    });

    // ---- Authentification (session Sanctum) ----------------------------------------------------
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:login');
    // ---- Application mobile : jeton d'accès par appareil (Authorization: Bearer) --------------
    Route::post('auth/token', [AuthController::class, 'issueToken'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::get('auth/tokens', [AuthController::class, 'tokens']);
        Route::delete('auth/tokens/{id}', [AuthController::class, 'revokeToken'])->whereNumber('id');

        // Espace client
        Route::prefix('account')->group(function () {
            Route::get('orders', [AccountController::class, 'orders']);
            Route::put('profile', [AccountController::class, 'updateProfile']);
            Route::get('addresses', [AccountController::class, 'addresses']);
            Route::post('addresses', [AccountController::class, 'storeAddress']);
            Route::put('addresses/{id}', [AccountController::class, 'updateAddress']);
            Route::delete('addresses/{id}', [AccountController::class, 'destroyAddress']);
        });

        // Espace livreur
        Route::prefix('courier')->middleware('permission:courier.missions')->group(function () {
            Route::get('missions', [MissionController::class, 'index']);
            Route::get('missions/{id}', [MissionController::class, 'show']);
            Route::post('missions/{id}/actions', [MissionController::class, 'action']);
            Route::put('availability', [MissionController::class, 'availability']);
            Route::post('push-subscriptions', [MissionController::class, 'subscribePush'])->middleware('throttle:20,1');
            Route::delete('push-subscriptions', [MissionController::class, 'unsubscribePush']);
        });

        // Back-office
        Route::prefix('admin')->group(function () {
            Route::get('dashboard', [BO::class, 'dashboard'])->middleware('permission:orders.view|preparation.view|deliveries.view');
            Route::get('notifications', [BO::class, 'notifications']);
            Route::post('notifications/read', [BO::class, 'markNotificationsRead']);
            Route::get('stats', [BO::class, 'stats'])->middleware('permission:stats.view');

            Route::middleware('permission:orders.view')->group(function () {
                Route::get('orders', [OrderController::class, 'index']);
                Route::post('orders', [OrderController::class, 'store'])->middleware('permission:orders.manage');
                Route::patch('orders/{order}/details', [OrderController::class, 'updateDetails'])->middleware('permission:orders.manage');
                Route::post('orders/{order}/payments/manual', [OrderController::class, 'manualPayment'])->middleware('permission:payments.manual');
                Route::post('orders/{order}/refunds', [OrderController::class, 'requestRefund'])->middleware('permission:refunds.request');
                Route::get('customers', [BO::class, 'customers'])->middleware('permission:customers.view');
                Route::get('customers/{id}', [BO::class, 'customer'])->middleware('permission:customers.view');
            });
            // Détail et transitions : commerciaux et préparateurs (la permission fine est vérifiée par action).
            Route::middleware('permission:orders.view|preparation.manage')->group(function () {
                Route::get('orders/{order}', [OrderController::class, 'show']);
                Route::post('orders/{order}/transitions', [OrderController::class, 'transition']);
            });
            Route::get('preparation', [PreparationController::class, 'index'])->middleware('permission:preparation.view');

            Route::middleware('permission:payments.view')->group(function () {
                Route::get('payments', [FinanceController::class, 'payments']);
                Route::get('payments/events', [FinanceController::class, 'paymentEvents']);
                Route::post('payments/{payment}/reconcile', [OrderController::class, 'reconcilePayment']);
                Route::get('refunds', [FinanceController::class, 'refundIndex']);
            });
            Route::middleware('permission:invoices.view')->group(function () {
                Route::get('invoices', [InvoiceController::class, 'index']);
                Route::get('invoices/export', [InvoiceController::class, 'export']);
                Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf']);
            });
            Route::post('orders/{order}/invoice', [InvoiceController::class, 'issue'])->middleware('permission:invoices.issue');
            Route::middleware('permission:refunds.approve')->group(function () {
                Route::post('refunds/{refund}/approve', [FinanceController::class, 'approve']);
                Route::post('refunds/{refund}/reject', [FinanceController::class, 'reject']);
                Route::post('refunds/{refund}/complete', [FinanceController::class, 'complete']);
            });

            Route::middleware('permission:catalog.manage')->group(function () {
                Route::get('products', [ProductController::class, 'index']);
                Route::post('products', [ProductController::class, 'store']);
                Route::get('products/{id}', [ProductController::class, 'show']);
                Route::patch('products/{id}', [ProductController::class, 'update']);
                Route::post('products/{id}/status', [ProductController::class, 'setStatus']);
                Route::delete('products/{id}', [ProductController::class, 'archive']);
                Route::post('products/{id}/restore', [ProductController::class, 'restore']);
                Route::post('products/{id}/images', [ProductController::class, 'uploadImage']);
                Route::patch('products/{id}/images/{image}', [ProductController::class, 'updateImage']);
                Route::delete('products/{id}/images/{image}', [ProductController::class, 'deleteImage']);
                Route::get('categories', [Ref::class, 'categories']);
                Route::post('categories', [Ref::class, 'saveCategory']);
                Route::put('categories/{id}', [Ref::class, 'saveCategory']);
            });

            Route::middleware('permission:promotions.manage')->group(function () {
                Route::get('promotions', [Ref::class, 'promotions']);
                Route::post('promotions', [Ref::class, 'savePromotion']);
                Route::put('promotions/{id}', [Ref::class, 'savePromotion']);
            });
            Route::middleware('permission:reviews.moderate')->group(function () {
                Route::get('reviews', [BO::class, 'reviews']);
                Route::post('reviews/{review}/moderate', [BO::class, 'moderateReview']);
            });
            Route::middleware('permission:content.manage')->group(function () {
                Route::get('content/pages', [BO::class, 'pages']);
                Route::post('content/pages', [BO::class, 'savePage']);
                Route::put('content/pages/{id}', [BO::class, 'savePage']);
                Route::get('content/blocks', [BO::class, 'blocks']);
                Route::put('content/blocks/{key}', [BO::class, 'saveBlock']);
            });

            Route::prefix('delivery')->group(function () {
                Route::get('zones', [Ref::class, 'zones'])->middleware('permission:zones.view');
                Route::post('zones', [Ref::class, 'saveZone'])->middleware('permission:zones.manage');
                Route::put('zones/{id}', [Ref::class, 'saveZone'])->middleware('permission:zones.manage');
                Route::middleware('permission:deliveries.view')->group(function () {
                    Route::get('board', [DeliveryController::class, 'board']);
                    Route::get('missions', [DeliveryController::class, 'missions']);
                });
                Route::middleware('permission:deliveries.assign')->group(function () {
                    Route::post('assignments', [DeliveryController::class, 'assign']);
                    Route::post('assignments/{assignment}/cancel', [DeliveryController::class, 'cancel']);
                });
                Route::middleware('permission:partners.manage')->group(function () {
                    Route::get('partners', [Ref::class, 'partners']);
                    Route::post('partners', [Ref::class, 'savePartner']);
                    Route::put('partners/{id}', [Ref::class, 'savePartner']);
                });
                Route::middleware('permission:deliveries.settle')->group(function () {
                    Route::get('payouts', [DeliveryController::class, 'payouts']);
                    Route::post('payouts/settle', [DeliveryController::class, 'settle']);
                });
            });

            Route::middleware('permission:users.manage')->group(function () {
                Route::get('users', [BO::class, 'users']);
                Route::post('users', [BO::class, 'saveUser']);
                Route::put('users/{id}', [BO::class, 'saveUser']);
            });
            Route::middleware('permission:settings.manage')->group(function () {
                Route::get('settings', [BO::class, 'settings']);
                Route::put('settings', [BO::class, 'saveSettings']);
            });
            Route::get('audit-logs', [BO::class, 'auditLogs'])->middleware('permission:audit.view');
        });
    });
});

// ---- Webhooks prestataires (signés, hors session, hors CSRF) -----------------------------------
Route::post('webhooks/payments/{provider}', PaymentWebhookController::class)
    ->middleware('throttle:webhooks')->where('provider', '[a-z_]+');

// ---- Simulateur de paiement : local/testing uniquement (404 sinon) -----------------------------
Route::prefix('dev/payment-simulator')->middleware('throttle:checkout')->group(function () {
    Route::get('{reference}', [PaymentSimulatorController::class, 'show']);
    Route::post('{reference}/complete', [PaymentSimulatorController::class, 'complete']);
});

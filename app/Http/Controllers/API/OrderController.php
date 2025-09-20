<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Midtrans\Config;
use Midtrans\Snap;

class OrderController extends Controller
{
    public function __construct()
    {
        // Set Midtrans configuration
        Config::$serverKey = config('services.midtrans.server_key');
        Config::$isProduction = config('services.midtrans.is_production');
        Config::$isSanitized = true;
        Config::$is3ds = true;
    }

    public function index()
    {
        $user = Auth::user();

        try {
            $orders = Order::with('items.product')
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Orders retrieved successfully',
                'data' => $orders
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $user = Auth::user();

        try {
            $order = Order::with('items.product')
                ->where('user_id', $user->id)
                ->where('id', $id)
                ->firstOrFail(); // jika tidak ditemukan, otomatis throw 404

            return response()->json([
                'success' => true,
                'message' => 'Order retrieved successfully',
                'data' => $order
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve order',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function paymentStatus($orderId)
    {
        $user = Auth::user();

        try {
            $order = Order::with('items.product')
                ->where('user_id', $user->id)
                ->where('id', $orderId)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'message' => 'Payment status retrieved successfully',
                'data' => [
                    'order_id' => $order->id,
                    'payment_status' => $order->payment_status,
                    'order_status' => $order->status,
                    'snap_token' => $order->snap_token,
                    'payment_details' => $order->payment_details ?? null
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found or unauthorized',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function checkout(Request $request)
    {
        $user = Auth::user();

        $validator = validator($request->all(), [
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Calculate total amount and check stock
        $totalAmount = 0;
        $orderItems = [];

        foreach ($request->items as $item) {
            $product = Product::find($item['product_id']);

            if ($product->stock < $item['quantity']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient stock for product: ' . $product->name
                ], 400);
            }

            $subtotal = $product->price * $item['quantity'];
            $totalAmount += $subtotal;

            $orderItems[] = [
                'product_id' => $product->id,
                'quantity' => $item['quantity'],
                'price' => $product->price,
                'subtotal' => $subtotal
            ];

            // Reduce stock
            $product->stock -= $item['quantity'];
            $product->save();
        }

        // Create order
        $order = Order::create([
            'user_id' => $user->id,
            'total_amount' => $totalAmount,
            'status' => 'pending',
            'payment_status' => 'unpaid'
        ]);

        // Create order items
        foreach ($orderItems as $item) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'price' => $item['price']
            ]);
        }

        // Generate Snap token for Midtrans
        $params = [
            'transaction_details' => [
                'order_id' => $order->id,
                'gross_amount' => $totalAmount,
            ],
            'customer_details' => [
                'first_name' => $user->name,
                'email' => $user->email,
            ],
            'item_details' => array_map(function ($item) {
                return [
                    'id' => $item['product_id'],
                    'price' => $item['price'],
                    'quantity' => $item['quantity'],
                    'name' => Product::find($item['product_id'])->name,
                ];
            }, $orderItems),
            'callbacks' => [
                'finish' => config('app.url') . '/payment/finish',
                'error' => config('app.url') . '/payment/error',
                'pending' => config('app.url') . '/payment/pending'
            ]
        ];

        try {
            $snapToken = Snap::getSnapToken($params);

            $order->snap_token = $snapToken;
            $order->save();

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => [
                    'order' => $order->load('items.product'),
                    'snap_token' => $snapToken,
                    'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/' . $snapToken
                ]
            ], 201);
        } catch (\Exception $e) {
            // Jika gagal, kembalikan stok produk
            foreach ($orderItems as $item) {
                $product = Product::find($item['product_id']);
                $product->stock += $item['quantity'];
                $product->save();
            }

            // Hapus order yang gagal
            $order->delete();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

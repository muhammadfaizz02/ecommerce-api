<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function handleNotification(Request $request)
    {
        try {
            // Terima data notifikasi
            $notification = $request->all();

            Log::info('Payment Notification Received:', $notification);

            // Validasi field yang diperlukan
            if (!isset($notification['order_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing order_id in notification'
                ], 400);
            }

            if (!isset($notification['transaction_status'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing transaction_status in notification'
                ], 400);
            }

            $orderId = $notification['order_id'];
            $transactionStatus = $notification['transaction_status'];
            $fraudStatus = $notification['fraud_status'] ?? 'accept';
            $grossAmount = $notification['gross_amount'] ?? 0;
            $paymentType = $notification['payment_type'] ?? 'manual';
            $transactionId = $notification['transaction_id'] ?? 'test-' . time();

            // Extract the actual order ID (remove timestamp suffix jika ada)
            $realOrderId = explode('-', $orderId)[0];
            $order = Order::with('items')->find($realOrderId);

            if (!$order) {
                Log::error('Order not found for payment notification', [
                    'order_id' => $realOrderId,
                    'full_order_id' => $orderId
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            // Create or update payment record
            $payment = Payment::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'payment_method' => $paymentType,
                    'amount' => $grossAmount,
                    'transaction_id' => $transactionId,
                    'status' => $transactionStatus,
                    'response' => json_encode($notification)
                ]
            );

            // Update order status based on payment status
            $message = '';

            if ($transactionStatus == 'capture' || $transactionStatus == 'settlement') {
                if ($fraudStatus == 'accept') {
                    $order->payment_status = 'paid';
                    $order->status = 'processing';
                    $message = 'Payment successful';
                }
            } elseif (in_array($transactionStatus, ['cancel', 'deny', 'expire'])) {
                $order->payment_status = 'failed';
                $order->status = 'cancelled';
                $message = 'Payment failed or cancelled';

                // Restore product stock
                foreach ($order->items as $item) {
                    $product = $item->product;
                    $product->stock += $item->quantity;
                    $product->save();
                }
            } elseif ($transactionStatus == 'pending') {
                $order->payment_status = 'pending';
                $message = 'Payment pending';
            } else {
                $message = 'Unknown payment status: ' . $transactionStatus;
            }

            $order->save();

            Log::info('Order status updated', [
                'order_id' => $order->id,
                'payment_status' => $order->payment_status,
                'order_status' => $order->status,
                'message' => $message
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment notification handled successfully: ' . $message
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to handle payment notification', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'notification_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to handle payment notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getPaymentStatus($orderId)
    {
        $user = Auth::user();
        $order = Order::with('payment')->where('user_id', $user->id)->find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment status retrieved successfully',
            'data' => [
                'order_id' => $order->id,
                'payment_status' => $order->payment_status,
                'order_status' => $order->status,
                'snap_token' => $order->snap_token,
                'payment_details' => $order->payment
            ]
        ]);
    }
}

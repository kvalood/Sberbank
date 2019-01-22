<?php

// Работаем в корневой директории
chdir('../../');
require_once('api/Simpla.php');
require_once('payment/Sberbank/RBS.php');

$simpla = new Simpla();

$order = $simpla->orders->get_order(intval($simpla->request->get('order', 'integer')));

if (empty($order)) {
    die('Оплачиваемый заказ не найден');
}

$method = $simpla->payment->get_payment_method(intval($order->payment_method_id));
if (empty($method)) {
    die("Неизвестный метод оплаты");
}

$settings = unserialize($method->settings);
$payment_currency = $simpla->money->get_currency(intval($method->currency_id));

/**
 * Проверим статус заказа
 */
$rbs = new RBS($settings['sbr_login'], $settings['sbr_password'], FALSE, $settings['sbr_mode'] ? TRUE : FALSE);
$order_id_merchant = $simpla->request->get('orderId', 'string');
$response = $rbs->get_order_status_by_orderId($order_id_merchant);

// Если указана ошибка оплаты
if ($response['errorCode']) {
    die($response['errorMessage']);
}

if ($response['actionCode'] != 0) {
    header('Location: ' . $simpla->config->root_url . '/order/' . $order->url);
    die("Ошибка оплаты. " . $response['actionCodeDescription']);
}

// Нельзя оплатить уже оплаченный заказ  
if ($order->paid) {
    header('Location: ' . $simpla->config->root_url . '/order/' . $order->url);
}

// Проверяем оплаченный заказ
$total_price = round($order->total_price, 2) * 100;
if ($response['amount'] != (int)$total_price || $response['amount'] <= 0) {
    die("incorrect price");
}

// Получим данные о чеке
$payment_details = $rbs->get_receipt_status_by_orderId($order_id_merchant);

// Установим статус "оплачен"
$simpla->orders->update_order(intval($order->id), [
    'paid' => 1,
    'payment_date' => date('Y-m-d H:i:s'),
    'payment_details' => json_encode([
        'orderId' => $payment_details['orderId'], // Номер заказа в Платежном Шлюзе
        'uuid' => $payment_details['receipt'][0]['uuid'], // Номер чека
    ])
]);
// $simpla->orders->pay(intval($order->id)); // Должно быть так, но не работает

// Спишем товары
$simpla->orders->close(intval($order->id));

// Отправим уведомление на email
$simpla->notify->email_order_user(intval($order->id));
$simpla->notify->email_order_admin(intval($order->id));

header('Location: ' . $simpla->config->root_url . '/order/' . $order->url);

exit();
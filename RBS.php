<?php

class RBS
{
    /**
     * АДРЕС ТЕСТОВОГО ШЛЮЗА
     *
     * @var string
     */
    const TEST_URL = 'https://3dsec.sberbank.ru/payment/rest/';

    /**
     * АДРЕС БОЕВОГО ШЛЮЗА
     *
     * @var string
     */
    const PROD_URL = 'https://securepayments.sberbank.ru/payment/rest/';

    /**
     * ЛОГИН МЕРЧАНТА
     *
     * @var string
     */
    private $user_name;

    /**
     * ПАРОЛЬ МЕРЧАНТА
     *
     * @var string
     */
    private $password;

    /**
     * ДВУХСТАДИЙНЫЙ ПЛАТЕЖ
     *
     * Если значение true - будет производиться двухстадийный платеж
     *
     * @var boolean
     */
    private $two_stage;

    /**
     * ТЕСТОВЫЙ РЕЖИМ
     *
     * Если значение true - плагин будет работать в тестовом режиме
     *
     * @var boolean
     */
    private $test_mode;


    /**
     * КОНСТРУКТОР КЛАССА
     *
     * Заполнение свойств объекта
     *
     * @param string $user_name логин мерчанта
     * @param string $password пароль мерчанта
     * @param boolean $two_stage двухстадийный платеж
     * @param boolean $test_mode тестовый режим
     */
    public function __construct($user_name, $password, $two_stage, $test_mode)
    {
        $this->user_name = $user_name;
        $this->password = $password;
        $this->two_stage = $two_stage;
        $this->test_mode = $test_mode;
    }

    /**
     * ЗАПРОС В ПШ
     *
     * Формирование запроса в платежный шлюз и парсинг JSON-ответа
     *
     * @param string $method метод запроса в ПШ
     * @param string[] $data данные в запросе
     * @param string $url адрес ПШ
     * @return string[]
     */
    private function gateway($method, $data)
    {
        $data['userName'] = $this->user_name;
        $data['password'] = $this->password;
        if ($this->test_mode) {
            $url = self::TEST_URL;
        } else {
            $url = self::PROD_URL;
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url . $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data)
        ]);
        $response = curl_exec($curl);
        $response = json_decode($response, true);
        curl_close($curl);
        return $response;
    }


    /**
     * РЕГИСТРАЦИЯ ЗАКАЗА
     * Метод register.do или registerPreAuth.do
     * @param string $order_number номер заказа в магазине
     * @param integer $amount сумма заказа
     * @param string $return_url страница, на которую необходимо вернуть пользователя если платеж прошел успешно
     * @param string $order_description Описание заказа
     * @param array $order_bundle Корзина товаров заказа
     * @param integer $taxSystem Система налогообложения
     * @return string[]
     */
    function register_order($order_number, $amount, $return_url, $order_description, $order_bundle, $taxSystem = false)
    {
        $data = [
            'orderNumber' => $order_number,
            'amount' => $amount,
            'returnUrl' => $return_url,
            'description' => $order_description,
            'orderBundle' => $order_bundle,
            //'sessionTimeoutSecs' => 60
        ];

        if ($taxSystem !== false) {
            $data['taxSystem'] = $taxSystem;
        }
        if ($this->two_stage) {
            $method = 'registerPreAuth.do';
        } else {
            $method = 'register.do';
        }
        $response = $this->gateway($method, $data);
        return $response;
    }

    /**
     * СТАТУС ЗАКАЗА ПО ORDER ID в мерчанте
     *
     * Метод getOrderStatusExtended.do
     *
     * @param string $orderId номер заказа
     * @return string[]
     */
    public function get_order_status_by_orderId($orderId)
    {
        $data = ['orderId' => $orderId];
        $response = $this->gateway('getOrderStatusExtended.do', $data);
        return $response;
    }

    /**
     * СТАТУС ЗАКАЗА ПО ORDER NUMBER в магазине
     *
     * Метод getOrderStatusExtended.do
     *
     * @param string $order_number номер заказа в магазине
     * @return string[]
     */
    public function get_order_status_by_orderNumber($order_number)
    {
        $data = ['orderNumber' => $order_number];
        $response = $this->gateway('getOrderStatusExtended.do', $data);
        return $response;
    }


    /**
     * Запрос отмены заказа
     * Метод reverse.do
     *
     * @param string $orderId номер заказа в мерчанте
     * @return string[]
     */
    public function reverse_order($orderId)
    {
        $data = ['orderId' => $orderId];
        $response = $this->gateway('reverse.do', $data);
        return $response;
    }


    /**
     * MDORDER ИЗ FORMURL
     *
     * @param string $url адрес платежной страницы
     * @return string
     */
    public function get_mdOrder_from_url($url)
    {
        $parse = parse_url($url);
        $mdOrder = explode('=', $parse['query']);
        return $mdOrder[1];
    }

}
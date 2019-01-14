# Модуль оплаты Сбербанк Интернет Эквайринг для SimplaCMS 2.*

### Особенности
* Передача корзины товаров (кассовый чек 54-ФЗ)
* Поддержка ФФД 1.05
* Если оплата не прошла или прошла с ошибкой, оплату можно повторить, хоть спустя 20 дней...
* Работает через REST
* Одноэтапная оплата


### Требования:
* php 5.6 и выше
* curl
* SimplaCMS 2+


### Установка:
1. Просто распаковать архив с файлами модуля по адресу `\payment\Sberbank\`\
2. В админ. панели добавить новый способ оплаты `Сбербанк-Эквайринг`
3. Настроить модуль в соответствии с вашими требованиями.



Офф. документация:
-
- https://web.rbsdev.com/dokuwiki/doku.php/integration:api:rest:requests:register_cart (наиболее актуальная)
- https://securepayments.sberbank.ru/wiki/doku.php/integration:api:rest:requests:register_cart#items
- https://developer.sberbank.ru/doc/v1/acquiring/api-basket

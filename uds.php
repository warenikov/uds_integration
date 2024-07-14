<?php

namespace Local\ApiTypes;

use \Bitrix\Main\Loader,
    \Bitrix\Main\SystemException,
    \Bitrix\Sale,
    \Bitrix\Main\Application,
    \Bitrix\Main\Web\Cookie;

class ExсeptionUDS extends SystemException
{
    public function __construct($message = "")
    {
        parent::__construct($message);
    }
}


//регистрируем необходимые обработчики событий, чтобы все было компактно собрано в обдом месте для переноса
//при применении скидки на весь заказ нужно раскомментировать строку ниже
AddEventHandler('sale', 'OnSaleComponentOrderJsData', ['\Local\ApiTypes\UDS', 'addUDS']);

//отправляем транзакцию после добавления заказа
AddEventHandler('sale', 'OnSaleComponentOrderOneStepComplete', ['\Local\ApiTypes\UDS', 'UDSOrderComplete']);

//отмена транзакции в случае отмены заказа
AddEventHandler('sale', 'OnOrderUpdate', ['\Local\ApiTypes\UDS', 'UDSOrderUpdate']);

//обновление товаров при логине
AddEventHandler("main", "OnAfterUserLogin", ['\Local\ApiTypes\UDS', 'refreshUDS']);

//AddEventHandler('sale', 'OnBasketUpdate', ['\Local\ApiTypes\UDS', 'UDSRefresh']);
//AddEventHandler('sale', 'OnBasketAdd', ['\Local\ApiTypes\UDS', 'UDSRefresh']);
//AddEventHandler('sale', 'OnBeforeBasketDelete', ['\Local\ApiTypes\UDS', 'UDSRefresh']);

class UDS
{
    private $apiKey = "XAXAXA";
    private $companyID = "XAXAXA";
    private $apiUrl = "https://api.uds.app/partner/v2/";
    private $objLog = false;
    private $objUser = false;
    public $idUDSCurrentUser = false;

    public $intUserBXID = false;
    public $intUserSaleAccountBudget = false;
    public $intUserSaleAccountRezerv = false;
    public $arUserSaleAccountAccount = false;
    public $CurrentPurchaseCode = false;

    public $intMaxDiscountPercent = false;
    private $intUserSaleAccountID = false;

    protected static $instance;

    const ID_IBLOCK_LOG = 37;//ИБ для логирования
    const VAR_NAME_UDS_ID = "UF_UDS_ID";//поле, где хранится ИД пользователя в UDS
    const VAR_NAME_UDS_MAX_DISCOUNT_PER = "UF_UDS_MAX_DISCOUNT_PER";//поле, где хранится максимальная скидка от заказа в процентах
    const VAR_BUDGET_CAN = "BUDGET";//поле, где хранится данные о бюджете
    const VAR_BUDGET_WISH = "REZERV";//поле, где хранится данные о желаемом списании баллов
    const PURCHASE_CODE = "purchaseCode";
    const START_LIVE_CODE = "start_live_code";
    const TTL_CODE = 15;//время жизни кода в минутах

    const TRANSACTION_ID = 100;
    const UDS_GUID = 101;//поле, где хранится данные о желаемом списании баллов
    const UDS_BALLS = 99;//поле, где хранится данные о желаемом списании баллов

    public function __construct()
    {
        global $USER;

        if (!Loader::includeModule('iblock')) {
            throw new SystemException('Ошибка подключения модуля iblock');
        }
        $this->objLog = new \CIBlockElement();


        if (!Loader::includeModule('catalog')) {
            throw new SystemException('Ошибка подключения модуля catalog');
        }

        //пытаемся получить данные ID UDS
        $this->getUDSCurrentUserID();
        $this->getMaxDiscountPercent();
        $this->getSaleUserAccount();
        $this->intUserBXID = $USER->GetID() ?? false;
//        $eventManager = \Bitrix\Main\EventManager::getInstance();

        if ($this->getSaleUserAccountBudget()) {
            $this->recalcBasket();
        }

        self::$instance = $this;
    }

    public function __destruct()
    {
        $this->checkPurchaseCode();
    }

    private function checkPurchaseCode()
    {
        $session = Application::getInstance()->getSession();

        if (time() - $session->get(self::START_LIVE_CODE) > self::TTL_CODE * 60) {
            self::refreshUDS();
        }
    }

    //пересчет текущей корзины исходя из текущих параметров, сюда нужно передать объект корзины
    public function recalcBasket()
    {
        //текущая корзина
        $basket = \Bitrix\Sale\Basket::loadItemsForFUser(
            \Bitrix\Sale\Fuser::getId(),
            \Bitrix\Main\Context::getCurrent()->getSite()
        );

        //текущий бюджет
        $intBudget = $this->getSaleUserAccountRezerv();
        $basketItems = $basket->getBasketItems();
        //если есть бюджет
        if ($intBudget) {
            //если товары корзины существуют
            if ($basketItems = $basket->getBasketItems()) {
                $diff = 0;
                foreach ($basketItems as $item) {
                    if (!$item->canBuy()) {
                        continue;
                    }
                    $arT = [];
                    $arT["PRODUCT_ID"] = $item->getProductID();
                    $arT["CAN_BUY"] = $item->canBuy() ? 1 : 0;
                    $arT["NAME"] = $item->getField('NAME');
                    $arT["PRICE"] = $item->getPrice();
                    $arT["BASE_PRICE"] = \Bitrix\Catalog\PriceTable::getList(
                        [
                            "select" => ["*"],
                            "filter" => ["=PRODUCT_ID" => $item->getProductID(), "CATALOG_GROUP_ID" => 3],
                            "limit" => 1,
                            "cache" => [
                                "ttl" => 60
                            ]
                        ]
                    )->fetch()["PRICE"];

                    $arT["RATION"] = $this->getProdRatioByID($item->getProductID());
                    $arT["FINAL_PRICE"] = $item->getFinalPrice();
                    $arT["QUANT"] = $item->getQuantity() >= 1 ? round($item->getQuantity() / $arT["RATION"]) : $item->getQuantity() * 10;
                    $arT["TRUE_PRICE"] = $arT["QUANT"] * $arT["BASE_PRICE"] * $arT["RATION"];
                    $arT["UNPROFIT_PRICE"] = $this->getUnprofitablePrice($item->getProductID(), $arT["BASE_PRICE"]);
                    $arT["DIFF"] = round(($arT["BASE_PRICE"] - $arT["UNPROFIT_PRICE"]) * $arT["RATION"] * $arT["QUANT"]);
                    $diff += $arT["DIFF"];
                    $arT["OBJ"] = $item;
                    $arBasketItems[] = $arT;
                }
                $t = round($diff / $intBudget, 3, PHP_ROUND_HALF_DOWN);

                // сортируем товары в корзине по цене от бОльшего к мЕньшему
                usort($arBasketItems, [__CLASS__, 'sort']);
                $tt = 0;
                $balls = 0;
                $temp = 0;
                $discount = 0;
                $truePrice = 0;
                foreach ($arBasketItems as &$item) {
                    $objBasketItem = $item["OBJ"];//объект товара в корзине
                    $tt += round($item["DIFF"] / $t, 0, PHP_ROUND_HALF_DOWN);
                    $item["BALLS"] = round($item["DIFF"] / $t, 0, PHP_ROUND_HALF_DOWN);
                    $discount += $item["DISCOUNT_PRICE"] = ($item["BASE_PRICE"] - $item["BALLS"] / $objBasketItem->getQuantity()) * $item["QUANT"] * $item["RATION"];
                    $balls += $item["BALLS"];
                    $truePrice += $item["TRUE_PRICE"];
                }


//                log_to_console([$truePrice, $discount, $truePrice - $discount]);
                //если посчитали больше чем бюджет, то разницу вычитаем из самого дорогого товара
                if ($tt > $intBudget) {
                    $diffBalls = $tt - $intBudget;
                    $arBasketItems[0]["BALLS"] = $arBasketItems[0]["BALLS"] - $diffBalls;
                    foreach($arBasketItems as &$item){
                        //на случай, если у самого дорогого товара нельзя списывать баллы
                        if($item["BALLS"] > 0){
                            $item["BALLS"] = $item["BALLS"] - $diffBalls;
                            break;
                        }
                    }
                } else {
                    if ($tt < $intBudget) {
                        $diffBalls = $intBudget - $tt;
                        foreach($arBasketItems as &$item){
                            //на случай, если у самого дорогого товара нельзя списывать баллы
                            if($item["BALLS"] > 0){
                                $item["BALLS"] = $item["BALLS"] + $diffBalls;
                                break;
                            }
                        }
                    }
                }

                //перебираем все товары в корзине и пытаемся применить баллы к товарам
                foreach ($arBasketItems as $basketItem) {
                    $objBasketItem = $basketItem["OBJ"];//объект товара в корзине
                    if ($intBudget > 0 && $basketItem["BALLS"] > 0) {
                        $objBasketItem->setFields(
                            [
                                'CUSTOM_PRICE' => 'Y',
                                'PRICE' => round($basketItem["BASE_PRICE"] - $basketItem["BALLS"] / $objBasketItem->getQuantity(), 0, PHP_ROUND_HALF_UP),
                                'DISCOUNT_PRICE' => $basketItem["BALLS"] / $objBasketItem->getQuantity(),
                                'PRODUCT_PROVIDER_CLASS' => '',
                                'CURRENCY' => 'RUB'
                            ]
                        );
                        $objBasketItem->save();
                    } else {
                        //если бюджета нет, скидываем все скидки на товары
                        $objBasketItem->setFields(['CUSTOM_PRICE' => 'N']);
                        $objBasketItem->save();
                    }
                }

                $basket->save();
            }
        } else {
            $basketItems = $basket->getBasketItems();
            foreach ($basketItems as $item) {
                $item->setFields(['CUSTOM_PRICE' => 'N']);
                $item->save();
            }
        }
    }

    public function getMaxPriceWriteOff()
    {
        $intMaxPriceOff = 0;

        $basket = \Bitrix\Sale\Basket::loadItemsForFUser(
            \Bitrix\Sale\Fuser::getId(),
            \Bitrix\Main\Context::getCurrent()->getSite()
        );

        if ($basketItems = $basket->getBasketItems()) {
            foreach ($basketItems as $item) {
                if (!$item->canBuy()) {
                    continue;
                }

                $ratio = $this->getProdRatioByID($item->getProductId());
                $quant = $item->getQuantity() >= 1 ? round($item->getQuantity() / $ratio) : $item->getQuantity() * 10;

                $priceOff = $this->getUnprofitablePrice($item->getProductID(), $item->getBasePrice());

                $d = ($item->getBasePrice() - $priceOff) * $quant * $ratio;

                $intMaxPriceOff += $d;
            }

            return round($intMaxPriceOff);
        }
    }

    public function getSkipLoyaltyTotal($basket)
    {
        if (!$basket) {
            $basket = \Bitrix\Sale\Basket::loadItemsForFUser(
                \Bitrix\Sale\Fuser::getId(),
                \Bitrix\Main\Context::getCurrent()->getSite()
            );
        }
        $res = 0;
        if ($basketItems = $basket->getBasketItems()) {
            foreach ($basketItems as $item) {
                $ar["C"] = $item->getField('CUSTOM_PRICE');
                $ar["NAME"] = $item->getField('NAME');
                if ($item->getField('CUSTOM_PRICE') == "N") {
                    $res += $item->getFinalPrice();
                }
                $arT[] = $ar;
            }
        }
        return $res;
    }

    //получаем Коэффициент единицы измерения товара
    private function getProdRatioByID(int $PRODUCT_ID)
    {
        Loader::includeModule("catalog");

        $res = \Bitrix\Catalog\MeasureRatioTable::getList(
            [
                'select' => ['*'],
                'filter' => ['PRODUCT_ID' => $PRODUCT_ID],
                'limit' => 1
            ]
        )->fetch();

        return $res["RATIO"];
    }

    //получаем неснижаемую цену для товара
    private function getUnprofitablePrice(?int $PRODUCT_ID, ?int $PRICE)
    {
        if (!$PRODUCT_ID) {
            return $PRICE * 0.5;//если не передали ИД товара и передали цену, то вернем 50% от цены, иначе сделаем расчет
        } else {
            //тут расчет цену БУ

            $res = \CIBlockElement::GetList(
                [],
                [
                    "ID" => $PRODUCT_ID,
                    "ACTIVE_DATE" => "Y",
                    "ACTIVE" => "Y",
                ],
                false,
                ["nPageSize" => "1"],
                ["ID", "NAME", "PROPERTY_UDS_ZNACHENIE_TSENY_DLYA_RASCHETA_BEZUBYTOCHNOY_SK", "PROPERTY_UDS_PROTSENT_NATSENKI_DLYA_RASCHETA_BEZUBYTOCHNOY_", "PROPERTY_UDS_NE_PRIMENYAT_SKIDKU"]
            );

            $result = $PRICE;

            //заказчик не может сделать скидку меньше чем 50% от розницы
            $fifth_percent = round($PRICE / 2);
            if ($el = $res->GetNext()) {
                if ($el["PROPERTY_UDS_ZNACHENIE_TSENY_DLYA_RASCHETA_BEZUBYTOCHNOY_SK_VALUE"]
                    && $el["PROPERTY_UDS_PROTSENT_NATSENKI_DLYA_RASCHETA_BEZUBYTOCHNOY__VALUE"]
                    && ($el["PROPERTY_UDS_NE_PRIMENYAT_SKIDKU_ENUM_ID"] == 189 || $el["PROPERTY_UDS_NE_PRIMENYAT_SKIDKU_ENUM_ID"] == null)) {                   $percent = $el["PROPERTY_UDS_ZNACHENIE_TSENY_DLYA_RASCHETA_BEZUBYTOCHNOY_SK_VALUE"] * (1 + $el["PROPERTY_UDS_PROTSENT_NATSENKI_DLYA_RASCHETA_BEZUBYTOCHNOY__VALUE"] / 100);
                    $calc_price = ceil($percent);
                    //если получается, что 50% это больше чем цена безубытка, то скидываем максимум 50%
                    if ($fifth_percent > $calc_price) {
                        $result = $fifth_percent;
                    } else {
                        $result = $calc_price;
                    }
                    if ($calc_price > $PRICE) {
                        $result = $PRICE;
                    }
                }
            }

            //log_to_console([$calc_price, $PRICE, $fifth_percent, $result]);

            //if(10553 == $PRODUCT_ID)
            return $result;
        }
    }

    private function sort($a, $b)
    {
        if ($a["PRICE"] == $b["PRICE"]) {
            return 0;
        }
        return ($a["PRICE"] > $b["PRICE"]) ? -1 : 1;
    }

    /** Склонение существительных с числительными
     * @param int $n число
     * @param string $form1 Единственная форма: 1 секунда
     * @param string $form2 Двойственная форма: 2 секунды
     * @param string $form5 Множественная форма: 5 секунд
     * @return string Правильная форма
     */
    public function pluralForm($n, $form1, $form2, $form5)
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $form5;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $form2;
        }
        if ($n1 == 1) {
            return $form1;
        }
        return $form5;
    }


    //геттеры и сеттер
    public function setUDSCurrentUserID(?string $udsID)
    {
        if ($udsID) {
            $this->idUDSCurrentUser = $udsID;

            global $USER;
            //если пользователь авторизован
            if ($USER->IsAuthorized() && $udsID) {
                $USER->Update($USER->GetID(), [self::VAR_NAME_UDS_ID => $udsID]);
            }

            //и пишем данные в сессию
            $session = Application::getInstance()->getSession();
            $result = $session->set(self::VAR_NAME_UDS_ID, $udsID) ?? false;

            return true;
        }

        return false;
    }

    public function getUDSCurrentUserID()
    {
        if ($this->idUDSCurrentUser) {
            return $this->idUDSCurrentUser;
        }

        global $USER;
        //если пользователь авторизован
        if ($USER->IsAuthorized()) {
            $result = $USER->GetByID($USER->GetID())->Fetch()[self::VAR_NAME_UDS_ID] ?? false;
        } //если нет, берем данные из куки
        else {
            $session = Application::getInstance()->getSession();
            $result = $session->get(self::VAR_NAME_UDS_ID) ?? false;
        }

        if ($result) {
            return $this->idUDSCurrentUser = $result;
        }

        return false;
    }

    public function getMaxDiscountPercent()
    {
        if ($this->intMaxDiscountPercent) {
            return $this->intMaxDiscountPercent;
        }

        global $USER;
        if ($USER->IsAuthorized()) {
            $result = $USER->GetByID($USER->GetID())->Fetch()[self::VAR_NAME_UDS_MAX_DISCOUNT_PER] ?? false;
        } else {
            $session = Application::getInstance()->getSession();
            $result = $session->get(self::VAR_NAME_UDS_MAX_DISCOUNT_PER) ?? false;
        }

        if ($result) {
            return $this->intMaxDiscountPercent = $result;
        }

        return false;
    }

    public function setMaxDiscountPercent(?int $percent)
    {
        if ($percent) {
            $this->intMaxDiscountPercent = $percent;

            global $USER;
            //если пользователь авторизован
            if ($USER->IsAuthorized() && $percent) {
                $USER->Update($USER->GetID(), [self::VAR_NAME_UDS_MAX_DISCOUNT_PER => $percent]);
            }

            //и пишем данные в сессию
            $session = Application::getInstance()->getSession();
            $session->set(self::VAR_NAME_UDS_MAX_DISCOUNT_PER, $percent);

            return true;
        }

        return false;
    }

    public function getUserBXId()
    {
        global $USER;

        //если пользователь есть
        if ($this->intUserBXID) {
            return $this->intUserBXID;
        }

        if ($USER->IsAuthorized()) {
            $this->intUserBXID = $USER->GetID();
            return $this->intUserBXID;
        }

        return false;
    }

    //конец геттерам и сеттерам

    public static function getInstance()
    {
        if (!isset(static::$instance)) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    private function getApiKey()
    {
        //тут будет возможность получить данные из БД

        return $this->apiKey;
    }

    private function getAPIIdUds()
    {
        //тут будет возможность получить данные из БД

        return $this->idAPIUds;
    }

    private function getApiUrl()
    {
        //тут будет возможность получить данные из БД

        return $this->apiUrl;
    }

    private function sendRequest(?string $params = null, ?array $content = null, ?string $method = "GET"): array|false
    {
        $date = new \DateTime();
        $uuid_v4 = 'UUID';
        $headerPostString = "";
        if ($method == "POST") {
            if (is_array($content)) {
                $postData = json_encode($content);
            }

            $headerPostString = "Content-Type: application/json\r\n";
        }

        // Create a stream
        $opts = array(
            'http' => array(
                'method' => $method,
                'header' => "Accept: application/json\r\n" .
                    "Accept-Charset: utf-8\r\n" .
                    $headerPostString .
                    "Authorization: Basic " . base64_encode("$this->companyID:$this->apiKey") . "\r\n" .
                    "X-Origin-Request-Id: " . $uuid_v4 . "\r\n" .
                    "X-Timestamp: " . $date->format($date::ATOM),
                'ignore_errors' => true
            )
        );

        if (is_array($content)) {
            $opts["http"]["content"] = $postData;
        }

        $context = stream_context_create($opts);
        $result = json_decode(file_get_contents($this->getApiUrl() . $params, false, $context), true);

        if (!isset($result["errorCode"])) {
            return $result;
        } else {
            throw new ExсeptionUDS("Ошибка обмена с UDS " . $result["errorCode"] . ":" . $result["message"] . " \n" . print_r($result, true));
        }

        return false;
    }


    public function getUDSUserInfoByDiscountCode(string $code)
    {
        if ($code) {
            $result = $this->sendRequest("customers/find?code=" . $code);

            //если запрос по коду и у нас нет кода польщовалея в БД записываем его туда
            $this->setUDSCurrentUserID($result["user"]["uid"]);
            $this->setMaxDiscountPercent($result["user"]["participant"]["membershipTier"]["maxScoresDiscount"]);

            return $result;
        }
        return false;
    }

    public function getDiscountByCode(string|int $code = 0)
    {
        if ($code) {
            $result = $this->sendRequest("customers/find?code=" . $code);
            //если запрос по коду и у нас нет кода польщовалея в БД записываем его туда
            $this->setUDSCurrentUserID($result["user"]["uid"]);
            $this->setMaxDiscountPercent($result["user"]["participant"]["membershipTier"]["maxScoresDiscount"]);
            $this->setSaleUserAccount($result["user"]["participant"]["points"]);
            $this->setCurrentPurchaseCode($code);
            return $result["user"]["participant"]["points"];
        }
        return false;
    }

    public function setCurrentPurchaseCode($code = null)
    {
        $this->CurrentPurchaseCode = $code;

        $session = Application::getInstance()->getSession();
        $session->set(self::PURCHASE_CODE, $code);
        if ($code) {
            $session->set(self::START_LIVE_CODE, time());
        } else {
            $session->remove(self::START_LIVE_CODE);
        }
    }

    public function getPurchaseCode()
    {
        if ($this->CurrentPurchaseCode) {
            return $this->CurrentPurchaseCode;
        }

        $session = Application::getInstance()->getSession();
        $code = $this->CurrentPurchaseCode = $session->get(self::PURCHASE_CODE);

        return $code;
    }



    //устанавливаем личный счет пользователя в системе
    //$budget_wish - сумма, которую хочет списать клиент
    public function setSaleUserAccount(int $budget_can = 0, int $budget_wish = 0)
    {
        //мы передали на резерв баллы
        if ($budget_wish && $budget_wish > $budget_can) {
            throw new ExсeptionUDS("Баллов на счете в UDS не достаточно для оплаты.");
        }

        if ($this->getUserBXId()) {
            if (!Loader::includeModule('sale')) {
                throw new SystemException('Ошибка подключения модуля sale');
            }

            $accountId = $this->getSaleUserAccount()["ID"] ?? 0;

            if ($accountId) {
                $arFields = array("CURRENT_BUDGET" => $budget_wish, "NOTES" => $budget_can);
                $accountId = \CSaleUserAccount::Update($accountId, $arFields);
            } else {
                $arFields = array("USER_ID" => $this->getUserBXId(), "CURRENCY" => "RUB", "CURRENT_BUDGET" => $budget_wish, "NOTES" => $budget_can);
                $accountId = \CSaleUserAccount::Add($arFields);
            }

            $this->intUserSaleAccountID = $accountId;
            $this->intUserSaleAccountBudget = $budget_can;
            $this->intUserSaleAccountRezerv = $budget_wish;

            $this->getSaleUserAccount();
//            $this->writeLog("Для пользователя " . $this->getUserBXId() . " обновили личный счет $this->intUserSaleAccountID и записали туда $this->intUserSaleAccountBudget баллов", "", "обновили личный счет для ID $this->intUserBXID");
        } else {
            //или пишем данные в куку
            $session = Application::getInstance()->getSession();
            $session->set(self::VAR_BUDGET_CAN, $budget_can);
            $this->intUserSaleAccountBudget = $budget_can;

            $session->set(self::VAR_BUDGET_WISH, $budget_wish);
            $this->intUserSaleAccountRezerv = $budget_wish;
        }

        //после установки баллов пересчет корзины
        $this->recalcBasket();
    }

    public static function refreshUDS()
    {
        $uds = self::getInstance();
        $uds->unsetBudget();
    }

    public function unsetBudget()
    {
        $session = Application::getInstance()->getSession();
        $session->remove(self::VAR_BUDGET_WISH);
        $session->remove(self::VAR_BUDGET_CAN);
        $session->remove(self::PURCHASE_CODE);

        $this->setCurrentPurchaseCode();

        $this->setSaleUserAccount();
    }

    //получаем личный счет пользователя в системе
    public function getSaleUserAccount()
    {
        if ($this->getUserBXId()) {
            if (!Loader::includeModule('sale')) {
                throw new SystemException('Ошибка подключения модуля sale');
                return fasle;
            }

            $dbAccount = \CSaleUserAccount::GetList(
                array(),
                array("USER_ID" => $this->getUserBXId()),
                false,
                array("nTopCount" => 1),
                array("ID", "CURRENT_BUDGET", "CURRENCY", "NOTES")
            );

            if ($arAccount = $dbAccount->Fetch()) {
                //изначально планировал не так
                $arT = $arAccount;
                $this->intUserSaleAccountBudget = $arAccount["CURRENT_BUDGET"] = $arT["NOTES"];//тут будет хранится информация про ВОЗМОЖНОЕ количество списания баллов
                $this->intUserSaleAccountRezerv = $arAccount["NOTES"] = $arT["CURRENT_BUDGET"];//ту будет хранится о ЖЕЛАЕМОМ списании баллов
                $arAccount["ID"] = $arT["ID"];

                $this->arUserSaleAccountAccount = $arAccount;
                return $this->arUserSaleAccountAccount;
            }
        } else {
            $session = Application::getInstance()->getSession();
            $this->intUserSaleAccountBudget = $session->get(self::VAR_BUDGET_CAN) ?? false;
            $this->intUserSaleAccountRezerv = $session->get(self::VAR_BUDGET_WISH) ?? false;
        }
        return false;
    }


    public function getSaleUserAccountBudget()
    {
        if (!$this->intUserSaleAccountBudget) {
            $this->getSaleUserAccount();
        }

        return (int)$this->intUserSaleAccountBudget;
    }

    public function getSaleUserAccountRezerv()
    {
        if (!$this->intUserSaleAccountRezerv) {
            $this->getSaleUserAccount();
        }

        return (int)$this->intUserSaleAccountRezerv;
    }

    public function getUDSUserInfoByGUID()
    {
        if ($this->getUDSCurrentUserID()) {
            $result = $this->sendRequest("customers/find?uid=" . $this->getUDSCurrentUserID());
            $this->updateUserInfo(self::VAR_NAME_UDS_MAX_DISCOUNT_PER, $result["user"]["participant"]["membershipTier"]["maxScoresDiscount"]);
            $this->updateUserInfo(self::VAR_NAME_UDS_ID, $result["user"]["uid"]);

            $this->setSaleUserAccount((int)$result["user"]["participant"]["points"]);
            return $result;
        }
        return false;
    }

    private function updateUserInfo(string $fieldsName = "", string $fieldsValue = "")
    {
        global $USER;
        if ($this->getUserBXId()) {
            if ($fieldsName && $fieldsValue) {
                $USER->Update($USER->GetID(), [$fieldsName => $fieldsValue]);
            }
        } else {
            $session = Application::getInstance()->getSession();
            $session->set($fieldsName, $fieldsValue);
        }
    }

    /**
     * @param array|string $request
     * @param array|string $answer
     */
    public
    function writeLog(
        array|string $request,
        array|string $answer = "",
        string $namePref = "",
    ): void {
        $arFields = [
            "NAME" => date("d.m.Y G:i:s") . " " . $namePref,
            "IBLOCK_ID" => self::ID_IBLOCK_LOG,
            "IBLOCK_SECTION_ID" => false,
            'ACTIVE' => 'Y',
            "PREVIEW_TEXT" => is_array($request) ? print_r($request, true) : $request,
            "DETAIL_TEXT" => is_array($answer) ? print_r($answer, true) : json_decode($answer, true),

        ];

        if (!$this->objLog->Add($arFields)) {
            throw new SystemException('Ошибка логирования записи');
        }
    }


    //получаем информацию о текущем пользователе системы UDS
    public function getUDSUserSettings()
    {
        if ($this->getUDSCurrentUserID()) {
            $result = $this->sendRequest($this->getUDSCurrentUserID());
            return $result;
        }
        return false;
    }


    //получаем всех клиентов
    public function getAllUDSUsers()
    {
        echo $this->getUDSCurrentUserID();
        if ($this->getUDSCurrentUserID()) {
            $result = $this->sendRequest("customers/?max=10&offset=0");
            return $result;
        }
        return false;
    }

    //получаем настройки
    public function getUDSSettings()
    {
        return $this->sendRequest("settings");
    }

    public function getTransactions(?string $cursor = "")
    {
        return $this->sendRequest("operations?max=50" . ($cursor ? "&cursor=" . $cursor : ""));
    }

    //сбрасываем скидку в корзине
    public function resetUDSDiscountCart()
    {
        $this->setSaleUserAccount();
    }

    public function calculateTransaction($user_id, $total, $skip = null, $points)
    {
        $postData = [
            "participant" => [
                "uid" => $user_id
            ],
            "receipt" => [
                "total" => $total,
                "points" => $points
            ]
        ];

        if ($skip) {
            $postData["receipt"]["skipLoyaltyTotal"] = $skip;
        }
        $result = $this->sendRequest("operations/calc", $postData, "POST");

        return $result;
    }

    public function sendTransaction(int $code, int $total, int $cash, int $orderID, int $skipLoyaltyTotal = null)
    {
        $points = $total - $cash;
        $postData =
            [
                'code' => $code,
                'cashier' => [
                    'externalId' => 'SITE',
                    'name' => 'Продажа с сайта. Заказ ' . $orderID
                ],
                'receipt' => [
                    'total' => $total,
                    'cash' => $cash,
                    'points' => $points,
                ]
            ];
        //если передали на какую часть не распространяется скидка, то
        if ($skipLoyaltyTotal) {
            $postData['receipt']['skipLoyaltyTotal'] = $skipLoyaltyTotal;
        }

        $result = $this->sendRequest("operations", $postData, "POST");
        $calc = $this->calculateTransaction($result["customer"]["uid"], $total, $skipLoyaltyTotal, $points);


        $this->writeLog(["code" => $code, "total" => $total, "cash" => $cash, "orderID" => $orderID, "postData" => $postData, "calcTransaction" => $calc], $result, "[" . $result["id"] . "] Транзакция № " . $result["id"] . " по заказу " . $orderID);

        return $result["id"];//вернем ID транзакции
    }

    public function refundTransaction(int $id, $orderID)
    {
        $result = $this->sendRequest("operations/" . $id . "/refund", ["partialAmount" => null], "POST");
        $this->writeLog(["transactionID" => $id], $result, "[" . $result["id"] . "] Отмена транзакции № " . $id . " по заказу № " . $orderID);

        return $result;
    }


    //СОБЫТИЯ СТАРТ
    public static function addUDS(&$arResult, &$arParams): void
    {
        $session = Application::getInstance()->getSession();

        //добавляем в результирующий массив данные по UDS
        $user = self::getInstance();
        $arResult["JS_DATA"]["UDS_COUPON"]["MAX_BASKET_DISCOUNT"] = $intMaxDiscountBasket = $user->getMaxPriceWriteOff();

        $userBudget = $user->getSaleUserAccountBudget();//сколько может списать
        $userBalls = $user->getSaleUserAccountRezerv();//сколько хочет списать

        if ($userBudget == 0) {
            $arResult['JS_DATA']['UDS_COUPON']["SHOW_GET_BLOCK"] = 1;
            $arResult['JS_DATA']['UDS_COUPON']["SHOW_UPDATE_BLOCK"] = 0;
        } else {
            //проверяем, сколько может списать максимум пользователь при текущей корзине
            $intMaxDiscountUser = (int)($arResult['JS_DATA']["TOTAL"]["PRICE_WITHOUT_DISCOUNT_VALUE"] * ($user->getMaxDiscountPercent() / 100));

            if ($intMaxDiscountBasket < $intMaxDiscountUser) {
                $arResult["JS_DATA"]["UDS_COUPON"]["MAX_BASKET_DISCOUNT"] = $intMaxDiscountUser = $intMaxDiscountBasket;
            }

            $arResult['JS_DATA']['UDS_COUPON']["USR"]["MAX"] = $intMaxDiscountUser;
            $arResult['JS_DATA']['UDS_COUPON']["USR"]["ORDER"] = (int)($arResult['JS_DATA']["TOTAL"]["ORDER_PRICE"]);
            $arResult['JS_DATA']['UDS_COUPON']["USR"]["MAX_USR"] = $user->getMaxDiscountPercent();

            if ($userBudget > $intMaxDiscountUser) {
                $userBudget = $intMaxDiscountUser;
                $arResult['JS_DATA']['UDS_COUPON']["EXT_TEXT"] = $user->pluralForm($userBudget, "балл ", "балла", "баллов") . " UDS при текущем составе корзины";
            } else {
                $arResult['JS_DATA']['UDS_COUPON']["EXT_TEXT"] = " баллов UDS";
            }


            //локальный бюджет пользователя существует, значит есть не использованный код
            $arResult['JS_DATA']['UDS_COUPON']["SHOW_UPDATE_BLOCK"] = 1;
            $arResult['JS_DATA']['UDS_COUPON']["SHOW_GET_BLOCK"] = 0;
            $arResult['JS_DATA']['UDS_COUPON']["BUDGET"] = $userBudget;
            $arResult['JS_DATA']['UDS_COUPON']["BALLS"] = $userBalls ?? 0;
            $arResult['JS_DATA']['UDS_COUPON']["PLURALS"] = $user->pluralForm($userBalls, "балл", "балла", "баллов");
            $arResult['JS_DATA']['UDS_COUPON']["BALLS_FORMATED"] = number_format($arResult['JS_DATA']['UDS_COUPON']["BALLS"], 0, ".", " ") . " &#8381;";
            $arResult['JS_DATA']['UDS_COUPON']["BALLS_TEXT"] = $user->pluralForm($arResult['JS_DATA']['UDS_COUPON']["BALLS"], "балл", "балла", "баллов") . " спишется со счета в UDS <b>после</b> оформления заказа.";

            //если пользователь собрался списывать баллы в системе UDS
            if ($arResult['JS_DATA']['UDS_COUPON']["BALLS"] > 0) {
                $totalDiscount = $arResult['JS_DATA']['UDS_COUPON']["BALLS"];
                $arResult['JS_DATA']['UDS_COUPON']["USR"]["DISC"] = $totalDiscount;
                $totalDiscountFormat = number_format($totalDiscount, 0, ".", " ");
                $arResult['JS_DATA']["TOTAL"]["BASKET_PRICE_DISCOUNT_DIFF"] = "$totalDiscountFormat &#8381;";
                $arResult['JS_DATA']["TOTAL"]["BASKET_PRICE_DISCOUNT_DIFF_VALUE"] = $totalDiscount;
                $arResult['JS_DATA']["TOTAL"]["ORDER_PRICE"] = $arResult['JS_DATA']["TOTAL"]["PRICE_WITHOUT_DISCOUNT_VALUE"] - $totalDiscount;
                $arResult['JS_DATA']["TOTAL"]["ORDER_PRICE_FORMATED"] = number_format($arResult['JS_DATA']["TOTAL"]["ORDER_PRICE"], 0, ".", " ") . " &#8381;";
                $arResult['JS_DATA']["TOTAL"]["ORDER_TOTAL_PRICE"] = $arResult['JS_DATA']["TOTAL"]["ORDER_PRICE"] + $arResult['JS_DATA']["TOTAL"]["DELIVERY_PRICE"];
                $arResult['JS_DATA']["TOTAL"]["ORDER_TOTAL_PRICE_FORMATED"] = number_format($arResult['JS_DATA']["TOTAL"]["ORDER_TOTAL_PRICE"], 0, ".", " ") . " &#8381;";

                $arResult['JS_DATA']["TOTAL"]["DISCOUNT_PRICE"] = $arResult['JS_DATA']["TOTAL"]["BASKET_PRICE_DISCOUNT_DIFF_VALUE"];
                $arResult['JS_DATA']["TOTAL"]["DISCOUNT_PRICE_FORMATED"] = number_format($arResult['JS_DATA']["TOTAL"]["DISCOUNT_PRICE"], 0, ".", " ") . " &#8381;";
            }
        }
    }

    public static function UDSOrderComplete($orderId, $orderFields, $componentParams)
    {
        $uds = self::getInstance();

        if ($uds->getSaleUserAccountRezerv() && $uds->getUDSCurrentUserID() && $uds->getPurchaseCode()) {
            $order = Sale\Order::load($orderId);
            $basket = $order->getBasket();
            $skip = $uds->getSkipLoyaltyTotal($basket);
            //мы использовали баллы UDS при оформлении заказа, значит отправляем транзакцию
            $transactionID = $uds->sendTransaction($uds->getPurchaseCode(), $order->getBasket()->getBasePrice(), (int)($order->getBasket()->getBasePrice() - $uds->getSaleUserAccountRezerv()), $order->getId(), $skip);

            $propsUDS_GIUD = $order->getPropertyCollection()->getItemByOrderPropertyId($uds::UDS_GUID);
            $propsUDS_BALLS = $order->getPropertyCollection()->getItemByOrderPropertyId($uds::UDS_BALLS);
            $propsUDS_transaction = $order->getPropertyCollection()->getItemByOrderPropertyId($uds::TRANSACTION_ID);
            $propsUDS_GIUD->setValue($uds->getUDSCurrentUserID());
            $propsUDS_BALLS->setValue($uds->getSaleUserAccountRezerv());
            $propsUDS_transaction->setValue($transactionID);

            $order->save();
            self::refreshUDS();
        }
    }

    public static function UDSRefresh($orderId, ?array $orderFields = [])
    {
        $objUDS = self::getInstance();
        $objUDS->resetUDSDiscountCart();

        return true;
    }

    public static function UDSAfterOrderAdd($orderId, ?array $orderFields = [])
    {
        //получаем текущий инстанс нашего приложения
        $objUDS = self::getInstance();

        //смотрим, есть ли баллы, которые списал пользователь
        if ($intBudget = $objUDS->getSaleUserAccountBudget()) {
//            $objUDS
        }
    }

    public static function UDSOrderUpdate($id, &$arFields)
    {
        // отмена транзакции если отменили заказ
        $uds = self::getInstance();
        $order = \Bitrix\Sale\Order::load($id);
        $canceled_status = $order->getField('CANCELED');
        if ($canceled_status == "Y") {
            $transactionID = $order->getPropertyCollection()->getItemByOrderPropertyId($uds::TRANSACTION_ID)->getValue();

            //если была транзакция у заказа, то отменяем ее
            if ($transactionID) {
                $result = $uds->refundTransaction($transactionID, $order->getId());
                $order->getPropertyCollection()->getItemByOrderPropertyId($uds::TRANSACTION_ID)->setValue("");
                $order->save();
            }
        }
    }


    //СОБЫТИЯ СТОП


}


?>
